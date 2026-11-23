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

    // Reponer de forma controlada si está habilitado.
    app(\App\Services\GameDescriptionAutoRefill::class)
        ->refillIfNeeded();

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
        // No procesar si la cuota diaria de IA está agotada.
        $limiter = app(\Illuminate\Cache\RateLimiter::class);
        $dailyKey = md5('game-descriptions-aigame-descriptions-day');

        return $limiter->remaining($dailyKey, 30) > 0
            && $limiter->availableIn(
                'laravel_throttles_exceptions:openai-game-descriptions'
            ) === 0;
    })
    ->withoutOverlapping(10);
