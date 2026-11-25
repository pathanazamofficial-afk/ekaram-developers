<?php
declare(strict_types=1);

function loadEnvironment(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $loaded = true;
    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read application environment file');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (!preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
            throw new RuntimeException('Malformed application environment entry');
        }

        $key = $matches[1];
        $value = trim($matches[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function appConfig(): array
{
    loadEnvironment();
    $environment = getenv('APP_ENV') ?: 'production';

    return [
        'environment' => $environment,
        'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'url' => rtrim((string) (getenv('APP_URL') ?: ''), '/'),
        'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Kolkata',
    ];
}
