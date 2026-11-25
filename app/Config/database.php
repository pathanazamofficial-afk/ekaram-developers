<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function databaseConfig(): array
{
    loadEnvironment();

    return [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'database' => getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: ''),
        'username' => getenv('DB_USERNAME') !== false ? (string) getenv('DB_USERNAME') : (string) (getenv('DB_USER') ?: ''),
        'password' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : (string) (getenv('DB_PASS') ?: ''),
        'charset' => 'utf8mb4',
    ];
}
