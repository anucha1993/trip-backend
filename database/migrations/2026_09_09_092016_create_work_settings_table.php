<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_settings', function (Blueprint $table) {
            $table->id();
            $table->time('shift_start_time')->default('08:00:00');
            $table->time('shift_end_time')->default('17:00:00');
            $table->unsignedInteger('late_grace_minutes')->default(0);
            $table->unsignedTinyInteger('weekly_off_day')->default(0); // 0=Sunday .. 6=Saturday
            $table->boolean('alt_saturday_enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_settings');
    }
};
