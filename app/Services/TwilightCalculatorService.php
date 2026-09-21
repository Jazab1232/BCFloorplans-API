<?php

namespace App\Services;

use App\Models\Service;
use App\Models\Vendor;
use App\Models\Property;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Log;

class TwilightCalculatorService
{
    /**
     * Default Vancouver coordinates & timezone
     */
    const DEFAULT_LATITUDE = 49.2827;
    const DEFAULT_LONGITUDE = -123.1207;
    const DEFAULT_TIMEZONE = 'America/Vancouver';

    /**
     * Calculate seasonal sunset window for a given date & location.
     *
     * Window:
     * - Sunset time S (e.g., 20:15)
     * - Window Start = Sunset - 45 minutes (e.g., 19:30)
     * - Window End = Sunset + 15 minutes (e.g., 20:30)
     *
     * @param string $date (YYYY-MM-DD)
     * @param float|null $latitude
     * @param float|null $longitude
     * @param string $timezone
     * @return array
     */
    public function calculateSunsetWindow(
        string $date,
        ?float $latitude = null,
        ?float $longitude = null,
        string $timezone = self::DEFAULT_TIMEZONE
    ): array {
        $lat = $latitude ?? self::DEFAULT_LATITUDE;
        $lng = $longitude ?? self::DEFAULT_LONGITUDE;
        $tz = !empty($timezone) ? $timezone : self::DEFAULT_TIMEZONE;

        $sunsetTime = $this->calculateSunsetTime($date, $lat, $lng, $tz);
        
        $sunsetCarbon = Carbon::parse("{$date} {$sunsetTime}", $tz);
        $windowStartCarbon = $sunsetCarbon->copy()->subMinutes(45);
        $windowEndCarbon = $sunsetCarbon->copy()->addMinutes(15);

        return [
            'date' => $date,
            'sunset' => $sunsetCarbon->format('H:i'),
            'window_start' => $windowStartCarbon->format('H:i'),
            'window_end' => $windowEndCarbon->format('H:i'),
            'formatted_sunset' => $sunsetCarbon->format('g:i A'),
            'formatted_window' => $windowStartCarbon->format('g:i A') . ' – ' . $windowEndCarbon->format('g:i A'),
            'timezone' => $tz,
        ];
    }

    /**
     * Check if a given service model or service payload is a Twilight service.
     */
    public function isTwilightService($service): bool
    {
        if (!$service) return false;

        if (is_array($service)) {
            $name = strtolower($service['name'] ?? $service['title'] ?? '');
            $categoryName = strtolower($service['category']['name'] ?? $service['category_name'] ?? '');
            $isTwilightFlag = !empty($service['is_twilight']);
        } elseif (is_object($service)) {
            $name = strtolower($service->name ?? $service->title ?? '');
            $categoryName = strtolower($service->category->name ?? '');
            $isTwilightFlag = !empty($service->is_twilight);
        } else {
            return false;
        }

        return $isTwilightFlag ||
               $categoryName === 'twilight photos' ||
               str_contains($name, 'twilight');
    }

    /**
     * Check if a slot start_time (HH:mm) falls within the sunset window.
     */
    public function isSlotInTwilightWindow(string $startTime, array $sunsetWindow): bool
    {
        $startMins = $this->timeToMinutes($startTime);
        $windowStartMins = $this->timeToMinutes($sunsetWindow['window_start']);
        $windowEndMins = $this->timeToMinutes($sunsetWindow['window_end']);

        return ($startMins >= $windowStartMins && $startMins <= $windowEndMins);
    }

    /**
     * Check if a vendor is available for a twilight shoot on a specific date.
     *
     * Rules:
     * - If vendor has `is_twilight` checked in `work_hours->work_days` for that day of week:
     *   The vendor IS eligible/available for twilight during the twilight window, even if outside regular hours or if day is marked off!
     * - If vendor does NOT have `is_twilight` checked:
     *   The vendor is only available if the twilight window falls within their standard `start_time`..`end_time` and day is not off.
     */
    public function isVendorAvailableForTwilight(Vendor $vendor, string $date, array $sunsetWindow): bool
    {
        $dayOfWeek = strtolower(Carbon::parse($date)->format('ddd'));
        $workHours = $vendor->workHours;

        if (!$workHours || empty($workHours->work_days)) {
            return false;
        }

        $daySchedule = collect($workHours->work_days)->firstWhere('day', $dayOfWeek);
        if (!$daySchedule) {
            return false;
        }

        $isTwilightChecked = !empty($daySchedule['is_twilight']) &&
            ($daySchedule['is_twilight'] === true || $daySchedule['is_twilight'] === 1 || $daySchedule['is_twilight'] === '1');

        if ($isTwilightChecked) {
            return true;
        }

        // If not checked for twilight, vendor must be working during twilight window
        $isDayOff = !empty($daySchedule['is_off']) &&
            ($daySchedule['is_off'] === true || $daySchedule['is_off'] === 1 || $daySchedule['is_off'] === '1');

        if ($isDayOff) {
            return false;
        }

        $workStart = $daySchedule['start_time'] ?? $workHours->start_time ?? '09:00';
        $workEnd = $daySchedule['end_time'] ?? $workHours->end_time ?? '17:00';

        $windowStartMins = $this->timeToMinutes($sunsetWindow['window_start']);
        $windowEndMins = $this->timeToMinutes($sunsetWindow['window_end']);
        $workStartMins = $this->timeToMinutes($workStart);
        $workEndMins = $this->timeToMinutes($workEnd);

        // Check if twilight window overlaps with vendor work hours
        return ($windowStartMins >= $workStartMins && $windowEndMins <= $workEndMins);
    }

    /**
     * Convert time (HH:mm) to minutes from midnight.
     */
    private function timeToMinutes(string $timeStr): int
    {
        $parts = explode(':', $timeStr);
        $h = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        return $h * 60 + $m;
    }

    /**
     * Mathematical NOAA sunset algorithm.
     */
    private function calculateSunsetTime(string $dateStr, float $latitude, float $longitude, string $tzName): string
    {
        try {
            $dt = new DateTime($dateStr, new DateTimeZone($tzName));
            $year = (int)$dt->format('Y');
            $month = (int)$dt->format('m');
            $day = (int)$dt->format('d');

            $N = (int)$dt->format('z') + 1;
            $lngHour = $longitude / 15.0;
            $t = $N + ((18.0 - $lngHour) / 24.0);

            $M = (0.9856 * $t) - 3.289;

            $L = $M + (1.916 * sin(deg2rad($M))) + (0.020 * sin(deg2rad(2 * $M))) + 282.634;
            $L = fmod($L, 360.0);
            if ($L < 0) $L += 360.0;

            $RA = rad2deg(atan(0.91764 * tan(deg2rad($L))));
            $RA = fmod($RA, 360.0);
            if ($RA < 0) $RA += 360.0;

            $Lquadrant  = floor($L / 90.0) * 90.0;
            $RAquadrant = floor($RA / 90.0) * 90.0;
            $RA = $RA + ($Lquadrant - $RAquadrant);
            $RA = $RA / 15.0;

            $sinDec = 0.39782 * sin(deg2rad($L));
            $cosDec = cos(asin($sinDec));

            $zenith = 90.8333; // Standard sunset zenith
            $cosH = (cos(deg2rad($zenith)) - ($sinDec * sin(deg2rad($latitude)))) / ($cosDec * cos(deg2rad($latitude)));

            if ($cosH > 1 || $cosH < -1) {
                return '20:15';
            }

            $H = rad2deg(acos($cosH)) / 15.0;
            $T = $H + $RA - (0.06571 * $t) - 6.622;
            $UT = $T - $lngHour;
            $UT = fmod($UT, 24.0);
            if ($UT < 0) $UT += 24.0;

            $utSec = (int)round($UT * 3600);
            $utHours = (int)floor($utSec / 3600);
            $utMins = (int)floor(($utSec % 3600) / 60);

            $utcDt = new DateTime(sprintf('%04d-%02d-%02dT%02d:%02d:00Z', $year, $month, $day, $utHours, $utMins), new DateTimeZone('UTC'));
            $utcDt->setTimezone(new DateTimeZone($tzName));

            return $utcDt->format('H:i');
        } catch (\Throwable $e) {
            Log::warning("Sunset calculation failed, using seasonal fallback for date {$dateStr}: " . $e->getMessage());
            return $this->getSeasonalSunsetFallback($dateStr);
        }
    }

    /**
     * Seasonal sunset fallback for Pacific Time BC if coordinates calculation throws.
     */
    private function getSeasonalSunsetFallback(string $dateStr): string
    {
        $month = (int)Carbon::parse($dateStr)->format('m');
        return match ($month) {
            1  => '16:45',
            2  => '17:30',
            3  => '19:15',
            4  => '20:00',
            5  => '20:45',
            6  => '21:15',
            7  => '21:00',
            8  => '20:15',
            9  => '19:15',
            10 => '18:15',
            11 => '16:45',
            12 => '16:15',
            default => '20:15',
        };
    }
}
