<?php

namespace Engine;

class ClimateData
{
    /** Monthly normals for Vaduz (reference elevation ~450 m), °C. */
    public const MONTHLY_TEMPERATURES = [
        1 => ['avg' => 0.8, 'min' => -2.7, 'max' => 4.3],
        2 => ['avg' => 2.1, 'min' => -1.8, 'max' => 6.0],
        3 => ['avg' => 6.3, 'min' => 1.9, 'max' => 11.1],
        4 => ['avg' => 9.9, 'min' => 4.9, 'max' => 15.1],
        5 => ['avg' => 14.4, 'min' => 9.3, 'max' => 19.8],
        6 => ['avg' => 17.1, 'min' => 12.2, 'max' => 22.3],
        7 => ['avg' => 19.0, 'min' => 14.1, 'max' => 24.5],
        8 => ['avg' => 18.4, 'min' => 13.9, 'max' => 23.7],
        9 => ['avg' => 14.9, 'min' => 10.6, 'max' => 19.8],
        10 => ['avg' => 10.9, 'min' => 6.7, 'max' => 15.5],
        11 => ['avg' => 5.2, 'min' => 1.7, 'max' => 8.9],
        12 => ['avg' => 1.9, 'min' => -1.4, 'max' => 5.1],
    ];

    public const MONTHLY_PRECIPITATION = [
        1 => ['total' => 65, 'rainyDays' => 10, 'snowDays' => 5],
        2 => ['total' => 55, 'rainyDays' => 9, 'snowDays' => 4],
        3 => ['total' => 70, 'rainyDays' => 11, 'snowDays' => 3],
        4 => ['total' => 80, 'rainyDays' => 12, 'snowDays' => 1],
        5 => ['total' => 105, 'rainyDays' => 13, 'snowDays' => 0],
        6 => ['total' => 130, 'rainyDays' => 14, 'snowDays' => 0],
        7 => ['total' => 140, 'rainyDays' => 13, 'snowDays' => 0],
        8 => ['total' => 135, 'rainyDays' => 12, 'snowDays' => 0],
        9 => ['total' => 95, 'rainyDays' => 10, 'snowDays' => 0],
        10 => ['total' => 75, 'rainyDays' => 9, 'snowDays' => 1],
        11 => ['total' => 80, 'rainyDays' => 10, 'snowDays' => 3],
        12 => ['total' => 70, 'rainyDays' => 10, 'snowDays' => 5],
    ];

    /** Diurnal blend 0 = daily min, 1 = daily max (peak ~13–14 h). */
    private const DIURNAL_BLEND = [
        0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.05, 5 => 0.12, 6 => 0.28,
        7 => 0.48, 8 => 0.68, 9 => 0.82, 10 => 0.92, 11 => 0.97, 12 => 1.0,
        13 => 1.0, 14 => 1.0, 15 => 0.97, 16 => 0.9, 17 => 0.78, 18 => 0.62,
        19 => 0.45, 20 => 0.3, 21 => 0.18, 22 => 0.08, 23 => 0.02,
    ];

    public const SEASONAL_WIND = [
        'winter' => ['avg' => 12, 'max' => 45, 'direction' => 270],
        'spring' => ['avg' => 15, 'max' => 55, 'direction' => 225],
        'summer' => ['avg' => 10, 'max' => 40, 'direction' => 180],
        'autumn' => ['avg' => 12, 'max' => 50, 'direction' => 270],
    ];

    public const SEASONAL_HUMIDITY = [
        'winter' => ['avg' => 80, 'min' => 60, 'max' => 95],
        'spring' => ['avg' => 65, 'min' => 45, 'max' => 85],
        'summer' => ['avg' => 60, 'min' => 40, 'max' => 80],
        'autumn' => ['avg' => 75, 'min' => 55, 'max' => 90],
    ];

    public const MONTHLY_UV_INDEX = [
        1 => 1, 2 => 2, 3 => 3, 4 => 5, 5 => 6, 6 => 7,
        7 => 8, 8 => 7, 9 => 5, 10 => 3, 11 => 2, 12 => 1,
    ];

    public const BASE_PRESSURE = 1013.25;

    public static function getSeason(int $month): string
    {
        if ($month >= 3 && $month <= 5) return 'spring';
        if ($month >= 6 && $month <= 8) return 'summer';
        if ($month >= 9 && $month <= 11) return 'autumn';
        return 'winter';
    }

    public static function getElevationTemperatureAdjustment(float $elevation, float $baseElevation = 450): float
    {
        return -0.6 * (($elevation - $baseElevation) / 100);
    }

    public static function getElevationPressureAdjustment(float $elevation, float $baseElevation = 450): float
    {
        return -12 * (($elevation - $baseElevation) / 100);
    }

    public static function getExpectedTemperature(\DateTimeInterface $date, int $hour, float $elevation = 450): float
    {
        $month = (int) $date->format('n');
        $monthData = self::MONTHLY_TEMPERATURES[$month];
        $elevationAdjustment = self::getElevationTemperatureAdjustment($elevation);
        $blend = self::DIURNAL_BLEND[$hour] ?? 0.5;
        $dailyMin = $monthData['min'] + $elevationAdjustment;
        $dailyMax = $monthData['max'] + $elevationAdjustment;

        return $dailyMin + ($dailyMax - $dailyMin) * $blend;
    }

    public static function getTemperatureRange(int $month, float $elevation = 450): array
    {
        $monthData = self::MONTHLY_TEMPERATURES[$month];
        $elevationAdjustment = self::getElevationTemperatureAdjustment($elevation);
        $slack = ($month >= 4 && $month <= 9) ? 0.5 : 2.0;

        return [
            'min' => $monthData['min'] + $elevationAdjustment - $slack,
            'max' => $monthData['max'] + $elevationAdjustment + $slack,
            'avg' => $monthData['avg'] + $elevationAdjustment,
        ];
    }

    public static function clampToTemperatureRange(
        int $month,
        float $elevation,
        float $tempHigh,
        float $tempLow
    ): array {
        $range = self::getTemperatureRange($month, $elevation);
        $high = max($range['min'], min($range['max'], $tempHigh));
        $low = max($range['min'], min($range['max'], $tempLow));

        if ($low > $high) {
            $low = min($low, $high);
        }

        return ['high' => round($high, 1), 'low' => round($low, 1)];
    }

    public static function getPrecipitationProbability(int $month): float
    {
        $monthData = self::MONTHLY_PRECIPITATION[$month];
        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1));
        return ($monthData['rainyDays'] + $monthData['snowDays']) / $daysInMonth;
    }

    public static function getExpectedHumidity(int $month, bool $isRaining = false): float
    {
        $season = self::getSeason($month);
        $seasonData = self::SEASONAL_HUMIDITY[$season];
        
        if ($isRaining) {
            return min(95, $seasonData['avg'] + 20);
        }
        return $seasonData['avg'];
    }

    public static function getExpectedWind(int $month): array
    {
        $season = self::getSeason($month);
        return self::SEASONAL_WIND[$season];
    }

    public static function getExpectedUVIndex(int $month, float $cloudCover = 0, int $hour = 12): float
    {
        $baseUV = self::MONTHLY_UV_INDEX[$month];
        
        $hourFactor = 1;
        if ($hour < 8 || $hour > 18) {
            $hourFactor = 0.1;
        } elseif ($hour < 10 || $hour > 16) {
            $hourFactor = 0.5;
        } elseif ($hour < 11 || $hour > 15) {
            $hourFactor = 0.8;
        }
        
        $cloudFactor = 1 - ($cloudCover / 100) * 0.7;
        return round($baseUV * $hourFactor * $cloudFactor, 1);
    }

    public static function getPrecipitationType(float $temperature): string
    {
        if ($temperature <= -2) return 'snow';
        if ($temperature <= 1) return mt_rand(0, 1) ? 'snow' : 'sleet';
        if ($temperature <= 3) return mt_rand(0, 100) > 70 ? 'sleet' : 'rain';
        return 'rain';
    }
}
