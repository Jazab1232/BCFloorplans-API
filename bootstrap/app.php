<?php

// Compatibility shim for PHP environments without AVIF support in GD extension
if (!function_exists('imagecreatefromavif')) {
    function imagecreatefromavif($filename) {
        return false;
    }
}
if (!function_exists('imageavif')) {
    function imageavif($image, $file = null, $quality = null) {
        return false;
    }
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            // register your custom middleware aliases here
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'auth.any' => \App\Http\Middleware\AuthAnyGuard::class,
            'resolve.org' => \App\Http\Middleware\ResolveOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
