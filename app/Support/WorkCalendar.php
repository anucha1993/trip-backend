<?php

namespace App\Support;

use App\Models\WorkSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkCalendar
{
    public function __construct(private WorkSetting $settings)
    {
    }

    /**
     * Classify a calendar date as one of:
     * "working", "weekly_off", "alt_saturday_off", "holiday".
     *
     * @param  Collection<int, string>  $holidayDates  Y-m-d strings, preloaded for the period being reported.
     */
    public function classifyDay(Carbon $date, Collection $holidayDates): string
    {
        if ($holidayDates->contains($date->toDateString())) {
            return 'holiday';
        }

        $dayOfWeek = $date->dayOfWeek; // Carbon: 0 = Sunday ... 6 = Saturday

        if ($dayOfWeek === (int) $this->settings->weekly_off_day) {
            return 'weekly_off';
        }

        if ($dayOfWeek === Carbon::SATURDAY && $this->settings->alt_saturday_enabled) {
            return $this->isWorkingSaturday($date) ? 'working' : 'alt_saturday_off';
        }

        return 'working';
    }

    public function isWorkingDay(Carbon $date, Collection $holidayDates): bool
    {
        return $this->classifyDay($date, $holidayDates) === 'working';
    }

    /**
     * Alternating Saturday rule: the FIRST Saturday of the month is always a
     * working Saturday, then it alternates off/working each following Saturday.
     * Resets every month (always anchored to that month's first Saturday).
     */
    private function isWorkingSaturday(Carbon $date): bool
    {
        $firstSaturday = $date->copy()->startOfMonth();
        while ($firstSaturday->dayOfWeek !== Carbon::SATURDAY) {
            $firstSaturday->addDay();
        }

        $occurrence = intdiv($date->day - $firstSaturday->day, 7) + 1; // 1-indexed

        return $occurrence % 2 === 1;
    }

    /**
     * Determine whether a check-in time counts as late, based on shift start + grace period.
     */
    public function isLate(Carbon $checkInTime): bool
    {
        $shiftStart = $checkInTime->copy()
            ->setTimeFromTimeString($this->settings->shift_start_time)
            ->addMinutes($this->settings->late_grace_minutes);

        return $checkInTime->greaterThan($shiftStart);
    }
}
