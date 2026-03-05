<?php

namespace Config;

use PDO;
use PDOException;

class Database
{
	private static ?PDO $instance = null;

	public static function getInstance(): PDO
	{
		if (self::$instance === null) {
			$config = require __DIR__ . '/config.php';
			$dbPath = $config['database']['path'];

			try {
				self::$instance = new PDO("sqlite:$dbPath");
				self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

				try {
					self::$instance->exec('PRAGMA journal_mode = WAL;');
				} catch (\Throwable $e) {
					error_log('[eulenmeteo] WAL mode not available, fallback to DELETE: ' . $e->getMessage());
					self::$instance->exec('PRAGMA journal_mode = DELETE;');
				}

				try {
					self::$instance->exec('PRAGMA foreign_keys = ON;');
				} catch (\Throwable $e) {
					error_log('[eulenmeteo] Could not enable foreign_keys pragma: ' . $e->getMessage());
				}
			} catch (PDOException $e) {
				throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
			}
		}

		return self::$instance;
	}

	public static function close(): void
	{
		self::$instance = null;
	}
}
