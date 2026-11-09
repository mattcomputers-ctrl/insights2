<?php

declare(strict_types=1);

namespace PII\Core;

/**
 * CMSDatabase — Read-only PDO connection to the CMS MSSQL database.
 *
 * Mirrors the SDS system's CMS connection pattern: tries the sqlsrv
 * driver first (Windows), falls back to dblib (Linux/FreeTDS).
 * All queries against this connection should be read-only.
 */
class CMSDatabase
{
    private static ?self $instance = null;

    /**
     * When true (web requests), getInstance() refuses CMS access during a
     * backup blackout window or while the admin kill switch is on, throwing
     * CMSUnavailableException with a friendly message. The sync worker turns
     * this off and applies its own policy (blackouts stop runs at step
     * boundaries; an admin override run may proceed).
     */
    private static bool $administrativeChecks = true;

    private \PDO $pdo;

    /** Long enough to cover the slow CMS TVFs (e.g. GetInventoryAtDate ~45s).
     *  Without this, the dblib driver kills the connection mid-query with
     *  "DBPROCESS is dead or not enabled (20047)". */
    private const QUERY_TIMEOUT_SECONDS = 300;

    public function __construct(array $config)
    {
        $host = $config['host'];
        $port = $config['port'] ?? 1433;
        $name = $config['name'];

        $driver = null;
        if (in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
            $driver = 'sqlsrv';
            $dsn = sprintf(
                'sqlsrv:Server=%s,%d;Database=%s;LoginTimeout=30;Encrypt=1',
                $host, $port, $name
            );
            // ODBC Driver 18 validates the server certificate by default.
            // The CMS SQL Server presents a self-signed certificate, so the
            // admin may explicitly opt in to trusting it (connection stays
            // encrypted). Set via Admin → CMS & Sync or config cms_db
            // trust_server_certificate. Without it, ODBC 18 fails with
            // "SSL Provider: certificate verify failed: self-signed certificate".
            if (!empty($config['trust_server_certificate'])) {
                $dsn .= ';TrustServerCertificate=1';
            }
        } elseif (in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $driver = 'dblib';
            $dsn = sprintf('dblib:host=%s:%d;dbname=%s', $host, $port, $name);
        } else {
            throw new \RuntimeException(
                'No SQL Server PDO driver available. Install php_pdo_sqlsrv (Windows) or php_pdo_dblib (Linux).'
            );
        }

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if ($driver === 'dblib') {
            // dblib takes its connect timeout as a PDO attribute. pdo_sqlsrv
            // does NOT support ATTR_TIMEOUT and refuses the connection with
            // "IMSSP: An unsupported attribute was designated on the PDO
            // object" — its connect timeout is the DSN's LoginTimeout.
            $options[\PDO::ATTR_TIMEOUT] = self::QUERY_TIMEOUT_SECONDS;
        }

        $this->pdo = new \PDO($dsn, $config['user'], $config['password'], $options);

        // Driver-specific query-execution timeouts. ATTR_TIMEOUT in the
        // options array sets the *connect* timeout for sqlsrv but is
        // ignored for dblib's query timeout, which has its own attribute.
        if ($driver === 'dblib' && defined('PDO::DBLIB_ATTR_QUERY_TIMEOUT')) {
            $this->pdo->setAttribute(\PDO::DBLIB_ATTR_QUERY_TIMEOUT, self::QUERY_TIMEOUT_SECONDS);
        }
        if ($driver === 'sqlsrv' && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $this->pdo->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, self::QUERY_TIMEOUT_SECONDS);
        }
    }

    public static function setAdministrativeChecks(bool $on): void
    {
        self::$administrativeChecks = $on;
    }

    /**
     * Effective CMS connection settings: config/config.php's cms_db section,
     * overridden by config/cms.local.php when present (written from
     * Admin → CMS & Sync; gitignored, never in the database).
     */
    public static function effectiveConfig(): ?array
    {
        $config = App::config('cms_db');
        $overrideFile = App::basePath() . '/config/cms.local.php';
        if (file_exists($overrideFile)) {
            $override = require $overrideFile;
            if (is_array($override)) {
                $config = array_merge($config ?? [], $override);
            }
        }
        return $config ?: null;
    }

    public static function getInstance(): self
    {
        if (self::$administrativeChecks) {
            self::assertNotBlocked();
        }
        if (self::$instance === null) {
            $config = self::effectiveConfig();
            if (!$config) {
                throw new \RuntimeException(
                    'CMS database not configured. Add a cms_db section to config/config.php.'
                );
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /** Throw the friendly exception if the kill switch or a blackout blocks CMS access. */
    public static function assertNotBlocked(): void
    {
        if (\PII\Services\Sync\SyncSettings::killSwitch()) {
            throw new CMSUnavailableException(CMSUnavailableException::KILL_SWITCH);
        }
        $blackout = \PII\Services\Sync\SyncSettings::activeBlackout();
        if ($blackout !== null) {
            throw new CMSUnavailableException(
                CMSUnavailableException::BLACKOUT,
                $blackout['until']->format('g:i A')
            );
        }
    }

    public static function isConfigured(): bool
    {
        $config = self::effectiveConfig();
        return $config !== null
            && !empty($config['host'])
            && ($config['password'] ?? 'CHANGE_ME') !== 'CHANGE_ME';
    }

    /**
     * Try a connection with the given (or effective) settings.
     * Returns ['ok' => bool, 'message' => string, 'driver' => ?string].
     */
    public static function testConnection(?array $config = null): array
    {
        $config = $config ?? self::effectiveConfig();
        if (!$config || empty($config['host'])) {
            return ['ok' => false, 'message' => 'No CMS connection settings.', 'driver' => null];
        }
        $drivers = \PDO::getAvailableDrivers();
        $driver  = in_array('sqlsrv', $drivers, true) ? 'sqlsrv'
                 : (in_array('dblib', $drivers, true) ? 'dblib' : null);
        if ($driver === null) {
            return [
                'ok' => false,
                'message' => 'SQL Server driver not installed (need pdo_sqlsrv or pdo_dblib).',
                'driver' => null,
            ];
        }
        try {
            $probe = new self($config);
            $row = $probe->fetch('SELECT 1 AS ok');
            return ($row['ok'] ?? null) == 1
                ? ['ok' => true, 'message' => 'Connected and query returned.', 'driver' => $driver]
                : ['ok' => false, 'message' => 'Connected but test query failed.', 'driver' => $driver];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'driver' => $driver];
        }
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
