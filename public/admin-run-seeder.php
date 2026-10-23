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

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$expected = 'alyk-seed-7d41f2b9';
$token = $_GET['token'] ?? '';
$mode = $_GET['mode'] ?? 'run';

if (! hash_equals($expected, (string) $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);

    exit;
}

if ($mode === 'run') {
    $exit = Artisan::call('db:seed', [
        '--class' => 'Database\\Seeders\\AssessmentInstrumentsSeeder',
        '--force' => true,
    ]);

    echo json_encode([
        'exit_code' => $exit,
        'output' => Artisan::output(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}

if ($mode === 'summary') {
    $summary = [];
    foreach (['disorders', 'exams', 'subscales', 'questions', 'question_options', 'severity_bands'] as $t) {
        $summary[$t] = (int) DB::selectOne("SELECT COUNT(*) AS c FROM `{$t}`")->c;
    }

    $exams = DB::select('SELECT e.id, e.name_ar, e.min_level_score, e.max_level_score, d.name_ar AS disorder_ar,
        (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS questions,
        (SELECT COUNT(*) FROM severity_bands sb WHERE sb.exam_id = e.id) AS bands
        FROM exams e JOIN disorders d ON d.id = e.disorder_id');

    $subscales = DB::select('SELECT s.id, s.name_ar, s.exam_id FROM subscales s ORDER BY s.exam_id, s.`order`');

    echo json_encode([
        'summary' => $summary,
        'exams' => $exams,
        'subscales' => $subscales,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

if ($mode === 'token') {
    $role = $_GET['role'] ?? 'patient';
    $user = DB::table('users')->where('role', $role)->orderBy('id')->first();

    if (! $user) {
        echo json_encode(['error' => 'no user with role ' . $role]);

        exit;
    }

    $model = \App\Models\User::find($user->id);
    $token = $model->createToken('temp-verify')->plainTextToken;

    echo json_encode([
        'user_id' => $model->id,
        'role' => $model->role,
        'token' => $token,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

if ($mode === 'questions') {
    $examId = (int) ($_GET['id'] ?? 0);
    $exam = DB::selectOne('SELECT id, name, name_ar, min_level_score, max_level_score FROM exams WHERE id = ?', [$examId]);

    if (! $exam) {
        echo json_encode(['error' => 'exam not found']);

        exit;
    }

    $questions = DB::select('SELECT id, subscale_id, text, text_ar, type, `order` FROM questions WHERE exam_id = ? ORDER BY `order`', [$examId]);
    foreach ($questions as $q) {
        $q->options = DB::select('SELECT id, text, text_ar, score, `order` FROM question_options WHERE question_id = ? ORDER BY `order`', [$q->id]);
    }

    echo json_encode([
        'exam' => $exam,
        'questions' => $questions,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}

if ($mode === 'revoke') {
    $deleted = DB::table('personal_access_tokens')->where('name', 'temp-verify')->delete();

    echo json_encode(['revoked' => $deleted], JSON_PRETTY_PRINT);

    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown mode']);
