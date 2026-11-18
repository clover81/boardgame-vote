<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Poll;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();

    $this->owner = User::factory()->create();

    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Colección de prueba',
    ]);

    $this->game = Game::create([
        'name' => 'White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
    ]);

    $this->collection->games()->attach($this->game->id);

    $this->poll = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Borrador de prueba',
        'token' => 'SAFEDELETE01',
        'status' => 'draft',
    ]);

    $this->participant = $this->poll->participants()->create([
        'display_name' => 'Ana',
    ]);

    $this->candidate = $this->poll->candidates()->create([
        'game_id' => $this->game->id,
    ]);

    $this->poll->gameCollections()->attach($this->collection->id);
});

test('se puede eliminar un borrador sin actividad', function () {
    $this->actingAs($this->owner)
        ->delete(route('polls.destroy', $this->poll))
        ->assertRedirect(route('polls.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('polls', ['id' => $this->poll->id]);

    $this->assertDatabaseMissing('poll_participants', [
        'id' => $this->participant->id,
    ]);

    $this->assertDatabaseMissing('poll_candidates', [
        'id' => $this->candidate->id,
    ]);

    $this->assertDatabaseMissing('poll_game_collection', [
        'poll_id' => $this->poll->id,
    ]);

    // Los datos del catálogo se conservan.
    $this->assertDatabaseHas('games', ['id' => $this->game->id]);

    $this->assertDatabaseHas('game_collections', [
        'id' => $this->collection->id,
    ]);
});

test('borrar el segundo juego conserva el primero', function () {
    $second = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Segundo juego',
        'token' => 'SAFEDELETE02',
        'status' => 'draft',
        'parent_poll_id' => $this->poll->id,
    ]);

    $second->participants()->create(['display_name' => 'Bea']);
    $second->candidates()->create(['game_id' => $this->game->id]);

    $this->actingAs($this->owner)
        ->delete(route('polls.destroy', $second))
        ->assertRedirect(route('polls.index'));

    $this->assertDatabaseMissing('polls', ['id' => $second->id]);
    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);

    $this->assertDatabaseHas('poll_candidates', [
        'id' => $this->candidate->id,
        'poll_id' => $this->poll->id,
    ]);

    expect($this->poll->secondGamePoll()->exists())->toBeFalse();
});

test('no se puede borrar el primero mientras tenga segundo juego', function () {
    $second = Poll::create([
        'created_by' => $this->owner->id,
        'name' => 'Segundo juego',
        'token' => 'SAFEDELETE02',
        'status' => 'draft',
        'parent_poll_id' => $this->poll->id,
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(route('polls.destroy', $this->poll))
        ->assertRedirect(route('polls.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
    $this->assertDatabaseHas('polls', [
        'id' => $second->id,
        'parent_poll_id' => $this->poll->id,
    ]);
});

test('las votaciones abiertas y cerradas estan protegidas', function (
    string $status
) {
    $this->poll->update(['status' => $status]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(route('polls.destroy', $this->poll))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', [
        'id' => $this->poll->id,
        'status' => $status,
    ]);
})->with(['open', 'closed']);

test('no se borra un borrador con votos', function () {
    $this->participant->votes()->create([
        'poll_id' => $this->poll->id,
        'poll_candidate_id' => $this->candidate->id,
        'position' => 1,
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(route('polls.destroy', $this->poll))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
    $this->assertDatabaseHas('votes', [
        'poll_candidate_id' => $this->candidate->id,
    ]);
});

test('no se borra un borrador con partidas asociadas', function () {
    $play = Play::create([
        'created_by' => $this->owner->id,
        'poll_id' => $this->poll->id,
        'game_id' => $this->game->id,
        'played_at' => '2026-11-17',
    ]);

    $this->actingAs($this->owner)
        ->from(route('polls.index'))
        ->delete(route('polls.destroy', $this->poll))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
    $this->assertDatabaseHas('plays', [
        'id' => $play->id,
        'poll_id' => $this->poll->id,
    ]);
});

test('otra cuenta no puede eliminar la votacion', function () {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->delete(route('polls.destroy', $this->poll))
        ->assertForbidden();

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
});

test('es necesario iniciar sesion para borrar', function () {
    $this->delete(route('polls.destroy', $this->poll))
        ->assertRedirect(route('login'));

    $this->assertDatabaseHas('polls', ['id' => $this->poll->id]);
});
