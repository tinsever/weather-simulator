<?php

declare(strict_types=1);

/**
 * Delete simulated weather, forecasts, and synoptic patterns from today onward,
 * then optionally regenerate via WeatherCron.
 *
 * Usage:
 *   php scripts/reset-weather-from-today.php
 *   php scripts/reset-weather-from-today.php --no-regenerate
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use Config\Database;
use Scheduler\WeatherCron;

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Vaduz');

$regenerate = !in_array('--no-regenerate', $argv ?? [], true);
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$fromTimestamp = $today . ' 00:00:00';

$db = Database::getInstance();

$weatherDeleted = $db->prepare('DELETE FROM weather_data WHERE timestamp >= ?');
$weatherDeleted->execute([$fromTimestamp]);

$forecastDeleted = $db->prepare('DELETE FROM weather_forecast WHERE forecast_date >= ?');
$forecastDeleted->execute([$today]);

$synopticDeleted = $db->prepare('DELETE FROM synoptic_patterns WHERE date >= ?');
$synopticDeleted->execute([$today]);

$summary = [
    'from_date' => $today,
    'deleted' => [
        'weather_data' => $weatherDeleted->rowCount(),
        'weather_forecast' => $forecastDeleted->rowCount(),
        'synoptic_patterns' => $synopticDeleted->rowCount(),
    ],
];

if ($regenerate) {
    $summary['regenerated'] = (new WeatherCron())->run();
}

fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
