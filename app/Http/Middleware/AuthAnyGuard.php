<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class AuthAnyGuard
{
    public function handle($request, Closure $next, ...$guards)
    {
        // Support token passed via query parameter (e.g. for opening PDFs in a new browser tab)
        if (!$request->bearerToken()) {
            $token = $request->query('token') ?: $request->query('api_token') ?: $request->query('access_token');
            if ($token) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

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