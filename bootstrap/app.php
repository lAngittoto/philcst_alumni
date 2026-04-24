<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // Register middleware aliases
        $middleware->alias([
            'admin'                    => \App\Http\Middleware\AdminMiddleware::class,
            'organizer.password.ensure'=> \App\Http\Middleware\EnsureOrganizerPasswordChanged::class,
            'alumni.onboarded'         => \App\Http\Middleware\EnsureAlumniOnboarded::class,
            'registrar'                => \App\Http\Middleware\RegistrarMiddleware::class,
            'director'                 => \App\Http\Middleware\DirectorMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();