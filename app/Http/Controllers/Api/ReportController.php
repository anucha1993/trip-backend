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

        $date = $data['date'] ?? Carbon::now()->toDateString();
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

        return response()->json([
            'date' => $date,
            'day_type' => $dayType,
            'holiday_name' => $holidayName,
            'employee_count' => $rows->count(),
            'rows' => $rows,
        ]);
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

        $month = $data['month'] ?? Carbon::now()->format('Y-m');
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

        return response()->json([
            'month' => $month,
            'employee_count' => $rows->count(),
            'rows' => $rows,
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
            'total_hours' => round($this->workedMinutes($records) / 60, 2),
            'events' => $records->map(fn (Attendance $a) => [
                'type' => $a->type,
                'scanned_at' => $a->scanned_at->toIso8601String(),
                'location' => $a->location?->name,
            ])->values(),
        ];
    }

    /**
     * Sum minutes between each check_in and the following check_out.
     * An unmatched trailing check_in (no check_out yet) is ignored.
     */
    private function workedMinutes(?Collection $records): int
    {
        if (! $records) {
            return 0;
        }

        $sorted = $records->sortBy([['scanned_at', 'asc'], ['id', 'asc']])->values();
        $minutes = 0;
        $pendingCheckIn = null;

        foreach ($sorted as $record) {
            if ($record->type === 'check_in') {
                $pendingCheckIn = $record->scanned_at;
            } elseif ($record->type === 'check_out' && $pendingCheckIn) {
                $minutes += $pendingCheckIn->diffInMinutes($record->scanned_at);
                $pendingCheckIn = null;
            }
        }

        return $minutes;
    }
}
