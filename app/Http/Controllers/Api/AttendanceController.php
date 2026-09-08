<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    /**
     * Employee scans a location QR code to check in or check out.
     * The type (in/out) is inferred automatically from today's last record.
     */
    public function scan(Request $request)
    {
        $data = $request->validate([
            'qr_token' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Accept either a raw token or a scanned URL containing ?token=...
        $token = $data['qr_token'];
        if (preg_match('/[?&]token=([^&]+)/', $token, $m)) {
            $token = urldecode($m[1]);
        }

        $location = Location::where('qr_token', $token)->where('is_active', true)->first();

        if (! $location) {
            return response()->json(['message' => 'Invalid or inactive QR code.'], 422);
        }

        $employee = $request->user();
        $now = Carbon::now();
        $workDate = $now->toDateString();

        $lastToday = Attendance::where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->first();

        $type = (! $lastToday || $lastToday->type === 'check_out') ? 'check_in' : 'check_out';

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'location_id' => $location->id,
            'type' => $type,
            'work_date' => $workDate,
            'scanned_at' => $now,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ]);

        return response()->json([
            'message' => $type === 'check_in' ? 'Checked in successfully.' : 'Checked out successfully.',
            'attendance' => [
                'id' => $attendance->id,
                'type' => $attendance->type,
                'scanned_at' => $attendance->scanned_at->toIso8601String(),
                'location' => $location->name,
            ],
        ]);
    }

    /**
     * The authenticated employee's own attendance history.
     */
    public function myHistory(Request $request)
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Attendance::with('location')
            ->where('employee_id', $request->user()->id)
            ->orderByDesc('scanned_at');

        if (! empty($data['month'])) {
            [$year, $month] = explode('-', $data['month']);
            $query->whereYear('work_date', $year)->whereMonth('work_date', $month);
        }

        $attendances = $query->paginate($data['per_page'] ?? 30);

        return response()->json($attendances);
    }
}
