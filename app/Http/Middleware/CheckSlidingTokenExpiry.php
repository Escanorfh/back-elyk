<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSlidingTokenExpiry
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token = $user->currentAccessToken();
            $lastActivity = $token->last_used_at ?? $token->created_at;
            
            if ($lastActivity && $lastActivity->lt(now()->subDays(60))) {
                $token->delete();
                abort(401, 'Token expired due to inactivity.');
            }
        }
        return $next($request);
    }
}
