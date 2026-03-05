# Eulenmeteo PHP Version

Weather simulation service for the fictional Principality of Eulenthal.

## Requirements

- PHP 8.1+
- SQLite3 extension
- Composer (for autoloading)

## Installation

```bash
composer install
```

## Configuration

1. Copy the example environment file:
```bash
cp .env.example .env
```

2. Set secure values in `.env` (or in Coolify environment variables):
- `ADMIN_PASSWORD` - required for `/admin/login`
- `CRON_SECRET` - required for `/cron.php`
- `SESSION_SECRET` - session secret
- `APP_DEBUG` - `false` in production
- `APP_TIMEZONE` - e.g. `Europe/Vaduz`
- `DB_PATH` - optional SQLite path override

## Database

The SQLite database is stored at `database/clauswetter.db`.

Initialize the database:
```bash
sqlite3 database/clauswetter.db < database/schema.sql
```

## Running

### Development (PHP built-in server)
```bash
php -S localhost:3000
```

### Production
Point your web server's document root to the project root.

Apache: The included `.htaccess` handles routing.
Nginx example:
```nginx
location /api {
    try_files $uri $uri/ /index.php?$query_string;
}
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Coolify + Nixpacks

This repo includes `nixpacks.toml`.

Build/install:
- `composer install --no-dev --optimize-autoloader --no-interaction`

Start command:
- `php -d variables_order=EGPCS scripts/bootstrap.php && php -d variables_order=EGPCS -S 0.0.0.0:${PORT:-8080} -t . index.php`

In Coolify, set at least:
- `ADMIN_PASSWORD`
- `CRON_SECRET`
- `SESSION_SECRET`

## Cron Job

Set up a daily cron job to generate weather data:
```bash
0 0 * * * curl "https://your-domain.com/cron.php?secret=YOUR_CRON_SECRET"
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/health | Health check |
| GET | /api/regions | List all regions |
| GET | /api/regions/{id} | Get region details |
| GET | /api/stations | List all stations |
| GET | /api/stations/with-weather | Stations with current weather |
| GET | /api/stations/{id} | Get station details |
| GET | /api/stations/{id}/weather | Current weather for station |
| GET | /api/stations/{id}/day-hours | Hourly data for a day |
| GET | /api/forecast/{stationId} | 7-day forecast |
| POST | /api/weather/generate | Generate weather now |
| POST | /api/weather/generate-7days | Generate 7 days of data |
| GET | /api/map/config | Map configuration |

## Changes from Node.js Version

1. **Clock Fix**: Hourly forecasts now display in ascending order (1, 2, 3... instead of 23, 22, 21...)
2. **PHP 8.1+**: Uses modern PHP features (typed properties, match expressions, etc.)
3. **Same Architecture**: Direct port of the Node.js weather simulation engine
