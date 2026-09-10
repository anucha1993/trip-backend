<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_settings', function (Blueprint $table) {
            // false (default) = first scan/day is check-in, every later scan is check-out.
            // true = old toggle behaviour (alternate check-in/check-out each scan).
            $table->boolean('alternate_scan_mode')->default(false)->after('alt_saturday_enabled');
            $table->unsignedInteger('min_scan_interval_minutes')->default(0)->after('alternate_scan_mode');
        });
    }

    public function down(): void
    {
        Schema::table('work_settings', function (Blueprint $table) {
            $table->dropColumn(['alternate_scan_mode', 'min_scan_interval_minutes']);
        });
    }
};
