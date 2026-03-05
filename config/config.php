<?php

declare(strict_types=1);

$envBool = static function (string $key, bool $default = false): bool {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
};

return [
    'app' => [
        'name' => 'Eulenmeteo',
        'version' => '2.0.0',
        'debug' => $envBool('APP_DEBUG', false),
        'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Vaduz',
    ],
    'database' => [
        'path' => getenv('DB_PATH') ?: (__DIR__ . '/../database/clauswetter.db'),
    ],
    'map' => [
        'image' => '/maps/map.png',
        'dimensions' => [
            'width' => 3493,
            'height' => 2203,
        ],
        'minZoom' => 0.5,
        'maxZoom' => 3,
    ],
    'weather' => [
        'generationHours' => 168,
        'forecastDays' => 7,
    ],
    'session' => [
        'secret' => getenv('SESSION_SECRET') ?: null,
        'lifetime' => (int) (getenv('SESSION_LIFETIME') ?: 86400),
    ],
];
