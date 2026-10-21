<?php

namespace App\Services;

use Carbon\CarbonInterface;

/** Is a branch open at a given moment? Hours are stored per weekday as "09:00-22:00". */
class OpeningHours
{
    public static function isOpen(?array $hours, ?array $holidays, CarbonInterface $at): bool
    {
        // a branch that never configured its hours is treated as always open
        if (empty($hours)) {
            return true;
        }

        if (in_array($at->toDateString(), $holidays ?? [], true)) {
            return false;
        }

        $today = $hours[strtolower($at->englishDayOfWeek)] ?? null;
        if (!$today || !preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim((string) $today), $m)) {
            return false;
        }

        $open = $at->copy()->setTime((int) $m[1], (int) $m[2]);
        $close = $at->copy()->setTime((int) $m[3], (int) $m[4]);
        if ($close->lte($open)) {
            $close->addDay(); // closes after midnight
        }

        return $at->betweenIncluded($open, $close);
    }

    /** "Open 09:00-22:00" style hint for error messages */
    public static function todayLabel(?array $hours, CarbonInterface $at): string
    {
        return $hours[strtolower($at->englishDayOfWeek)] ?? 'closed';
    }
}
