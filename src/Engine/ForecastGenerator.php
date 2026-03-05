<?php

namespace Engine;

use Models\WeatherStation;
use Models\Forecast;
use Models\WeatherData;

class ForecastGenerator
{
    private const DRY_STATES = ['clear', 'sunny', 'partly_cloudy'];
    private const WET_STATES = ['light_rain', 'moderate_rain', 'heavy_rain', 'snow'];

    public function generateForecasts(): array
    {
        $stations = WeatherStation::getAll();
        $results = [];
        
        foreach ($stations as $station) {
            $forecasts = $this->generateForecastForStation($station);
            $results[] = ['station' => $station['name'], 'forecasts' => count($forecasts)];
        }
        
        return $results;
    }

    public function generateForecastForStation(array $station): array
    {
        $stationDetails = WeatherStation::getById($station['id']);
        $today = new \DateTimeImmutable('today');
        
        $forecasts = [];
        
        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $forecastDate = $today->modify("+{$dayOffset} days");
            $dateStr = $forecastDate->format('Y-m-d');
            
            $existing = Forecast::exists($station['id'], $dateStr);
            
            if (!$existing || $dayOffset <= 1) {
                $forecast = $this->generateDayForecast($stationDetails, $forecastDate, $dayOffset);
                
                if ($existing) {
                    Forecast::update($station['id'], $dateStr, $forecast);
                } else {
                    Forecast::create($forecast);
                }
                
                $forecasts[] = array_merge(['date' => $dateStr], $forecast);
            }
        }
        
        $cutoffDate = $today->modify('-7 days')->format('Y-m-d');
        Forecast::deleteOld($station['id'], $cutoffDate);
        
        return $forecasts;
    }

    private function generateDayForecast(array $station, \DateTimeInterface $date, int $dayOffset): array
    {
        $hourlyForecast = $this->buildForecastFromHourlyData($station, $date, $dayOffset);
        if ($hourlyForecast !== null) {
            return $hourlyForecast;
        }

        $month = (int) $date->format('n');
        $monthData = ClimateData::MONTHLY_TEMPERATURES[$month];
        $precipData = ClimateData::MONTHLY_PRECIPITATION[$month];
        
        $confidence = max(30, 100 - $dayOffset * 10);
        $randomFactor = 1 + ($dayOffset * 0.15);
        
        $elevationAdjust = ClimateData::getElevationTemperatureAdjustment($station['elevation'] ?? 450);
        $tempModifier = $this->getEffectiveTemperatureModifier($station);
        
        $baseHigh = $monthData['max'] + $elevationAdjust + $tempModifier;
        $baseLow = $monthData['min'] + $elevationAdjust + $tempModifier;
        
        $randomHigh = (mt_rand() / mt_getrandmax() - 0.5) * 4 * $randomFactor;
        $randomLow = (mt_rand() / mt_getrandmax() - 0.5) * 4 * $randomFactor;
        
        $tempHigh = round(($baseHigh + $randomHigh) * 10) / 10;
        $tempLow = round(($baseLow + $randomLow) * 10) / 10;
        
        $daysInMonth = (int) $date->format('t');
        $basePrecipProb = (($precipData['rainyDays'] + $precipData['snowDays']) / $daysInMonth) * 100;
        
        $daySeed = (int) ($date->getTimestamp() / 86400) + $station['id'];
        $dailyRandom = $this->seededRandom($daySeed);
        
        $precipitationProbability = round(
            $basePrecipProb * $this->getEffectivePrecipitationModifier($station) * (0.8 + $dailyRandom * 0.4)
        );
        $precipitationProbability = max(0, min(100, $precipitationProbability));
        
        $weatherState = $this->determineWeatherState(
            $precipitationProbability,
            $tempHigh,
            $this->seededRandom($daySeed + 1000)
        );
        
        $precipitationAmount = 0;
        if (in_array($weatherState, self::WET_STATES)) {
            $avgDailyPrecip = $precipData['total'] / $precipData['rainyDays'];
            $precipitationAmount = round($avgDailyPrecip * (0.5 + $this->seededRandom($daySeed + 1000)) * 10) / 10;
        }
        
        $season = ClimateData::getSeason($month);
        $seasonHumidity = ClimateData::SEASONAL_HUMIDITY[$season];
        $humidityAvg = $seasonHumidity['avg'];
        
        if (in_array($weatherState, self::WET_STATES)) {
            $humidityAvg += 15;
        } elseif (in_array($weatherState, self::DRY_STATES)) {
            $humidityAvg -= 10;
        }
        $humidityAvg = max(30, min(95, (int) round($humidityAvg)));
        
        $seasonWind = ClimateData::SEASONAL_WIND[$season];
        $stateRandom = $this->seededRandom($daySeed + 1000);
        $windSpeedAvg = $seasonWind['avg'] * $this->getEffectiveWindExposure($station);
        $windSpeedAvg = round($windSpeedAvg * (0.7 + $stateRandom * 0.6) * 10) / 10;
        
        $cloudCoverAvg = $this->determineCloudCover($weatherState, $stateRandom);
        
        return [
            'station_id' => $station['id'],
            'forecast_date' => $date->format('Y-m-d'),
            'generated_at' => date('Y-m-d H:i:s'),
            'temp_high' => $tempHigh,
            'temp_low' => $tempLow,
            'weather_state' => $weatherState,
            'precipitation_probability' => $precipitationProbability,
            'precipitation_amount' => $precipitationAmount,
            'humidity_avg' => $humidityAvg,
            'wind_speed_avg' => $windSpeedAvg,
            'cloud_cover_avg' => $cloudCoverAvg,
            'confidence' => $confidence,
        ];
    }

    private function buildForecastFromHourlyData(array $station, \DateTimeInterface $date, int $dayOffset): ?array
    {
        $dateStr = $date->format('Y-m-d');
        $hours = WeatherData::getDayHours((int) $station['id'], $dateStr);

        if (count($hours) < 12) {
            return null;
        }

        $temps = array_map(static fn(array $h): float => (float) ($h['temperature'] ?? 0.0), $hours);
        $humidities = array_map(static fn(array $h): float => (float) ($h['humidity'] ?? 0.0), $hours);
        $windSpeeds = array_map(static fn(array $h): float => (float) ($h['wind_speed'] ?? 0.0), $hours);
        $clouds = array_map(static fn(array $h): float => (float) ($h['cloud_cover'] ?? 0.0), $hours);
        $precips = array_map(static fn(array $h): float => (float) ($h['precipitation'] ?? 0.0), $hours);

        $weatherCounts = [];
        foreach ($hours as $h) {
            $state = (string) ($h['weather_state'] ?? 'partly_cloudy');
            $weatherCounts[$state] = ($weatherCounts[$state] ?? 0) + 1;
        }

        arsort($weatherCounts);
        $dominantState = (string) array_key_first($weatherCounts);

        $precipHours = count(array_filter($precips, static fn(float $p): bool => $p > 0.05));
        $precipAmount = array_sum($precips);
        $precipProbability = (int) round(($precipHours / max(1, count($hours))) * 100);

        $confidence = max(
            40,
            (int) round(100 - ($dayOffset * 8) - max(0, 24 - count($hours)) * 1.2)
        );

        return [
            'station_id' => $station['id'],
            'forecast_date' => $dateStr,
            'generated_at' => date('Y-m-d H:i:s'),
            'temp_high' => round(max($temps), 1),
            'temp_low' => round(min($temps), 1),
            'weather_state' => $dominantState,
            'precipitation_probability' => max(0, min(100, $precipProbability)),
            'precipitation_amount' => round($precipAmount, 1),
            'humidity_avg' => (int) round(array_sum($humidities) / max(1, count($humidities))),
            'wind_speed_avg' => round(array_sum($windSpeeds) / max(1, count($windSpeeds)), 1),
            'cloud_cover_avg' => (int) round(array_sum($clouds) / max(1, count($clouds))),
            'confidence' => $confidence,
        ];
    }

    private function determineWeatherState(float $precipProb, float $tempHigh, float $stateRandom): string
    {
        if ($precipProb > 60) {
            if ($tempHigh < 2) {
                return 'snow';
            } elseif ($stateRandom > 0.7) {
                return 'heavy_rain';
            } elseif ($stateRandom > 0.3) {
                return 'moderate_rain';
            } else {
                return 'light_rain';
            }
        } elseif ($precipProb > 30) {
            return $stateRandom > 0.5 ? 'cloudy' : 'light_rain';
        } elseif ($precipProb > 15) {
            return $stateRandom > 0.5 ? 'partly_cloudy' : 'cloudy';
        } else {
            return $stateRandom > 0.3 ? 'sunny' : 'partly_cloudy';
        }
    }

    private function determineCloudCover(string $weatherState, float $stateRandom): int
    {
        return match ($weatherState) {
            'sunny', 'clear' => (int) round(10 + $stateRandom * 15),
            'partly_cloudy' => (int) round(30 + $stateRandom * 25),
            'cloudy' => (int) round(70 + $stateRandom * 20),
            default => (int) round(80 + $stateRandom * 20),
        };
    }

    public function getForecast(int $stationId, int $days = 7): array
    {
        return Forecast::getByStation($stationId, $days);
    }

    private function seededRandom(int $seed): float
    {
        $x = sin($seed) * 10000;
        return $x - floor($x);
    }

    private function getEffectiveTemperatureModifier(array $station): float
    {
        return (float) ($station['temperature_modifier'] ?? $station['region_temp_mod'] ?? 0.0);
    }

    private function getEffectivePrecipitationModifier(array $station): float
    {
        return (float) ($station['precipitation_modifier'] ?? $station['region_precip_mod'] ?? 1.0);
    }

    private function getEffectiveWindExposure(array $station): float
    {
        return (float) ($station['wind_exposure'] ?? $station['region_wind_exp'] ?? 1.0);
    }
}
