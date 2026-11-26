<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->owner = User::factory()->create();
    $this->token = (string) Str::uuid();
    $this->owner->forceFill(['catalog_share_token' => $this->token])->save();
    $this->collection = GameCollection::forceCreate([
        'created_by' => $this->owner->id,
        'name' => 'Colección pública',
        'is_public' => true,
    ]);
    $this->game = Game::create([
        'name' => 'The White Castle',
        'min_players' => 1,
        'max_players' => 4,
        'game_mode' => 'competitive',
        'is_active' => true,
        'additional_information' => 'Información del juego',
    ]);
    $this->collection->games()->attach($this->game);
});

test('guests can view public game details without private memberships or expansion notes', function () {
    $privateCollection = GameCollection::forceCreate([
        'created_by' => $this->owner->id,
        'name' => 'Colección privada',
        'is_public' => false,
    ]);
    $foreignCollection = GameCollection::forceCreate([
        'created_by' => User::factory()->create()->id,
        'name' => 'Colección ajena',
        'is_public' => true,
    ]);
    $this->game->collections()->attach([$privateCollection->id, $foreignCollection->id]);
    $this->game->idealPlayerCounts()->create(['player_count' => 3]);
    $expansion = $this->game->expansions()->create([
        'name' => 'Matcha',
        'is_active' => true,
        'notes' => 'Notas privadas',
    ]);
    $this->game->expansions()->create(['name' => 'Inactiva', 'is_active' => false]);

    $this->get(route('catalog.games.show', [$this->token, $this->game->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('auth.user', null)
            ->where('catalogToken', $this->token)
            ->where('game.id', $this->game->id)
            ->where('game.additional_information', 'Información del juego')
            ->where('game.ideal_player_counts', [3])
            ->where('game.collections', [[
                'id' => $this->collection->id,
                'name' => $this->collection->name,
            ]])
            ->where('game.expansions', [[
                'id' => $expansion->id,
                'name' => 'Matcha',
                'image_url' => null,
                'bgg_url' => null,
                'youtube_url' => null,
            ]])
            ->missing('game.plays')
            ->missing('game.players')
            ->missing('game.votes')
            ->missing('game.polls')
            ->missing('players')
            ->missing('polls')
            ->missing('plays')
        );
});

test('public game details return not found for inaccessible games or invalid tokens', function ($scenario) {
    $token = $this->token;
    $gameId = $this->game->id;

    if ($scenario === 'private collection') {
        $this->collection->is_public = false;
        $this->collection->save();
    } elseif ($scenario === 'another accounts collection') {
        $this->collection->created_by = User::factory()->create()->id;
        $this->collection->save();
    } elseif ($scenario === 'inactive game') {
        $this->game->update(['is_active' => false]);
    } elseif ($scenario === 'game without collections') {
        $this->game->collections()->detach();
    } elseif ($scenario === 'nonexistent game') {
        $gameId += 1000;
    } elseif ($scenario === 'unknown token') {
        $token = (string) Str::uuid();
    } elseif ($scenario === 'nonnumeric game') {
        $gameId = 'invalid';
    }

    $this->get(route('catalog.games.show', [$token, $gameId]))
        ->assertNotFound();
})->with([
    'private collection',
    'another accounts collection',
    'inactive game',
    'game without collections',
    'nonexistent game',
    'unknown token',
    'nonnumeric game',
]);


test('la ficha publica tiene metadatos personalizados para WhatsApp', function () {
    $this->game->update([
        'image_url' => 'https://cf.geekdo-images.com/example.jpg',
    ]);

    $this->get(route('catalog.games.show', [
        $this->token, $this->game->id,
    ]))
        ->assertOk()
        ->assertSee(
            'property="og:title" content="🎲 The White Castle · Clover"',
            false
        )
        ->assertSee(
            'property="og:description" content="Consulta la ficha de The White Castle y accede a sus tutoriales."',
            false
        )
        ->assertSee(
            'property="og:image" content="https://cf.geekdo-images.com/example.jpg"',
            false
        );
});

test('la ficha publica sin portada usa la imagen de reserva en Open Graph', function () {
    $this->get(route('catalog.games.show', [
        $this->token, $this->game->id,
    ]))
        ->assertOk()
        ->assertSee(
            'property="og:title" content="🎲 The White Castle · Clover"',
            false
        )
        ->assertSee('property="og:image" content="'.e(asset('images/catalog-shelf-comic.webp')).'"', false);
});


test('el catalogo publico respeta las colecciones de las expansiones', function () {
    $private = GameCollection::forceCreate([
        'created_by' => $this->owner->id,
        'name' => 'Privada',
        'is_public' => false,
    ]);

    $foreign = GameCollection::forceCreate([
        'created_by' => User::factory()->create()->id,
        'name' => 'Ajena',
        'is_public' => true,
    ]);

    $this->game->collections()->attach([
        $private->id, $foreign->id,
    ]);

    $publicExpansion = $this->game->expansions()->create([
        'name' => 'A pública',
        'is_active' => true,
    ]);

    $this->game->expansions()->create([
        'name' => 'B informativa sin colección',
        'is_active' => true,
    ]);

    $privateExpansion = $this->game->expansions()->create([
        'name' => 'C solo privada',
        'is_active' => true,
    ]);

    $foreignExpansion = $this->game->expansions()->create([
        'name' => 'D solo ajena',
        'is_active' => true,
    ]);

    $this->collection->expansions()->attach($publicExpansion);
    $private->expansions()->attach($privateExpansion);
    $foreign->expansions()->attach($foreignExpansion);

    $this->get(route('catalog.games.show', [
        $this->token, $this->game->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->has('game.expansions', 2)
            ->where('game.expansions.0.name', 'A pública')
            ->where('game.expansions.1.name', 'B informativa sin colección')
        );
});
