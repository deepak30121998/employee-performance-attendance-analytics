<?php

use Illuminate\Auth\AuthenticationException;
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
        // API-only app: there is no "login" web route to redirect guests to.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every route in this app lives under /api - always answer in JSON,
        // regardless of what Accept header the client happened to send.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));

        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json(['message' => $e->getMessage()], 401);
        });
    })->create();
