<?php

namespace Engine;

use Config\Database;

/**
 * Synoptic-scale weather pattern engine.
 *
 * Generates daily Großwetterlagen (weather regimes) that drive coherent weather
 * across all stations. Each regime defines pressure fields, wind patterns,
 * front systems, and cloud/precipitation factors.
 *
 * The 8 regimes:
 *   high_pressure  – Hochdrucklage    (clear, stable, light winds)
 *   westwind       – Westwindlage     (Atlantic flow, cloudy, intermittent rain)
 *   front_cold     – Kaltfront        (sharp front sweeps across, heavy rain then clearing)
 *   front_warm     – Warmfront        (gradual thickening, light persistent rain, warming)
 *   nordstau       – Nordstaulage     (cold N wind, heavy orographic precip on north side)
 *   foehn          – Föhnlage         (warm dry south wind, very clear, strong gusts)
 *   bise           – Bisenlage        (cold dry NE wind, persistent, low cloud possible)
 *   flat_pressure  – Flachdrucklage   (weak gradients, local effects dominate)
 */
class SynopticEngine
{
    private const MAP_WIDTH  = 3493;
    private const MAP_HEIGHT = 2203;
    private const MAP_CX     = 1746;
    private const MAP_CY     = 1101;
    private const PX_PER_KM  = 23;

    // ───────────────────────────────────────────────────
    //  Regime parameters
    // ───────────────────────────────────────────────────
    private const REGIMES = [
        'high_pressure' => [
            'label'       => 'Hochdrucklage',
            'p_anomaly'   => [10, 20],       // hPa above 1013
            'p_sigma'     => 2500,           // px – pressure field scale
            'p_center_dx' => 500,            // px offset from map centre
            'p_center_dy' => -200,
            'wind_speed'  => [3, 10],        // km/h
            'wind_dir'    => [120, 200],     // degrees
            'cloud_factor'  => 0.15,
            'precip_factor' => 0.0,
            'temp_adv'    => [0.5, 3.0],     // °C
            'state_bias'  => 'dry',
            'has_front'   => false,
        ],
        'westwind' => [
            'label'       => 'Westwindlage',
            'p_anomaly'   => [-8, -3],
            'p_sigma'     => 2000,
            'p_center_dx' => -1200,
            'p_center_dy' => 300,
            'wind_speed'  => [15, 30],
            'wind_dir'    => [240, 280],
            'cloud_factor'  => 1.3,
            'precip_factor' => 0.8,
            'temp_adv'    => [0.0, 2.0],
            'state_bias'  => 'wet',
            'has_front'   => false,
        ],
        'front_cold' => [
            'label'       => 'Kaltfrontdurchgang',
            'p_anomaly'   => [-12, -5],
            'p_sigma'     => 2000,
            'p_center_dx' => -800,
            'p_center_dy' => 0,
            'wind_speed'  => [15, 35],
            'wind_dir'    => [270, 330],
            'cloud_factor'  => 1.0,          // overridden by front distance
            'precip_factor' => 1.0,
            'temp_adv'    => [-5.0, -2.0],
            'state_bias'  => 'wet',
            'has_front'   => true,
            'front_bearing' => 280,          // front arrives from ~W-NW
            'front_speed' => [30, 50],       // km/h
        ],
        'front_warm' => [
            'label'       => 'Warmfrontdurchgang',
            'p_anomaly'   => [-8, -3],
            'p_sigma'     => 2500,
            'p_center_dx' => -1000,
            'p_center_dy' => 400,
            'wind_speed'  => [10, 25],
            'wind_dir'    => [180, 240],
            'cloud_factor'  => 1.0,
            'precip_factor' => 1.0,
            'temp_adv'    => [2.0, 4.5],
            'state_bias'  => 'wet',
            'has_front'   => true,
            'front_bearing' => 210,          // from SW
            'front_speed' => [15, 25],
        ],
        'nordstau' => [
            'label'       => 'Nordstaulage',
            'p_anomaly'   => [-6, -2],
            'p_sigma'     => 2000,
            'p_center_dx' => 0,
            'p_center_dy' => -800,
            'wind_speed'  => [15, 25],
            'wind_dir'    => [340, 60],       // wraps N
            'cloud_factor'  => 1.8,
            'precip_factor' => 1.5,
            'temp_adv'    => [-3.0, -1.0],
            'state_bias'  => 'wet',
            'has_front'   => false,
        ],
        'foehn' => [
            'label'       => 'Föhnlage',
            'p_anomaly'   => [-5, 8],
            'p_sigma'     => 2500,
            'p_center_dx' => 0,
            'p_center_dy' => 800,
            'wind_speed'  => [25, 55],
            'wind_dir'    => [160, 200],
            'cloud_factor'  => 0.1,
            'precip_factor' => 0.0,
            'temp_adv'    => [3.0, 7.0],
            'state_bias'  => 'dry',
            'has_front'   => false,
        ],
        'bise' => [
            'label'       => 'Bisenlage',
            'p_anomaly'   => [8, 16],
            'p_sigma'     => 2500,
            'p_center_dx' => 800,
            'p_center_dy' => -600,
            'wind_speed'  => [20, 40],
            'wind_dir'    => [30, 70],
            'cloud_factor'  => 0.45,
            'precip_factor' => 0.05,
            'temp_adv'    => [-6.0, -2.0],
            'state_bias'  => 'dry',
            'has_front'   => false,
        ],
        'flat_pressure' => [
            'label'       => 'Flachdrucklage',
            'p_anomaly'   => [-2, 2],
            'p_sigma'     => 4000,
            'p_center_dx' => 0,
            'p_center_dy' => 0,
            'wind_speed'  => [2, 8],
            'wind_dir'    => [0, 360],
            'cloud_factor'  => 0.6,
            'precip_factor' => 0.3,
            'temp_adv'    => [-0.5, 0.5],
            'state_bias'  => 'neutral',
            'has_front'   => false,
        ],
    ];

    // ───────────────────────────────────────────────────
    //  Markov transition matrix  (weights, not probabilities)
    // ───────────────────────────────────────────────────
    private const TRANSITIONS = [
        'high_pressure' => ['high_pressure'=>55,'westwind'=>15,'flat_pressure'=>12,'bise'=>8,'foehn'=>5,'front_cold'=>3,'front_warm'=>2],
        'westwind'      => ['westwind'=>35,'front_cold'=>25,'front_warm'=>10,'high_pressure'=>10,'nordstau'=>8,'flat_pressure'=>7,'foehn'=>3,'bise'=>2],
        'front_cold'    => ['high_pressure'=>25,'westwind'=>20,'nordstau'=>15,'bise'=>15,'flat_pressure'=>10,'front_cold'=>10,'front_warm'=>3,'foehn'=>2],
        'front_warm'    => ['westwind'=>30,'high_pressure'=>20,'flat_pressure'=>15,'front_cold'=>15,'front_warm'=>10,'foehn'=>5,'nordstau'=>3,'bise'=>2],
        'nordstau'      => ['nordstau'=>40,'westwind'=>20,'high_pressure'=>15,'front_cold'=>10,'bise'=>8,'flat_pressure'=>5,'foehn'=>2],
        'foehn'         => ['foehn'=>30,'high_pressure'=>25,'westwind'=>15,'flat_pressure'=>15,'front_cold'=>8,'front_warm'=>5,'bise'=>2],
        'bise'          => ['bise'=>50,'high_pressure'=>25,'flat_pressure'=>10,'westwind'=>8,'front_cold'=>4,'nordstau'=>3],
        'flat_pressure' => ['flat_pressure'=>25,'high_pressure'=>25,'westwind'=>18,'front_warm'=>10,'front_cold'=>8,'foehn'=>7,'bise'=>4,'nordstau'=>3],
    ];

    // Seasonal multipliers on transition weights
    private const SEASON_WEIGHTS = [
        'winter' => ['high_pressure'=>1.0,'westwind'=>1.2,'front_cold'=>1.3,'front_warm'=>0.6,'nordstau'=>1.3,'foehn'=>0.5,'bise'=>1.5,'flat_pressure'=>0.4],
        'spring' => ['high_pressure'=>1.0,'westwind'=>1.1,'front_cold'=>1.0,'front_warm'=>1.2,'nordstau'=>0.8,'foehn'=>1.3,'bise'=>0.7,'flat_pressure'=>1.0],
        'summer' => ['high_pressure'=>1.3,'westwind'=>0.8,'front_cold'=>0.9,'front_warm'=>0.7,'nordstau'=>0.5,'foehn'=>0.8,'bise'=>0.3,'flat_pressure'=>1.8],
        'autumn' => ['high_pressure'=>1.0,'westwind'=>1.2,'front_cold'=>1.1,'front_warm'=>0.8,'nordstau'=>1.0,'foehn'=>1.2,'bise'=>0.8,'flat_pressure'=>0.7],
    ];

    private array $cache = [];

    public function __construct()
    {
        $this->ensureTable();
    }

    // ═══════════════════════════════════════════════════
    //  PUBLIC API
    // ═══════════════════════════════════════════════════

    /**
     * Get the synoptic regime for a given date. Creates one if none exists.
     */
    public function getRegimeForDate(\DateTimeInterface $date): array
    {
        $dateStr = $date->format('Y-m-d');

        if (isset($this->cache[$dateStr])) {
            return $this->cache[$dateStr];
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM synoptic_patterns WHERE date = ?');
        $stmt->execute([$dateStr]);
        $regime = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$regime) {
            // Look up yesterday's regime (non-recursive, single query)
            $prevStr = (new \DateTimeImmutable($dateStr))->modify('-1 day')->format('Y-m-d');
            $stmt2 = $db->prepare('SELECT * FROM synoptic_patterns WHERE date = ?');
            $stmt2->execute([$prevStr]);
            $prevRegime = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: null;

            $regime = $this->generateRegime($date, $prevRegime);
            $this->persistRegime($regime);
        }

        $this->cache[$dateStr] = $regime;
        return $regime;
    }

    /**
     * Calculate synoptic context for a specific station at a specific time.
     * This is the main interface consumed by WeatherSimulator.
     */
    public function getStationContext(\DateTimeInterface $timestamp, array $station): array
    {
        $regime = $this->getRegimeForDate($timestamp);
        $params = self::REGIMES[$regime['regime_type']] ?? self::REGIMES['flat_pressure'];

        $x = (float) ($station['x_coord'] ?? self::MAP_CX);
        $y = (float) ($station['y_coord'] ?? self::MAP_CY);
        $hour = (int) $timestamp->format('G');

        // ── Pressure ──
        $localPressure = $this->pressureAt($regime, $x, $y);

        // ── Wind ──
        $windDir   = (float) $regime['wind_direction'];
        $windSpeed = (float) $regime['wind_speed'];

        // Spatial variation from pressure gradient
        $grad = $this->pressureGradient($regime, $x, $y);
        $windDir   += $grad['dir_adj'];
        $windSpeed += $grad['spd_adj'];

        // Diurnal modulation
        $windSpeed *= $this->diurnalWindFactor($regime['regime_type'], $hour);

        // Topographic amplification (valleys channel, peaks expose)
        $topo = $station['topography'] ?? '';
        if ($topo === 'peak' || $topo === 'mountain') {
            $windSpeed *= 1.4;
        } elseif ($topo === 'valley') {
            // Föhn funnels through valleys
            if ($regime['regime_type'] === 'foehn') {
                $windSpeed *= 1.3;
            } else {
                $windSpeed *= 0.7;
            }
        }

        // ── Cloud & Precipitation factors ──
        $cloudFactor  = $params['cloud_factor'];
        $precipFactor = $params['precip_factor'];
        $stateBias    = $params['state_bias'];

        // Front effects (override ambient factors)
        if ($regime['front_active']) {
            $frontDist   = $this->frontDistance($regime, $x, $y, $hour);
            $frontFx     = $this->frontEffects($frontDist, $regime['front_type']);
            $cloudFactor  = $frontFx['cloud'];
            $precipFactor = $frontFx['precip'];
            $stateBias    = $frontFx['state_bias'];
        }

        // Nordstau: more precipitation on north side (low y)
        if ($regime['regime_type'] === 'nordstau') {
            $northness = max(0.0, (self::MAP_CY - $y) / self::MAP_HEIGHT + 0.5);
            $precipFactor *= (0.6 + $northness * 1.2);
            $cloudFactor  *= (0.7 + $northness * 0.8);
        }

        // Föhn: dry north side, possible Stau on south
        if ($regime['regime_type'] === 'foehn') {
            $southness = max(0.0, ($y - self::MAP_CY) / self::MAP_HEIGHT + 0.5);
            $cloudFactor  = 0.08 + $southness * 1.6;
            $precipFactor = $southness * 0.8;
            if ($southness < 0.4) {
                $stateBias = 'dry';
            }
        }

        // Diurnal cloud cycle for flat pressure (afternoon convection in summer)
        if ($regime['regime_type'] === 'flat_pressure') {
            $month = (int) $timestamp->format('n');
            if ($month >= 5 && $month <= 9 && $hour >= 13 && $hour <= 19) {
                $cloudFactor  *= 1.6;
                $precipFactor *= 2.0;
            }
        }

        // ── Temperature advection ──
        $tempAdv = (float) $regime['temp_advection'];
        if ($regime['front_active']) {
            $fd = $this->frontDistance($regime, $x, $y, $hour);
            $fdKm = $fd / self::PX_PER_KM;
            if ($fdKm > 50) {
                $tempAdv *= 0.15;  // front hasn't arrived, old air mass
            } else {
                $progress = min(1.0, max(0.0, (50 - $fdKm) / 130));
                $tempAdv *= $progress;
            }
        }

        return [
            'regime'        => $regime['regime_type'],
            'regime_label'  => $params['label'],
            'pressure'      => round($localPressure, 1),
            'wind_direction' => fmod(fmod($windDir, 360) + 360, 360),
            'wind_speed'    => max(0.0, round($windSpeed, 1)),
            'cloud_factor'  => max(0.0, min(2.5, $cloudFactor)),
            'precip_factor' => max(0.0, min(3.0, $precipFactor)),
            'temp_advection' => round($tempAdv, 2),
            'state_bias'    => $stateBias,
        ];
    }

    /**
     * Return the raw regime label for display.
     */
    public function getRegimeLabel(\DateTimeInterface $date): string
    {
        $regime = $this->getRegimeForDate($date);
        $params = self::REGIMES[$regime['regime_type']] ?? null;
        return $params['label'] ?? $regime['regime_type'];
    }

    // ═══════════════════════════════════════════════════
    //  REGIME GENERATION
    // ═══════════════════════════════════════════════════

    private function generateRegime(\DateTimeInterface $date, ?array $previous): array
    {
        $month  = (int) $date->format('n');
        $season = ClimateData::getSeason($month);
        $daySeed = (int) ($date->getTimestamp() / 86400);

        // Choose regime type via Markov transition
        $prevType = $previous['regime_type'] ?? null;
        $regimeType = $this->rollTransition($prevType, $season, $daySeed);
        $params = self::REGIMES[$regimeType];

        // Roll concrete values within parameter ranges
        $rng = fn(int $offset): float => $this->seededRandom($daySeed + $offset);

        $pAnomaly = $this->lerp($params['p_anomaly'][0], $params['p_anomaly'][1], $rng(100));
        $windSpeed = $this->lerp($params['wind_speed'][0], $params['wind_speed'][1], $rng(200));

        // Wind direction – handle wrap-around (e.g. nordstau 340..60)
        $dirMin = $params['wind_dir'][0];
        $dirMax = $params['wind_dir'][1];
        if ($dirMax < $dirMin) {
            // wraps around 360 → pick in [dirMin, dirMin + (360 - dirMin + dirMax)]
            $range = (360 - $dirMin) + $dirMax;
            $windDir = fmod($dirMin + $rng(300) * $range, 360);
        } else {
            $windDir = $this->lerp($dirMin, $dirMax, $rng(300));
        }

        $tempAdv = $this->lerp($params['temp_adv'][0], $params['temp_adv'][1], $rng(400));

        // Pressure centre position
        $pCenterX = self::MAP_CX + $params['p_center_dx'] + ($rng(500) - 0.5) * 600;
        $pCenterY = self::MAP_CY + $params['p_center_dy'] + ($rng(501) - 0.5) * 400;

        // Front parameters (if applicable)
        $frontActive = $params['has_front'] ? 1 : 0;
        $frontType   = 'none';
        $frontStartX = 0.0;
        $frontStartY = 0.0;
        $frontSpeedX = 0.0;
        $frontSpeedY = 0.0;

        if ($params['has_front']) {
            $frontType = ($regimeType === 'front_cold') ? 'cold' : 'warm';

            $frontBearing = $params['front_bearing'];
            $frontSpeedKmh = $this->lerp(
                $params['front_speed'][0],
                $params['front_speed'][1],
                $rng(600)
            );

            // Front moves in the direction of front_bearing (from that direction)
            $moveRad = deg2rad($frontBearing + 180); // movement direction
            $frontSpeedPx = $frontSpeedKmh * self::PX_PER_KM; // px per hour
            $frontSpeedX = cos($moveRad) * $frontSpeedPx;
            $frontSpeedY = sin($moveRad) * $frontSpeedPx;

            // Place front so it arrives at the map between hour 4-18
            $arrivalHour = 4 + $rng(700) * 14;
            $frontStartX = self::MAP_CX - $frontSpeedX * $arrivalHour;
            $frontStartY = self::MAP_CY - $frontSpeedY * $arrivalHour;
        }

        return [
            'date'             => $date->format('Y-m-d'),
            'regime_type'      => $regimeType,
            'pressure_anomaly' => round($pAnomaly, 1),
            'pressure_center_x' => round($pCenterX),
            'pressure_center_y' => round($pCenterY),
            'pressure_sigma'   => $params['p_sigma'],
            'wind_direction'   => round($windDir),
            'wind_speed'       => round($windSpeed, 1),
            'temp_advection'   => round($tempAdv, 2),
            'front_active'     => $frontActive,
            'front_type'       => $frontType,
            'front_start_x'    => round($frontStartX, 1),
            'front_start_y'    => round($frontStartY, 1),
            'front_speed_x'    => round($frontSpeedX, 2),
            'front_speed_y'    => round($frontSpeedY, 2),
        ];
    }

    private function rollTransition(?string $prevType, string $season, int $seed): string
    {
        $seasonWeights = self::SEASON_WEIGHTS[$season] ?? self::SEASON_WEIGHTS['winter'];

        // If no previous, pick weighted by season
        if ($prevType === null || !isset(self::TRANSITIONS[$prevType])) {
            $weights = $seasonWeights;
        } else {
            $raw = self::TRANSITIONS[$prevType];
            $weights = [];
            foreach ($raw as $type => $w) {
                $weights[$type] = $w * ($seasonWeights[$type] ?? 1.0);
            }
        }

        $total = array_sum($weights);
        $roll  = $this->seededRandom($seed + 999) * $total;

        $cum = 0.0;
        foreach ($weights as $type => $w) {
            $cum += $w;
            if ($roll < $cum) {
                return $type;
            }
        }

        return array_key_last($weights);
    }

    // ═══════════════════════════════════════════════════
    //  PHYSICS CALCULATIONS
    // ═══════════════════════════════════════════════════

    /**
     * Pressure at a map point from the synoptic pressure field.
     */
    private function pressureAt(array $regime, float $x, float $y): float
    {
        $cx = (float) ($regime['pressure_center_x'] ?? self::MAP_CX);
        $cy = (float) ($regime['pressure_center_y'] ?? self::MAP_CY);
        $sigma = (float) ($regime['pressure_sigma'] ?? 7000);
        $anomaly = (float) ($regime['pressure_anomaly'] ?? 0);

        $dx = $x - $cx;
        $dy = $y - $cy;
        $distSq = $dx * $dx + $dy * $dy;

        // Gaussian pressure field
        $p = 1013.25 + $anomaly * exp(-$distSq / (2 * $sigma * $sigma));

        return $p;
    }

    /**
     * Pressure gradient → small wind direction/speed adjustments.
     * Provides spatial variation so nearby stations aren't identical.
     */
    private function pressureGradient(array $regime, float $x, float $y): array
    {
        $h = 50.0; // finite-difference step (px)
        $pL = $this->pressureAt($regime, $x - $h, $y);
        $pR = $this->pressureAt($regime, $x + $h, $y);
        $pU = $this->pressureAt($regime, $x, $y - $h);
        $pD = $this->pressureAt($regime, $x, $y + $h);

        $dpdx = ($pR - $pL) / (2 * $h);
        $dpdy = ($pD - $pU) / (2 * $h);

        // Geostrophic wind is perpendicular to pressure gradient
        // In NH: wind blows with low pressure to the left
        // Surface friction rotates ~25° toward low pressure
        $gradMag = sqrt($dpdx * $dpdx + $dpdy * $dpdy);
        if ($gradMag < 1e-8) {
            return ['dir_adj' => 0, 'spd_adj' => 0];
        }

        // Direction adjustment proportional to gradient asymmetry
        $gradAngle = rad2deg(atan2($dpdy, $dpdx));
        $dirAdj = sin(deg2rad($gradAngle)) * $gradMag * 800;  // ±few degrees
        $dirAdj = max(-15, min(15, $dirAdj));

        // Speed adjustment from gradient strength
        $spdAdj = $gradMag * 3000; // hPa/px → km/h  
        $spdAdj = max(-5, min(8, $spdAdj));

        return ['dir_adj' => $dirAdj, 'spd_adj' => $spdAdj];
    }

    /**
     * Signed distance from a point to the front line (in px).
     * Positive = front hasn't arrived (ahead), negative = front has passed.
     */
    private function frontDistance(array $regime, float $x, float $y, int $hour): float
    {
        $fsx = (float) ($regime['front_start_x'] ?? 0);
        $fsy = (float) ($regime['front_start_y'] ?? 0);
        $fvx = (float) ($regime['front_speed_x'] ?? 0);
        $fvy = (float) ($regime['front_speed_y'] ?? 0);

        // Front position at this hour
        $fx = $fsx + $fvx * $hour;
        $fy = $fsy + $fvy * $hour;

        // Movement direction (normalised)
        $speed = sqrt($fvx * $fvx + $fvy * $fvy);
        if ($speed < 0.01) {
            return 99999;
        }
        $nx = $fvx / $speed;
        $ny = $fvy / $speed;

        // Signed distance: positive = station is ahead of front
        return ($x - $fx) * $nx + ($y - $fy) * $ny;
    }

    /**
     * Cloud/precip modifiers and state bias based on distance to front.
     */
    private function frontEffects(float $distPx, string $frontType): array
    {
        $d = $distPx / self::PX_PER_KM;  // km

        if ($frontType === 'cold') {
            // Cold front: narrow band of heavy rain, sharp clearing behind
            if ($d > 200) {
                return ['cloud' => 0.3,  'precip' => 0.0, 'state_bias' => 'dry'];
            } elseif ($d > 80) {
                $t = ($d - 80) / 120;
                return ['cloud' => 0.3 + 1.0 * (1 - $t), 'precip' => 0.0, 'state_bias' => 'neutral'];
            } elseif ($d > 20) {
                return ['cloud' => 1.6, 'precip' => 0.6, 'state_bias' => 'wet'];
            } elseif ($d > -30) {
                // At the front — peak intensity
                return ['cloud' => 2.2, 'precip' => 2.5, 'state_bias' => 'wet'];
            } elseif ($d > -80) {
                $t = ($d + 80) / 50;
                return ['cloud' => 0.6 + 1.4 * $t, 'precip' => 0.3 + 1.5 * $t, 'state_bias' => 'wet'];
            } elseif ($d > -200) {
                $t = ($d + 200) / 120;
                return ['cloud' => 0.25 + 0.4 * $t, 'precip' => 0.0, 'state_bias' => 'clearing'];
            } else {
                return ['cloud' => 0.2, 'precip' => 0.0, 'state_bias' => 'dry'];
            }
        }

        // Warm front: wide band of light persistent rain, gradual thickening
        if ($d > 300) {
            return ['cloud' => 0.3,  'precip' => 0.0, 'state_bias' => 'neutral'];
        } elseif ($d > 150) {
            $t = ($d - 150) / 150;
            return ['cloud' => 0.5 + 0.8 * (1 - $t), 'precip' => 0.0, 'state_bias' => 'neutral'];
        } elseif ($d > 50) {
            $t = ($d - 50) / 100;
            return ['cloud' => 1.3 + 0.5 * (1 - $t), 'precip' => 0.4 + 0.6 * (1 - $t), 'state_bias' => 'wet'];
        } elseif ($d > -20) {
            return ['cloud' => 1.8, 'precip' => 1.5, 'state_bias' => 'wet'];
        } elseif ($d > -100) {
            $t = ($d + 100) / 80;
            return ['cloud' => 1.0 + 0.8 * $t, 'precip' => 0.3 + 1.0 * $t, 'state_bias' => 'wet'];
        } elseif ($d > -250) {
            $t = ($d + 250) / 150;
            return ['cloud' => 0.6 + 0.4 * $t, 'precip' => 0.0 + 0.3 * $t, 'state_bias' => 'neutral'];
        } else {
            return ['cloud' => 0.5, 'precip' => 0.0, 'state_bias' => 'neutral'];
        }
    }

    /**
     * Diurnal wind speed factor (mountain/valley breezes, nighttime lull).
     */
    private function diurnalWindFactor(string $regime, int $hour): float
    {
        // Strong-wind regimes: less diurnal variation
        if (in_array($regime, ['foehn', 'bise', 'front_cold'], true)) {
            return ($hour >= 2 && $hour <= 6) ? 0.85 : 1.0;
        }

        // Weak-wind regimes: strong diurnal cycle
        if (in_array($regime, ['high_pressure', 'flat_pressure'], true)) {
            if ($hour >= 0 && $hour <= 6) return 0.5;
            if ($hour >= 10 && $hour <= 16) return 1.4;
            return 0.9;
        }

        // Default: moderate diurnal cycle
        if ($hour >= 1 && $hour <= 5) return 0.7;
        if ($hour >= 11 && $hour <= 15) return 1.15;
        return 0.95;
    }

    // ═══════════════════════════════════════════════════
    //  DATABASE
    // ═══════════════════════════════════════════════════

    private function ensureTable(): void
    {
        $db = Database::getInstance();
        $db->exec('
            CREATE TABLE IF NOT EXISTS synoptic_patterns (
                date TEXT PRIMARY KEY,
                regime_type TEXT NOT NULL,
                pressure_anomaly REAL NOT NULL DEFAULT 0,
                pressure_center_x REAL,
                pressure_center_y REAL,
                pressure_sigma REAL DEFAULT 7000,
                wind_direction REAL,
                wind_speed REAL,
                temp_advection REAL DEFAULT 0,
                front_active INTEGER DEFAULT 0,
                front_type TEXT DEFAULT \'none\',
                front_start_x REAL DEFAULT 0,
                front_start_y REAL DEFAULT 0,
                front_speed_x REAL DEFAULT 0,
                front_speed_y REAL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    private function persistRegime(array $regime): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT OR REPLACE INTO synoptic_patterns
                (date, regime_type, pressure_anomaly, pressure_center_x, pressure_center_y,
                 pressure_sigma, wind_direction, wind_speed, temp_advection,
                 front_active, front_type, front_start_x, front_start_y,
                 front_speed_x, front_speed_y)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $regime['date'],
            $regime['regime_type'],
            $regime['pressure_anomaly'],
            $regime['pressure_center_x'],
            $regime['pressure_center_y'],
            $regime['pressure_sigma'],
            $regime['wind_direction'],
            $regime['wind_speed'],
            $regime['temp_advection'],
            $regime['front_active'],
            $regime['front_type'],
            $regime['front_start_x'],
            $regime['front_start_y'],
            $regime['front_speed_x'],
            $regime['front_speed_y'],
        ]);
    }

    // ═══════════════════════════════════════════════════
    //  UTILITIES
    // ═══════════════════════════════════════════════════

    private function seededRandom(int $seed): float
    {
        $x = sin($seed) * 10000;
        return $x - floor($x);
    }

    private function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }
}
