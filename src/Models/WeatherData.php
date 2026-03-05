<?php

namespace Models;

use Config\Database;
use PDO;

class WeatherData
{
    public static function getLatest(int $stationId): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT * FROM weather_data 
            WHERE station_id = ? 
            ORDER BY timestamp DESC 
            LIMIT 1
        ');
        $stmt->execute([$stationId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function getLastBefore(int $stationId, string $timestamp): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT * FROM weather_data 
            WHERE station_id = ? AND timestamp < ? 
            ORDER BY timestamp DESC 
            LIMIT 1
        ');
        $stmt->execute([$stationId, $timestamp]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function getPrevious(int $stationId, int $hours = 3): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT * FROM weather_data 
            WHERE station_id = ? 
            ORDER BY timestamp DESC 
            LIMIT ?
        ');
        $stmt->execute([$stationId, $hours]);
        return $stmt->fetchAll();
    }

    public static function getHistory(int $stationId, int $hours = 24): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT * FROM weather_data 
            WHERE station_id = ? 
            AND timestamp >= datetime("now", ?)
            ORDER BY timestamp DESC
        ');
        $stmt->execute([$stationId, "-{$hours} hours"]);
        return $stmt->fetchAll();
    }

    public static function getByDateRange(int $stationId, string $startDate, string $endDate): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT * FROM weather_data 
            WHERE station_id = ? 
            AND timestamp >= ? AND timestamp <= ?
            ORDER BY timestamp ASC
        ');
        $stmt->execute([$stationId, $startDate, $endDate]);
        return $stmt->fetchAll();
    }

    public static function getDayHours(int $stationId, string $date): array
    {
        $db = Database::getInstance();
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';
        
        $stmt = $db->prepare('
            SELECT * FROM weather_data 
            WHERE station_id = ? 
            AND timestamp >= ? AND timestamp <= ?
            ORDER BY timestamp ASC
        ');
        $stmt->execute([$stationId, $start, $end]);
        return $stmt->fetchAll();
    }

    public static function existsForTimestamp(int $stationId, string $timestamp): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM weather_data 
            WHERE station_id = ? AND timestamp = ?
        ');
        $stmt->execute([$stationId, $timestamp]);
        return $stmt->fetchColumn() > 0;
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO weather_data (
                station_id, timestamp, temperature, temperature_feels_like,
                precipitation, precipitation_type, humidity, wind_speed,
                wind_direction, wind_gusts, pressure, cloud_cover, visibility,
                uv_index, weather_state, is_generated
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['station_id'],
            $data['timestamp'],
            $data['temperature'],
            $data['temperature_feels_like'] ?? null,
            $data['precipitation'] ?? 0,
            $data['precipitation_type'] ?? 'none',
            $data['humidity'],
            $data['wind_speed'] ?? 0,
            $data['wind_direction'] ?? 0,
            $data['wind_gusts'] ?? 0,
            $data['pressure'] ?? 1013.25,
            $data['cloud_cover'] ?? 0,
            $data['visibility'] ?? 10,
            $data['uv_index'] ?? 0,
            $data['weather_state'],
            $data['is_generated'] ?? 1,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function deleteByDateRange(int $stationId, string $startDate, string $endDate): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            DELETE FROM weather_data 
            WHERE station_id = ? 
            AND timestamp >= ? AND timestamp <= ?
        ');
        $stmt->execute([$stationId, $startDate, $endDate]);
        return $stmt->rowCount();
    }

    public static function recordStateTransition(
        int $stationId,
        string $previousState,
        string $newState,
        string $reason = 'automatic_transition'
    ): void {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO weather_state_history (
                station_id, timestamp, previous_state, new_state, transition_reason
            ) VALUES (?, datetime("now"), ?, ?, ?)
        ');
        $stmt->execute([$stationId, $previousState, $newState, $reason]);
    }

    public static function getDailyStatistics(int $stationId, ?string $date = null): array
    {
        $db = Database::getInstance();
        $date = $date ?? date('Y-m-d');
        
        $stmt = $db->prepare('
            SELECT 
                DATE(timestamp) as date,
                MAX(temperature) as temp_high,
                MIN(temperature) as temp_low,
                AVG(temperature) as temp_avg,
                SUM(precipitation) as total_precipitation,
                AVG(humidity) as avg_humidity,
                AVG(wind_speed) as avg_wind_speed
            FROM weather_data
            WHERE station_id = ? AND DATE(timestamp) = ?
            GROUP BY DATE(timestamp)
        ');
        $stmt->execute([$stationId, $date]);
        return $stmt->fetch() ?: [];
    }

    public static function getCurrentAll(string $timestamp): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT wd.*, ws.name as station_name
            FROM weather_data wd
            JOIN weather_stations ws ON wd.station_id = ws.id
            WHERE wd.timestamp = (
                SELECT MAX(timestamp) FROM weather_data wd2 
                WHERE wd2.station_id = wd.station_id AND wd2.timestamp <= ?
            )
            ORDER BY ws.name
        ');
        $stmt->execute([$timestamp]);
        return $stmt->fetchAll();
    }
}
