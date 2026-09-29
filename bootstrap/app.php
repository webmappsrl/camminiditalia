<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo('login');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => __('The uploaded files are too large.'),
                ], 413);
            }
        });
    })
    ->withSchedule(function (Schedule $schedule) {

        $schedule->command('telescope:prune')
            ->weekly();

        $schedule->command('scout:import "Wm\WmPackage\Models\EcTrack"')
            ->everyFifteenMinutes();

        $schedule->command('horizon:snapshot')
            ->everyFiveMinutes()
            ->description('Take Horizon snapshot');
    })
    ->create();
