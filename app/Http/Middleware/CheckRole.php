<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, $role): Response
    {
        if (!$request->user() || $request->user()->role !== $role) {
            abort(403, "Unauthorized action. Role {$role} required.");
        }
        return $next($request);
    }
}
