<?php
/**
 * migrate.php — Apply pending SQL migrations.
 * Usage:  php migrations/migrate.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "config/config.php not found.\n");
    exit(1);
}
$config = require $configFile;

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['port'] ?? 3306,
    $config['db']['name'],
    $config['db']['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Bootstrap schema_migrations table (so we can record this migration's row)
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = [];
foreach ($pdo->query("SELECT version FROM schema_migrations") as $row) {
    $applied[$row['version']] = true;
}

$files = glob(__DIR__ . '/*.sql');
sort($files);
$pending = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (isset($applied[$version])) {
        echo "  [skip]  $version (already applied)\n";
        continue;
    }
    echo "  [apply] $version ... ";
    $sql = file_get_contents($file);
    try {
        $pdo->exec($sql);
        echo "ok\n";
        $pending++;
    } catch (PDOException $e) {
        echo "FAILED\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\nDone — $pending migration(s) applied.\n";
