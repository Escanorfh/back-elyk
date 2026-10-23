<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware تشخيصي مؤقت: يسجّل كل طلب API مع حالة الاستجابة وجسمها
 * في storage/logs/api_trace.log لتحديد مصدر أخطاء 404/4xx/5xx.
 */
class TraceApi
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->write($request, 0, get_class($e).': '.$e->getMessage());
            throw $e;
        }

        $this->write(
            $request,
            $response->getStatusCode(),
            mb_substr((string) $response->getContent(), 0, 400)
        );

        return $response;
    }

    private function write(Request $request, int $status, string $body): void
    {
        @file_put_contents(
            storage_path('logs/api_trace.log'),
            date('Y-m-d H:i:s')
                .' | '.$request->method().' '.$request->path()
                .' | USER='.(optional($request->user())->id ?? '-')
                .' | STATUS='.$status
                .' | BODY='.str_replace(["\n", "\r"], ' ', $body)."\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
