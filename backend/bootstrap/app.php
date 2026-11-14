<?php

use App\Http\ApiErrorRenderer;
use App\Http\Middleware\EnforceTokenScope;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\ResolveWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    // Channel sign-in for realtime updates goes through the API, with the same session or token.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA signs in with a cookie session; API clients and agents use bearer tokens.
        $middleware->statefulApi();

        // The workspace must be known before tenant models are bound from the route.
        $middleware->alias(['workspace' => ResolveWorkspace::class, 'idempotent' => Idempotency::class, 'token.scope' => EnforceTokenScope::class]);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveWorkspace::class);
        // Token limits also apply before binding, so out-of-scope projects and items are simply not found.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnforceTokenScope::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return app(ApiErrorRenderer::class)->render($e);
            }
        });
    })->create();
