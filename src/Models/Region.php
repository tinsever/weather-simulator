<?php

namespace Models;

use Config\Database;
use PDO;

class Region
{
    public static function getAll(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query('SELECT * FROM regions ORDER BY name');
        return $stmt->fetchAll();
    }

    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM regions WHERE id = ?');
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function getStations(int $regionId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM weather_stations WHERE region_id = ? AND is_active = 1 ORDER BY name');
        $stmt->execute([$regionId]);
        return $stmt->fetchAll();
    }

    public static function getFeatures(int $regionId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM topographic_features WHERE region_id = ?');
        $stmt->execute([$regionId]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO regions (
                name, description, min_x, min_y, max_x, max_y,
                center_x, center_y, elevation, land_usage, hydrology,
                topography, temperature_modifier, precipitation_modifier, wind_exposure
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['min_x'],
            $data['min_y'],
            $data['max_x'],
            $data['max_y'],
            $data['center_x'],
            $data['center_y'],
            $data['elevation'] ?? 450,
            $data['land_usage'] ?? 'mixed',
            $data['hydrology'] ?? 'normal',
            $data['topography'] ?? 'valley',
            $data['temperature_modifier'] ?? 0.0,
            $data['precipitation_modifier'] ?? 1.0,
            $data['wind_exposure'] ?? 1.0,
        ]);
        return (int) $db->lastInsertId();
    }
}
