<?php

namespace Engine;

use Models\WeatherData;
use Models\WeatherStation;

class WarningGenerator
{
    private const WARNING_LEVELS = [
        1 => ['name' => 'Vorabinformation', 'color' => '#3498db', 'icon' => 'ℹ️'],
        2 => ['name' => 'Achtung', 'color' => '#f1c40f', 'icon' => '⚠️'],
        3 => ['name' => 'Warnung', 'color' => '#e67e22', 'icon' => '🔶'],
        4 => ['name' => 'Unwetterwarnung', 'color' => '#e74c3c', 'icon' => '🔴'],
    ];

    private const WARNING_TYPES = [
        'heavy_rain' => [
            'threshold' => ['intensity' => 5, 'duration' => 3],
            'level' => 3,
            'titles' => [
                'Starke Regenfälle erwartet',
                'Regenwarnung für {region}',
                'Erhebliche Niederschläge',
            ],
            'texts' => [
                'In den nächsten Stunden sind starke Regenfälle mit Niederschlagsmengen bis zu {amount} mm erwartet. Dies kann zu lokalen Überschwemmungen führen.',
                'Der Wetterdienst erwartet intensive Niederschläge in {region}. Die Bevölkerung wird gebeten, Keller und tiefliegende Bereiche zu kontrollieren.',
                'Schwere Regenfälle mit bis zu {amount} mm in kurzer Zeit werden erwartet. Vorsicht vor Sturzfluten in Tälern und Schluchten.',
            ],
        ],
        'storm' => [
            'threshold' => ['wind_speed' => 60],
            'level' => 3,
            'titles' => [
                'Sturmwarnung für {region}',
                'Orkanartige Böen erwartet',
                'Stürmische Winde angekündigt',
            ],
            'texts' => [
                'Windgeschwindigkeiten bis zu {wind_speed} km/h werden erwartet. Sicherung von losen Gegenständen wird empfohlen.',
                'Sturmböen mit Spitzen bis {wind_speed} km/h. Wanderungen im Hochgebirge sollten vermieden werden.',
                'Der Wetterdienst warnt vor starken Windböen. In exponierten Lagen sind orkanartige Verhältnisse möglich.',
            ],
        ],
        'snow' => [
            'threshold' => ['temperature' => 0, 'precipitation' => 2],
            'level' => 2,
            'titles' => [
                'Schneefall erwartet',
                'Winterliche Bedingungen in {region}',
                'Schneewarnung ausgegeben',
            ],
            'texts' => [
                'Neuschnee von {amount} cm wird erwartet. Die Straßenverhältnisse können sich rasch verschlechtern.',
                'Winterliche Niederschläge in {region}. Reifen sollten auf Winterbereifung geprüft werden.',
                'Erheblicher Schneefall erwartet. In höheren Lagen können Verkehrsbehinderungen auftreten.',
            ],
        ],
        'frost' => [
            'threshold' => ['temperature' => -5],
            'level' => 2,
            'titles' => [
                'Frostwarnung',
                'Starke Kälte erwartet',
                'Glättegefahr in {region}',
            ],
            'texts' => [
                'Temperaturen bis zu {temp}°C werden erwartet. Glättebildung auf Straßen und Wegen ist möglich.',
                'Der Wetterdienst warnt vor Frostschäden an Pflanzen und Wasserleitungen.',
                'Kalte Nacht mit Tiefsttemperaturen um {temp}°C. Vorsicht bei Straßenquerungen.',
            ],
        ],
        'heat' => [
            'threshold' => ['temperature' => 30],
            'level' => 2,
            'titles' => [
                'Hitzewarnung',
                'Starke Hitze erwartet',
                'Hohes UV-Strahlung in {region}',
            ],
            'texts' => [
                'Temperaturen über {temp}°C werden erwartet. Ausreichende Flüssigkeitszufuhr wird empfohlen.',
                'Der Wetterdienst warnt vor hoher UV-Belastung. Sonnenschutz ist unerlässlich.',
                'Heißer Tag mit Spitzentemperaturen um {temp}°C. Körperliche Anstrengung im Freien vermeiden.',
            ],
        ],
        'fog' => [
            'threshold' => ['visibility' => 0.5],
            'level' => 1,
            'titles' => [
                'Nebelwarnung',
                'Sichtweite eingeschränkt',
                'Dichter Nebel in {region}',
            ],
            'texts' => [
                'Sichtweiten unter {visibility} m durch dichten Nebel. Vorsicht im Straßenverkehr.',
                'Der Wetterdienst informiert über neblige Verhältnisse in Tallagen.',
                'Eingeschränkte Sicht durch Nebelbildung. Flugverkehr kann beeinträchtigt sein.',
            ],
        ],
    ];

    public function generateWarnings(): array
    {
        $warnings = [];
        $stations = WeatherStation::getAllWithCurrentWeather();
        
        $regionalData = $this->aggregateRegionalWeather($stations);
        
        foreach ($regionalData as $region => $data) {
            $regionWarnings = $this->analyzeWeatherConditions($region, $data);
            $warnings = array_merge($warnings, $regionWarnings);
        }
        
        usort($warnings, fn($a, $b) => $b['level'] <=> $a['level']);
        
        return $warnings;
    }

    private function aggregateRegionalWeather(array $stations): array
    {
        $regional = [];
        
        foreach ($stations as $station) {
            $region = $station['region_name'] ?? 'Eulenthal';
            
            if (!isset($regional[$region])) {
                $regional[$region] = [
                    'stations' => [],
                    'max_temp' => -100,
                    'min_temp' => 100,
                    'max_wind' => 0,
                    'total_precip' => 0,
                    'min_visibility' => 100,
                    'weather_states' => [],
                ];
            }
            
            $regional[$region]['stations'][] = $station;
            $regional[$region]['max_temp'] = max($regional[$region]['max_temp'], $station['temperature'] ?? -100);
            $regional[$region]['min_temp'] = min($regional[$region]['min_temp'], $station['temperature'] ?? 100);
            $regional[$region]['max_wind'] = max($regional[$region]['max_wind'], $station['wind_speed'] ?? 0);
            $regional[$region]['total_precip'] += $station['precipitation'] ?? 0;
            $regional[$region]['min_visibility'] = min($regional[$region]['min_visibility'], $station['visibility'] ?? 100);
            
            $state = $station['weather_state'] ?? 'sunny';
            $regional[$region]['weather_states'][$state] = ($regional[$region]['weather_states'][$state] ?? 0) + 1;
        }
        
        return $regional;
    }

    private function analyzeWeatherConditions(string $region, array $data): array
    {
        $warnings = [];
        $seed = crc32($region . date('Y-m-d-H'));
        
        if ($data['max_temp'] >= 30) {
            $warnings[] = $this->createWarning(
                'heat',
                $region,
                $data,
                $data['max_temp'] >= 35 ? 4 : ($data['max_temp'] >= 32 ? 3 : 2),
                ['temp' => round($data['max_temp'])],
                $seed
            );
        }
        
        if ($data['min_temp'] <= -5) {
            $warnings[] = $this->createWarning(
                'frost',
                $region,
                $data,
                $data['min_temp'] <= -15 ? 4 : ($data['min_temp'] <= -10 ? 3 : 2),
                ['temp' => round($data['min_temp'])],
                $seed + 1
            );
        }
        
        if ($data['max_wind'] >= 50) {
            $warnings[] = $this->createWarning(
                'storm',
                $region,
                $data,
                $data['max_wind'] >= 80 ? 4 : ($data['max_wind'] >= 65 ? 3 : 2),
                ['wind_speed' => round($data['max_wind'])],
                $seed + 2
            );
        }
        
        if ($data['total_precip'] >= 5) {
            $warnings[] = $this->createWarning(
                'heavy_rain',
                $region,
                $data,
                $data['total_precip'] >= 15 ? 4 : ($data['total_precip'] >= 10 ? 3 : 2),
                ['amount' => round($data['total_precip'], 1)],
                $seed + 3
            );
        }
        
        if ($data['min_visibility'] <= 0.5) {
            $warnings[] = $this->createWarning(
                'fog',
                $region,
                $data,
                1,
                ['visibility' => round($data['min_visibility'] * 1000)],
                $seed + 4
            );
        }
        
        if (isset($data['weather_states']['snow']) && $data['weather_states']['snow'] > 0) {
            $warnings[] = $this->createWarning(
                'snow',
                $region,
                $data,
                $data['total_precip'] >= 10 ? 3 : 2,
                ['amount' => round($data['total_precip'] / 2)],
                $seed + 5
            );
        }
        
        return $warnings;
    }

    private function createWarning(string $type, string $region, array $data, int $level, array $params, int $seed): array
    {
        $typeConfig = self::WARNING_TYPES[$type] ?? null;
        if (!$typeConfig) {
            return [];
        }
        
        $level = min($level, 4);
        $levelInfo = self::WARNING_LEVELS[$level];
        
        $titleIndex = $seed % count($typeConfig['titles']);
        $textIndex = ($seed + 1) % count($typeConfig['texts']);
        
        $title = str_replace('{region}', $region, $typeConfig['titles'][$titleIndex]);
        $text = $typeConfig['texts'][$textIndex];
        
        foreach ($params as $key => $value) {
            $text = str_replace('{' . $key . '}', $value, $text);
        }
        $text = str_replace('{region}', $region, $text);
        
        return [
            'id' => md5($region . $type . date('Y-m-d-H')),
            'type' => $type,
            'level' => $level,
            'level_name' => $levelInfo['name'],
            'color' => $levelInfo['color'],
            'icon' => $levelInfo['icon'],
            'region' => $region,
            'title' => $title,
            'description' => $text,
            'issued' => date('c'),
            'expires' => date('c', strtotime('+6 hours')),
            'effective_from' => date('H:i'),
            'affected_stations' => count($data['stations']),
        ];
    }

    /*public function generateRSS(): string
    {
        $warnings = $this->generateWarnings();
        
        $rss = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $rss .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $rss .= '  <channel>' . "\n";
        $rss .= '    <title>Wetterwarnungen - Wetterdienst Eulenthal</title>' . "\n";
        $rss .= '    <link>https://eulenmeteo.mn-netz.de/</link>' . "\n";
        $rss .= '    <description>Aktuelle Wetterwarnungen für das Fürstentum Eulenthal</description>' . "\n";
        $rss .= '    <language>de-DE</language>' . "\n";
        $rss .= '    <lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";
        $rss .= '    <atom:link href="https://eulenmeteo.mn-netz.de/api/warnings/rss" rel="self" type="application/rss+xml" />' . "\n";
        
        if (empty($warnings)) {
            $rss .= '    <item>' . "\n";
            $rss .= '      <title>Keine aktuellen Warnungen</title>' . "\n";
            $rss .= '      <description>Es sind aktuell keine Wetterwarnungen für das Fürstentum Eulenthal aktiv.</description>' . "\n";
            $rss .= '      <guid isPermaLink="false">no-warnings-' . date('Y-m-d') . '</guid>' . "\n";
            $rss .= '      <pubDate>' . date('r') . '</pubDate>' . "\n";
            $rss .= '    </item>' . "\n";
        } else {
            foreach ($warnings as $warning) {
                $rss .= '    <item>' . "\n";
                $rss .= '      <title>' . $warning['icon'] . ' [' . $warning['level_name'] . '] ' . htmlspecialchars($warning['title']) . '</title>' . "\n";
                $rss .= '      <description>' . htmlspecialchars($warning['description']) . '</description>' . "\n";
                $rss .= '      <category>' . htmlspecialchars($warning['region']) . '</category>' . "\n";
                $rss .= '      <guid isPermaLink="false">' . $warning['id'] . '</guid>' . "\n";
                $rss .= '      <pubDate>' . date('r', strtotime($warning['issued'])) . '</pubDate>' . "\n";
                $rss .= '      <author>Wetterdienst Eulenthal</author>' . "\n";
                $rss .= '    </item>' . "\n";
            }
        }
        
        $rss .= '  </channel>' . "\n";
        $rss .= '</rss>';
        
        return $rss;
    }*/
  
  public function generateRSS(): string
{
    $warnings = $this->generateWarnings();
    $date = date('d.m.Y');

    $rss = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $rss .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    $rss .= '  <channel>' . "\n";
    $rss .= '    <title>Wetterwarnungen - Wetterdienst Eulenthal</title>' . "\n";
    $rss .= '    <link>https://eulenmeteo.mn-netz.de/</link>' . "\n";
    $rss .= '    <description>Aktuelle Wetterwarnungen für das Fürstentum Eulenthal</description>' . "\n";
    $rss .= '    <language>de-DE</language>' . "\n";
    $rss .= '    <lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";
    $rss .= '    <atom:link href="https://eulenmeteo.mn-netz.de/api/warnings/rss" rel="self" type="application/rss+xml" />' . "\n";

    $rss .= '    <item>' . "\n";

    if (empty($warnings)) {
        $rss .= '      <title>☀️ Warnlagebericht ' . $date . ' — Keine Warnungen</title>' . "\n";
        $rss .= '      <description>' . htmlspecialchars(
            '<p>Für das Fürstentum Eulenthal liegen derzeit keine Wetterwarnungen vor.</p>'
            . '<p><em>Nächste Aktualisierung: morgen früh.</em></p>'
        ) . '</description>' . "\n";
    } else {
        $maxLevel = max(array_column($warnings, 'level'));
        $count = count($warnings);
        $icon = self::WARNING_LEVELS[$maxLevel]['icon'] ?? '⚠️';

        $rss .= '      <title>' . $icon . ' Warnlagebericht ' . $date
            . ' — ' . $count . ' aktive Warnung' . ($count !== 1 ? 'en' : '')
            . '</title>' . "\n";

        $rss .= '      <description>' . htmlspecialchars(
            $this->buildBulletinHtml($warnings, $date)
        ) . '</description>' . "\n";
    }

    $rss .= '      <guid isPermaLink="false">warnlage-' . date('Y-m-d') . '</guid>' . "\n";
    $rss .= '      <pubDate>' . date('r') . '</pubDate>' . "\n";
    $rss .= '      <author>Wetterdienst Eulenthal</author>' . "\n";
    $rss .= '    </item>' . "\n";

    $rss .= '  </channel>' . "\n";
    $rss .= '</rss>';

    return $rss;
}

private function buildBulletinHtml(array $warnings, string $date): string
{
    $html = '<p style="text-align:center;">';
    $html .= '<span style="font-size:24px;"><strong>Wetterdienst Eulenthal</strong></span><br>';
    $html .= '<span style="font-size:16px;">Warnlagebericht vom ' . $date . '</span>';
    $html .= '</p>' . "\n";
    $html .= '<hr>' . "\n";

    $byRegion = [];
    foreach ($warnings as $w) {
        $byRegion[$w['region']][] = $w;
    }

    foreach ($byRegion as $region => $regionWarnings) {
        $html .= '<p><span style="font-size:20px;"><strong>📍 '
            . htmlspecialchars($region) . '</strong></span></p>' . "\n";

        foreach ($regionWarnings as $w) {
            $html .= '<p>';
            $html .= '<span style="font-size:16px;"><strong>'
                . $w['icon'] . ' ' . htmlspecialchars($w['title'])
                . '</strong></span><br>' . "\n";
            $html .= '<strong>Warnstufe:</strong> '
                . htmlspecialchars($w['level_name'])
                . ' (' . $w['level'] . '/4)<br>' . "\n";
            $html .= '<strong>Gültig:</strong> '
                . $w['effective_from'] . ' Uhr bis '
                . date('H:i', strtotime($w['expires'])) . ' Uhr<br>' . "\n";
            $html .= '<strong>Betroffene Stationen:</strong> '
                . $w['affected_stations'] . '<br><br>' . "\n";
            $html .= htmlspecialchars($w['description']);
            $html .= '</p>' . "\n";
            $html .= '<hr>' . "\n";
        }
    }

    $html .= '<p><span style="font-size:11px;"><em>Automatisch generiert '
        . 'vom Wetterdienst Eulenthal. Nächste Aktualisierung: '
        . 'morgen früh.</em></span></p>';

    return $html;
}

private function translateType(string $type): string
{
    return match ($type) {
        'heavy_rain' => 'Starkregen',
        'storm' => 'Sturm',
        'snow' => 'Schneefall',
        'frost' => 'Frost',
        'heat' => 'Hitze',
        'fog' => 'Nebel',
        default => ucfirst($type),
    };
}

    public function getWarningsJSON(): array
    {
        return $this->generateWarnings();
    }
}
