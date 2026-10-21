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
    echo 'BOOTSTRAP ERROR: ' . $e->getMessage() . "\n";
    exit;
}

$cfg = config('services.livekit') ?: [];
$key = (string) ($cfg['api_key'] ?? '');
$secret = (string) ($cfg['api_secret'] ?? '');

echo "=== LiveKit config ===\n";
echo 'url            : ' . ($cfg['url'] ?? '-') . "\n";
echo 'ws_url         : ' . ($cfg['ws_url'] ?? '-') . "\n";
echo 'api_key        : ' . ($key !== '' ? 'set (' . substr($key, 0, 8) . '...)' : '*** NOT SET ***') . "\n";
echo 'api_secret     : ' . ($secret !== '' ? 'set (len ' . strlen($secret) . ')' : '*** NOT SET ***') . "\n";
echo 'APP_ENV        : ' . config('app.env') . "\n\n";

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

$body = json_encode([
    'event' => 'participant_joined',
    'room' => ['name' => 'diag-room'],
    'participant' => ['identity' => 'diag-user'],
]);

$header = b64u(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$claims = [
    'iss' => $key,
    'sub' => 'diag-room',
    'nbf' => time() - 10,
    'exp' => time() + 300,
    'sha256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
];
$payload = b64u(json_encode($claims));
$signature = b64u(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
$token = $header . '.' . $payload . '.' . $signature;

$verifier = $app->make(App\Services\LiveKitWebhookVerifier::class);

echo "=== Verifier tests ===\n";
[$ok, $reason] = $verifier->verify($body, 'Bearer ' . $token);
echo 'valid signature -> ' . ($ok ? "ACCEPTED ($reason)" : "REJECTED ($reason)") . "\n";
[$ok, $reason] = $verifier->verify($body, 'Bearer ' . $token . 'x');
echo 'tampered token  -> ' . ($ok ? "ACCEPTED ($reason)" : "REJECTED ($reason)") . "\n";
[$ok, $reason] = $verifier->verify('{"tampered":true}', 'Bearer ' . $token);
echo 'tampered body   -> ' . ($ok ? "ACCEPTED ($reason)" : "REJECTED ($reason)") . "\n";
[$ok, $reason] = $verifier->verify($body, null);
echo 'no auth header  -> ' . ($ok ? "ACCEPTED ($reason)" : "REJECTED ($reason)") . "\n";

echo "\n=== Full internal request through the route ===\n";
$request = Illuminate\Http\Request::create(
    '/api/v1/webhooks/livekit',
    'POST',
    [],
    [],
    [],
    [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ],
    $body,
);
$response = $app->handle($request);
echo 'status: ' . $response->getStatusCode() . '  body: ' . $response->getContent() . "\n";

$bad = Illuminate\Http\Request::create(
    '/api/v1/webhooks/livekit',
    'POST',
    [],
    [],
    [],
    [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token . 'x',
        'CONTENT_TYPE' => 'application/json',
    ],
    $body,
);
$badResponse = $app->handle($bad);
echo 'bad token status: ' . $badResponse->getStatusCode() . '  body: ' . $badResponse->getContent() . "\n";

echo "\nTOKEN_FOR_EXTERNAL_TEST=$token\n";
