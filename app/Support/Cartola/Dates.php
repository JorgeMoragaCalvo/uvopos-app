<?php

namespace App\Support\Cartola;

use Carbon\Carbon;

/**
 * Dates as Chilean bank exports write them: day first, either separator,
 * sometimes with a time appended, sometimes ISO because the export went
 * through a spreadsheet first.
 */
class Dates
{
    /**
     * Tried in order. Day-first formats come before the ISO one because
     * "01/02/2026" is 1 February in Chile, never 2 January.
     */
    private const FORMATS = [
        'd/m/Y',
        'd-m-Y',
        'd/m/y',
        'd-m-y',
        'Y-m-d',
        'Y/m/d',
    ];

    public static function parse(?string $raw): ?Carbon
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        // Drop a trailing time component: "12/03/2026 14:05:00".
        $value = preg_split('/\s+/', $value)[0];

        foreach (self::FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat('!' . $format, $value);
            } catch (\Throwable $e) {
                continue;
            }

            // createFromFormat is lenient — it turns 32/01/2026 into
            // 01/02/2026 — so only accept a round trip.
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }
}
