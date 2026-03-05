<?php

namespace Controllers;

use Config\Database;
use Models\Region;
use Models\WeatherStation;
use PDO;

class AdminController
{
    // ============================================
    // AUTH
    // ============================================

    public function loginPage(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!empty($_SESSION['isAdmin'])) {
            header('Location: /admin');
            exit;
        }
        readfile(__DIR__ . '/../../admin/login.html');
    }

    public function login(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $password = $input['password'] ?? '';
        $adminPassword = getenv('ADMIN_PASSWORD') ?: '';

        if ($adminPassword === '') {
            $this->json(['success' => false, 'error' => 'Admin password is not configured'], 503);
            return;
        }

        if (hash_equals($adminPassword, $password)) {
            $_SESSION['isAdmin'] = true;
            $_SESSION['loginTime'] = date('c');
            $this->json(['success' => true, 'message' => 'Login successful']);
        } else {
            $this->json(['success' => false, 'error' => 'Invalid password'], 401);
        }
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_destroy();
        $this->json(['success' => true, 'message' => 'Logged out successfully']);
    }

    public function checkAuth(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $this->json([
            'success' => true,
            'authenticated' => !empty($_SESSION['isAdmin']),
            'loginTime' => $_SESSION['loginTime'] ?? null,
        ]);
    }

    public function dashboard(): void
    {
        $this->requireAuth();
        readfile(__DIR__ . '/../../admin/index.html');
    }

    // ============================================
    // STATS
    // ============================================

    public function stats(): void
    {
        $this->requireAuth();
        $db = Database::getInstance();

        $regions = $db->query('SELECT COUNT(*) as count FROM regions')->fetch()['count'];
        $stations = $db->query('SELECT COUNT(*) as count FROM weather_stations')->fetch()['count'];
        $features = $db->query('SELECT COUNT(*) as count FROM topographic_features')->fetch()['count'];
        $weatherRecords = $db->query('SELECT COUNT(*) as count FROM weather_data')->fetch()['count'];

        $this->json([
            'success' => true,
            'data' => [
                'regions' => (int) $regions,
                'stations' => (int) $stations,
                'features' => (int) $features,
                'weatherRecords' => (int) $weatherRecords,
            ],
        ]);
    }

    // ============================================
    // OPTIONS
    // ============================================

    public function options(): void
    {
        $this->requireAuth();
        $this->json([
            'success' => true,
            'data' => [
                'land_usage' => ['agricultural', 'urban', 'forest', 'alpine', 'mixed'],
                'hydrology' => ['river_proximity', 'lake_proximity', 'wetland', 'glacier_fed', 'dry', 'normal'],
                'topography' => ['valley', 'hillside', 'mountain', 'plateau', 'peak'],
                'station_type' => ['standard', 'alpine', 'valley', 'urban'],
                'feature_type' => ['peak', 'twin_peaks', 'valley', 'gorge', 'lake', 'river', 'glacier'],
            ],
        ]);
    }

    // ============================================
    // REGIONS CRUD
    // ============================================

    public function listRegions(): void
    {
        $this->requireAuth();
        $this->json(['success' => true, 'data' => Region::getAll()]);
    }

    public function getRegion(int $id): void
    {
        $this->requireAuth();
        $region = Region::getById($id);
        if (!$region) {
            $this->json(['success' => false, 'error' => 'Region not found'], 404);
            return;
        }
        $this->json(['success' => true, 'data' => $region]);
    }

    public function createRegion(): void
    {
        $this->requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (empty($data['name'])) {
            $this->json(['success' => false, 'error' => 'Name is required'], 400);
            return;
        }

        $id = Region::create($data);
        $region = Region::getById($id);
        $this->json(['success' => true, 'data' => $region], 201);
    }

    public function updateRegion(int $id): void
    {
        $this->requireAuth();
        $existing = Region::getById($id);
        if (!$existing) {
            $this->json(['success' => false, 'error' => 'Region not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $this->doUpdate('regions', $id, $data);
        $region = Region::getById($id);
        $this->json(['success' => true, 'data' => $region]);
    }

    public function deleteRegion(int $id): void
    {
        $this->requireAuth();
        $existing = Region::getById($id);
        if (!$existing) {
            $this->json(['success' => false, 'error' => 'Region not found'], 404);
            return;
        }

        $stations = Region::getStations($id);
        if (count($stations) > 0) {
            $this->json(['success' => false, 'error' => "Cannot delete region with " . count($stations) . " weather station(s). Delete stations first."], 400);
            return;
        }

        $features = Region::getFeatures($id);
        if (count($features) > 0) {
            $this->json(['success' => false, 'error' => "Cannot delete region with " . count($features) . " topographic feature(s). Delete features first."], 400);
            return;
        }

        $db = Database::getInstance();
        $db->prepare('DELETE FROM regions WHERE id = ?')->execute([$id]);
        $this->json(['success' => true, 'message' => 'Region deleted successfully']);
    }

    // ============================================
    // STATIONS CRUD
    // ============================================

    public function listStations(): void
    {
        $this->requireAuth();
        $db = Database::getInstance();
        $stations = $db->query('
            SELECT ws.*, r.name as region_name
            FROM weather_stations ws
            LEFT JOIN regions r ON ws.region_id = r.id
            ORDER BY ws.name
        ')->fetchAll();
        $this->json(['success' => true, 'data' => $stations]);
    }

    public function getStation(int $id): void
    {
        $this->requireAuth();
        $station = WeatherStation::getById($id);
        if (!$station) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }
        $this->json(['success' => true, 'data' => $station]);
    }

    public function createStation(): void
    {
        $this->requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (empty($data['name'])) {
            $this->json(['success' => false, 'error' => 'Name is required'], 400);
            return;
        }
        if (empty($data['region_id'])) {
            $this->json(['success' => false, 'error' => 'Region ID is required'], 400);
            return;
        }

        $region = Region::getById((int) $data['region_id']);
        if (!$region) {
            $this->json(['success' => false, 'error' => 'Region not found'], 400);
            return;
        }

        $id = WeatherStation::create($data);
        $station = WeatherStation::getById($id);
        $this->json(['success' => true, 'data' => $station], 201);
    }

    public function updateStation(int $id): void
    {
        $this->requireAuth();
        $existing = WeatherStation::getById($id);
        if (!$existing) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (!empty($data['region_id']) && (int) $data['region_id'] !== (int) $existing['region_id']) {
            $region = Region::getById((int) $data['region_id']);
            if (!$region) {
                $this->json(['success' => false, 'error' => 'Region not found'], 400);
                return;
            }
        }

        $this->doUpdate('weather_stations', $id, $data);
        $station = WeatherStation::getById($id);
        $this->json(['success' => true, 'data' => $station]);
    }

    public function deleteStation(int $id): void
    {
        $this->requireAuth();
        $existing = WeatherStation::getById($id);
        if (!$existing) {
            $this->json(['success' => false, 'error' => 'Station not found'], 404);
            return;
        }

        $db = Database::getInstance();
        $db->prepare('DELETE FROM weather_data WHERE station_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM weather_state_history WHERE station_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM weather_stations WHERE id = ?')->execute([$id]);
        $this->json(['success' => true, 'message' => 'Station deleted successfully']);
    }

    // ============================================
    // FEATURES CRUD
    // ============================================

    public function listFeatures(): void
    {
        $this->requireAuth();
        $db = Database::getInstance();
        $features = $db->query('
            SELECT tf.*, r.name as region_name
            FROM topographic_features tf
            JOIN regions r ON tf.region_id = r.id
            ORDER BY tf.name
        ')->fetchAll();
        $this->json(['success' => true, 'data' => $features]);
    }

    public function getFeature(int $id): void
    {
        $this->requireAuth();
        $db = Database::getInstance();
        $stmt = $db->prepare('
            SELECT tf.*, r.name as region_name
            FROM topographic_features tf
            JOIN regions r ON tf.region_id = r.id
            WHERE tf.id = ?
        ');
        $stmt->execute([$id]);
        $feature = $stmt->fetch();
        if (!$feature) {
            $this->json(['success' => false, 'error' => 'Feature not found'], 404);
            return;
        }
        $this->json(['success' => true, 'data' => $feature]);
    }

    public function createFeature(): void
    {
        $this->requireAuth();
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (empty($data['name'])) {
            $this->json(['success' => false, 'error' => 'Name is required'], 400);
            return;
        }
        if (empty($data['region_id'])) {
            $this->json(['success' => false, 'error' => 'Region ID is required'], 400);
            return;
        }
        if (empty($data['feature_type'])) {
            $this->json(['success' => false, 'error' => 'Feature type is required'], 400);
            return;
        }

        $region = Region::getById((int) $data['region_id']);
        if (!$region) {
            $this->json(['success' => false, 'error' => 'Region not found'], 400);
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare('
            INSERT INTO topographic_features (
                region_id, name, feature_type,
                x_coord, y_coord, elevation,
                influence_radius, temperature_effect,
                precipitation_effect, wind_effect, description
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $data['region_id'],
            $data['name'],
            $data['feature_type'],
            $data['x_coord'] ?? 0,
            $data['y_coord'] ?? 0,
            $data['elevation'] ?? null,
            $data['influence_radius'] ?? 50,
            $data['temperature_effect'] ?? 0,
            $data['precipitation_effect'] ?? 1.0,
            $data['wind_effect'] ?? 1.0,
            $data['description'] ?? null,
        ]);

        $id = (int) $db->lastInsertId();
        $stmt2 = $db->prepare('SELECT * FROM topographic_features WHERE id = ?');
        $stmt2->execute([$id]);
        $feature = $stmt2->fetch();
        $this->json(['success' => true, 'data' => $feature], 201);
    }

    public function updateFeature(int $id): void
    {
        $this->requireAuth();
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM topographic_features WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) {
            $this->json(['success' => false, 'error' => 'Feature not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if (!empty($data['region_id']) && (int) $data['region_id'] !== (int) $existing['region_id']) {
            $region = Region::getById((int) $data['region_id']);
            if (!$region) {
                $this->json(['success' => false, 'error' => 'Region not found'], 400);
                return;
            }
        }

        $this->doUpdate('topographic_features', $id, $data);
        $stmt2 = $db->prepare('SELECT * FROM topographic_features WHERE id = ?');
        $stmt2->execute([$id]);
        $feature = $stmt2->fetch();
        $this->json(['success' => true, 'data' => $feature]);
    }

    public function deleteFeature(int $id): void
    {
        $this->requireAuth();
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM topographic_features WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $this->json(['success' => false, 'error' => 'Feature not found'], 404);
            return;
        }

        $db->prepare('DELETE FROM topographic_features WHERE id = ?')->execute([$id]);
        $this->json(['success' => true, 'message' => 'Feature deleted successfully']);
    }

    // ============================================
    // Helpers
    // ============================================

    private function requireAuth(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['isAdmin'])) {
            $this->json(['success' => false, 'error' => 'Unauthorized. Please log in.'], 401);
            exit;
        }
    }

    private function doUpdate(string $table, int $id, array $data): void
    {
        $allowed = [
            'regions' => ['name','description','min_x','min_y','max_x','max_y','center_x','center_y','elevation','land_usage','hydrology','topography','temperature_modifier','precipitation_modifier','wind_exposure'],
            'weather_stations' => ['region_id','name','x_coord','y_coord','elevation','station_type','is_active'],
            'topographic_features' => ['region_id','name','feature_type','x_coord','y_coord','elevation','influence_radius','temperature_effect','precipitation_effect','wind_effect','description'],
        ];

        $fields = [];
        $values = [];
        foreach ($data as $key => $value) {
            if ($key !== 'id' && in_array($key, $allowed[$table] ?? [], true)) {
                $fields[] = "$key = ?";
                $values[] = $value;
            }
        }

        if (empty($fields)) return;

        $values[] = $id;
        $db = Database::getInstance();
        $db->prepare("UPDATE $table SET " . implode(', ', $fields) . " WHERE id = ?")->execute($values);
    }

    private function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
