<?php

namespace Scheduler;

use Models\WeatherStation;
use Models\WeatherData;
use Engine\WeatherSimulator;
use Engine\ForecastGenerator;

class WeatherCron
{
    private WeatherSimulator $weatherSimulator;
    private ForecastGenerator $forecastGenerator;

    public function __construct()
    {
        $this->weatherSimulator = new WeatherSimulator();
        $this->forecastGenerator = new ForecastGenerator();
    }

    public function run(): array
    {
        $startTime = microtime(true);
        
        $weatherResults = $this->generateWeatherForNext7Days();
        
        $forecastResults = $this->forecastGenerator->generateForecasts();
        
        $duration = round((microtime(true) - $startTime) * 1000);
        
        return [
            'success' => true,
            'weather' => $weatherResults,
            'forecasts' => count($forecastResults),
            'duration_ms' => $duration,
        ];
    }

    private function generateWeatherForNext7Days(): array
    {
        $stations = WeatherStation::getAll();
        $totalHours = 168;
        
        $now = new \DateTimeImmutable();
        $dayStart = $now->setTime(0, 0, 0);
        
        $totalGenerated = 0;
        $totalSkipped = 0;
        
        for ($hourOffset = 0; $hourOffset < $totalHours; $hourOffset++) {
            $timestamp = $dayStart->modify("+{$hourOffset} hours");
            $isFuture = $timestamp > $now;
            $timestampStr = $timestamp->format('Y-m-d H:i:s');
            $stationsToGenerate = [];
            $lastWeatherByStation = [];
            
            foreach ($stations as $station) {
                if ($isFuture) {
                    $exists = WeatherData::existsForTimestamp($station['id'], $timestampStr);
                    if ($exists) {
                        WeatherData::deleteByDateRange(
                            $station['id'],
                            $timestamp->format('Y-m-d H:00:00'),
                            $timestamp->format('Y-m-d H:59:59')
                        );
                    }
                } else {
                    $exists = WeatherData::existsForTimestamp($station['id'], $timestampStr);
                    if ($exists) {
                        $totalSkipped++;
                        continue;
                    }
                }

                $stationsToGenerate[] = $station;
                $lastWeatherByStation[(int) $station['id']] = WeatherData::getLastBefore((int) $station['id'], $timestampStr);
            }

            if ($stationsToGenerate) {
                $generatedRows = $this->weatherSimulator->generateWeatherForBatch(
                    $stationsToGenerate,
                    $timestamp,
                    $lastWeatherByStation,
                    $stations
                );
                $totalGenerated += count($generatedRows);
            }
        }
        
        return [
            'stations' => count($stations),
            'totalHours' => $totalHours,
            'generated' => $totalGenerated,
            'skipped' => $totalSkipped,
        ];
    }
}
