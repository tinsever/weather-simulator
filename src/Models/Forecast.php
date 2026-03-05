<?php

namespace Models;

use Config\Database;
use PDO;

class Forecast
{
    public static function getByStation(int $stationId, int $days = 7): array
    {
        $db = Database::getInstance();
        $today = date('Y-m-d');
        
        $stmt = $db->prepare('
            SELECT * FROM weather_forecast
            WHERE station_id = ? AND forecast_date >= ?
            ORDER BY forecast_date ASC
            LIMIT ?
        ');
        $stmt->execute([$stationId, $today, $days]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO weather_forecast (
                station_id, forecast_date, generated_at, temp_high, temp_low,
                weather_state, precipitation_probability, precipitation_amount,
                humidity_avg, wind_speed_avg, cloud_cover_avg, confidence
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['station_id'],
            $data['forecast_date'],
            $data['generated_at'],
            $data['temp_high'],
            $data['temp_low'],
            $data['weather_state'],
            $data['precipitation_probability'] ?? 0,
            $data['precipitation_amount'] ?? 0,
            $data['humidity_avg'] ?? 60,
            $data['wind_speed_avg'] ?? 10,
            $data['cloud_cover_avg'] ?? 30,
            $data['confidence'] ?? 80,
        ]);
        return (int) $db->lastInsertId();
    }

    public static function update(int $stationId, string $date, array $data): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            UPDATE weather_forecast SET
                generated_at = ?, temp_high = ?, temp_low = ?,
                weather_state = ?, precipitation_probability = ?,
                precipitation_amount = ?, humidity_avg = ?,
                wind_speed_avg = ?, cloud_cover_avg = ?, confidence = ?
            WHERE station_id = ? AND forecast_date = ?
        ');
        return $stmt->execute([
            $data['generated_at'],
            $data['temp_high'],
            $data['temp_low'],
            $data['weather_state'],
            $data['precipitation_probability'],
            $data['precipitation_amount'],
            $data['humidity_avg'],
            $data['wind_speed_avg'],
            $data['cloud_cover_avg'],
            $data['confidence'],
            $stationId,
            $date,
        ]);
    }

    public static function deleteOld(int $stationId, string $beforeDate): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            DELETE FROM weather_forecast 
            WHERE station_id = ? AND forecast_date < ?
        ');
        $stmt->execute([$stationId, $beforeDate]);
        return $stmt->rowCount();
    }

    public static function exists(int $stationId, string $date): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM weather_forecast 
            WHERE station_id = ? AND forecast_date = ?
        ');
        $stmt->execute([$stationId, $date]);
        return $stmt->fetchColumn() > 0;
    }
}
