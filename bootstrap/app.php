<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\JWTMiddleware;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\OrganizationAccessMiddleware;
use App\Http\Middleware\ForcePasswordChangeMiddleware;
use App\Http\Middleware\RoundFloatResponseMiddleware;
use App\Http\Middleware\NormalizeDecimalInputMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt.auth' => JWTMiddleware::class,
            'force.password.change' => ForcePasswordChangeMiddleware::class,
            'permission' => CheckPermission::class,
            'org.access' => OrganizationAccessMiddleware::class,
        ]);

        $middleware->appendToGroup('api', NormalizeDecimalInputMiddleware::class);
        $middleware->appendToGroup('api', RoundFloatResponseMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
