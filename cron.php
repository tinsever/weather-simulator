<?php
/**
 * Aufruf: /cron.php?secret=… oder via X-Cron-Secret Header.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';

use Config\Database;
use Scheduler\WeatherCron;

$config = require __DIR__ . '/config/config.php';
$timezone = $config['app']['timezone'] ?? 'Europe/Vaduz';

setlocale(LC_TIME, 'de_DE.UTF-8');
date_default_timezone_set($timezone);

$cronSecret   = $_GET['secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? null;
$configSecret = getenv('CRON_SECRET') ?: null;

if (!$configSecret) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'CRON_SECRET is not configured']);
    exit;
}

if ($cronSecret !== $configSecret) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    Database::getInstance();
    $cron   = new WeatherCron();
    $result = $cron->run();

    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
