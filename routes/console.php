<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    // Activar la IA únicamente durante esta ejecución.
    config()->set(
        'services.game_descriptions.enabled',
        true
    );

    $hayTrabajos = \Illuminate\Support\Facades\DB::table('jobs')
        ->where('queue', 'descriptions')
        ->exists();

    // Si la cola está vacía, incorporar un juego pendiente.
    if (!$hayTrabajos) {
        Artisan::call('games:descriptions-queue', [
            '--limit' => 1,
            '--apply' => true,
        ]);
    }

    // Procesar como máximo un trabajo.
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'descriptions',
        '--once' => true,
        '--timeout' => 180,
    ]);
})
    ->name('procesar-descripciones-bgg')
    ->hourly()
    ->when(function () {
        // No ejecutar mientras haya un bloqueo HTTP 429.
        return app(\Illuminate\Cache\RateLimiter::class)
            ->availableIn(
                'laravel_throttles_exceptions:openai-game-descriptions'
            ) === 0;
    })
    ->withoutOverlapping(10);
