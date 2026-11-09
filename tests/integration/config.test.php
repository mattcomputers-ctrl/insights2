<?php
/**
 * Integration-test configuration (used by tests/integration only).
 * The CMS section is deliberately unconfigured: the permission crawl
 * must pass with no CMS reachable.
 */
return [
    'app' => [
        'name'      => 'Insights (integration test)',
        'url'       => 'http://127.0.0.1:8080',
        'debug'     => true,
        'timezone'  => 'America/Chicago',
        'version'   => 'test',
    ],
    'db' => [
        'host'     => getenv('PII_TEST_DB_HOST') ?: 'pii-test-db',
        'port'     => 3306,
        'name'     => 'insights_test',
        'user'     => 'insights',
        'password' => 'insights_test_pw',
        'charset'  => 'utf8mb4',
    ],
    'cms_db' => [
        'host'     => '',
        'port'     => 1433,
        'name'     => 'CMS',
        'user'     => '',
        'password' => 'CHANGE_ME',
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
        'name' => 'Precision Ink (test)',
    ],
];
