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

$expected = 'alyk-schema-91b7c2e5';
if (! hash_equals($expected, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$tables = ['exams', 'questions', 'question_options', 'subscales', 'severity_bands', 'disorders', 'exam_attempts', 'attempt_results', 'attempt_subscale_results', 'intake_batteries', 'intake_battery_items', 'exam_retake_grants'];

$out = [];
foreach ($tables as $t) {
    try {
        $create = DB::selectOne("SHOW CREATE TABLE `$t`");
        $out[$t] = $create->{'Create Table'} ?? 'n/a';
    } catch (Throwable $e) {
        $out[$t] = 'ERROR: ' . $e->getMessage();
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
