<?php
/**
 * Precision Ink Insights — Configuration
 * Copy to config.php and edit values for your environment.
 */
return [
    'app' => [
        'name'      => 'Precision Ink Insights',
        'url'       => 'http://localhost',
        'debug'     => false,
        'timezone'  => 'America/New_York',
        'version'   => '1.0.0',
    ],

    // Local MySQL — auth, users, preferences, presets, audit
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'precision_ink_insights',
        'user'     => 'pii_user',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // CMS (MSSQL) — read-only data source for analytics
    'cms_db' => [
        'host'     => '10.10.10.11',
        'port'     => 1433,
        'name'     => 'CMS',
        'user'     => 'cms_reader',
        'password' => 'CHANGE_ME',
        // With the Microsoft ODBC 18 driver (pdo_sqlsrv), connections are
        // encrypted and the server certificate is validated. The CMS SQL
        // Server presents a self-signed certificate, so set this to true
        // to accept it (traffic stays encrypted). Not used by pdo_dblib.
        'trust_server_certificate' => false,
    ],

    'session' => [
        'lifetime' => 3600,
        'name'     => 'PII_SESSION',
    ],

    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'logs'    => __DIR__ . '/../storage/logs',
        'cache'   => __DIR__ . '/../storage/cache',
        'exports' => __DIR__ . '/../storage/exports',
    ],

    'company' => [
        'name' => 'Your Ink Company, Inc.',
    ],
];
