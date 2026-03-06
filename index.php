<?php

declare(strict_types=1);

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Autoloader fehlt. Bitte im Deploy-Verzeichnis "composer install" ausführen.';
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';

use Config\Database;
use Controllers\AdminController;
use Controllers\ApiController;

$config = require __DIR__ . '/config/config.php';
$timezone = $config['app']['timezone'] ?? 'Europe/Vaduz';

setlocale(LC_TIME, 'de_DE.UTF-8');
date_default_timezone_set($timezone);

$traceId = bin2hex(random_bytes(4));

set_exception_handler(function (Throwable $e) use ($traceId): void {
    error_log("[eulenmeteo][$traceId] Uncaught exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Internal Server Error',
        'trace' => $traceId,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

register_shutdown_function(function () use ($traceId): void {
    $last = error_get_last();
    if (!$last) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($last['type'], $fatalTypes, true)) {
        return;
    }

    error_log("[eulenmeteo][$traceId] Fatal error: {$last['message']} in {$last['file']}:{$last['line']}");

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => false,
        'error' => 'Fatal Server Error',
        'trace' => $traceId,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}

function sendJson(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function sendError(string $message, int $statusCode = 500): void
{
    sendJson(['success' => false, 'error' => $message], $statusCode);
}

function serveStaticFile(string $path): void
{
    $fullPath = __DIR__ . $path;
    
    if (!file_exists($fullPath) || is_dir($fullPath)) {
        http_response_code(404);
        echo 'Not Found';
        exit;
    }
    
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'html' => 'text/html',
    ];
    
    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    header('Content-Type: ' . $mime);
    readfile($fullPath);
    exit;
}

function route(string $method, string $path, callable $handler): bool
{
    $requestMethod = $_SERVER['REQUEST_METHOD'];
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $basePath = '/api';
    
    if (strpos($requestUri, $basePath) !== 0) {
        return false;
    }
    
    $requestPath = substr($requestUri, strlen($basePath));
    if ($requestPath === '') $requestPath = '/';
    
    $pathRegex = preg_replace('/\{([^}]+)\}/', '([^/]+)', $path);
    $pathRegex = '#^' . $pathRegex . '$#';
    
    if ($requestMethod === $method && preg_match($pathRegex, $requestPath, $matches)) {
        array_shift($matches);
        $handler(...$matches);
        return true;
    }
    return false;
}

try {
    Database::getInstance();
} catch (Exception $e) {
    sendError('Database connection failed: ' . $e->getMessage(), 503);
    exit;
}

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// --- Admin Page Routes ---
if (strpos($requestUri, '/admin') === 0) {
    $admin = new AdminController();
    $requestMethod = $_SERVER['REQUEST_METHOD'];

    if ($requestUri === '/admin/login' && $requestMethod === 'GET') {
        $admin->loginPage();
        exit;
    }
    if ($requestUri === '/admin/login' && $requestMethod === 'POST') {
        $admin->login();
        exit;
    }
    if ($requestUri === '/admin/logout' && $requestMethod === 'POST') {
        $admin->logout();
        exit;
    }
    if ($requestUri === '/admin/auth/check' && $requestMethod === 'GET') {
        $admin->checkAuth();
        exit;
    }
    // Admin dashboard (and any sub-page handled by JS hash routing)
    if ($requestUri === '/admin' || $requestUri === '/admin/') {
        $admin->dashboard();
        exit;
    }
    // Static admin assets (css/js) - serve directly
    if (preg_match('#^/admin/(css|js)/.+$#', $requestUri)) {
        serveStaticFile($requestUri);
    }
    // Unknown admin page → dashboard
    $admin->dashboard();
    exit;
}

// --- API Routes ---
if (strpos($requestUri, '/api') === 0) {
    // Admin API routes
    if (strpos($requestUri, '/api/admin') === 0) {
        $admin = new AdminController();
        $adminPath = substr($requestUri, strlen('/api/admin'));
        if ($adminPath === '' || $adminPath === '/') $adminPath = '/';
        $requestMethod = $_SERVER['REQUEST_METHOD'];

        $adminRoutes = [
            'GET /stats'            => fn() => $admin->stats(),
            'POST /import-db'       => fn() => $admin->importDatabase(),
            'GET /options'          => fn() => $admin->options(),
            'GET /regions'          => fn() => $admin->listRegions(),
            'POST /regions'         => fn() => $admin->createRegion(),
            'GET /stations'         => fn() => $admin->listStations(),
            'POST /stations'        => fn() => $admin->createStation(),
            'GET /features'         => fn() => $admin->listFeatures(),
            'POST /features'        => fn() => $admin->createFeature(),
        ];

        $routeKey = "$requestMethod $adminPath";
        if (isset($adminRoutes[$routeKey])) {
            $adminRoutes[$routeKey]();
            exit;
        }

        // Parameterized admin routes
        if (preg_match('#^/regions/(\d+)$#', $adminPath, $m)) {
            match($requestMethod) {
                'GET' => $admin->getRegion((int)$m[1]),
                'PUT' => $admin->updateRegion((int)$m[1]),
                'DELETE' => $admin->deleteRegion((int)$m[1]),
                default => sendError('Method not allowed', 405),
            };
            exit;
        }
        if (preg_match('#^/stations/(\d+)$#', $adminPath, $m)) {
            match($requestMethod) {
                'GET' => $admin->getStation((int)$m[1]),
                'PUT' => $admin->updateStation((int)$m[1]),
                'DELETE' => $admin->deleteStation((int)$m[1]),
                default => sendError('Method not allowed', 405),
            };
            exit;
        }
        if (preg_match('#^/features/(\d+)$#', $adminPath, $m)) {
            match($requestMethod) {
                'GET' => $admin->getFeature((int)$m[1]),
                'PUT' => $admin->updateFeature((int)$m[1]),
                'DELETE' => $admin->deleteFeature((int)$m[1]),
                default => sendError('Method not allowed', 405),
            };
            exit;
        }

        sendError('Admin endpoint not found', 404);
        exit;
    }

    // Public API routes
    $api = new ApiController();
    
    $routes = [
        ['GET', '/health', fn() => $api->health()],
        ['GET', '/regions', fn() => $api->regions()],
        ['GET', '/regions/{id}', fn($id) => $api->region((int) $id)],
        ['GET', '/stations', fn() => $api->stations()],
        ['GET', '/stations/with-weather', fn() => $api->stationsWithWeather()],
        ['GET', '/stations/{id}', fn($id) => $api->station((int) $id)],
        ['GET', '/stations/{id}/weather', fn($id) => $api->stationWeather((int) $id)],
        ['GET', '/stations/{id}/weather-by-day', fn($id) => $api->stationWeatherByDay((int) $id)],
        ['GET', '/stations/{id}/history', fn($id) => $api->stationHistory((int) $id)],
        ['GET', '/stations/{id}/day-hours', fn($id) => $api->stationDayHours((int) $id)],
        ['GET', '/weather/current', fn() => $api->currentWeather()],
        ['GET', '/weather/synoptic', fn() => $api->synopticRegime()],
        ['POST', '/weather/generate', fn() => $api->generateWeather()],
        ['POST', '/weather/generate-7days', fn() => $api->generate7Days()],
        ['POST', '/weather/resimulate-day', fn() => $api->resimulateDay()],
        ['GET', '/forecast/{stationId}', fn($id) => $api->forecast((int) $id)],
        ['POST', '/forecast/generate', fn() => $api->generateForecasts()],
        ['GET', '/map/config', fn() => $api->mapConfig()],
        ['GET', '/warnings', fn() => $api->warnings()],
        ['GET', '/warnings/rss', fn() => $api->warningsRSS()],
    ];
    
    foreach ($routes as [$method, $path, $handler]) {
        if (route($method, $path, $handler)) {
            exit;
        }
    }
    
    sendError('Not Found', 404);
    exit;
}

if ($requestUri === '/' || $requestUri === '') {
    require __DIR__ . '/views/home.html';
    exit;
}

$staticPaths = ['/css/', '/js/', '/maps/', '/font/', '/admin/'];
foreach ($staticPaths as $staticPath) {
    if (strpos($requestUri, $staticPath) === 0) {
        serveStaticFile($requestUri);
    }
}

if (preg_match('/\.(css|js|png|jpg|svg|woff|woff2|ttf|eot|html)$/i', $requestUri)) {
    serveStaticFile($requestUri);
}

http_response_code(404);
echo 'Not Found';
