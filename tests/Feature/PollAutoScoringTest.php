<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Player;
use App\Models\Poll;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();

    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Colección de prueba',
        'is_default' => true,
    ]);

    $this->players = collect(range(1, 5))
        ->map(fn ($number) => Player::create([
            'created_by' => $this->owner->id,
            'name' => 'Jugador '.$number,
            'is_active' => true,
        ]));

    $this->games = collect(range(1, 7))
        ->map(fn ($number) => Game::create([
            'name' => 'Juego '.$number,
            'min_players' => 1,
            'max_players' => 5,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]));

    $this->collection->games()->attach(
        $this->games->pluck('id')
    );
});

test('la puntuacion automatica depende de jugadores y candidatos', function (
    int $playerCount,
    int $candidateCount,
    int $expected
) {
    $this->actingAs($this->owner)->post(route('polls.store'), [
        'name' => 'Partida de prueba',
        'collection_ids' => [$this->collection->id],
        'player_ids' => $this->players
            ->take($playerCount)->pluck('id')->all(),
        'candidate_ids' => $this->games
            ->take($candidateCount)->pluck('id')->all(),
        'ranking_slots' => null,
    ])->assertSessionHasNoErrors();

    $poll = Poll::sole();

    expect($poll->ranking_slots)->toBe($expected)
        ->and($poll->requiredVoteCount($candidateCount))->toBe($expected)
        ->and($poll->single_choice)->toBe($expected === 1);
})->with([
    'dos candidatos' => [4, 2, 1],
    'tres candidatos' => [4, 3, 2],
    'cinco candidatos y cinco jugadores' => [5, 5, 3],
    'cinco candidatos y cuatro jugadores' => [4, 5, 2],
    'seis candidatos y tres personas' => [3, 6, 2],
    'seis candidatos y cuatro personas' => [4, 6, 3],
    'siete candidatos y cinco personas' => [5, 7, 3],
]);

test('se puede personalizar la puntuacion sin puntuar todos los candidatos', function () {
    $payload = [
        'name' => 'Partida personalizada',
        'collection_ids' => [$this->collection->id],
        'player_ids' => $this->players->take(4)->pluck('id')->all(),
        'candidate_ids' => $this->games->take(5)->pluck('id')->all(),
        'ranking_slots' => 3,
    ];

    $this->actingAs($this->owner)
        ->post(route('polls.store'), $payload)
        ->assertSessionHasNoErrors();

    expect(Poll::sole()->ranking_slots)->toBe(3);
});

test('se rechazan preferencias excesivas sin crear la votacion', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), [
            'name' => 'Partida no valida',
            'collection_ids' => [$this->collection->id],
            'player_ids' => $this->players->take(4)->pluck('id')->all(),
            'candidate_ids' => $this->games->take(3)->pluck('id')->all(),
            'ranking_slots' => 3,
        ])
        ->assertSessionHasErrors('ranking_slots');

    $this->assertDatabaseCount('polls', 0);
});

test('las dos votaciones de la sesion calculan puntos por separado', function () {
    $common = [
        'collection_ids' => [$this->collection->id],
        'ranking_slots' => null,
    ];

    $this->actingAs($this->owner)
        ->post(route('polls.store'), [
            ...$common,
            'name' => 'Juego 1',
            'player_ids' => $this->players->take(4)->pluck('id')->all(),
            'candidate_ids' => $this->games->take(6)->pluck('id')->all(),
            'create_second_game' => true,
        ])
        ->assertSessionHasNoErrors();

    $first = Poll::sole();

    $this->post(route('polls.store'), [
        ...$common,
        'name' => 'Juego 2',
        'parent_poll_id' => $first->id,
        'player_ids' => $this->players->take(2)->pluck('id')->all(),
        'candidate_ids' => $this->games->take(3)->pluck('id')->all(),
    ])->assertSessionHasNoErrors();

    $second = Poll::where('parent_poll_id', $first->id)->sole();

    expect($first->ranking_slots)->toBe(3)
        ->and($second->ranking_slots)->toBe(2)
        ->and($first->participants()->count())->toBe(4)
        ->and($second->participants()->count())->toBe(2)
        ->and($first->candidates()->count())->toBe(6)
        ->and($second->candidates()->count())->toBe(3);
});
