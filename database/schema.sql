-- Eulenmeteo Database Schema
-- Weather simulation engine for a fictional country based on Liechtenstein

-- Regions table: stores geographical data for each region
CREATE TABLE IF NOT EXISTS regions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    -- Bounding box coordinates (pixel coordinates on the map)
    min_x INTEGER NOT NULL,
    min_y INTEGER NOT NULL,
    max_x INTEGER NOT NULL,
    max_y INTEGER NOT NULL,
    -- Center coordinates
    center_x INTEGER NOT NULL,
    center_y INTEGER NOT NULL,
    -- Geographical characteristics
    elevation INTEGER NOT NULL DEFAULT 450, -- meters above sea level
    land_usage TEXT NOT NULL DEFAULT 'mixed', -- agricultural, urban, forest, alpine, mixed
    hydrology TEXT NOT NULL DEFAULT 'normal', -- river_proximity, lake_proximity, wetland, dry, normal
    topography TEXT NOT NULL DEFAULT 'valley', -- valley, hillside, mountain, plateau, peak
    -- Climate modifiers
    temperature_modifier REAL NOT NULL DEFAULT 0.0, -- additional temperature offset
    precipitation_modifier REAL NOT NULL DEFAULT 1.0, -- multiplier for precipitation
    wind_exposure REAL NOT NULL DEFAULT 1.0, -- wind exposure factor (0.5 = sheltered, 1.5 = exposed)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Weather stations table: stores station locations and metadata
CREATE TABLE IF NOT EXISTS weather_stations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    region_id INTEGER NOT NULL,
    name TEXT NOT NULL UNIQUE,
    -- Pixel coordinates on the map
    x_coord INTEGER NOT NULL,
    y_coord INTEGER NOT NULL,
    -- Station elevation (may differ from region average)
    elevation INTEGER NOT NULL DEFAULT 450,
    -- Station type
    station_type TEXT NOT NULL DEFAULT 'standard', -- standard, alpine, valley, urban
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id)
);

-- Weather data table: stores historical and current weather records
CREATE TABLE IF NOT EXISTS weather_data (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    timestamp DATETIME NOT NULL,
    -- Temperature (°C)
    temperature REAL NOT NULL,
    temperature_feels_like REAL,
    -- Precipitation (mm)
    precipitation REAL NOT NULL DEFAULT 0,
    precipitation_type TEXT DEFAULT 'none', -- none, rain, snow, sleet, hail
    -- Humidity (%)
    humidity INTEGER NOT NULL,
    -- Wind
    wind_speed REAL NOT NULL DEFAULT 0, -- km/h
    wind_direction INTEGER DEFAULT 0, -- degrees (0-359)
    wind_gusts REAL DEFAULT 0, -- km/h
    -- Pressure (hPa)
    pressure REAL NOT NULL DEFAULT 1013.25,
    -- Cloud cover (%)
    cloud_cover INTEGER NOT NULL DEFAULT 0,
    -- Visibility (km)
    visibility REAL NOT NULL DEFAULT 10,
    -- UV index (0-11+)
    uv_index REAL NOT NULL DEFAULT 0,
    -- Weather state for state machine
    weather_state TEXT NOT NULL DEFAULT 'sunny', -- sunny, partly_cloudy, cloudy, light_rain, moderate_rain, heavy_rain, clearing, snow, fog
    -- Metadata
    is_generated INTEGER NOT NULL DEFAULT 1, -- 1 = simulated, 0 = manual entry
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (station_id) REFERENCES weather_stations(id)
);

-- Topographic features table: special features that affect local weather
CREATE TABLE IF NOT EXISTS topographic_features (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    region_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    feature_type TEXT NOT NULL, -- peak, twin_peaks, valley, gorge, lake, river, glacier
    -- Center coordinates (pixels)
    x_coord INTEGER NOT NULL,
    y_coord INTEGER NOT NULL,
    -- Feature characteristics
    elevation INTEGER, -- meters (for peaks)
    influence_radius INTEGER NOT NULL DEFAULT 50, -- pixels
    -- Weather effects
    temperature_effect REAL DEFAULT 0, -- temperature modifier within influence
    precipitation_effect REAL DEFAULT 1.0, -- precipitation multiplier within influence
    wind_effect REAL DEFAULT 1.0, -- wind speed multiplier
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id)
);

-- Weather state history: tracks weather state transitions for the state machine
CREATE TABLE IF NOT EXISTS weather_state_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    timestamp DATETIME NOT NULL,
    previous_state TEXT NOT NULL,
    new_state TEXT NOT NULL,
    transition_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (station_id) REFERENCES weather_stations(id)
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_weather_data_station_timestamp ON weather_data(station_id, timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_weather_data_timestamp ON weather_data(timestamp DESC);
CREATE INDEX IF NOT EXISTS idx_weather_stations_region ON weather_stations(region_id);
CREATE INDEX IF NOT EXISTS idx_topographic_features_region ON topographic_features(region_id);
CREATE INDEX IF NOT EXISTS idx_weather_state_history_station ON weather_state_history(station_id, timestamp DESC);
