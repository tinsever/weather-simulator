<?php

namespace Engine;

use Models\WeatherData;
use Models\WeatherStation;

class WeatherSimulator
{
    private const WEATHER_STATES = [
        'sunny' => ['next' => ['sunny', 'partly_cloudy'], 'probability' => [0.7, 0.3]],
        'clear' => ['next' => ['clear', 'partly_cloudy'], 'probability' => [0.7, 0.3]],
        'partly_cloudy' => ['next' => ['sunny', 'partly_cloudy', 'cloudy'], 'probability' => [0.2, 0.5, 0.3]],
        'cloudy' => ['next' => ['partly_cloudy', 'cloudy', 'light_rain', 'fog'], 'probability' => [0.15, 0.45, 0.35, 0.05]],
        'light_rain' => ['next' => ['cloudy', 'light_rain', 'moderate_rain', 'clearing'], 'probability' => [0.1, 0.4, 0.35, 0.15]],
        'moderate_rain' => ['next' => ['light_rain', 'moderate_rain', 'heavy_rain', 'clearing'], 'probability' => [0.2, 0.4, 0.25, 0.15]],
        'heavy_rain' => ['next' => ['moderate_rain', 'heavy_rain', 'clearing'], 'probability' => [0.35, 0.4, 0.25]],
        'clearing' => ['next' => ['cloudy', 'partly_cloudy'], 'probability' => [0.4, 0.6]],
        'snow' => ['next' => ['snow', 'light_rain', 'cloudy'], 'probability' => [0.5, 0.3, 0.2]],
        'fog' => ['next' => ['fog', 'cloudy', 'partly_cloudy'], 'probability' => [0.4, 0.4, 0.2]],
    ];

    private const STATE_CLOUD_COVER = [
        'sunny' => ['min' => 0, 'max' => 15],
        'clear' => ['min' => 0, 'max' => 15],
        'partly_cloudy' => ['min' => 20, 'max' => 50],
        'cloudy' => ['min' => 60, 'max' => 85],
        'light_rain' => ['min' => 70, 'max' => 90],
        'moderate_rain' => ['min' => 80, 'max' => 95],
        'heavy_rain' => ['min' => 90, 'max' => 100],
        'clearing' => ['min' => 40, 'max' => 70],
        'snow' => ['min' => 80, 'max' => 100],
        'fog' => ['min' => 90, 'max' => 100],
    ];

    private const STATE_VISIBILITY = [
        'sunny' => ['min' => 15, 'max' => 30],
        'clear' => ['min' => 15, 'max' => 30],
        'partly_cloudy' => ['min' => 12, 'max' => 25],
        'cloudy' => ['min' => 8, 'max' => 15],
        'light_rain' => ['min' => 5, 'max' => 10],
        'moderate_rain' => ['min' => 3, 'max' => 7],
        'heavy_rain' => ['min' => 1, 'max' => 4],
        'clearing' => ['min' => 8, 'max' => 15],
        'snow' => ['min' => 1, 'max' => 5],
        'fog' => ['min' => 0.1, 'max' => 1],
    ];

    private const MAX_TEMP_CHANGE_NORMAL = 0.5;
    private const MAX_TEMP_CHANGE_TRANSITION = 0.8;
    private const SMOOTHING_FACTOR = 0.6;
    private const WEATHER_STATE_TRANSITION_HOURS = 4;

    private ?SynopticEngine $synopticEngine = null;

    public function generateWeatherForAll(\DateTimeInterface $timestamp): array
    {
        $this->synopticEngine = $this->synopticEngine ?? new SynopticEngine();
        $stations = WeatherStation::getAll();
        return $this->generateWeatherForBatch($stations, $timestamp, [], $stations);
    }

    public function generateWeatherForBatch(
        array $stations,
        \DateTimeInterface $timestamp,
        array $lastWeatherByStation = [],
        ?array $allStationsForSpatial = null
    ): array {
        $this->synopticEngine = $this->synopticEngine ?? new SynopticEngine();
        $results = [];
        if (!$stations) {
            return $results;
        }

        $timestampStr = $timestamp->format('Y-m-d H:i:s');
        $recentWeather = WeatherData::getCurrentAll($timestampStr);
        $recentByStation = [];
        foreach ($recentWeather as $row) {
            $recentByStation[(int) $row['station_id']] = $row;
        }

        $allStations = $allStationsForSpatial ?? WeatherStation::getAll();

        $stationDetailsMap = [];
        foreach ($stations as $station) {
            $stationDetailsMap[(int) $station['id']] = WeatherStation::getById((int) $station['id']) ?? $station;
        }

        $neighborIndex = $this->buildNeighborIndex($allStations, 4);
        
        foreach ($stations as $station) {
            $stationId = (int) $station['id'];
            $stationDetails = $stationDetailsMap[$stationId] ?? $station;
            $lastWeather = $lastWeatherByStation[$stationId] ?? $recentByStation[$stationId] ?? WeatherData::getLastBefore($stationId, $timestampStr);

            $neighbors = [];
            foreach ($neighborIndex[$stationId] ?? [] as $neighborMeta) {
                $neighborId = (int) $neighborMeta['id'];
                if (!isset($recentByStation[$neighborId])) {
                    continue;
                }

                $neighbors[] = [
                    'distance' => $neighborMeta['distance'],
                    'bearing_to_station' => $neighborMeta['bearing_to_station'],
                    'weather' => $recentByStation[$neighborId],
                ];
            }

            $weather = $this->generateWeatherForStation(
                $station,
                $timestamp,
                $lastWeather,
                $stationDetails,
                $neighbors
            );
            $results[] = $weather;
        }
        
        return $results;
    }

    public function generateWeatherForStation(
        array $station,
        \DateTimeInterface $timestamp,
        ?array $lastWeatherOverride = null,
        ?array $stationDetailsOverride = null,
        array $spatialNeighbors = []
    ): array
    {
        $stationDetails = $stationDetailsOverride ?? WeatherStation::getById($station['id']);
        $lastWeather = $lastWeatherOverride ?? WeatherData::getLastBefore($station['id'], $timestamp->format('Y-m-d H:i:s'));
        
        return $this->generateAndPersist($station['id'], $stationDetails, $timestamp, $lastWeather, 'automatic_transition', $spatialNeighbors);
    }

    public function resimulateDayForStation(int $stationId, string $dateStr): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            throw new \InvalidArgumentException('Date must be in format YYYY-MM-DD');
        }
        
        $stationDetails = WeatherStation::getById($stationId);
        if (!$stationDetails) {
            throw new \RuntimeException('Station not found');
        }
        
        $dayStart = new \DateTimeImmutable($dateStr . ' 00:00:00');
        $dayEnd = new \DateTimeImmutable($dateStr . ' 23:59:59');
        
        $deleted = WeatherData::deleteByDateRange(
            $stationId,
            $dayStart->format('Y-m-d H:i:s'),
            $dayEnd->format('Y-m-d H:i:s')
        );
        
        $generated = [];
        for ($h = 0; $h < 24; $h++) {
            $ts = $dayStart->modify("+{$h} hours");
            $lastWeather = WeatherData::getLastBefore($stationId, $ts->format('Y-m-d H:i:s'));
            $record = $this->generateAndPersist($stationId, $stationDetails, $ts, $lastWeather, 'resimulate_day');
            $generated[] = $record;
        }
        
        return [
            'station_id' => $stationId,
            'date' => $dateStr,
            'deleted' => $deleted,
            'generated' => count($generated),
        ];
    }

    private function generateAndPersist(
        int $stationId,
        array $stationDetails,
        \DateTimeInterface $timestamp,
        ?array $lastWeather,
        string $transitionReason = 'automatic_transition',
        array $spatialNeighbors = []
    ): array {
        // Get synoptic context (Großwetterlage)
        $synoptic = $this->synopticEngine
            ? $this->synopticEngine->getStationContext($timestamp, $stationDetails)
            : null;

        $weatherState = $this->generateWeatherState($lastWeather, $timestamp, $stationDetails, $synoptic);
        $temperature = $this->generateTemperature($lastWeather, $timestamp, $stationDetails, $weatherState, $synoptic);
        $precipitation = $this->generatePrecipitation($weatherState, $timestamp, $stationDetails, $synoptic);
        $humidity = $this->generateHumidity($weatherState, $timestamp, $stationDetails, $precipitation);
        $wind = $this->generateWind($timestamp, $stationDetails, $weatherState, $synoptic);
        $pressure = $this->generatePressure($stationDetails, $weatherState, $synoptic);
        $cloudCover = $this->generateCloudCover($weatherState, $synoptic);
        $visibility = $this->generateVisibility($weatherState, $precipitation);
        $normalized = $this->normalizeMeteorology(
            $weatherState,
            $temperature,
            $precipitation,
            $humidity,
            $pressure,
            $cloudCover,
            $visibility
        );

        $weatherState = $normalized['weather_state'];
        $precipitation = $normalized['precipitation'];
        $humidity = $normalized['humidity'];
        $pressure = $normalized['pressure'];
        $cloudCover = $normalized['cloud_cover'];
        $visibility = $normalized['visibility'];

        $coupled = $this->applySpatialCoupling(
            $weatherState,
            $temperature,
            $precipitation,
            $humidity,
            $pressure,
            $cloudCover,
            $visibility,
            $wind,
            $spatialNeighbors
        );

        $weatherState = $coupled['weather_state'];
        $temperature = $coupled['temperature'];
        $precipitation = $coupled['precipitation'];
        $humidity = $coupled['humidity'];
        $pressure = $coupled['pressure'];
        $cloudCover = $coupled['cloud_cover'];
        $visibility = $coupled['visibility'];

        $uvIndex = $this->generateUVIndex($timestamp, $cloudCover, $stationDetails);
        $feelsLike = $this->calculateFeelsLike($temperature, $humidity, $wind['speed']);
        
        $weatherRecord = [
            'station_id' => $stationId,
            'timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'temperature' => round($temperature, 1),
            'temperature_feels_like' => round($feelsLike, 1),
            'precipitation' => round($precipitation, 1),
            'precipitation_type' => $precipitation > 0 ? ClimateData::getPrecipitationType($temperature) : 'none',
            'humidity' => (int) round($humidity),
            'wind_speed' => round($wind['speed'], 1),
            'wind_direction' => $wind['direction'],
            'wind_gusts' => round($wind['gusts'], 1),
            'pressure' => round($pressure, 1),
            'cloud_cover' => (int) round($cloudCover),
            'visibility' => round($visibility, 1),
            'uv_index' => round($uvIndex, 1),
            'weather_state' => $weatherState,
            'is_generated' => 1,
        ];
        
        WeatherData::create($weatherRecord);
        
        if ($lastWeather && ($lastWeather['weather_state'] ?? '') !== $weatherState) {
            WeatherData::recordStateTransition(
                $stationId,
                $lastWeather['weather_state'],
                $weatherState,
                $transitionReason
            );
        }
        
        return $weatherRecord;
    }

    private function generateWeatherState(?array $lastWeather, \DateTimeInterface $timestamp, array $station, ?array $synoptic = null): string
    {
        $month = (int) $timestamp->format('n');
        $hour = (int) $timestamp->format('G');
        $night = $this->isNighttime($hour);
        
        $precipProb = ClimateData::getPrecipitationProbability($month);
        $daySeed = (int) ($timestamp->getTimestamp() / 86400);
        $dailyRandom = $this->seededRandom($daySeed + $station['id']);
        $isDryDay = $dailyRandom > $precipProb * 1.2;

        // Synoptic override: the Großwetterlage determines dry/wet
        if ($synoptic) {
            $bias = $synoptic['state_bias'];
            if ($bias === 'dry')     { $isDryDay = true; }
            elseif ($bias === 'wet') { $isDryDay = false; }
            elseif ($bias === 'clearing') { $isDryDay = true; }
        }
        
        $lastState = $lastWeather['weather_state'] ?? ($night ? 'clear' : 'sunny');
        
        if ($lastState === 'sunny' && $night) $lastState = 'clear';
        if ($lastState === 'clear' && !$night) $lastState = 'sunny';
        
        if ($isDryDay && in_array($lastState, ['light_rain', 'moderate_rain', 'heavy_rain'])) {
            return 'clearing';
        }
        
        $expectedTemp = ClimateData::getExpectedTemperature($timestamp, $hour, $station['elevation'] ?? 450);
        if ($expectedTemp < 0 && in_array($lastState, ['light_rain', 'moderate_rain', 'heavy_rain'])) {
            return 'snow';
        }
        
        if ($hour >= 5 && $hour <= 9 && ($station['topography'] ?? '') === 'valley') {
            if (mt_rand(0, 100) < 10 && $lastState === 'cloudy') {
                return 'fog';
            }
        }

        // Synoptic front forcing: high precip_factor → force rain transition
        if ($synoptic && ($synoptic['precip_factor'] ?? 0) > 1.5) {
            if (!in_array($lastState, ['heavy_rain', 'moderate_rain', 'light_rain', 'snow'])) {
                $forceProb = min(0.8, ($synoptic['precip_factor'] - 1.0) / 2.5);
                if (mt_rand() / mt_getrandmax() < $forceProb) {
                    $expectedTemp = ClimateData::getExpectedTemperature($timestamp, $hour, $station['elevation'] ?? 450);
                    if ($expectedTemp < 0) return 'snow';
                    return ($synoptic['precip_factor'] > 2.0) ? 'moderate_rain' : 'light_rain';
                }
            }
        }
        
        $stateConfig = self::WEATHER_STATES[$lastState] ?? self::WEATHER_STATES['sunny'];
        $roll = mt_rand() / mt_getrandmax();
        
        $cumulative = 0;
        for ($i = 0; $i < count($stateConfig['next']); $i++) {
            $cumulative += $stateConfig['probability'][$i];
            if ($roll < $cumulative) {
                $nextState = $stateConfig['next'][$i];
                
                if ($isDryDay && in_array($nextState, ['light_rain', 'moderate_rain', 'heavy_rain'])) {
                    $nextState = $stateConfig['next'][0];
                }
                
                if ($nextState === 'sunny' && $night) return 'clear';
                if ($nextState === 'clear' && !$night) return 'sunny';
                
                return $nextState;
            }
        }
        
        $finalState = $stateConfig['next'][count($stateConfig['next']) - 1];
        if ($finalState === 'sunny' && $night) return 'clear';
        if ($finalState === 'clear' && !$night) return 'sunny';
        return $finalState;
    }

    private function generateTemperature(?array $lastWeather, \DateTimeInterface $timestamp, array $station, string $weatherState, ?array $synoptic = null): float
    {
        $month = (int) $timestamp->format('n');
        $hour = (int) $timestamp->format('G');
        
        $targetTemp = ClimateData::getExpectedTemperature($timestamp, $hour, $station['elevation'] ?? 450);
        $targetTemp += $this->getEffectiveTemperatureModifier($station);
        $targetTemp += $this->getLandUsageTemperatureModifier($station['land_usage'] ?? '', $hour);
        $fullModifier = $this->getWeatherStateTemperatureModifier($weatherState);
        $targetTemp += $fullModifier;

        // Synoptic temperature advection (Föhn warmth, Bise cold, front air mass)
        $targetTemp += (float) ($synoptic['temp_advection'] ?? 0.0);
        
        $daySeed = (int) ($timestamp->getTimestamp() / 86400);
        $dailyOffset = ($this->seededRandom($daySeed + $station['id'] * 17) - 0.5) * 0.4;
        $hourlyNoise = (mt_rand() / mt_getrandmax() - 0.5) * 0.2;
        $targetTemp += $dailyOffset + $hourlyNoise;
        
        if (!$lastWeather) {
            $range = ClimateData::getTemperatureRange($month, $station['elevation'] ?? 450);
            return max($range['min'], min($range['max'], $targetTemp));
        }
        
        $lastTemp = $lastWeather['temperature'];
        $isTransitionPeriod = ($hour >= 5 && $hour <= 8) || ($hour >= 17 && $hour <= 20);
        $maxChange = $isTransitionPeriod ? self::MAX_TEMP_CHANGE_TRANSITION : self::MAX_TEMP_CHANGE_NORMAL;
        
        $desiredChange = $targetTemp - $lastTemp;
        $limitedChange = max(-$maxChange, min($maxChange, $desiredChange));
        $newTemp = $lastTemp + $limitedChange * self::SMOOTHING_FACTOR;
        
        $recentData = WeatherData::getPrevious($station['id'], 3);
        if (count($recentData) >= 2) {
            $temps = array_column(array_slice($recentData, 0, 3), 'temperature');
            $temps = array_filter($temps, fn($t) => $t !== null);
            if (count($temps) > 0) {
                $avgTemp = array_sum($temps) / count($temps);
                $momentumWeight = 0.15;
                $momentumTemp = $newTemp * (1 - $momentumWeight) + $avgTemp * $momentumWeight;
                
                $momentumChange = $momentumTemp - $lastTemp;
                if (abs($momentumChange) <= $maxChange) {
                    $newTemp = $momentumTemp;
                }
            }
        }
        
        $finalChange = $newTemp - $lastTemp;
        if (abs($finalChange) > $maxChange) {
            $newTemp = $lastTemp + ($finalChange > 0 ? 1 : -1) * $maxChange;
        }
        
        $range = ClimateData::getTemperatureRange($month, $station['elevation'] ?? 450);
        if ($newTemp < $range['min'] && $newTemp < $lastTemp) {
            $newTemp = $lastTemp + min($maxChange, 0.1);
        } elseif ($newTemp > $range['max'] && $newTemp > $lastTemp) {
            $newTemp = $lastTemp - min($maxChange, 0.1);
        }
        
        return $newTemp;
    }

    private function generatePrecipitation(string $weatherState, \DateTimeInterface $timestamp, array $station, ?array $synoptic = null): float
    {
        $precipStates = ['light_rain', 'moderate_rain', 'heavy_rain', 'snow'];
        if (!in_array($weatherState, $precipStates)) {
            return 0;
        }
        
        switch ($weatherState) {
            case 'light_rain':
            case 'snow':
                $basePrecip = 0.1 + mt_rand() / mt_getrandmax() * 2;
                break;
            case 'moderate_rain':
                $basePrecip = 1 + mt_rand() / mt_getrandmax() * 4;
                break;
            case 'heavy_rain':
                $basePrecip = 3 + mt_rand() / mt_getrandmax() * 8;
                break;
            default:
                return 0;
        }
        
        $basePrecip *= $this->getEffectivePrecipitationModifier($station);

        // Synoptic precip factor (fronts, Nordstau, etc.)
        $basePrecip *= (float) ($synoptic['precip_factor'] ?? 1.0);
        
        $topography = $station['topography'] ?? '';
        if ($topography === 'mountain' || $topography === 'peak') {
            $basePrecip *= 1.3;
        }
        
        if ($weatherState === 'heavy_rain' && mt_rand(0, 100) < 2) {
            $basePrecip *= 2;
        }
        
        return max(0, $basePrecip);
    }

    private function generateHumidity(string $weatherState, \DateTimeInterface $timestamp, array $station, float $precipitation): float
    {
        $month = (int) $timestamp->format('n');
        $isRaining = $precipitation > 0;
        
        $baseHumidity = ClimateData::getExpectedHumidity($month, $isRaining);
        
        switch ($weatherState) {
            case 'sunny':
                $baseHumidity -= 15;
                break;
            case 'partly_cloudy':
                $baseHumidity -= 5;
                break;
            case 'fog':
                $baseHumidity = 95 + mt_rand() / mt_getrandmax() * 5;
                break;
            case 'heavy_rain':
                $baseHumidity += 10;
                break;
        }
        
        $hydrology = $station['hydrology'] ?? '';
        if ($hydrology === 'lake_proximity' || $hydrology === 'river_proximity') {
            $baseHumidity += 5;
        } elseif ($hydrology === 'dry') {
            $baseHumidity -= 10;
        }
        
        $baseHumidity += (mt_rand() / mt_getrandmax() - 0.5) * 10;
        
        return max(20, min(100, $baseHumidity));
    }

    private function generateWind(\DateTimeInterface $timestamp, array $station, string $weatherState, ?array $synoptic = null): array
    {
        $month = (int) $timestamp->format('n');

        if ($synoptic) {
            // ── Synoptic-driven wind ──
            // Base wind from the Großwetterlage (already includes diurnal + topo from SynopticEngine)
            $speed = $synoptic['wind_speed'];
            $direction = $synoptic['wind_direction'];

            // Station exposure modifier
            $speed *= $this->getEffectiveWindExposure($station);

            // Weather state fine-tuning
            if ($weatherState === 'heavy_rain')     { $speed *= 1.25 + mt_rand() / mt_getrandmax() * 0.3; }
            elseif ($weatherState === 'fog')         { $speed *= 0.35; }

            // Small random noise for variety between hours
            $speed += (mt_rand() / mt_getrandmax() - 0.5) * 4;
            $direction += (mt_rand() / mt_getrandmax() - 0.5) * 20;
        } else {
            // Fallback: old seasonal table method
            $expectedWind = ClimateData::getExpectedWind($month);
            $speed = $expectedWind['avg'];
            $speed *= $this->getEffectiveWindExposure($station);

            switch ($weatherState) {
                case 'heavy_rain':    $speed *= 1.5 + mt_rand() / mt_getrandmax() * 0.5; break;
                case 'moderate_rain': $speed *= 1.2; break;
                case 'fog':           $speed *= 0.3; break;
                case 'sunny':         $speed *= 0.8; break;
            }

            $topography = $station['topography'] ?? '';
            if ($topography === 'peak' || $topography === 'mountain') { $speed *= 1.5; }
            elseif ($topography === 'valley') { $speed *= 0.7; }

            $speed += (mt_rand() / mt_getrandmax() - 0.5) * 8;
            $direction = $expectedWind['direction'] + (mt_rand() / mt_getrandmax() - 0.5) * 60;
        }

        $speed = max(0, $speed);
        $direction = fmod(fmod($direction, 360) + 360, 360);

        $gusts = $speed;
        if (mt_rand(0, 100) < 30) {
            $gusts = $speed * (1.3 + mt_rand() / mt_getrandmax() * 0.5);
        }

        $expectedWind = ClimateData::getExpectedWind($month);
        return [
            'speed' => min($speed, $expectedWind['max'] * 1.2),
            'direction' => (int) round($direction),
            'gusts' => min($gusts, $expectedWind['max'] * 1.8),
        ];
    }

    private function generatePressure(array $station, string $weatherState, ?array $synoptic = null): float
    {
        if ($synoptic) {
            // ── Synoptic pressure field (physically correct!) ──
            $pressure = $synoptic['pressure'];
            // Elevation adjustment (synoptic pressure is at map level, adjust for station altitude)
            $pressure += ClimateData::getElevationPressureAdjustment($station['elevation'] ?? 450);
            // Small noise
            $pressure += (mt_rand() / mt_getrandmax() - 0.5) * 1.5;
        } else {
            // Fallback: old state-derived method
            $pressure = ClimateData::BASE_PRESSURE;
            $pressure += ClimateData::getElevationPressureAdjustment($station['elevation'] ?? 450);

            switch ($weatherState) {
                case 'sunny':         $pressure += 5 + mt_rand() / mt_getrandmax() * 5; break;
                case 'heavy_rain':    $pressure -= 10 + mt_rand() / mt_getrandmax() * 5; break;
                case 'moderate_rain': $pressure -= 5 + mt_rand() / mt_getrandmax() * 3; break;
                case 'light_rain':    $pressure -= 2 + mt_rand() / mt_getrandmax() * 3; break;
            }

            $pressure += (mt_rand() / mt_getrandmax() - 0.5) * 4;
        }

        return max(950, min(1050, $pressure));
    }

    private function generateCloudCover(string $weatherState, ?array $synoptic = null): float
    {
        $config = self::STATE_CLOUD_COVER[$weatherState] ?? self::STATE_CLOUD_COVER['partly_cloudy'];
        $base = $config['min'] + mt_rand() / mt_getrandmax() * ($config['max'] - $config['min']);

        // Synoptic cloud factor modulates base cloud cover
        if ($synoptic) {
            $factor = $synoptic['cloud_factor'] ?? 1.0;
            $base *= $factor;
        }

        return max(0, min(100, $base));
    }

    private function generateVisibility(string $weatherState, float $precipitation): float
    {
        $config = self::STATE_VISIBILITY[$weatherState] ?? self::STATE_VISIBILITY['partly_cloudy'];
        $visibility = $config['min'] + mt_rand() / mt_getrandmax() * ($config['max'] - $config['min']);
        
        if ($precipitation > 5) {
            $visibility *= 0.7;
        } elseif ($precipitation > 2) {
            $visibility *= 0.85;
        }
        
        return max(0.1, $visibility);
    }

    private function generateUVIndex(\DateTimeInterface $timestamp, float $cloudCover, array $station): float
    {
        $month = (int) $timestamp->format('n');
        $hour = (int) $timestamp->format('G');
        
        $uvIndex = ClimateData::getExpectedUVIndex($month, $cloudCover, $hour);
        
        $elevationFactor = 1 + (($station['elevation'] ?? 450) - 450) / 1000 * 0.1;
        $uvIndex *= $elevationFactor;
        
        return max(0, $uvIndex);
    }

    private function calculateFeelsLike(float $temperature, float $humidity, float $windSpeed): float
    {
        if ($temperature <= 10 && $windSpeed > 4.8) {
            return 13.12 + 0.6215 * $temperature
                - 11.37 * pow($windSpeed, 0.16)
                + 0.3965 * $temperature * pow($windSpeed, 0.16);
        }
        
        if ($temperature >= 27 && $humidity >= 40) {
            return -8.784695
                + 1.61139411 * $temperature
                + 2.338549 * $humidity
                - 0.14611605 * $temperature * $humidity
                - 0.012308094 * $temperature * $temperature
                - 0.016424828 * $humidity * $humidity
                + 0.002211732 * $temperature * $temperature * $humidity
                + 0.00072546 * $temperature * $humidity * $humidity
                - 0.000003582 * $temperature * $temperature * $humidity * $humidity;
        }
        
        return $temperature;
    }

    private function getLandUsageTemperatureModifier(string $landUsage, int $hour): float
    {
        switch ($landUsage) {
            case 'urban':
                return ($hour >= 20 || $hour <= 6) ? 2.0 : 1.0;
            case 'forest':
                return ($hour >= 10 && $hour <= 16) ? -1.5 : 0.5;
            case 'alpine':
                return -1.0;
            case 'agricultural':
                return 0.3;
            default:
                return 0;
        }
    }

    private function getWeatherStateTemperatureModifier(string $weatherState): float
    {
        return match ($weatherState) {
            'heavy_rain' => -1.0,
            'moderate_rain' => -0.8,
            'light_rain' => -0.4,
            'clearing' => 0.3,
            'sunny' => 0.8,
            'snow' => -0.8,
            'fog' => -0.4,
            default => 0,
        };
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

    private function normalizeMeteorology(
        string $weatherState,
        float $temperature,
        float $precipitation,
        float $humidity,
        float $pressure,
        float $cloudCover,
        float $visibility
    ): array {
        if ($precipitation > 0) {
            $cloudCover = max($cloudCover, 70.0);
            $humidity = max($humidity, 78.0);
            $visibility *= $precipitation > 3 ? 0.75 : 0.9;
            $pressure -= min(6.0, $precipitation * 0.8);
        }

        if ($weatherState === 'fog') {
            $humidity = max($humidity, 92.0);
            $cloudCover = max($cloudCover, 85.0);
            $visibility = min($visibility, 1.5);
        }

        if (in_array($weatherState, ['sunny', 'clear'], true)) {
            $precipitation = 0.0;
            $cloudCover = min($cloudCover, 25.0);
            $humidity = min($humidity, 82.0);
        }

        if ($humidity < 55.0 && $precipitation > 0.2) {
            $precipitation *= 0.45;
        }

        if ($cloudCover < 45.0 && $precipitation > 0.1) {
            $precipitation *= 0.4;
        }

        if ($temperature <= 0.0 && in_array($weatherState, ['light_rain', 'moderate_rain', 'heavy_rain'], true)) {
            $weatherState = 'snow';
        }

        if ($temperature > 2.0 && $weatherState === 'snow' && $precipitation > 0.0) {
            $weatherState = 'light_rain';
        }

        if ($weatherState === 'sunny' && $cloudCover > 70.0) {
            $weatherState = 'partly_cloudy';
        }

        if ($weatherState === 'partly_cloudy' && $cloudCover > 85.0 && $precipitation <= 0.1) {
            $weatherState = 'cloudy';
        }

        return [
            'weather_state' => $weatherState,
            'precipitation' => max(0.0, $precipitation),
            'humidity' => max(20.0, min(100.0, $humidity)),
            'pressure' => max(950.0, min(1050.0, $pressure)),
            'cloud_cover' => max(0.0, min(100.0, $cloudCover)),
            'visibility' => max(0.1, min(40.0, $visibility)),
        ];
    }

    private function applySpatialCoupling(
        string $weatherState,
        float $temperature,
        float $precipitation,
        float $humidity,
        float $pressure,
        float $cloudCover,
        float $visibility,
        array $wind,
        array $spatialNeighbors
    ): array {
        if (!$spatialNeighbors) {
            return [
                'weather_state' => $weatherState,
                'temperature' => $temperature,
                'precipitation' => $precipitation,
                'humidity' => $humidity,
                'pressure' => $pressure,
                'cloud_cover' => $cloudCover,
                'visibility' => $visibility,
            ];
        }

        $flowDirection = fmod(((float) ($wind['direction'] ?? 0) + 180.0), 360.0);

        $tempSum = 0.0;
        $humidSum = 0.0;
        $pressureSum = 0.0;
        $cloudSum = 0.0;
        $precipSum = 0.0;
        $visSum = 0.0;
        $stateWeights = [];
        $totalWeight = 0.0;

        foreach ($spatialNeighbors as $neighbor) {
            $weather = $neighbor['weather'] ?? null;
            if (!$weather) {
                continue;
            }

            $distance = max(1.0, (float) ($neighbor['distance'] ?? 9999));
            $bearingToStation = (float) ($neighbor['bearing_to_station'] ?? 0.0);

            $distanceWeight = 1.0 / (1.0 + pow($distance / 450.0, 1.4));
            $angleDiff = $this->angularDifference($flowDirection, $bearingToStation);
            $alignment = max(0.0, cos(deg2rad($angleDiff)));
            $weight = $distanceWeight * (0.35 + 0.65 * $alignment);

            if ($weight < 0.04) {
                continue;
            }

            $tempSum += (float) ($weather['temperature'] ?? $temperature) * $weight;
            $humidSum += (float) ($weather['humidity'] ?? $humidity) * $weight;
            $pressureSum += (float) ($weather['pressure'] ?? $pressure) * $weight;
            $cloudSum += (float) ($weather['cloud_cover'] ?? $cloudCover) * $weight;
            $precipSum += (float) ($weather['precipitation'] ?? $precipitation) * $weight;
            $visSum += (float) ($weather['visibility'] ?? $visibility) * $weight;

            $state = (string) ($weather['weather_state'] ?? $weatherState);
            $stateWeights[$state] = ($stateWeights[$state] ?? 0.0) + $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0.08) {
            return [
                'weather_state' => $weatherState,
                'temperature' => $temperature,
                'precipitation' => $precipitation,
                'humidity' => $humidity,
                'pressure' => $pressure,
                'cloud_cover' => $cloudCover,
                'visibility' => $visibility,
            ];
        }

        $neighborTemp = $tempSum / $totalWeight;
        $neighborHumidity = $humidSum / $totalWeight;
        $neighborPressure = $pressureSum / $totalWeight;
        $neighborCloud = $cloudSum / $totalWeight;
        $neighborPrecip = $precipSum / $totalWeight;
        $neighborVisibility = $visSum / $totalWeight;

        $couplingStrength = min(0.28, 0.14 + 0.02 * count($spatialNeighbors));

        $temperature = $temperature * (1 - $couplingStrength) + $neighborTemp * $couplingStrength;
        $humidity = $humidity * (1 - $couplingStrength) + $neighborHumidity * $couplingStrength;
        $pressure = $pressure * (1 - $couplingStrength) + $neighborPressure * $couplingStrength;
        $cloudCover = $cloudCover * (1 - $couplingStrength) + $neighborCloud * $couplingStrength;
        $precipitation = $precipitation * (1 - $couplingStrength * 0.9) + $neighborPrecip * ($couplingStrength * 0.9);
        $visibility = $visibility * (1 - $couplingStrength) + $neighborVisibility * $couplingStrength;

        arsort($stateWeights);
        $dominantNeighborState = (string) array_key_first($stateWeights);
        $dominantShare = ($stateWeights[$dominantNeighborState] ?? 0.0) / $totalWeight;

        if ($dominantShare > 0.52) {
            if (in_array($dominantNeighborState, ['heavy_rain', 'moderate_rain', 'light_rain', 'snow'], true) && $precipitation > 0.15) {
                $weatherState = $dominantNeighborState;
            } elseif (in_array($dominantNeighborState, ['sunny', 'clear', 'partly_cloudy'], true) && $precipitation < 0.1) {
                $weatherState = $dominantNeighborState;
            } elseif ($dominantNeighborState === 'cloudy' && $precipitation < 0.2) {
                $weatherState = 'cloudy';
            }
        }

        return [
            'weather_state' => $weatherState,
            'temperature' => $temperature,
            'precipitation' => max(0.0, $precipitation),
            'humidity' => max(20.0, min(100.0, $humidity)),
            'pressure' => max(950.0, min(1050.0, $pressure)),
            'cloud_cover' => max(0.0, min(100.0, $cloudCover)),
            'visibility' => max(0.1, min(40.0, $visibility)),
        ];
    }

    private function buildNeighborIndex(array $stations, int $limit = 4): array
    {
        $index = [];

        foreach ($stations as $station) {
            $stationId = (int) ($station['id'] ?? 0);
            if ($stationId <= 0) {
                continue;
            }

            $x = (float) ($station['x_coord'] ?? 0);
            $y = (float) ($station['y_coord'] ?? 0);
            $distances = [];

            foreach ($stations as $candidate) {
                $candidateId = (int) ($candidate['id'] ?? 0);
                if ($candidateId <= 0 || $candidateId === $stationId) {
                    continue;
                }

                $cx = (float) ($candidate['x_coord'] ?? 0);
                $cy = (float) ($candidate['y_coord'] ?? 0);
                $distance = $this->distance($x, $y, $cx, $cy);

                $distances[] = [
                    'id' => $candidateId,
                    'distance' => $distance,
                    'bearing_to_station' => $this->bearingDegrees($cx, $cy, $x, $y),
                ];
            }

            usort($distances, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);
            $index[$stationId] = array_slice($distances, 0, max(1, $limit));
        }

        return $index;
    }

    private function distance(float $x1, float $y1, float $x2, float $y2): float
    {
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        return sqrt($dx * $dx + $dy * $dy);
    }

    private function bearingDegrees(float $x1, float $y1, float $x2, float $y2): float
    {
        $angle = rad2deg(atan2($y2 - $y1, $x2 - $x1));
        return fmod($angle + 360.0, 360.0);
    }

    private function angularDifference(float $a, float $b): float
    {
        $diff = fmod(abs($a - $b), 360.0);
        return $diff > 180.0 ? 360.0 - $diff : $diff;
    }

    private function isNighttime(int $hour): bool
    {
        return $hour >= 21 || $hour < 6;
    }

    private function seededRandom(int $seed): float
    {
        $x = sin($seed) * 10000;
        return $x - floor($x);
    }
}
