<?php

declare(strict_types=1);

/**
 * One-shot, non-destructive configuration of the Water MySQL connection.
 *
 * Credential comes only from STDIN over SSH; it is never printed.
 * Run with: /opt/alt/php84/usr/bin/php configure_mysql.php
 */
$project = rtrim((string) getenv('HOME'), '/') . '/repositories/water';
$path = $project . '/.env';
$vendor = $project . '/vendor/autoload.php';

function abortConfig(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$password = stream_get_contents(STDIN);
if (!is_string($password) || strlen($password) < 16 || strlen($password) > 512 ||
    preg_match('/[\x00-\x1f\x7f]/', $password)) {
    abortConfig('DATABASE_CONFIG: password must be 16-512 characters, without control characters.');
}
if (!is_file($path) || is_link($path) || !is_readable($path) || !is_writable($path)) {
    abortConfig('DATABASE_CONFIG: private .env file is missing, linked, or inaccessible.');
}
if (!is_file($vendor)) {
    abortConfig('DATABASE_CONFIG: vendor/autoload.php is missing.');
}

$original = file_get_contents($path);
if (!is_string($original) || $original === '') {
    abortConfig('DATABASE_CONFIG: cannot read .env.');
}

$values = [];
foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
    if (preg_match_all($pattern, $original, $matches) !== 1) {
        abortConfig("DATABASE_CONFIG: expected exactly one {$key} setting.");
    }
    $values[$key] = trim($matches[1][0]);
}
if ($values['DB_CONNECTION'] !== 'mysql' || $values['DB_DATABASE'] !== '' ||
    $values['DB_USERNAME'] !== '' || $values['DB_PASSWORD'] !== '') {
    abortConfig('DATABASE_CONFIG: database settings already changed; refusing to overwrite.');
}

try {
    $connection = new PDO(
        'mysql:host=localhost;port=3306;dbname=alweufti_water;charset=utf8mb4',
        'alweufti_water',
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
        ],
    );
    $selectedDatabase = $connection->query('SELECT DATABASE()')->fetchColumn();
    if ($selectedDatabase !== 'alweufti_water') {
        abortConfig('DATABASE_CONNECTION: unexpected database selected.');
    }
} catch (Throwable $exception) {
    abortConfig('DATABASE_CONNECTION: FAILED (credentials, permissions, or MySQL connectivity).');
}
$connection = null;
echo "DATABASE_CONNECTION: OK (read-only SELECT)\n";

// Escape password for the actual phpdotenv parser. Never print it.
$escaped = '"' . strtr($password, [
    '\\' => '\\\\',
    '"' => '\\"',
    '$' => '\\$',
]) . '"';
$changes = [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'alweufti_water',
    'DB_USERNAME' => 'alweufti_water',
    'DB_PASSWORD' => $escaped,
];
$modified = $original;
foreach ($changes as $key => $value) {
    $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
    $modified = preg_replace_callback(
        $pattern,
        static fn (): string => $key . '=' . $value,
        $modified,
        1,
        $count,
    );
    if (!is_string($modified) || $count !== 1) {
        abortConfig("DATABASE_CONFIG: failed to generate {$key} setting.");
    }
}

require $vendor;

try {
    $readBack = \Dotenv\Dotenv::parse($modified);
    if (($readBack['DB_PASSWORD'] ?? null) !== $password ||
        ($readBack['DB_DATABASE'] ?? null) !== 'alweufti_water' ||
        ($readBack['DB_USERNAME'] ?? null) !== 'alweufti_water' ||
        ($readBack['APP_DEBUG'] ?? null) !== 'false') {
        abortConfig('DATABASE_CONFIG: dotenv parse check failed; no changes written.');
    }
} catch (Throwable $exception) {
    abortConfig('DATABASE_CONFIG: unable to validate new .env; no changes written.');
}

if (hash('sha256', (string) file_get_contents($path)) !== hash('sha256', $original)) {
    abortConfig('DATABASE_CONFIG: .env was modified during execution; refusing to overwrite.');
}
$temp = tempnam($project, '.env-db-');
if ($temp === false) {
    abortConfig('DATABASE_CONFIG: unable to stage private .env.');
}
try {
    if (file_put_contents($temp, $modified, LOCK_EX) === false || !chmod($temp, 0600)) {
        abortConfig('DATABASE_CONFIG: unable to prepare private .env.');
    }
    if (!rename($temp, $path)) {
        abortConfig('DATABASE_CONFIG: unable to install .env.');
    }
} finally {
    if (is_file($temp)) {
        unlink($temp);
    }
}
echo "DATABASE_ENV: CONFIGURED\n";
echo "DATABASE_SCHEMA: UNCHANGED\n";
echo "DATABASE_IMPORT: NOT_ATTEMPTED\n";
