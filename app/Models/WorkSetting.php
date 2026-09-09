<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSetting extends Model
{
    protected $fillable = [
        'shift_start_time',
        'shift_end_time',
        'late_grace_minutes',
        'weekly_off_day',
        'alt_saturday_enabled',
    ];

    protected function casts(): array
    {
        return [
            'late_grace_minutes' => 'integer',
            'weekly_off_day' => 'integer',
            'alt_saturday_enabled' => 'boolean',
        ];
    }

    /**
     * There is always exactly one settings row. Create it with explicit
     * defaults if missing (firstOrCreate alone won't populate in-memory
     * attributes that only exist as DB column defaults).
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1], [
            'shift_start_time' => '08:00:00',
            'shift_end_time' => '17:00:00',
            'late_grace_minutes' => 0,
            'weekly_off_day' => 0,
            'alt_saturday_enabled' => true,
        ]);
    }
}
