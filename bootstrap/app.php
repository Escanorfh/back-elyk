<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
            'webhook.signature' => \App\Http\Middleware\VerifyWebhookSignature::class,
        ]);
        $middleware->api(append: [\App\Http\Middleware\TraceApi::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API غير مفوتر → 401 JSON، بدلاً من محاولة redirect إلى route "login" غير الموجودة.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if (str_starts_with($request->path(), 'api/') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return null;
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                config(['app.debug' => false]);
            }
            return null; // Let Laravel handle everything else natively
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('intake:remind-stalled')->daily();
        $schedule->command('auth:purge-stale-tokens')->daily();
    })->create();
