<?php

use App\Jobs\SyncBggCollection;
use App\Models\GameCollection;
use App\Models\User;
use App\Services\BggRetryCooldown;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('queue.default', 'database');

    $this->owner = User::factory()->create();
    $this->collection = GameCollection::forceCreate([
        'created_by' => $this->owner->id,
        'name' => 'BGG - prueba',
        'bgg_username' => 'clover81',
        'bgg_sync_status' => 'completed',
    ]);
});

test('HTTP 429 inicia espera y las consultas repetidas no llaman otra vez a BGG', function () {
    Http::fake(['*' => Http::response('', 429)]);

    $url = route('collections.bgg.preview', $this->collection);

    $this->actingAs($this->owner)
        ->getJson($url)
        ->assertStatus(429)
        ->assertJsonPath('retry_after_seconds', 900);

    Http::assertSentCount(1);

    $this->getJson($url)
        ->assertStatus(429);

    Http::assertSentCount(1);

    $this->get(route('collections.index'))->assertOk();

    $rows = collect($this->get(route('collections.index'))
        ->inertiaProps('collections'));

    expect($rows->first()['bgg_retry_until'])
        ->toBeGreaterThan(now()->timestamp);
});

test('el bloqueo evita nuevas importaciones pero permite reintentar tras la espera', function () {
    Bus::fake();

    $cooldown = app(BggRetryCooldown::class);
    $cooldown->register($this->collection);

    $this->actingAs($this->owner)
        ->from(route('collections.index'))
        ->post(route('collections.bgg.sync', $this->collection))
        ->assertSessionHasErrors('bgg');

    expect($this->collection->fresh()->bgg_sync_status)
        ->toBe('completed');

    Bus::assertNotDispatched(SyncBggCollection::class);

    $this->travel(16)->minutes();

    $this->from(route('collections.index'))
        ->post(route('collections.bgg.sync', $this->collection))
        ->assertSessionHasNoErrors();

    Bus::assertDispatched(SyncBggCollection::class);
});

test('un trabajo fallido por HTTP 429 registra el mismo bloqueo', function () {
    $job = new SyncBggCollection($this->collection->id);

    $job->failed(new RuntimeException(
        'Error al consultar expansiones BGG. HTTP 429'
    ));

    $this->collection->refresh();

    expect($this->collection->bgg_sync_status)->toBe('failed')
        ->and($this->collection->bgg_sync_error)->toContain('429')
        ->and(app(BggRetryCooldown::class)
            ->secondsRemaining($this->collection))
        ->toBeGreaterThan(0);
});
