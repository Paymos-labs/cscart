<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// Run only from the installed CS-Cart package, using its native bootstrap and DB.
if (!defined('BOOTSTRAP')) {
    define('AREA', 'A');
    require dirname(__DIR__, 4) . '/init.php';
}
require_once dirname(__DIR__) . '/src/Autoloader.php';
\PaymosCsCart\Autoloader::register();
\PaymosCsCart\RefundSchema::ensure();
$cursor = isset($argv[1]) && $argv[1] !== '' ? $argv[1] : null;
$result = (new \PaymosCsCart\RefundAdapter(new \PaymosCsCart\RefundIntentStore()))
    ->reconcileDue(new \DateTimeImmutable('now'), $cursor);
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
