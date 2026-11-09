<?php
/**
 * scripts/create-admin.php — Create or upsert an admin user from the CLI.
 *
 * Usage:
 *   php scripts/create-admin.php <username> <password> [<display_name>] [<email>]
 *
 * Useful when the installer was skipped or you need to recover from a lockout.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php <username> <password> [<display_name>] [<email>]\n");
    exit(1);
}

[$username, $password] = [$argv[1], $argv[2]];
$displayName = $argv[3] ?? $username;
$email       = $argv[4] ?? null;

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';

// Bootstrap a PDO without going through the App class (which expects sessions)
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db']['host'],
    $config['db']['port'] ?? 3306,
    $config['db']['name'],
    $config['db']['charset'] ?? 'utf8mb4'
);
$pdo = new PDO($dsn, $config['db']['user'], $config['db']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$hash = password_hash($password, PASSWORD_ARGON2ID);

// Upsert user
$stmt = $pdo->prepare(
    "INSERT INTO users (username, email, password_hash, display_name, is_active)
     VALUES (?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        display_name  = VALUES(display_name),
        is_active     = 1"
);
$stmt->execute([$username, $email, $hash, $displayName]);

$userId = (int) $pdo->query("SELECT id FROM users WHERE username = " . $pdo->quote($username))->fetchColumn();

// Ensure Administrators group exists
$pdo->exec(
    "INSERT IGNORE INTO permission_groups (name, description, is_admin)
     VALUES ('Administrators', 'Full access to all areas', 1)"
);
$adminGroupId = (int) $pdo->query("SELECT id FROM permission_groups WHERE name = 'Administrators'")->fetchColumn();

// Membership
$pdo->prepare(
    "INSERT IGNORE INTO user_group_members (user_id, group_id) VALUES (?, ?)"
)->execute([$userId, $adminGroupId]);

echo "Admin user '$username' created/updated (id=$userId) and added to Administrators group.\n";
