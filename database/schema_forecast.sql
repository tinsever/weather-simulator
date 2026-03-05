-- Forecast table for 7-day weather predictions
CREATE TABLE IF NOT EXISTS weather_forecast (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station_id INTEGER NOT NULL,
    forecast_date DATE NOT NULL,
    generated_at DATETIME NOT NULL,
    -- Temperature predictions
    temp_high REAL NOT NULL,
    temp_low REAL NOT NULL,
    -- Weather conditions
    weather_state TEXT NOT NULL,
    precipitation_probability INTEGER NOT NULL DEFAULT 0, -- 0-100%
    precipitation_amount REAL NOT NULL DEFAULT 0, -- expected mm
    -- Other conditions
    humidity_avg INTEGER NOT NULL,
    wind_speed_avg REAL NOT NULL,
    cloud_cover_avg INTEGER NOT NULL,
    -- Metadata
    confidence INTEGER NOT NULL DEFAULT 100, -- decreases for further dates
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (station_id) REFERENCES weather_stations(id),
    UNIQUE(station_id, forecast_date)
);

CREATE INDEX IF NOT EXISTS idx_forecast_station_date ON weather_forecast(station_id, forecast_date);

