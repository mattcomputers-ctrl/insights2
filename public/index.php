<?php
/**
 * Precision Ink Insights — Front Controller
 * All requests are routed through this file via .htaccess
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = new PII\Core\App();
$app->run();
