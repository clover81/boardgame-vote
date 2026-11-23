<?php

use App\Services\GameDescriptionAutoRefill;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('services.game_descriptions.auto_refill', false);
});

test('no repone trabajos si está desactivada', function () {
    Artisan::shouldReceive('call')->never();

    app(GameDescriptionAutoRefill::class)->refillIfNeeded();
});

test('no repone trabajos si la cola ya tiene pendientes', function () {
    config()->set('services.game_descriptions.auto_refill', true);

    DB::table('jobs')->insert([
        'queue' => 'descriptions',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time(),
    ]);

    Artisan::shouldReceive('call')->never();

    app(GameDescriptionAutoRefill::class)->refillIfNeeded();
});

test('no repone trabajos si hubo un fallo reciente', function () {
    config()->set('services.game_descriptions.auto_refill', true);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'descriptions',
        'payload' => '{}',
        'exception' => 'Fallo simulado',
        'failed_at' => now(),
    ]);

    Artisan::shouldReceive('call')->never();

    app(GameDescriptionAutoRefill::class)->refillIfNeeded();
});

test('repone solo un trabajo si está habilitada y no hay bloqueos', function () {
    config()->set('services.game_descriptions.auto_refill', true);

    Artisan::shouldReceive('call')
        ->once()
        ->with('games:descriptions-queue', [
            '--limit' => 1,
            '--apply' => true,
        ])
        ->andReturn(0);

    app(GameDescriptionAutoRefill::class)->refillIfNeeded();
});
