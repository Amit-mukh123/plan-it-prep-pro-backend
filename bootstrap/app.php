<?php

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
    ->withMiddleware(function (Middleware $middleware): void {
        // Register short-name aliases for the two new middleware classes.
        // Routes can use either the FQCN (as in api.php) or these alias strings.
        $middleware->alias([
            'maintenance.check' => \App\Http\Middleware\MaintenanceCheckMiddleware::class,
            'force.update'      => \App\Http\Middleware\ForceUpdateMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

