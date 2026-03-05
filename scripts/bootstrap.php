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

if (file_exists($dbPath)) {
    fwrite(STDOUT, "Database already exists at {$dbPath}\n");
    exit(0);
}

$schemaFiles = [
    __DIR__ . '/../database/schema.sql',
    __DIR__ . '/../database/schema_forecast.sql',
];

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
