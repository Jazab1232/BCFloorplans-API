<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class AuthAnyGuard
{
    public function handle($request, Closure $next, ...$guards)
    {
        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                $user = Auth::guard($guard)->user();
                Auth::shouldUse($guard);
                $request->setUserResolver(fn () => $user);
                return $next($request);
            }
        }

        return response()->json([
            'status' => false,
            'message' => 'Unauthorized',
        ], 401);
    }
}