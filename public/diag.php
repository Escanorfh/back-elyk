<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', '1');
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
try {
    $console = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $console->bootstrap();
} catch (Throwable $e) {
    echo 'BOOTSTRAP ERROR: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
$router = $app->make('router');
$routes = $router->getRoutes()->getRoutes();
echo 'TOTAL ROUTES: ' . count($routes) . "\n";
$apiRoutes = [];
foreach ($routes as $r) {
    if (str_starts_with($r->uri(), 'api')) {
        $apiRoutes[] = $r->uri();
    }
}
echo 'API ROUTES: ' . count($apiRoutes) . "\n";
foreach (array_slice($apiRoutes, 0, 30) as $u) {
    echo '  ' . $u . "\n";
}
echo "\napp->basePath('routes/api.php') = " . $app->basePath('routes/api.php') . "\n";
echo 'file_exists(basePath api) = ' . (file_exists($app->basePath('routes/api.php')) ? 'yes' : '*** NO ***') . "\n";
echo "\nDB default = " . config('database.default') . "\n";
echo "APP_KEY set = " . (config('app.key') ? 'yes' : '*** NO ***') . "\n";
echo 'APP_ENV = ' . config('app.env') . "\n";
echo 'mysql host = ' . config('database.connections.mysql.host') . "\n";
echo 'mysql name = ' . config('database.connections.mysql.database') . "\n";
echo 'mysql ssl options = ' . json_encode(array_keys(config('database.connections.mysql.options') ?: [])) . "\n";
echo "php_sapi = " . php_sapi_name() . "\n";
$n = null;
try {
    \Illuminate\Support\Facades\DB::connection()->getPdo();
    echo "DB CONNECTION: OK (" . DB::connection()->getDatabaseName() . ")\n";
} catch (Throwable $e) {
    echo 'DB CONNECTION FAILED: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 160) . "\n";
}
