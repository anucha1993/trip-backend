<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_settings', function (Blueprint $table) {
            $table->dropColumn(['alternate_scan_mode', 'min_scan_interval_minutes']);

            // Any scan is check-in if it falls in this window, check-out if
            // it falls in the other window, regardless of how many times
            // the employee scans — no more first/last or alternating logic.
            $table->string('check_in_window_start', 5)->default('04:00')->after('alt_saturday_enabled');
            $table->string('check_in_window_end', 5)->default('12:00')->after('check_in_window_start');
            $table->string('check_out_window_start', 5)->default('12:01')->after('check_in_window_end');
            $table->string('check_out_window_end', 5)->default('22:00')->after('check_out_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('work_settings', function (Blueprint $table) {
            $table->dropColumn([
                'check_in_window_start',
                'check_in_window_end',
                'check_out_window_start',
                'check_out_window_end',
            ]);
            $table->boolean('alternate_scan_mode')->default(false)->after('alt_saturday_enabled');
            $table->unsignedInteger('min_scan_interval_minutes')->default(0)->after('alternate_scan_mode');
        });
    }
};
