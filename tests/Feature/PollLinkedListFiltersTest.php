<?php

use App\Models\GameCollection;
use App\Models\Poll;
use App\Models\User;

test('los filtros no ocultan el estado del otro juego de la sesion', function () {
    $this->withoutVite();

    $owner = User::factory()->create();

    $first = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Primera votacion de prueba',
        'token' => 'FILTERFIRST',
        'status' => 'draft',
    ]);

    $second = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Segunda votacion distinta',
        'token' => 'FILTERSECOND',
        'status' => 'open',
        'parent_poll_id' => $first->id,
    ]);

    $this->actingAs($owner);

    // La búsqueda solo devuelve el primer juego.
    $response = $this->get(route('polls.index', [
        'search' => 'Primera votacion',
    ]))->assertOk();

    $rows = $response->inertiaProps('polls');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($first->id)
        ->and($rows[0]['linked_poll_id'])->toBe($second->id)
        ->and($rows[0]['linked_poll_status'])->toBe('open');

    // Ahora la búsqueda solo devuelve el segundo.
    $response = $this->get(route('polls.index', [
        'search' => 'Segunda votacion',
    ]))->assertOk();

    $rows = $response->inertiaProps('polls');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($second->id)
        ->and($rows[0]['linked_poll_id'])->toBe($first->id)
        ->and($rows[0]['linked_poll_status'])->toBe('draft');
});

test('al eliminar un borrador se conservan los filtros del listado', function () {
    $owner = User::factory()->create();

    $collection = GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Coleccion de prueba',
    ]);

    $poll = Poll::create([
        'created_by' => $owner->id,
        'name' => 'Borrador filtrado',
        'token' => 'FILTERDELETE',
        'status' => 'draft',
    ]);

    $poll->gameCollections()->attach($collection->id);

    $filters = [
        'search' => 'Borrador',
        'collection_id' => $collection->id,
        'status' => 'draft',
    ];

    $this->actingAs($owner)
        ->delete(route('polls.destroy', [
            'poll' => $poll,
            ...$filters,
        ]))
        ->assertRedirect(route('polls.index', $filters))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('polls', [
        'id' => $poll->id,
    ]);
});
