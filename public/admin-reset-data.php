<?php

header('Content-Type: application/json');

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';

try {
    $console = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $console->bootstrap();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'bootstrap: ' . $e->getMessage()]);

    exit;
}

use Illuminate\Support\Facades\DB;

// توكن مؤقت لازم يتطابق مع الملفات (كلمة سر عشوائية).
$expected = 'alyk-reset-2f8c41d7';
$token = $_GET['token'] ?? '';
$mode = $_GET['mode'] ?? 'list';

if (! hash_equals($expected, (string) $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);

    exit;
}

// جداول نمسكها من المسح (بيانات المستخدم + سجل الميجريشن).
$keep = ['users', 'migrations'];

$dbName = DB::connection()->getDatabaseName();
$tables = DB::select('SHOW TABLES');
$names = [];
foreach ($tables as $row) {
    $values = array_values((array) $row);
    $names[] = (string) $values[0];
}

if ($mode === 'list') {
    $counts = [];
    foreach ($names as $t) {
        $counts[$t] = (int) DB::selectOne("SELECT COUNT(*) AS c FROM `{$t}`")->c;
    }

    echo json_encode([
        'database' => $dbName,
        'keep' => $keep,
        'tables' => $counts,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

if ($mode === 'run') {
    $before = [];
    foreach ($names as $t) {
        $before[$t] = (int) DB::selectOne("SELECT COUNT(*) AS c FROM `{$t}`")->c;
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $done = [];
    $errors = [];
    foreach ($names as $t) {
        if (in_array($t, $keep, true)) {
            continue;
        }
        try {
            DB::statement("TRUNCATE TABLE `{$t}`");
            $done[] = $t;
        } catch (Throwable $e) {
            $errors[$t] = $e->getMessage();
        }
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $after = [];
    foreach ($names as $t) {
        $after[$t] = (int) DB::selectOne("SELECT COUNT(*) AS c FROM `{$t}`")->c;
    }

    echo json_encode([
        'database' => $dbName,
        'truncated' => $done,
        'errors' => $errors,
        'before' => $before,
        'after' => $after,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown mode']);
