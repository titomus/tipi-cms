<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('CodeGraft is not initialized. Run php bin/install.php in the terminal.');
}
$config = require $configFile;
if (!is_array($config) || !isset($config['root'], $config['vault'])) {
    throw new RuntimeException('Invalid CodeGraft configuration.');
}
$codegraft = codegraft((string)$config['root'], (string)$config['vault'],
    (string)($config['adapter'] ?? 'generic'));
