<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->owner = User::factory()->create(['name' => 'Miki']);
    $this->friend = User::factory()->create(['name' => 'Alejandro']);
    $this->collection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'Coleccion de Miki',
        'is_default' => false,
    ]);
    // Publica para terceros; la otra coleccion permanece privada.
    $this->collection->forceFill(['is_public' => true])->save();
    $this->otherCollection = GameCollection::create([
        'created_by' => $this->owner->id,
        'name' => 'No compartida en esta vista',
    ]);
    $this->game = Game::create([
        'name' => 'Terraforming Mars',
        'min_players' => 1,
        'max_players' => 5,
        'is_active' => true,
    ]);
    $this->game->collections()->attach($this->collection->id);
    $this->otherGame = Game::create([
        'name' => 'Un juego de otra coleccion',
        'min_players' => 2,
        'max_players' => 4,
        'is_active' => true,
    ]);
    $this->otherGame->collections()->attach($this->otherCollection->id);
});

test('solo se listan colecciones publicas ajenas sin exponer configuracion BGG', function () {
    $this->actingAs($this->friend)
        ->get(route('collections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->has('friendCollections', 1)
            ->where('friendCollections.0.id', $this->collection->id)
            ->where('friendCollections.0.owner_name', 'Miki')
            ->missing('friendCollections.0.bgg_username')
        );
});

test('un usuario autenticado puede consultar una coleccion publica ajena', function () {
    $this->actingAs($this->friend)
        ->get(route('friends.collections.index', $this->collection))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/index')
            ->where('catalogOwnerName', 'Miki')
            ->has('games', 1)
            ->where('games.0.name', 'Terraforming Mars')
        );

    $this->get(route('friends.collections.games.show', [$this->collection, $this->game]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('game.id', $this->game->id)
            ->missing('history')
        );
});

test('una coleccion privada ajena no se puede consultar ni siquiera por URL directa', function () {
    $this->actingAs($this->friend)
        ->get(route('friends.collections.index', $this->otherCollection))
        ->assertNotFound();

    $this->get(route('friends.collections.games.show', [$this->otherCollection, $this->otherGame]))
        ->assertNotFound();
});

test('al hacer privada una coleccion desaparece y se revoca el acceso anterior', function () {
    $this->actingAs($this->owner)
        ->patch(route('collections.public.toggle', $this->collection))
        ->assertRedirect();

    $this->assertDatabaseHas('game_collections', [
        'id' => $this->collection->id,
        'is_public' => false,
    ]);

    $this->actingAs($this->friend)
        ->get(route('collections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->has('friendCollections', 0)
        );

    $this->get(route('friends.collections.index', $this->collection))
        ->assertNotFound();
    $this->get(route('friends.collections.games.show', [$this->collection, $this->game]))
        ->assertNotFound();
});

test('no se puede leer un juego que no pertenece a la coleccion seleccionada', function () {
    $this->actingAs($this->friend)
        ->get(route('friends.collections.games.show', [$this->collection, $this->otherGame]))
        ->assertNotFound();
});

test('los visitantes sin sesion no pueden consultar colecciones de amigos', function () {
    $this->get(route('friends.collections.index', $this->collection))
        ->assertRedirect(route('login'));
    $this->get(route('friends.collections.games.show', [$this->collection, $this->game]))
        ->assertRedirect(route('login'));
});

test('consultar una coleccion no permite editar una coleccion ajena', function () {
    $this->actingAs($this->friend)
        ->get(route('games.edit', $this->game))
        ->assertForbidden();
    $this->patch(route('collections.default', $this->collection))
        ->assertForbidden();
    $this->assertDatabaseHas('game_collections', [
        'id' => $this->collection->id,
        'is_default' => false,
    ]);
});


test('el propietario usa sus rutas privadas en lugar de la vista de amigos', function () {
    $this->actingAs($this->owner)
        ->get(route('friends.collections.index', $this->collection))
        ->assertNotFound();
});

test('consultar colecciones ajenas no permite asociarles juegos ni eliminarlas', function () {
    $this->actingAs($this->friend)
        ->post(route('games.collections.attach', $this->game), [
            'collection_ids' => [$this->collection->id],
        ])->assertSessionHasErrors('collection_ids');

    $this->delete(route('collections.destroy', $this->collection))
        ->assertForbidden();

    $this->assertDatabaseHas('game_collection_game', [
        'game_id' => $this->game->id,
        'game_collection_id' => $this->collection->id,
    ]);
});
