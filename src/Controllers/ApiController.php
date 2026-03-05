<?php

namespace Controllers;

use Models\Region;
use Models\WeatherStation;
use Models\WeatherData;
use Models\Forecast;
use Engine\WeatherSimulator;
use Engine\ForecastGenerator;
use Engine\WarningGenerator;
use Engine\SynopticEngine;

class ApiController
{
    private WeatherSimulator $weatherSimulator;
    private ForecastGenerator $forecastGenerator;
    private WarningGenerator $warningGenerator;

    public function __construct()
    {
        $this->weatherSimulator = new WeatherSimulator();
        $this->forecastGenerator = new ForecastGenerator();
        $this->warningGenerator = new WarningGenerator();
    }

    public function regions(): void
    {
        $this->json([
            'success' => true,
            'count' => count(Region::getAll()),
            'data' => Region::getAll(),
        ]);
    }

    public function region(int $id): void
    {
        $region = Region::getById($id);
        if (!$region) {
            $this->json(['success' => false, 'error' => 'Region not found'], 404);
            return;
        }
        
        $this->json([
            'success' => true,
            'data' => array_merge($region, [
                'stations' => Region::getStations($id),
                'features' => Region::getFeatures($id),
            ]),
        ]);
    }

    public function stations(): void
    {
        $this->json([
            'success' => true,
            'count' => count(WeatherStation::getAll()),
            'data' => WeatherStation::getAll(),
        ]);
    }

    public function stationsWithWeather(): void
    {
        $stations = WeatherStation::getAllWithCurrentWeather();
        $this->json([
            'success' => true,
            'count' => count($stations),
            'data' => $stations,
        ]);
    }

    public function station(int $id): void
    {
        $station = WeatherStation::getById($id);
        if (!$station) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }
        $this->json(['success' => true, 'data' => $station]);
    }

    public function stationWeather(int $id): void
    {
        $station = WeatherStation::getById($id);
        if (!$station) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }
        
        $this->json([
            'success' => true,
            'data' => [
                'station' => $station,
                'weather' => WeatherData::getLatest($id),
            ],
        ]);
    }

    public function stationHistory(int $id): void
    {
        $station = WeatherStation::getById($id);
        if (!$station) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }
        
        $hours = (int) ($_GET['hours'] ?? 24);
        $history = WeatherData::getHistory($id, $hours);
        
        $this->json([
            'success' => true,
            'station' => $station['name'],
            'hours' => $hours,
            'count' => count($history),
            'data' => $history,
        ]);
    }

    public function stationDayHours(int $id): void
    {
        $station = WeatherStation::getById($id);
        if (!$station) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $data = WeatherData::getDayHours($id, $date);
        
        $this->json([
            'success' => true,
            'station' => $station['name'],
            'date' => $date,
            'count' => count($data),
            'data' => $data,
        ]);
    }

    public function currentWeather(): void
    {
        $weather = WeatherData::getCurrentAll(date('Y-m-d H:i:s'));
        $this->json([
            'success' => true,
            'timestamp' => date('c'),
            'count' => count($weather),
            'data' => $weather,
        ]);
    }

    public function synopticRegime(): void
    {
        $dateStr = $_GET['date'] ?? date('Y-m-d');
        try {
            $date = new \DateTimeImmutable($dateStr);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'error' => 'Invalid date'], 400);
            return;
        }

        $engine = new SynopticEngine();
        $regime = $engine->getRegimeForDate($date);
        $label = $engine->getRegimeLabel($date);

        $this->json([
            'success' => true,
            'date' => $dateStr,
            'regime' => $regime['regime_type'],
            'label' => $label,
            'front_active' => (bool) $regime['front_active'],
            'front_type' => $regime['front_type'],
            'wind_direction' => (float) $regime['wind_direction'],
            'wind_speed' => (float) $regime['wind_speed'],
            'pressure_anomaly' => (float) $regime['pressure_anomaly'],
            'pressure_center_x' => (float) ($regime['pressure_center_x'] ?? 1746),
            'pressure_center_y' => (float) ($regime['pressure_center_y'] ?? 1101),
            'pressure_sigma' => (float) ($regime['pressure_sigma'] ?? 7000),
            'front_start_x' => (float) ($regime['front_start_x'] ?? 0),
            'front_start_y' => (float) ($regime['front_start_y'] ?? 0),
            'front_speed_x' => (float) ($regime['front_speed_x'] ?? 0),
            'front_speed_y' => (float) ($regime['front_speed_y'] ?? 0),
        ]);
    }

    public function generateWeather(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $timestamp = isset($input['timestamp']) 
            ? new \DateTimeImmutable($input['timestamp']) 
            : new \DateTimeImmutable();
        
        $results = $this->weatherSimulator->generateWeatherForAll($timestamp);
        $this->forecastGenerator->generateForecasts();
        
        $this->json([
            'success' => true,
            'message' => 'Weather generated successfully',
            'timestamp' => $timestamp->format('c'),
            'generated' => count($results),
            'data' => $results,
        ]);
    }

    public function generate7Days(): void
    {
        $results = $this->generateWeatherForNext7Days();
        $this->forecastGenerator->generateForecasts();
        
        $this->json([
            'success' => true,
            'message' => '7-day hourly weather generated successfully',
            'data' => $results,
        ]);
    }

    public function resimulateDay(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        
        $stationId = (int) ($input['stationId'] ?? 0);
        $date = $input['date'] ?? '';
        
        if (!$stationId) {
            $this->json(['success' => false, 'error' => 'stationId is required'], 400);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->json(['success' => false, 'error' => 'date is required (YYYY-MM-DD)'], 400);
            return;
        }
        
        $station = WeatherStation::getById($stationId);
        if (!$station) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }
        
        $result = $this->weatherSimulator->resimulateDayForStation($stationId, $date);
        
        $this->json([
            'success' => true,
            'message' => 'Day re-simulated successfully',
            'station' => $station['name'],
            'data' => $result,
        ]);
    }

    public function forecast(int $stationId): void
    {
        $station = WeatherStation::getById($stationId);
        if (!$station) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }
        
        $days = (int) ($_GET['days'] ?? 7);
        $forecast = $this->forecastGenerator->getForecast($stationId, $days);
        
        $this->json([
            'success' => true,
            'station' => $station['name'],
            'days' => count($forecast),
            'data' => $forecast,
        ]);
    }

    public function generateForecasts(): void
    {
        $results = $this->forecastGenerator->generateForecasts();
        
        $this->json([
            'success' => true,
            'message' => 'Forecasts generated successfully',
            'stations' => count($results),
            'data' => $results,
        ]);
    }

    public function mapConfig(): void
    {
        $config = require __DIR__ . '/../../config/config.php';
        
        $this->json([
            'success' => true,
            'mapImage' => 'maps/map.png',
            'dimensions' => $config['map']['dimensions'],
            'minZoom' => $config['map']['minZoom'],
            'maxZoom' => $config['map']['maxZoom'],
        ]);
    }

    public function health(): void
    {
        $this->json([
            'success' => true,
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => '2.0.0',
        ]);
    }

    public function warnings(): void
    {
        $warnings = $this->warningGenerator->getWarningsJSON();
        $this->json([
            'success' => true,
            'count' => count($warnings),
            'data' => $warnings,
        ]);
    }

    public function warningsRSS(): void
    {
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo $this->warningGenerator->generateRSS();
    }

    public function stationWeatherByDay(int $id): void
    {
        $station = WeatherStation::getById($id);
        if (!$station) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $data = WeatherData::getDayHours($id, $date);
        
        $this->json([
            'success' => true,
            'station' => $station,
            'date' => $date,
            'count' => count($data),
            'data' => $data,
        ]);
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

    private function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
