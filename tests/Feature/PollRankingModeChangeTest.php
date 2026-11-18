<?php

use App\Models\Game;
use App\Models\Poll;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();

    $this->owner = User::factory()->create();

    $this->poll = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Sesión de prueba',
        'token' => 'RANKINGTEST',
        'status' => 'draft',
        'ranking_slots' => 2,
        'single_choice' => false,
    ]);

    $this->candidates = collect(range(1, 5))->map(function ($n) {
        $game = Game::create([
            'name' => 'Juego '.$n,
            'min_players' => 1,
            'max_players' => 5,
            'game_mode' => 'competitive',
            'is_active' => true,
        ]);

        return $this->poll->candidates()->create([
            'game_id' => $game->id,
        ]);
    });

    $this->participant = $this->poll->participants()->create([
        'display_name' => 'Ana',
    ]);
});

test('se puede pasar de dos a tres preferencias y despues a una', function () {
    $token = $this->poll->token;
    $voteToken = $this->participant->vote_token;

    $this->actingAs($this->owner)
        ->patch(route('polls.voting-mode.update', $this->poll), [
            'ranking_slots' => 3,
        ])->assertSessionHasNoErrors();

    expect($this->poll->refresh()->ranking_slots)->toBe(3)
        ->and($this->poll->single_choice)->toBeFalse()
        ->and($this->poll->requiredVoteCount(5))->toBe(3);

    $this->patch(route('polls.voting-mode.update', $this->poll), [
        'ranking_slots' => 1,
    ])->assertSessionHasNoErrors();

    expect($this->poll->refresh()->ranking_slots)->toBe(1)
        ->and($this->poll->single_choice)->toBeTrue()
        ->and($this->poll->token)->toBe($token)
        ->and($this->participant->fresh()->vote_token)->toBe($voteToken)
        ->and($this->poll->candidates()->count())->toBe(5);
});

test('se puede cambiar una votacion abierta mientras no tenga votos', function () {
    $this->poll->update(['status' => 'open']);

    $this->actingAs($this->owner)
        ->patch(route('polls.voting-mode.update', $this->poll), [
            'ranking_slots' => 3,
        ])->assertSessionHasNoErrors();

    expect($this->poll->refresh()->ranking_slots)->toBe(3);
});

test('con un voto guardado ya no se puede cambiar la puntuacion', function () {
    $this->poll->update(['status' => 'open']);

    $this->participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $this->candidates[0]->id,
        'position' => 1,
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.show', $this->poll))
        ->patch(route('polls.voting-mode.update', $this->poll), [
            'ranking_slots' => 3,
        ])->assertSessionHasErrors('poll');

    expect($this->poll->refresh()->ranking_slots)->toBe(2);
    $this->assertDatabaseCount('votes', 1);
});

test('se rechazan preferencias excesivas para los candidatos', function () {
    foreach ($this->candidates->slice(3) as $candidate) {
        $candidate->delete();
    }

    $this->actingAs($this->owner)
        ->patch(route('polls.voting-mode.update', $this->poll), [
            'ranking_slots' => 3,
        ])->assertSessionHasErrors('poll');

    expect($this->poll->refresh()->ranking_slots)->toBe(2);
});

test('no puede modificar la puntuacion otro usuario ni una votacion cerrada', function () {
    $url = route('polls.voting-mode.update', $this->poll);

    $this->actingAs(User::factory()->create())
        ->patch($url, ['ranking_slots' => 3])
        ->assertForbidden();

    $this->poll->update(['status' => 'closed']);

    $this->actingAs($this->owner)
        ->patch($url, ['ranking_slots' => 3])
        ->assertSessionHasErrors('poll');

    expect($this->poll->refresh()->ranking_slots)->toBe(2);
});

test('las votaciones antiguas mantienen su compatibilidad', function () {
    $this->poll->update([
        'ranking_slots' => null,
        'single_choice' => false,
    ]);

    $this->actingAs($this->owner)
        ->patch(route('polls.voting-mode.update', $this->poll), [
            'single_choice' => true,
        ])->assertSessionHasNoErrors();

    expect($this->poll->refresh()->ranking_slots)->toBeNull()
        ->and($this->poll->single_choice)->toBeTrue();

    // También pueden pasar al nuevo sistema antes de recibir votos.
    $this->patch(route('polls.voting-mode.update', $this->poll), [
        'ranking_slots' => 3,
    ])->assertSessionHasNoErrors();

    expect($this->poll->refresh()->ranking_slots)->toBe(3)
        ->and($this->poll->single_choice)->toBeFalse();
});

test('no se admiten valores invalidos ni dos sistemas simultaneos', function () {
    $this->actingAs($this->owner);

    $this->patch(route('polls.voting-mode.update', $this->poll), [
        'ranking_slots' => 4,
    ])->assertSessionHasErrors('ranking_slots');

    $this->patch(route('polls.voting-mode.update', $this->poll), [
        'ranking_slots' => 3,
        'single_choice' => true,
    ])->assertSessionHasErrors();

    expect($this->poll->refresh()->ranking_slots)->toBe(2);
});


test('al cambiar a tres preferencias el voto publico exige tres y puntua correctamente', function () {
    $this->poll->update(['status' => 'open']);

    $this->actingAs($this->owner)
        ->patch(route('polls.voting-mode.update', $this->poll), [
            'ranking_slots' => 3,
        ])
        ->assertSessionHasNoErrors();

    $publicUrl = route('vote.participant.show', [
        'token' => $this->poll->token,
        'voteToken' => $this->participant->vote_token,
    ]);

    $voteUrl = route('vote.participant.store', [
        'token' => $this->poll->token,
        'voteToken' => $this->participant->vote_token,
    ]);

    $this->get($publicUrl)
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) =>
            $page->where('poll.required_vote_count', 3)
                ->where('poll.ranking_slots', 3)
        );

    $first = $this->candidates[0]->id;
    $second = $this->candidates[1]->id;
    $third = $this->candidates[2]->id;

    // Un formulario antiguo que pide dos preferencias debe rechazarse.
    $this->from($publicUrl)->post($voteUrl, [
        'first_candidate_id' => $first,
        'second_candidate_id' => $second,
        'ranking_slots' => 2,
    ])->assertSessionHasErrors('vote');

    // Incluso con el modo correcto, faltaría la tercera opción.
    $this->from($publicUrl)->post($voteUrl, [
        'first_candidate_id' => $first,
        'second_candidate_id' => $second,
        'ranking_slots' => 3,
    ])->assertSessionHasErrors('third_candidate_id');

    $this->assertDatabaseCount('votes', 0);

    // Las tres preferencias se guardan correctamente.
    $this->from($publicUrl)->post($voteUrl, [
        'first_candidate_id' => $first,
        'second_candidate_id' => $second,
        'third_candidate_id' => $third,
        'ranking_slots' => 3,
    ])->assertRedirect($publicUrl)->assertSessionHasNoErrors();

    $saved = $this->participant->votes()
        ->orderBy('position')
        ->pluck('poll_candidate_id')
        ->all();

    expect($saved)->toBe([$first, $second, $third]);

    $this->assertDatabaseCount('votes', 3);

    // Las reglas quedan bloqueadas después del primer voto.
    $this->patch(route('polls.voting-mode.update', $this->poll), [
        'ranking_slots' => 2,
    ])->assertSessionHasErrors('poll');

    expect($this->poll->refresh()->ranking_slots)->toBe(3);

    // Al cerrar, la clasificación utiliza 3, 2 y 1 puntos.
    $this->patch(route('polls.close', $this->poll))
        ->assertSessionHasNoErrors();

    $this->get($publicUrl)
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) =>
            $page->where('poll.status', 'closed')
                ->where('poll.has_clear_winner', true)
                ->where('results.0.candidate_id', $first)
                ->where('results.0.points', 3)
                ->where('results.1.candidate_id', $second)
                ->where('results.1.points', 2)
                ->where('results.2.candidate_id', $third)
                ->where('results.2.points', 1)
        );
});
