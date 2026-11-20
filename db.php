<?php
declare(strict_types=1);

// Secrets come from the environment (Vercel's project settings). Locally they can sit in .env.local,
// which git ignores. They never belong in this public repository.
$envFile = __DIR__ . '/.env.local';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m) && getenv($m[1]) === false) {
            putenv("{$m[1]}={$m[2]}");
        }
    }
}

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

$pdo = null;
$usingTurso = env('TURSO_DATABASE_URL') !== '';

if ($usingTurso) {
    // Online: the Turso database (always on, no server to start).
    require_once __DIR__ . '/turso.php';
    try {
        $pdo = new TursoPDO(env('TURSO_DATABASE_URL'), env('TURSO_AUTH_TOKEN'));
    } catch (Throwable $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        $pdo = null;
    }
} else {
    // On a computer with XAMPP: the local MySQL database.
    $dbHost = '127.0.0.1';
    $dbName = 'tsv_secretary';
    $dbUser = 'root';
    $dbPassword = '';

    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        $pdo = null;
    }
}
