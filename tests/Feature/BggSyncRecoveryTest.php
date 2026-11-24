<?php

use App\Jobs\SyncBggCollection;
use App\Models\GameCollection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config()->set('queue.default', 'database');
    $this->user = User::factory()->create();
    $this->collection = GameCollection::create([
        'created_by' => $this->user->id,
        'name' => 'BGG prueba',
    ]);
    $this->collection->forceFill([
        'bgg_username' => 'clover81',
        'bgg_sync_status' => 'pending',
        'bgg_last_synced_at' => '2026-11-02 18:53:03',
    ])->save();
});

function ageBggCollection(GameCollection $collection, int $minutes = 75): void
{
    DB::table('game_collections')->where('id', $collection->id)->update([
        'updated_at' => now()->subMinutes($minutes),
    ]);
}

test('recupera un pending antiguo sin trabajo y conserva la ultima sincronizacion', function () {
    ageBggCollection($this->collection);

    $this->actingAs($this->user)
        ->get(route('collections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->where('collections.0.bgg_sync_status', 'failed')
        );

    $collection = $this->collection->fresh();
    expect($collection->bgg_sync_error)->toContain('trabajo en cola');
    expect($collection->bgg_last_synced_at)->toBe('2026-11-02 18:53:03');
});

test('recupera un running antiguo sin trabajo y no toca su ultima sincronizacion correcta', function () {
    $this->collection->forceFill([
        'bgg_sync_status' => 'running',
        'bgg_sync_started_at' => now()->subMinutes(80),
    ])->save();
    ageBggCollection($this->collection);

    $this->actingAs($this->user)->get(route('collections.index'))->assertOk();
    expect($this->collection->fresh()->bgg_sync_status)->toBe('failed');
    expect($this->collection->fresh()->bgg_last_synced_at)->toBe('2026-11-02 18:53:03');
});

test('no desbloquea una solicitud reciente', function () {
    $this->actingAs($this->user)->get(route('collections.index'))->assertOk();
    expect($this->collection->fresh()->bgg_sync_status)->toBe('pending');
});

test('no desbloquea si existe un trabajo sin identificar en default', function () {
    ageBggCollection($this->collection);
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $this->actingAs($this->user)->get(route('collections.index'))->assertOk();
    expect($this->collection->fresh()->bgg_sync_status)->toBe('pending');
});

test('la recuperación solo se bloquea por el trabajo de la misma colección', function (bool $sameCollection, bool $reserved) {
    ageBggCollection($this->collection);
    $id = $sameCollection ? $this->collection->id : $this->collection->id + 1;
    $jobId = Queue::connection('database')->push(new SyncBggCollection($id));

    if ($reserved) {
        DB::table('jobs')->where('id', $jobId)->update(['reserved_at' => now()->timestamp]);
    }

    $this->actingAs($this->user)->get(route('collections.index'))->assertOk();
    expect($this->collection->fresh()->bgg_sync_status)->toBe($sameCollection ? 'pending' : 'failed');
    $this->assertDatabaseHas('jobs', ['id' => $jobId]);
})->with([
    'misma colección en espera' => [true, false],
    'misma colección reservada' => [true, true],
    'otra colección en espera' => [false, false],
    'otra colección reservada' => [false, true],
]);

test('un trabajo de descriptions no bloquea recuperar la cola default', function () {
    ageBggCollection($this->collection);
    DB::table('jobs')->insert([
        'queue' => 'descriptions', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $this->actingAs($this->user)->get(route('collections.index'))->assertOk();
    expect($this->collection->fresh()->bgg_sync_status)->toBe('failed');
});

test('no modifica colecciones de otra cuenta', function () {
    ageBggCollection($this->collection);
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('collections.index'))->assertOk();
    expect($this->collection->fresh()->bgg_sync_status)->toBe('pending');
});

test('no intenta recuperacion con una cola distinta de database', function () {
    ageBggCollection($this->collection);
    config()->set('queue.default', 'sync');

    $this->actingAs($this->user)->get(route('collections.index'))->assertOk();
    expect($this->collection->fresh()->bgg_sync_status)->toBe('pending');
});


test('muestra al propietario las expansiones pendientes en Inertia', function () {
    $this->collection->forceFill([
        'bgg_sync_status' => 'completed',
        'bgg_sync_games_added' => 2,
        'bgg_sync_expansions_added' => 1,
        'bgg_sync_expansions_pending' => 3,
    ])->save();

    $this->actingAs($this->user)
        ->get(route('collections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->where('collections.0.id', $this->collection->id)
            ->where('collections.0.bgg_sync_status', 'completed')
            ->where('collections.0.bgg_sync_games_added', 2)
            ->where('collections.0.bgg_sync_expansions_added', 1)
            ->where('collections.0.bgg_sync_expansions_pending', 3)
        );
});

test('envia el informe detallado BGG a la coleccion propietaria', function () {
    $this->collection->forceFill([
        'bgg_sync_status' => 'completed',
        'bgg_sync_report' => [
            'games_added' => [
                ['id' => 11, 'bgg_id' => 1001, 'name' => 'Juego nuevo'],
            ],
            'expansions_added' => [
                ['id' => 21, 'bgg_id' => 2001, 'name' => 'Expansión nueva', 'game_id' => 11],
            ],
            'expansions_pending' => [
                [
                    'bgg_id' => 261663,
                    'name' => 'Outlive: Underwater',
                    'reason' => 'El juego base no pertenece a esta colección.',
                ],
            ],
        ],
    ])->save();

    $this->actingAs($this->user)
        ->get(route('collections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->where('collections.0.bgg_sync_report.games_added.0.name', 'Juego nuevo')
            ->where('collections.0.bgg_sync_report.games_added.0.id', 11)
            ->where('collections.0.bgg_sync_report.expansions_added.0.name', 'Expansión nueva')
            ->where('collections.0.bgg_sync_report.expansions_added.0.game_id', 11)
            ->where('collections.0.bgg_sync_report.expansions_pending.0.bgg_id', 261663)
            ->where('collections.0.bgg_sync_report.expansions_pending.0.name', 'Outlive: Underwater')
            ->where(
                'collections.0.bgg_sync_report.expansions_pending.0.reason',
                'El juego base no pertenece a esta colección.'
            )
        );
});
