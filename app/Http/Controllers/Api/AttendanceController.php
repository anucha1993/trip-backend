<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Location;
use App\Models\WorkSetting;
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
            // Location is always required — the system must record where
            // every check-in/out happened, not only for geofenced locations.
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ], [
            'latitude.required' => 'จำเป็นต้องอนุญาตการเข้าถึงตำแหน่ง (Location) ก่อนลงเวลา',
            'longitude.required' => 'จำเป็นต้องอนุญาตการเข้าถึงตำแหน่ง (Location) ก่อนลงเวลา',
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

        if ($location->hasGeofence()) {
            $distance = $location->distanceInMetersFrom($data['latitude'], $data['longitude']);

            if ($distance > $location->radius_meters) {
                return response()->json([
                    'message' => sprintf(
                        'คุณอยู่นอกพื้นที่ที่กำหนดไว้ (ห่างจากจุดสแกนประมาณ %d เมตร ต้องอยู่ในระยะ %d เมตร)',
                        round($distance),
                        $location->radius_meters
                    ),
                ], 422);
            }
        }

        $employee = $request->user();
        $now = Carbon::now();
        $workDate = $now->toDateString();
        $settings = WorkSetting::current();

        // The scan's type is decided purely by which configured time window
        // "now" falls into — not by scan order/count, so an employee can
        // scan as many times as they like within a window.
        $checkInStart = $now->copy()->setTimeFromTimeString($settings->check_in_window_start);
        $checkInEnd = $now->copy()->setTimeFromTimeString($settings->check_in_window_end);
        $checkOutStart = $now->copy()->setTimeFromTimeString($settings->check_out_window_start);
        $checkOutEnd = $now->copy()->setTimeFromTimeString($settings->check_out_window_end);

        if ($now->between($checkInStart, $checkInEnd)) {
            $type = 'check_in';
        } elseif ($now->between($checkOutStart, $checkOutEnd)) {
            $type = 'check_out';
        } else {
            return response()->json([
                'message' => sprintf(
                    'นอกช่วงเวลาที่กำหนดให้สแกน (ช่วงเข้างาน %s-%s, ช่วงออกงาน %s-%s)',
                    $settings->check_in_window_start,
                    $settings->check_in_window_end,
                    $settings->check_out_window_start,
                    $settings->check_out_window_end
                ),
            ], 422);
        }

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
