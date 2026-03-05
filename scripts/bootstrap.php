<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
$dbPath = $config['database']['path'] ?? (__DIR__ . '/../database/clauswetter.db');

if (!is_string($dbPath) || $dbPath === '') {
    fwrite(STDERR, "Invalid DB_PATH configuration.\n");
    exit(1);
}

$dbDir = dirname($dbPath);
if (!is_dir($dbDir) && !mkdir($dbDir, 0775, true) && !is_dir($dbDir)) {
    fwrite(STDERR, "Failed to create database directory: {$dbDir}\n");
    exit(1);
}
if (!is_writable($dbDir)) {
    fwrite(STDERR, "Database directory is not writable: {$dbDir}\n");
    exit(1);
}

if (file_exists($dbPath)) {
    fwrite(STDOUT, "Database already exists at {$dbPath}\n");
    exit(0);
}

$schemaFiles = [
    __DIR__ . '/../resources/sql/schema.sql',
    __DIR__ . '/../resources/sql/schema_forecast.sql',
];

// Backward-compatible fallback for environments that still keep schema under /database.
if (!file_exists($schemaFiles[0]) || !file_exists($schemaFiles[1])) {
    $schemaFiles = [
        __DIR__ . '/../database/schema.sql',
        __DIR__ . '/../database/schema_forecast.sql',
    ];
}

foreach ($schemaFiles as $schemaFile) {
    if (!file_exists($schemaFile)) {
        fwrite(STDERR, "Missing schema file: {$schemaFile}\n");
        exit(1);
    }
}

try {
    $pdo = new PDO("sqlite:{$dbPath}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach ($schemaFiles as $schemaFile) {
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException("Unable to read schema file: {$schemaFile}");
        }
        $pdo->exec($sql);
    }

    fwrite(STDOUT, "Initialized SQLite database at {$dbPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Database bootstrap failed: {$e->getMessage()}\n");
    exit(1);
}
