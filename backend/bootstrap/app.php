<?php

use App\Http\Middleware\EnsureActiveHotelMembership;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\RequireOwner;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\EnsureOutletAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->validateCsrfTokens(except: [
            'api/v1/public/tables/*/call-waiter',
        ]);
        $middleware->alias([
            'active.hotel' => EnsureActiveHotelMembership::class,
            'idempotent' => IdempotentRequest::class,
            'owner' => RequireOwner::class,
            'permission' => RequirePermission::class,
            'outlet.access' => EnsureOutletAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $exception) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ValidationException $exception, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], 422);
        });
        $exceptions->render(function (AuthenticationException $exception, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        });
        $exceptions->render(function (AuthorizationException $exception, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['success' => false, 'message' => 'You do not have permission to perform this action.'], 403);
        });
        $exceptions->render(function (ModelNotFoundException $exception, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(['success' => false, 'message' => 'The requested resource was not found.'], 404);
        });
    })->create();
