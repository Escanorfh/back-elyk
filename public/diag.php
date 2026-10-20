<?php
header('Content-Type: text/plain');
echo "PHP " . PHP_VERSION . "\n";
echo "doc_root: " . ($_SERVER['DOCUMENT_ROOT'] ?? '-') . "\n";
echo "cwd: " . getcwd() . "\n\n";
$base = dirname(__DIR__);
echo "project base: " . $base . "\n";
echo "base realpath: " . (realpath($base) ?: '*** MISSING ***') . "\n\n";
$files = [
    'routes/api.php',
    'routes/web.php',
    'routes/console.php',
    'routes/channels.php',
    'bootstrap/app.php',
    'bootstrap/cache',
    'storage/framework',
    'vendor/autoload.php',
    'composer.lock',
    '.env',
];
foreach ($files as $f) {
    $p = $base . '/' . $f;
    $exists = file_exists($p);
    $rp = realpath($p);
    echo str_pad($f, 25) . ($exists ? 'EXISTS' : '*** MISSING ***') . '  realpath=' . ($rp ?: '-') . "\n";
}
echo "\n" . str_repeat('-', 40) . "\n";
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$kernelNeeded = false;
try {
    $router = $app->make('router');
    $routes = [];
    foreach ($router->getRoutes()->getRoutes() as $r) {
        $routes[] = $r->uri();
    }
    echo "REGISTERED ROUTES (" . count($routes) . "):\n";
    foreach (array_slice($routes, 0, 60) as $u) {
        echo "  " . $u . "\n";
    }
} catch (Throwable $e) {
    echo "ROUTER ERROR: " . $e->getMessage() . "\n";
}
