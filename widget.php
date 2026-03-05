<?php
/**
 * Eulenwetter Widget
 * Fetches weather data from the Clauswetter API
 */

$apiBaseUrl = 'https://eulenthal.mn-netz.de/api';
$headerSvgUrl = 'https://eulenthal.mn-netz.de/nwep.svg?v=2';
$iconBaseUrl = 'https://eulenthal.mn-netz.de/icons/';

error_reporting(E_ALL);
ini_set('display_errors', 0);

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            'Accept: application/json',
            'User-Agent: Eulenwetter-Widget/1.0',
        ],
        'timeout' => 5,
    ],
]);

$stationsResponse = @file_get_contents(
    $apiBaseUrl . '/stations/with-weather',
    false,
    $context
);

if (!$stationsResponse) {
    die('Unable to fetch weather data from API.');
}

$stationsData = json_decode($stationsResponse, true);

if (!isset($stationsData['success']) || !$stationsData['success']) {
    die('API returned an error');
}

$todayDate = date('Y-m-d');

$stationsByRegion = [];

foreach ($stationsData['data'] as $station) {
    $region = $station['region_name'] ?? 'Unbekannt';
    $stationsByRegion[$region][] = $station;
}

$selectedStationId = $_GET['station'] ?? null;

if (!$selectedStationId) {
    $firstRegion = array_key_first($stationsByRegion);
    $selectedStationId = $stationsByRegion[$firstRegion][0]['id'] ?? null;
}

$station = null;
foreach ($stationsByRegion as $stations) {
    foreach ($stations as $s) {
        if ((string) $s['id'] === (string) $selectedStationId) {
            $station = $s;
            break 2;
        }
    }
}

if (!$station) {
    die('No station found');
}

/*
|--------------------------------------------------------------------------
| Weather state mapping → SVG Icons
|--------------------------------------------------------------------------
*/

$weatherStateMap = [
    'sunny' => ['name' => 'Sonnig', 'icon' => 'wi-day-sunny.svg'],
    'clear' => ['name' => 'Klar', 'icon' => 'wi-night-clear.svg'],
    'partly_cloudy' => [
        'name' => 'Teilweise bewölkt',
        'icon' => 'wi-day-cloudy.svg',
    ],
    'cloudy' => ['name' => 'Bewölkt', 'icon' => 'wi-cloudy.svg'],
    'light_rain' => ['name' => 'Leichter Regen', 'icon' => 'wi-sprinkle.svg'],
    'moderate_rain' => ['name' => 'Mäßiger Regen', 'icon' => 'wi-rain.svg'],
    'heavy_rain' => ['name' => 'Starker Regen', 'icon' => 'wi-rain-wind.svg'],
    'snow' => ['name' => 'Schnee', 'icon' => 'wi-snow.svg'],
    'fog' => ['name' => 'Nebel', 'icon' => 'wi-fog.svg'],
    'clearing' => [
        'name' => 'Aufklarend',
        'icon' => 'wi-day-sunny-overcast.svg',
    ],
];

$weatherState = $station['weather_state'] ?? 'sunny';

$weatherInfo = $weatherStateMap[$weatherState] ?? [
    'name' => 'Unbekannt',
    'icon' => 'wi-na.svg',
];

$weatherIconUrl = $iconBaseUrl . $weatherInfo['icon'];

/*
|--------------------------------------------------------------------------
| Forecast
|--------------------------------------------------------------------------
*/

$forecastResponse = @file_get_contents(
    $apiBaseUrl . '/forecast/' . $station['id'] . '?days=1',
    false,
    $context
);

$todayForecast = null;

if ($forecastResponse) {
    $forecastJson = json_decode($forecastResponse, true);
    if (
        isset($forecastJson['success']) &&
        $forecastJson['success'] &&
        !empty($forecastJson['data'])
    ) {
        foreach ($forecastJson['data'] as $forecast) {
            if (($forecast['forecast_date'] ?? null) === $todayDate) {
                $todayForecast = $forecast;
                break;
            }
        }

        if (!$todayForecast) {
            $todayForecast = $forecastJson['data'][0];
        }
    }
}

$temp =
    $station['temperature'] ??
    ($todayForecast
        ? ($todayForecast['temp_high'] + $todayForecast['temp_low']) / 2
        : 0);

$windDirection = $station['wind_direction'] ?? 0;

if ($windDirection >= 337.5 || $windDirection < 22.5) {
    $windDirName = 'Nördlich';
} elseif ($windDirection < 67.5) {
    $windDirName = 'Nordöstlich';
} elseif ($windDirection < 112.5) {
    $windDirName = 'Östlich';
} elseif ($windDirection < 157.5) {
    $windDirName = 'Südöstlich';
} elseif ($windDirection < 202.5) {
    $windDirName = 'Südlich';
} elseif ($windDirection < 247.5) {
    $windDirName = 'Südwestlich';
} elseif ($windDirection < 292.5) {
    $windDirName = 'Westlich';
} else {
    $windDirName = 'Nordwestlich';
}

$currentWeather = [
    'wetter' => $weatherInfo['name'],
    'temperatur' => number_format($temp, 1, ',', ''),
    'niederschlag_wahrscheinlichkeit' => $todayForecast
        ? $todayForecast['precipitation_probability']
        : 0,
    'wind' => $windDirName,
    'updated' => $station['weather_timestamp'] ?? date('Y-m-d H:i:s'),
];
?>
<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Eulenwetter Widget</title>

        <style>
            body {
                margin: 0;
                padding: 0;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                height: 200px;
                background: transparent;
                color: #2c3e50;
                overflow: hidden;
            }

            .widget-container {
                height: 100%;
                display: flex;
                flex-direction: column;
                box-sizing: border-box;
            }

            .header {
                text-align: center;
            }

            .header-logo {
                width: 100%;
                height: 44px;
                object-fit: contain;
                display: block;
                margin: 0 auto 6px auto;
            }

            .region-select {
                margin-top: 6px;
                font-size: 10px;
                padding: 4px 8px;
                width: 100%;
                max-width: 220px;
            }

            .current-weather {
                flex: 1;
                text-align: center;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }

            .weather-icon {
                width: 64px;
                height: 64px;
                margin: 0 auto -10px auto;
                padding-top: 10px;

                background-color: #bd3e3e;

                -webkit-mask-image: var(--icon-url);
                -webkit-mask-repeat: no-repeat;
                -webkit-mask-position: center;
                -webkit-mask-size: contain;

                mask-image: var(--icon-url);
                mask-repeat: no-repeat;
                mask-position: center;
                mask-size: contain;
            }

            .temperature {
                font-size: 28px;
                font-weight: 300;
                margin: 0;
            }

            .weather-info {
                font-size: 12px;
                margin-top: 5px;
            }

            .updated {
                font-size: 9px;
                opacity: 0.7;
                text-align: center;
                margin-top: 2px;
            }
        </style>
    </head>

    <body>
        <div class="widget-container">
            <div class="header">
                <img
                    class="header-logo"
                    src="<?= htmlspecialchars($headerSvgUrl) ?>"
                    alt="Nationale Wetterprognose"
                >

                <form method="GET">
                    <select
                        name="station"
                        class="region-select"
                        onchange="this.form.submit()"
                    >
                        <?php foreach ($stationsByRegion as $region => $stations): ?>
                            <optgroup label="<?= htmlspecialchars($region) ?>">
                                <?php foreach ($stations as $s): ?>
                                    <option
                                        value="<?= htmlspecialchars((string) $s['id']) ?>"
                                        <?= (string) $s['id'] === (string) $selectedStationId
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars($s['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="current-weather">
                <div
                    class="weather-icon"
                    style="--icon-url: url('<?= htmlspecialchars($weatherIconUrl) ?>');"
                    role="img"
                    aria-label="<?= htmlspecialchars($currentWeather['wetter']) ?>"
                ></div>

                <h2 class="temperature">
                    <?= htmlspecialchars($currentWeather['temperatur']) ?>°
                </h2>

                <div class="weather-info">
                    <?= (int) $currentWeather['niederschlag_wahrscheinlichkeit'] ?>%
                    Regen •
                    <?= htmlspecialchars($currentWeather['wind']) ?>er Wind
                </div>
            </div>

            <div class="updated">
                Aktualisiert:
                <?= date('H:i', strtotime($currentWeather['updated'])) ?>
            </div>
        </div>
    </body>
</html>