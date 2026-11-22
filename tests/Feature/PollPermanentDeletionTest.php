<?php

use App\Models\Game;
use App\Models\Play;
use App\Models\Poll;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();

    $this->owner = User::factory()->create();

    $this->game = Game::create([
        'name' => 'Juego conservado',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
    ]);

    $this->poll = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Votación de pruebas',
        'token' => 'PERMANENTDELETE1',
        'status' => 'draft',
    ]);
});

test('una votación abierta o cerrada con votos se elimina solo con confirmación', function ($status) {
    $this->poll->update(['status' => $status]);

    $candidate = $this->poll->candidates()->create([
        'game_id' => $this->game->id,
    ]);

    $participant = $this->poll->participants()->create([
        'display_name' => 'Invitada',
    ]);

    $participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);

    $this->actingAs($this->owner)
        ->delete(
            route('polls.destroy', $this->poll),
            ['confirmation' => 'ELIMINAR']
        )
        ->assertRedirect(route('polls.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('polls', ['id' => $this->poll->id]);
    $this->assertDatabaseMissing('votes', ['poll_id' => $this->poll->id]);
    $this->assertDatabaseMissing('poll_candidates', ['id' => $candidate->id]);
    $this->assertDatabaseMissing('poll_participants', ['id' => $participant->id]);
    $this->assertDatabaseHas('games', ['id' => $this->game->id]);
})->with(['open', 'closed']);

test('una votación con actividad no se borra con una confirmación incorrecta', function ($status, $confirmation) {
    $this->poll->update(['status' => $status]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(
            route('polls.destroy', $this->poll),
            ['confirmation' => $confirmation]
        )
        ->assertRedirect(route('polls.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
})->with([
    'open', 'closed',
])->with([
    'empty' => '',
    'wrong' => 'BORRAR',
]);

test('un borrador con votos requiere confirmación expresa', function () {
    $candidate = $this->poll->candidates()->create([
        'game_id' => $this->game->id,
    ]);

    $participant = $this->poll->participants()->create([
        'display_name' => 'Participante',
    ]);

    $participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(route('polls.destroy', $this->poll))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);

    $this->delete(
        route('polls.destroy', $this->poll),
        ['confirmation' => 'ELIMINAR']
    )->assertSessionHas('success');

    $this->assertDatabaseMissing('polls', ['id' => $this->poll->id]);
});

test('una partida registrada impide eliminar incluso una votación cerrada', function () {
    $this->poll->update(['status' => 'closed']);

    $play = Play::create([
        'created_by' => $this->owner->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-11-18',
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(
            route('polls.destroy', $this->poll),
            ['confirmation' => 'ELIMINAR']
        )
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
    $this->assertDatabaseHas('plays', [
        'id' => $play->id,
        'poll_id' => $this->poll->id,
    ]);
});

test('primero se elimina el segundo juego y se conserva el primero', function () {
    $this->poll->update(['status' => 'closed']);

    $second = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Segundo juego',
        'token' => 'PERMANENTSECOND',
        'status' => 'closed',
        'parent_poll_id' => $this->poll->id,
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(
            route('polls.destroy', $this->poll),
            ['confirmation' => 'ELIMINAR']
        )
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $second->id]);

    $this->delete(
        route('polls.destroy', $second),
        ['confirmation' => 'ELIMINAR']
    )->assertSessionHas('success');

    $this->assertDatabaseMissing('polls', ['id' => $second->id]);
    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
});

test('un segundo juego no se elimina si el primero tiene una partida', function () {
    $second = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Segundo juego',
        'token' => 'PERMANENTSECOND2',
        'status' => 'draft',
        'parent_poll_id' => $this->poll->id,
    ]);

    Play::create([
        'created_by' => $this->owner->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-11-18',
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(route('polls.destroy', $second))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $second->id]);
});

test('una votación original no elimina en cascada su desempate', function () {
    $round = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Desempate',
        'token' => 'PERMANENTTIE',
        'status' => 'closed',
        'tie_break_for_poll_id' => $this->poll->id,
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(route('polls.destroy', $this->poll))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
    $this->assertDatabaseHas('polls', ['id' => $round->id]);
});

test('eliminar un desempate sin partidas conserva el original y limpia su sorteo', function () {
    $this->poll->update([
        'status' => 'closed',
        'tie_break_draw_winner_game_id' => $this->game->id,
        'tie_break_drawn_at' => now(),
    ]);

    $round = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Desempate',
        'token' => 'PERMANENTTIE2',
        'status' => 'closed',
        'tie_break_for_poll_id' => $this->poll->id,
    ]);

    $this->actingAs($this->owner)
        ->delete(
            route('polls.destroy', $round),
            ['confirmation' => 'ELIMINAR']
        )
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('polls', ['id' => $round->id]);

    $this->assertDatabaseHas('polls', [
        'id' => $this->poll->id,
        'tie_break_draw_winner_game_id' => null,
        'tie_break_drawn_at' => null,
    ]);
});

test('otra cuenta no puede eliminar una votación cerrada', function () {
    $this->poll->update(['status' => 'closed']);

    $this->actingAs(User::factory()->create())
        ->delete(
            route('polls.destroy', $this->poll),
            ['confirmation' => 'ELIMINAR']
        )
        ->assertForbidden();

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
});

test('el borrado definitivo mantiene los filtros del listado', function () {
    $this->poll->update(['status' => 'closed']);

    $filters = ['search' => 'pruebas', 'status' => 'closed'];

    $this->actingAs($this->owner)
        ->delete(
            route('polls.destroy', [
                'poll' => $this->poll,
                ...$filters,
            ]),
            ['confirmation' => 'ELIMINAR']
        )
        ->assertRedirect(route('polls.index', $filters));
});

test('el listado informa del numero de votos y bloqueos de borrado', function () {
    $this->poll->update(['status' => 'closed']);

    $candidate = $this->poll->candidates()->create([
        'game_id' => $this->game->id,
    ]);

    $participant = $this->poll->participants()->create([
        'display_name' => 'Ana',
    ]);

    $participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $candidate->id,
        'position' => 1,
    ]);

    $rows = $this->actingAs($this->owner)
        ->get(route('polls.index'))
        ->assertOk()
        ->inertiaProps('polls');

    $row = collect($rows)->firstWhere('id', $this->poll->id);

    expect($row['votes_count'])->toBe(1)
        ->and($row['delete_block_reason'])->toBeNull();

    Play::create([
        'created_by' => $this->owner->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-11-18',
    ]);

    $rows = $this->get(route('polls.index'))
        ->assertOk()
        ->inertiaProps('polls');

    $row = collect($rows)->firstWhere('id', $this->poll->id);

    expect($row['delete_block_reason'])
        ->toBe('Tiene partidas registradas. No puede eliminarse.');
});

test('el listado protege una votacion original con desempate', function () {
    $round = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Ronda de desempate',
        'token' => 'CHECKTIEROUND',
        'status' => 'draft',
        'tie_break_for_poll_id' => $this->poll->id,
    ]);

    $rows = $this->actingAs($this->owner)
        ->get(route('polls.index'))
        ->assertOk()
        ->inertiaProps('polls');

    $original = collect($rows)->firstWhere('id', $this->poll->id);
    $tie = collect($rows)->firstWhere('id', $round->id);

    expect($original['delete_block_reason'])
        ->toBe('Elimina primero su ronda de desempate.')
        ->and($tie['delete_block_reason'])
        ->toBeNull();
});

test('el listado protege el segundo juego si el primero tiene partidas', function () {
    $second = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Segundo juego',
        'token' => 'CHECKSECONDPLAY',
        'status' => 'draft',
        'parent_poll_id' => $this->poll->id,
    ]);

    Play::create([
        'created_by' => $this->owner->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-11-18',
    ]);

    $rows = $this->actingAs($this->owner)
        ->get(route('polls.index'))
        ->assertOk()
        ->inertiaProps('polls');

    $secondRow = collect($rows)->firstWhere('id', $second->id);

    expect($secondRow['delete_block_reason'])
        ->toBe('Pertenece a una sesión con partidas registradas.');

    $this->assertDatabaseHas('polls', ['id' => $second->id]);
});

test('un desempate no se elimina si el original tiene partidas', function () {
    $round = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Desempate protegido',
        'token' => 'CHECKTIEPLAY',
        'status' => 'closed',
        'tie_break_for_poll_id' => $this->poll->id,
    ]);

    Play::create([
        'created_by' => $this->owner->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-11-18',
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(
            route('polls.destroy', $round),
            ['confirmation' => 'ELIMINAR']
        )
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $round->id]);
    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
});
