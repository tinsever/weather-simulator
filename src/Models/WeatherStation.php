<?php

namespace Models;

use Config\Database;
use PDO;

class WeatherStation
{
    public static function getAll(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query('
            SELECT ws.*, r.name as region_name 
            FROM weather_stations ws
            LEFT JOIN regions r ON ws.region_id = r.id
            WHERE ws.is_active = 1
            ORDER BY ws.name
        ');
        return $stmt->fetchAll();
    }

    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT ws.*, r.name as region_name,
                   r.elevation as region_elevation,
                   r.land_usage, r.hydrology, r.topography,
                   r.temperature_modifier as region_temp_mod,
                   r.precipitation_modifier as region_precip_mod,
                   r.wind_exposure as region_wind_exp
            FROM weather_stations ws
            LEFT JOIN regions r ON ws.region_id = r.id
            WHERE ws.id = ?
        ');
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function getAllWithCurrentWeather(?string $timestamp = null): array
    {
        $db = Database::getInstance();
        $timestamp = $timestamp ?? date('Y-m-d H:i:s');
        
        $stmt = $db->prepare('
            SELECT ws.*, r.name as region_name,
                   wd.temperature, wd.temperature_feels_like,
                   wd.humidity, wd.precipitation, wd.wind_speed,
                   wd.wind_direction, wd.pressure, wd.cloud_cover,
                   wd.visibility, wd.weather_state
            FROM weather_stations ws
            LEFT JOIN regions r ON ws.region_id = r.id
            LEFT JOIN weather_data wd ON wd.station_id = ws.id 
                AND wd.timestamp = (
                    SELECT MAX(timestamp) FROM weather_data 
                    WHERE station_id = ws.id AND timestamp <= ?
                )
            WHERE ws.is_active = 1
            ORDER BY ws.name
        ');
        $stmt->execute([$timestamp]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO weather_stations (
                region_id, name, x_coord, y_coord, elevation, station_type, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, 1)
        ');
        $stmt->execute([
            $data['region_id'],
            $data['name'],
            $data['x_coord'],
            $data['y_coord'],
            $data['elevation'] ?? 450,
            $data['station_type'] ?? 'standard',
        ]);
        return (int) $db->lastInsertId();
    }
}
