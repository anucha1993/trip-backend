<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\WorkSetting;
use App\Support\WorkCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    /**
     * Daily report: status (present/late/absent/leave/holiday/day off) and
     * worked hours for every active employee, for one calendar day.
     */
    public function daily(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->buildDailyReport($data['date'] ?? Carbon::now()->toDateString()));
    }

    /**
     * CSV export of the daily report (opens in Excel).
     */
    public function dailyExport(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->buildDailyReport($data['date'] ?? Carbon::now()->toDateString());

        $statusLabels = [
            'present' => 'มาปกติ',
            'late' => 'มาสาย',
            'absent' => 'ขาดงาน',
            'leave' => 'ลา',
            'holiday' => 'วันหยุด',
            'weekly_off' => 'วันหยุดประจำสัปดาห์',
            'alt_saturday_off' => 'เสาร์หยุด',
        ];

        $header = ['พนักงาน', 'ชื่อ-สกุล', 'รหัสพนักงาน', 'สถานะ', 'เข้างานครั้งแรก', 'ออกงานครั้งสุดท้าย', 'ชั่วโมงทำงาน'];

        $rows = collect($report['rows'])->map(fn (array $row) => [
            $row['display_name'],
            $row['full_name'],
            $row['employee_code'],
            $statusLabels[$row['status']] ?? $row['status'],
            $row['first_check_in'] ? Carbon::parse($row['first_check_in'])->format('H:i') : '',
            $row['last_check_out'] ? Carbon::parse($row['last_check_out'])->format('H:i') : '',
            $row['total_hours'],
        ])->all();

        return $this->csvResponse(
            "daily-report-{$report['date']}.csv",
            $header,
            $rows
        );
    }

    /**
     * Monthly report: days present/late/absent/on-leave and total worked
     * hours per employee, for one calendar month.
     */
    public function monthly(Request $request)
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        return response()->json($this->buildMonthlyReport($data['month'] ?? Carbon::now()->format('Y-m')));
    }

    /**
     * CSV export of the monthly report (opens in Excel).
     */
    public function monthlyExport(Request $request)
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $report = $this->buildMonthlyReport($data['month'] ?? Carbon::now()->format('Y-m'));

        $header = ['พนักงาน', 'ชื่อ-สกุล', 'รหัสพนักงาน', 'มาปกติ (วัน)', 'มาสาย (วัน)', 'ขาดงาน (วัน)', 'ลา (วัน)', 'ชั่วโมงทำงานรวม'];

        $rows = collect($report['rows'])->map(fn (array $row) => [
            $row['display_name'],
            $row['full_name'],
            $row['employee_code'],
            $row['days_present'],
            $row['days_late'],
            $row['days_absent'],
            $row['days_leave'],
            $row['total_hours'],
        ])->all();

        return $this->csvResponse(
            "monthly-report-{$report['month']}.csv",
            $header,
            $rows
        );
    }

    private function buildDailyReport(string $date): array
    {
        $day = Carbon::parse($date);

        $calendar = new WorkCalendar(WorkSetting::current());
        $holidayDates = Holiday::pluck('date')->map(fn ($d) => $d->toDateString());
        $dayType = $calendar->classifyDay($day, $holidayDates);
        $holidayName = $dayType === 'holiday'
            ? Holiday::whereDate('date', $date)->value('name')
            : null;

        $employees = Employee::where('is_active', true)->orderBy('display_name')->get();

        $attendancesByEmployee = Attendance::with('location')
            ->whereDate('work_date', $date)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $leaveEmployeeIds = LeaveRequest::whereDate('date', $date)->pluck('employee_id');

        $rows = $employees->map(function (Employee $employee) use (
            $attendancesByEmployee, $leaveEmployeeIds, $dayType, $calendar
        ) {
            $records = $attendancesByEmployee->get($employee->id, collect());

            return $this->summarizeDay($employee, $records, $dayType, $leaveEmployeeIds->contains($employee->id), $calendar);
        })->values();

        return [
            'date' => $date,
            'day_type' => $dayType,
            'holiday_name' => $holidayName,
            'employee_count' => $rows->count(),
            'rows' => $rows,
        ];
    }

    private function buildMonthlyReport(string $month): array
    {
        [$year, $monthNumber] = explode('-', $month);

        $monthStart = Carbon::createFromDate((int) $year, (int) $monthNumber, 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $lastEvaluatedDay = Carbon::now()->min($monthEnd);

        $calendar = new WorkCalendar(WorkSetting::current());
        $holidayDates = Holiday::whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->pluck('date')->map(fn ($d) => $d->toDateString());

        $employees = Employee::where('is_active', true)->orderBy('display_name')->get();

        $attendancesByEmployee = Attendance::whereYear('work_date', $year)
            ->whereMonth('work_date', $monthNumber)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $leavesByEmployee = LeaveRequest::whereYear('date', $year)
            ->whereMonth('date', $monthNumber)
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(function (Employee $employee) use (
            $attendancesByEmployee, $leavesByEmployee, $calendar, $holidayDates, $monthStart, $lastEvaluatedDay
        ) {
            $records = $attendancesByEmployee->get($employee->id, collect());
            $byDay = $records->groupBy(fn (Attendance $a) => $a->work_date->toDateString());
            $leaveDates = ($leavesByEmployee->get($employee->id, collect()))
                ->map(fn (LeaveRequest $l) => $l->date->toDateString());

            $daysPresent = 0;
            $daysLate = 0;
            $daysAbsent = 0;
            $daysLeave = 0;
            $totalMinutes = 0;

            for ($cursor = $monthStart->copy(); $cursor->lte($lastEvaluatedDay); $cursor->addDay()) {
                $dateKey = $cursor->toDateString();

                if ($calendar->classifyDay($cursor, $holidayDates) !== 'working') {
                    continue;
                }

                if ($leaveDates->contains($dateKey)) {
                    $daysLeave++;

                    continue;
                }

                $dayRecords = $byDay->get($dateKey);
                $firstCheckIn = $dayRecords?->firstWhere('type', 'check_in');

                if (! $firstCheckIn) {
                    $daysAbsent++;

                    continue;
                }

                if ($calendar->isLate($firstCheckIn->scanned_at)) {
                    $daysLate++;
                } else {
                    $daysPresent++;
                }

                $totalMinutes += $this->workedMinutes($dayRecords);
            }

            return [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'display_name' => $employee->display_name,
                'full_name' => $employee->full_name,
                'days_present' => $daysPresent,
                'days_late' => $daysLate,
                'days_absent' => $daysAbsent,
                'days_leave' => $daysLeave,
                'total_hours' => round($totalMinutes / 60, 2),
            ];
        })->values();

        return [
            'month' => $month,
            'employee_count' => $rows->count(),
            'rows' => $rows,
        ];
    }

    /**
     * Stream a UTF-8 (with BOM, so Excel renders Thai text correctly) CSV download.
     */
    private function csvResponse(string $filename, array $header, array $rows)
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($handle, $header);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function summarizeDay(
        Employee $employee,
        Collection $records,
        string $dayType,
        bool $onLeave,
        WorkCalendar $calendar
    ): array {
        $firstCheckIn = $records->firstWhere('type', 'check_in');
        $lastCheckOut = $records->where('type', 'check_out')->last();

        if ($dayType !== 'working') {
            $status = $dayType;
        } elseif ($onLeave) {
            $status = 'leave';
        } elseif ($firstCheckIn) {
            $status = $calendar->isLate($firstCheckIn->scanned_at) ? 'late' : 'present';
        } else {
            $status = 'absent';
        }

        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'display_name' => $employee->display_name,
            'full_name' => $employee->full_name,
            'status' => $status,
            'first_check_in' => $firstCheckIn?->scanned_at?->toIso8601String(),
            'last_check_out' => $lastCheckOut?->scanned_at?->toIso8601String(),
            'location' => $firstCheckIn?->location?->name,
            'locations' => $records->pluck('location.name')->filter()->unique()->values(),
            'total_hours' => round($this->workedMinutes($records) / 60, 2),
            'events' => $records->map(fn (Attendance $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'scanned_at' => $a->scanned_at->toIso8601String(),
                'location' => $a->location?->name,
                'latitude' => $a->latitude !== null ? (float) $a->latitude : null,
                'longitude' => $a->longitude !== null ? (float) $a->longitude : null,
                'is_manual' => $a->is_manual,
                'note' => $a->note,
            ])->values(),
        ];
    }

    /**
     * Minutes between the day's first check-in and its last check-out.
     * Only the first scan of a day is ever a check-in (see
     * AttendanceController::scan), so there is at most one check-in to
     * anchor from; every later scan is a check-out and the latest one wins.
     */
    private function workedMinutes(?Collection $records): int
    {
        if (! $records) {
            return 0;
        }

        $firstCheckIn = $records->firstWhere('type', 'check_in');
        $lastCheckOut = $records->where('type', 'check_out')->last();

        if (! $firstCheckIn || ! $lastCheckOut) {
            return 0;
        }

        $minutes = $firstCheckIn->scanned_at->diffInMinutes($lastCheckOut->scanned_at, false);

        return max($minutes, 0);
    }
}
