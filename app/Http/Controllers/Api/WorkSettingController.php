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
        ]);

        $settings = WorkSetting::current();
        $settings->update($data);

        return response()->json($settings);
    }
}
