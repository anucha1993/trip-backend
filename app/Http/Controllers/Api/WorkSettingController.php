<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkSetting;
use Illuminate\Http\Request;

class WorkSettingController extends Controller
{
    public function show()
    {
        return response()->json(WorkSetting::current());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'shift_start_time' => ['required', 'date_format:H:i'],
            'shift_end_time' => ['required', 'date_format:H:i'],
            'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'weekly_off_day' => ['required', 'integer', 'min:0', 'max:6'],
            'alt_saturday_enabled' => ['required', 'boolean'],
            'check_in_window_start' => ['required', 'date_format:H:i'],
            'check_in_window_end' => ['required', 'date_format:H:i', 'after:check_in_window_start'],
            'check_out_window_start' => ['required', 'date_format:H:i', 'after:check_in_window_end'],
            'check_out_window_end' => ['required', 'date_format:H:i', 'after:check_out_window_start'],
        ], [
            'check_in_window_end.after' => 'เวลาสิ้นสุดช่วงเข้างานต้องอยู่หลังเวลาเริ่มต้น',
            'check_out_window_start.after' => 'ช่วงเวลาออกงานต้องเริ่มหลังช่วงเวลาเข้างานสิ้นสุด',
            'check_out_window_end.after' => 'เวลาสิ้นสุดช่วงออกงานต้องอยู่หลังเวลาเริ่มต้น',
        ]);

        $settings = WorkSetting::current();
        $settings->update($data);

        return response()->json($settings);
    }
}
