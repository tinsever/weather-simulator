<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use Config\Database;
use Scheduler\WeatherCron;

$config = require __DIR__ . '/../config/config.php';
$timezone = $config['app']['timezone'] ?? 'Europe/Vaduz';

setlocale(LC_TIME, 'de_DE.UTF-8');
date_default_timezone_set($timezone);

try {
    Database::getInstance();
    $result = (new WeatherCron())->run();
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit($result['success'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
