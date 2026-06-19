<?php

use App\Http\Middleware\EnforceSiteScope;
use App\Http\Middleware\FixViteHotFile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ReportCspViolations;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('alerts:poll')->everyMinute()->withoutOverlapping();
        $schedule->command('inventory:probe-reachability')->everyFiveMinutes()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            FixViteHotFile::class,
        ]);
        $middleware->web(append: [
            ReportCspViolations::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'site-scope' => EnforceSiteScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            return inertia('Errors/Forbidden', [
                'message' => $e->getMessage() ?: 'You do not have access to this resource.',
            ])->toResponse($request)->setStatusCode(403);
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 403) {
                return null;
            }

            return inertia('Errors/Forbidden', [
                'message' => $e->getMessage() ?: 'You do not have access to this resource.',
            ])->toResponse($request)->setStatusCode(403);
        });
    })->create();
