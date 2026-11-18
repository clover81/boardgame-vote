<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();

    $this->owner = User::factory()->create();

    $collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Colección',
    ]);

    $players = collect(['Ana', 'Bea'])->map(fn ($name) =>
        Player::create([
            'created_by' => $this->owner->id,
            'name' => $name,
            'is_active' => true,
        ])
    );

    $games = collect(['Heat', 'Cascadia'])->map(fn ($name) =>
        Game::create([
            'name' => $name,
            'min_players' => 1,
            'max_players' => 4,
            'game_mode' => 'competitive',
            'is_active' => true,
        ])
    );

    $collection->games()->attach($games->pluck('id')->all());

    $this->payload = [
        'name' => 'Sesión de prueba',
        'collection_ids' => [$collection->id],
        'player_ids' => $players->pluck('id')->all(),
        'candidate_ids' => $games->pluck('id')->all(),
    ];
});

test('repetir una solicitud no crea otra votación', function () {
    $payload = [
        ...$this->payload,
        'submission_key' => (string) Str::uuid(),
    ];

    $this->actingAs($this->owner)
        ->post(route('polls.store'), $payload)
        ->assertSessionHasNoErrors();

    $poll = Poll::sole();

    $this->post(route('polls.store'), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', $poll));

    $this->assertDatabaseCount('polls', 1);
    $this->assertDatabaseCount('poll_participants', 2);
    $this->assertDatabaseCount('poll_candidates', 2);
});

test('repetir el primer juego conserva la transición al segundo', function () {
    $payload = [
        ...$this->payload,
        'submission_key' => (string) Str::uuid(),
        'create_second_game' => true,
    ];

    $this->actingAs($this->owner)
        ->post(route('polls.store'), $payload)
        ->assertSessionHasNoErrors();

    $first = Poll::sole();

    $this->post(route('polls.store'), $payload)
        ->assertRedirect(route('polls.create', [
            'parent_poll_id' => $first->id,
        ]));

    $this->assertDatabaseCount('polls', 1);
});

test('repetir el segundo juego no crea un tercero', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), $this->payload)
        ->assertSessionHasNoErrors();

    $first = Poll::sole();

    $payload = [
        ...$this->payload,
        'name' => 'Segundo juego',
        'parent_poll_id' => $first->id,
        'submission_key' => (string) Str::uuid(),
    ];

    $this->post(route('polls.store'), $payload)
        ->assertSessionHasNoErrors();

    $second = Poll::where('parent_poll_id', $first->id)->sole();

    $this->post(route('polls.store'), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('polls.show', $second));

    $this->assertDatabaseCount('polls', 2);
    expect($first->secondGamePoll->id)->toBe($second->id);
});

test('claves diferentes permiten crear sesiones independientes', function () {
    $this->actingAs($this->owner);

    foreach (range(1, 2) as $number) {
        $this->post(route('polls.store'), [
            ...$this->payload,
            'submission_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    $this->assertDatabaseCount('polls', 2);
});

test('los formularios antiguos siguen funcionando', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), $this->payload)
        ->assertSessionHasNoErrors();

    expect(Poll::sole()->submission_key)->toBeNull();
});

test('una clave inválida no crea una votación', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), [
            ...$this->payload,
            'submission_key' => 'invalida',
        ])->assertSessionHasErrors('submission_key');

    $this->assertDatabaseCount('polls', 0);
});

test('la base de datos impide reutilizar una clave', function () {
    $key = (string) Str::uuid();

    Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Primera',
        'token' => 'KEYTEST01',
        'status' => 'draft',
        'submission_key' => $key,
    ]);

    expect(fn () => Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Duplicada',
        'token' => 'KEYTEST02',
        'status' => 'draft',
        'submission_key' => $key,
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    $this->assertDatabaseCount('polls', 1);
});
