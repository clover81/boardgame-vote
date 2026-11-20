<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Poll;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();

    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();

    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Colección de prueba',
        'is_default' => true,
    ]);

    $this->games = collect([
        'Coloma', 'Rococo', 'Power Grid',
    ])->map(fn ($name) => Game::create([
        'name' => $name,
        'min_players' => 1,
        'max_players' => 6,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]));

    $this->collection->games()->attach(
        $this->games->pluck('id')->all()
    );

    $this->poll = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Votación original',
        'token' => 'TIEBREAKBASE',
        'status' => 'closed',
        'ranking_slots' => 1,
        'single_choice' => true,
    ]);

    $this->poll->gameCollections()->attach(
        $this->collection->id
    );

    $this->candidates = $this->games->map(
        fn ($game) => $this->poll->candidates()->create([
            'game_id' => $game->id,
        ])
    );

    $this->participants = collect(range(1, 4))->map(
        fn ($i) => $this->poll->participants()->create([
            'display_name' => 'Jugador '.$i,
        ])
    );

    // Coloma: 2 votos; Rococo: 2; Power Grid: 0.
    foreach ([0, 1, 0, 1] as $i => $gameIndex) {
        $this->participants[$i]->votes()->create([
            'poll_id' => $this->poll->id,
            'poll_candidate_id' => $this->candidates[$gameIndex]->id,
            'position' => 1,
        ]);
    }
});

test('crea una ronda con los empatados sin alterar la original', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    expect($round->status)->toBe('draft')
        ->and($round->ranking_slots)->toBe(1)
        ->and($round->single_choice)->toBeTrue()
        ->and($round->tie_break_for_poll_id)->toBe($this->poll->id)
        ->and($round->participants()->count())->toBe(4)
        ->and($round->candidates()->count())->toBe(2)
        ->and($round->votes()->count())->toBe(0);

    $expectedGames = $this->games->take(2)
        ->pluck('id')->sort()->values()->all();

    $actualGames = $round->candidates()
        ->pluck('game_id')->sort()->values()->all();

    expect($actualGames)->toBe($expectedGames);

    $originalNames = $this->poll->participants()
        ->pluck('display_name')->sort()->values()->all();

    $roundNames = $round->participants()
        ->pluck('display_name')->sort()->values()->all();

    expect($roundNames)->toBe($originalNames);

    $oldTokens = $this->poll->participants()
        ->pluck('vote_token')->all();

    $newTokens = $round->participants()
        ->pluck('vote_token')->all();

    expect(array_intersect($oldTokens, $newTokens))->toBe([]);

    expect(
        $round->gameCollections()->get()->pluck('id')->all()
    )->toBe([$this->collection->id]);

    expect($this->poll->fresh()->status)->toBe('closed');

    $this->assertDatabaseCount('votes', 4);
    $this->assertDatabaseCount('polls', 2);
});

test('solo el propietario puede crear un desempate', function () {
    $url = route('polls.tie-break.store', $this->poll);

    $this->post($url)->assertRedirect();

    $this->actingAs($this->other)
        ->post($url)
        ->assertForbidden();

    $this->assertDatabaseCount('polls', 1);
});

test('pulsar dos veces no duplica el desempate', function () {
    $url = route('polls.tie-break.store', $this->poll);

    $this->actingAs($this->owner)
        ->post($url)->assertSessionHasNoErrors();

    $roundId = $this->poll->tieBreakPoll()->firstOrFail()->id;

    $this->post($url)
        ->assertRedirect(route('polls.show', $roundId));

    $this->assertDatabaseCount('polls', 2);

    expect($this->poll->tieBreakPoll()->count())->toBe(1);
});

test('rechaza votaciones sin empate o no cerradas', function () {
    // Coloma pasa a tener tres votos frente a uno.
    $this->participants[1]->votes()->update([
        'poll_candidate_id' => $this->candidates[0]->id,
    ]);

    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasErrors('poll');

    $this->poll->update(['status' => 'draft']);

    $this->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasErrors('poll');

    $this->assertDatabaseCount('polls', 1);
});

test('el desempate mantiene bloqueadas sus reglas y candidatos', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    $this->patch(route('polls.voting-mode.update', $round), [
        'ranking_slots' => 2,
    ])->assertSessionHasErrors('poll');

    $this->post(route('polls.candidates.store', $round), [
        'game_id' => $this->games[2]->id,
    ])->assertSessionHasErrors('game_id');

    $this->get(route('polls.create', [
        'parent_poll_id' => $round->id,
    ]))->assertStatus(409);

    $this->get(route('polls.show', $round))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('polls/show')
            ->where('canAddCandidates', false)
            ->where('poll.can_add_second_game', false)
        );

    expect($round->fresh()->ranking_slots)->toBe(1)
        ->and($round->candidates()->count())->toBe(2);
});


test('el resultado definitivo distingue victoria y nuevo empate', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    // Un borrador nunca proclama ganador.
    $draft = \App\Support\PollTieBreakOutcome::forOriginal($this->poll);

    expect($draft['status'])->toBe('draft')
        ->and($draft['winner'])->toBeNull();

    $round->update(['status' => 'closed']);

    $candidates = $round->candidates()->orderBy('id')->get();
    $participants = $round->participants()->orderBy('id')->get();

    // Tres votos frente a uno.
    foreach ($participants as $index => $participant) {
        $candidate = $index < 3 ? $candidates[0] : $candidates[1];

        $participant->votes()->create([
            'poll_id' => $round->id,
            'poll_candidate_id' => $candidate->id,
            'position' => 1,
        ]);
    }

    $resolved = \App\Support\PollTieBreakOutcome::forOriginal($this->poll);

    expect($resolved['status'])->toBe('closed')
        ->and($resolved['winner']['game_id'])->toBe(
            (int) $candidates[0]->game_id
        )
        ->and($resolved['winner']['votes'])->toBe(3);

    $this->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.has_clear_winner', false)
            ->where(
                'poll.tie_break_outcome.winner.game_id',
                (int) $candidates[0]->game_id
            )
            ->where('poll.tie_break_outcome.winner.votes', 3)
        );

    // Si quedan dos votos para cada uno, no inventamos ganador.
    $participants[2]->votes()->update([
        'poll_candidate_id' => $candidates[1]->id,
    ]);

    $tied = \App\Support\PollTieBreakOutcome::forOriginal($this->poll);

    expect($tied['winner'])->toBeNull();

    // Los cuatro votos de la votación original permanecen intactos.
    expect($this->poll->votes()->count())->toBe(4);
});


test('el resultado publico muestra el ganador del desempate', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    $url = route('vote.participant.show', [
        'token' => $this->poll->token,
        'voteToken' => $this->participants[0]->vote_token,
    ]);

    // Mientras se prepara, no hay ganador definitivo.
    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.has_clear_winner', false)
            ->where('poll.tie_break_outcome.status', 'draft')
            ->where('poll.tie_break_outcome.winner', null)
        );

    $candidates = $round->candidates()->orderBy('id')->get();
    $participants = $round->participants()->orderBy('id')->get();

    foreach ($participants as $index => $participant) {
        $candidate = $index < 3
            ? $candidates[0]
            : $candidates[1];

        $participant->votes()->create([
            'poll_id' => $round->id,
            'poll_candidate_id' => $candidate->id,
            'position' => 1,
        ]);
    }

    $round->update(['status' => 'closed']);

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('vote/show')
            ->where('poll.has_clear_winner', false)
            ->where('poll.tie_break_outcome.status', 'closed')
            ->where(
                'poll.tie_break_outcome.winner.game_id',
                (int) $candidates[0]->game_id
            )
            ->where('poll.tie_break_outcome.winner.votes', 3)
        );

    expect($this->poll->votes()->count())->toBe(4);
});


test('el registro usa el ganador definitivo sin duplicar la partida', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    // La original sigue empatada mientras el desempate está pendiente.
    $this->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.has_clear_winner', false)
            ->where('defaults.game_ids', [])
        );

    $candidates = $round->candidates()->orderBy('id')->get();
    $participants = $round->participants()->orderBy('id')->get();

    foreach ($participants as $index => $participant) {
        $candidate = $index < 3 ? $candidates[0] : $candidates[1];

        $participant->votes()->create([
            'poll_id' => $round->id,
            'poll_candidate_id' => $candidate->id,
            'position' => 1,
        ]);
    }

    $round->update(['status' => 'closed']);

    $winnerId = (int) $candidates[0]->game_id;

    // El formulario original preselecciona el ganador definitivo.
    $this->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('plays/create')
            ->where('poll.id', $this->poll->id)
            ->where('poll.has_clear_winner', true)
            ->where('defaults.game_ids', [$winnerId])
        );

    // Desde la ronda auxiliar se vuelve al registro original.
    $this->get(route('plays.create-from-poll', $round))
        ->assertRedirect(route('plays.create-from-poll', $this->poll));

    $player = \App\Models\Player::create([
        'created_by' => $this->owner->id,
        'name' => 'Jugador de prueba',
        'is_active' => true,
    ]);

    // Una petición directa tampoco puede registrar en la ronda auxiliar.
    $this->post(route('plays.store'), [
        'poll_id' => $round->id,
        'game_ids' => [$winnerId],
        'player_ids' => [$player->id],
        'played_at' => now()->toDateString(),
    ])->assertSessionHasErrors('poll_id');

    $this->assertDatabaseCount('plays', 0);

    // Si la ronda vuelve a empatar, no existe ganador preseleccionado.
    $participants[2]->votes()->update([
        'poll_candidate_id' => $candidates[1]->id,
    ]);

    $this->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.has_clear_winner', false)
            ->where('defaults.game_ids', [])
        );

    expect($this->poll->votes()->count())->toBe(4);
});


test('el listado identifica la ronda de desempate y su partida original', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    $rows = $this->get(route('polls.index'))
        ->assertOk()
        ->inertiaProps('polls');

    $originalRow = collect($rows)->firstWhere('id', $this->poll->id);
    $roundRow = collect($rows)->firstWhere('id', $round->id);

    expect($originalRow['tie_break_for_poll_id'])->toBeNull()
        ->and($roundRow['tie_break_for_poll_id'])->toBe($this->poll->id)
        ->and($roundRow['session_position'])->toBeNull()
        ->and($roundRow['has_registered_play'])->toBeFalse();

    \App\Models\Play::create([
        'created_by' => $this->owner->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->games[0]->id,
        'played_at' => now()->toDateString(),
    ]);

    $updated = $this->get(route('polls.index'))
        ->assertOk()
        ->inertiaProps('polls');

    expect(collect($updated)->firstWhere('id', $this->poll->id)['has_registered_play'])
        ->toBeTrue()
        ->and(collect($updated)->firstWhere('id', $round->id)['has_registered_play'])
        ->toBeTrue();
});

test('eliminar un desempate en borrador conserva la votacion original', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    $this->delete(route('polls.destroy', $round))
        ->assertRedirect(route('polls.index'));

    $this->assertDatabaseMissing('polls', ['id' => $round->id]);
    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);

    expect($this->poll->fresh()->status)->toBe('closed')
        ->and($this->poll->votes()->count())->toBe(4)
        ->and($this->poll->tieBreakPoll()->exists())->toBeFalse();
});


test('el sorteo registra un ganador una sola vez', function () {
    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();
    $candidates = $round->candidates()->orderBy('id')->get();
    $participants = $round->participants()->orderBy('id')->get();

    // Empate 2-2 en la ronda adicional.
    foreach ($participants as $index => $participant) {
        $participant->votes()->create([
            'poll_id' => $round->id,
            'poll_candidate_id' => $candidates[$index % 2]->id,
            'position' => 1,
        ]);
    }

    $round->update(['status' => 'closed']);

    $url = route('polls.tie-break.draw', $this->poll);

    $this->post($url)
        ->assertRedirect(route('polls.show', $this->poll))
        ->assertSessionHasNoErrors();

    $original = $this->poll->fresh();

    $winnerId = (int) $original->tie_break_draw_winner_game_id;
    $fecha = $original->tie_break_drawn_at?->toISOString();

    expect($winnerId)->toBeIn(
        $candidates->pluck('game_id')
            ->map(fn ($id) => (int) $id)
            ->all()
    );

    expect($fecha)->not->toBeNull();

    $resultado = \App\Support\PollTieBreakOutcome::forOriginal($original);

    expect($resultado['winner']['game_id'])->toBe($winnerId)
        ->and($resultado['winner']['method'])->toBe('draw')
        ->and($resultado['winner']['votes'])->toBe(2);

    // Vista privada, pública y registro: mismo ganador.
    $this->get(route('polls.show', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.tie_break_outcome.winner.game_id', $winnerId)
            ->where('poll.tie_break_outcome.winner.method', 'draw')
        );

    $this->get(route('vote.participant.show', [
        'token' => $this->poll->token,
        'voteToken' => $this->participants[0]->vote_token,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('poll.tie_break_outcome.winner.game_id', $winnerId)
            ->where('poll.tie_break_outcome.winner.method', 'draw')
        );

    $this->get(route('plays.create-from-poll', $this->poll))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('defaults.game_ids', [$winnerId])
        );

    // El segundo intento no cambia ni ganador ni fecha.
    $this->post($url)
        ->assertRedirect(route('polls.show', $this->poll));

    expect(
        (int) $this->poll->fresh()->tie_break_draw_winner_game_id
    )->toBe($winnerId);

    expect(
        $this->poll->fresh()->tie_break_drawn_at?->toISOString()
    )->toBe($fecha);

    $this->assertDatabaseCount('polls', 2);
    $this->assertDatabaseCount('votes', 8);
});

test('el sorteo rechaza estados invalidos y usuarios ajenos', function () {
    $url = route('polls.tie-break.draw', $this->poll);

    $this->post($url)->assertRedirect(route('login'));

    $this->actingAs($this->other)
        ->post($url)->assertForbidden();

    $this->actingAs($this->owner)
        ->post($url)->assertSessionHasErrors('poll');

    $this->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    // No se sortea mientras está en borrador.
    $this->post($url)->assertSessionHasErrors('poll');

    $round->update(['status' => 'closed']);

    // Tampoco una ronda sin votos.
    $this->post($url)->assertSessionHasErrors('poll');

    $candidates = $round->candidates()->orderBy('id')->get();

    // Con un 3-1 ya existe ganador por votos.
    foreach ($round->participants()->orderBy('id')->get() as $index => $participant) {
        $participant->votes()->create([
            'poll_id' => $round->id,
            'poll_candidate_id' => $index < 3
                ? $candidates[0]->id
                : $candidates[1]->id,
            'position' => 1,
        ]);
    }

    $this->post($url)->assertSessionHasErrors('poll');

    $resultado = \App\Support\PollTieBreakOutcome::forOriginal(
        $this->poll->fresh()
    );

    expect($resultado['winner']['method'])->toBe('votes')
        ->and($this->poll->fresh()->tie_break_draw_winner_game_id)
        ->toBeNull();
});

test('el sorteo excluye candidatos que no lideran', function () {
    // Crear un empate original entre tres juegos: 3-3-3.
    foreach ([0, 1, 2, 2, 2] as $index => $gameIndex) {
        $participant = $this->poll->participants()->create([
            'display_name' => 'Participante adicional '.$index,
        ]);

        $participant->votes()->create([
            'poll_id' => $this->poll->id,
            'poll_candidate_id' => $this->candidates[$gameIndex]->id,
            'position' => 1,
        ]);
    }

    $this->actingAs($this->owner)
        ->post(route('polls.tie-break.store', $this->poll))
        ->assertSessionHasNoErrors();

    $round = $this->poll->tieBreakPoll()->firstOrFail();

    $candidates = $round->candidates()->orderBy('id')->get();
    $participants = $round->participants()->orderBy('id')->get();

    expect($candidates)->toHaveCount(3);
    expect($participants)->toHaveCount(9);

    // Resultado del desempate: 4-4-1.
    foreach ($participants as $index => $participant) {
        $candidateIndex = $index < 4 ? 0 : ($index < 8 ? 1 : 2);

        $participant->votes()->create([
            'poll_id' => $round->id,
            'poll_candidate_id' => $candidates[$candidateIndex]->id,
            'position' => 1,
        ]);
    }

    $round->update(['status' => 'closed']);

    $this->post(route('polls.tie-break.draw', $this->poll))
        ->assertSessionHasNoErrors();

    $winnerId = (int) $this->poll->fresh()->tie_break_draw_winner_game_id;

    expect($winnerId)->toBeIn([
        (int) $candidates[0]->game_id,
        (int) $candidates[1]->game_id,
    ]);

    expect($winnerId)->not->toBe(
        (int) $candidates[2]->game_id
    );
});
