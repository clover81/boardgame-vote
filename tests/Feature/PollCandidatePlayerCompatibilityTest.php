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
    ]);

    $this->players = collect(range(1, 5))->map(fn ($number) =>
        Player::create([
            'created_by' => $this->owner->id,
            'name' => 'Jugador '.$number,
            'is_active' => true,
        ])
    );

    $this->game = Game::create([
        'name' => 'Juego de prueba',
        'min_players' => 1,
        'max_players' => 5,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);

    $this->collection->games()->attach($this->game->id);
});

test('no se crea una votacion con candidatos fuera del rango de jugadores', function (
    int $minimum,
    int $maximum,
    int $playerCount
) {
    $this->game->update([
        'min_players' => $minimum,
        'max_players' => $maximum,
    ]);

    $this->actingAs($this->owner)
        ->post(route('polls.store'), [
            'name' => 'Partida de prueba',
            'collection_ids' => [$this->collection->id],
            'player_ids' => $this->players
                ->take($playerCount)->pluck('id')->all(),
            'candidate_ids' => [$this->game->id],
        ])
        ->assertSessionHasErrors('candidate_ids');

    $this->assertDatabaseCount('polls', 0);
})->with([
    'demasiados jugadores' => [1, 3, 5],
    'menos jugadores de los necesarios' => [4, 5, 3],
]);

test('se permite un candidato que admite exactamente ese numero de jugadores', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.store'), [
            'name' => 'Partida válida',
            'collection_ids' => [$this->collection->id],
            'player_ids' => $this->players->pluck('id')->all(),
            'candidate_ids' => [$this->game->id],
        ])
        ->assertSessionHasNoErrors();

    expect(Poll::sole()->candidates()->count())->toBe(1);
});

test('no se añade un candidato incompatible a una votacion existente', function () {
    $poll = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Partida existente',
        'token' => 'COMPATIBILITYTEST',
        'status' => 'draft',
    ]);

    $poll->gameCollections()->attach($this->collection->id);

    foreach (range(1, 5) as $number) {
        $poll->participants()->create([
            'display_name' => 'Jugador '.$number,
        ]);
    }

    $this->game->update([
        'min_players' => 1,
        'max_players' => 3,
    ]);

    $this->actingAs($this->owner)
        ->post(route('polls.candidates.store', $poll), [
            'game_id' => $this->game->id,
        ])
        ->assertSessionHasErrors('game_id');

    expect($poll->candidates()->count())->toBe(0);

    $this->game->update(['max_players' => 5]);

    $this->post(route('polls.candidates.store', $poll), [
        'game_id' => $this->game->id,
    ])->assertSessionHasNoErrors();

    expect($poll->candidates()->count())->toBe(1);
});


test('añadir candidatos solo ofrece juegos compatibles con el grupo', function () {
    $this->withoutVite();

    $poll = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Votación con cinco jugadores',
        'token' => 'COMPATIBLELIST',
        'status' => 'draft',
    ]);

    $poll->gameCollections()->attach($this->collection->id);

    foreach ($this->players as $player) {
        $poll->participants()->create([
            'player_id' => $player->id,
            'display_name' => $player->name,
        ]);
    }

    // No ofrecer juegos limitados a tres personas.
    $this->game->update(['max_players' => 3]);

    $this->actingAs($this->owner)
        ->get(route('polls.show', $poll))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canAddCandidates', true)
            ->has('availableGames', 0)
        );

    // Al admitir cinco jugadores, vuelve a aparecer.
    $this->game->update(['max_players' => 5]);

    $this->get(route('polls.show', $poll))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('availableGames', 1)
            ->where('availableGames.0.id', $this->game->id)
        );
});
