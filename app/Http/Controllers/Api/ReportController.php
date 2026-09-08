<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    /**
     * Daily report: every employee's check-in/out records and worked hours for one day.
     */
    public function daily(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = $data['date'] ?? Carbon::now()->toDateString();

        $attendances = Attendance::with('employee', 'location')
            ->whereDate('work_date', $date)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $rows = $attendances->map(function (Collection $records) {
            $employee = $records->first()->employee;

            return $this->summarizeDay($employee, $records);
        })->values();

        return response()->json([
            'date' => $date,
            'employee_count' => $rows->count(),
            'rows' => $rows,
        ]);
    }

    /**
     * Monthly report: total days present and total worked hours per employee.
     */
    public function monthly(Request $request)
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = $data['month'] ?? Carbon::now()->format('Y-m');
        [$year, $monthNumber] = explode('-', $month);

        $attendances = Attendance::with('employee')
            ->whereYear('work_date', $year)
            ->whereMonth('work_date', $monthNumber)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('employee_id');

        $rows = $attendances->map(function (Collection $records) {
            $employee = $records->first()->employee;
            $byDay = $records->groupBy(fn (Attendance $a) => $a->work_date->toDateString());

            $totalMinutes = 0;
            foreach ($byDay as $dayRecords) {
                $totalMinutes += $this->workedMinutes($dayRecords);
            }

            return [
                'employee_id' => $employee->id,
                'display_name' => $employee->display_name,
                'days_present' => $byDay->count(),
                'total_hours' => round($totalMinutes / 60, 2),
            ];
        })->values();

        return response()->json([
            'month' => $month,
            'employee_count' => $rows->count(),
            'rows' => $rows,
        ]);
    }

    private function summarizeDay(Employee $employee, Collection $records): array
    {
        $firstCheckIn = $records->firstWhere('type', 'check_in');
        $lastCheckOut = $records->where('type', 'check_out')->last();

        return [
            'employee_id' => $employee->id,
            'display_name' => $employee->display_name,
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
    private function workedMinutes(Collection $records): int
    {
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
