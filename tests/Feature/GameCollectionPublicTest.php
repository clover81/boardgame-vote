<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can share and hide collections while keeping the same catalog token', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $collection = GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Mi colección',
    ]);
    $otherCollection = GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Otra colección',
    ]);
    $url = route('collections.public.toggle', $collection);
    $indexUrl = route('collections.index');

    expect($user->catalog_share_token)->toBeNull();

    $this->actingAs($user)->get($indexUrl)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->where('publicCatalogPath', null)
        );

    $this->actingAs($user)->from($indexUrl)->patch($url)
        ->assertRedirect($indexUrl)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Colección compartida correctamente.');

    $token = $user->fresh()->catalog_share_token;
    expect(Str::isUuid($token))->toBeTrue();
    $this->assertDatabaseHas('game_collections', ['id' => $collection->id, 'is_public' => true]);
    $this->assertDatabaseHas('game_collections', ['id' => $otherCollection->id, 'is_public' => false]);

    $this->get($indexUrl)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->where('publicCatalogPath', '/catalog/'.$token)
        );

    $this->from($indexUrl)->patch($url)
        ->assertRedirect($indexUrl)
        ->assertSessionHas('success', 'Colección marcada como privada.');

    $this->assertDatabaseHas('game_collections', ['id' => $collection->id, 'is_public' => false]);
    expect($user->fresh()->catalog_share_token)->toBe($token);

    $this->get($indexUrl)
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->where('publicCatalogPath', null)
        );

    $this->from($indexUrl)->patch($url)->assertRedirect($indexUrl);
    $this->assertDatabaseHas('game_collections', ['id' => $collection->id, 'is_public' => true]);
    expect($user->fresh()->catalog_share_token)->toBe($token);

    $this->from($indexUrl)->patch(route('collections.public.toggle', $otherCollection))
        ->assertRedirect($indexUrl);
    $this->assertDatabaseHas('game_collections', ['id' => $otherCollection->id, 'is_public' => true]);
    expect($user->fresh()->catalog_share_token)->toBe($token);
});

test('collection cards count only active games', function () {
    $this->withoutVite();

    $user = User::factory()->create();

    $collection = GameCollection::create([
        'created_by' => $user->id,
        'name' => 'Colección con juego inactivo',
    ]);

    $active = Game::create([
        'name' => 'Juego activo',
        'min_players' => 1,
        'max_players' => 4,
        'is_active' => true,
    ]);

    $inactive = Game::create([
        'name' => 'Juego inactivo',
        'min_players' => 1,
        'max_players' => 4,
        'is_active' => false,
    ]);

    $collection->games()->attach([$active->id, $inactive->id]);

    $this->actingAs($user)
        ->get(route('collections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('collections/index')
            ->has('collections', 1)
            ->where('collections.0.games_count', 1)
        );
});

test('another account cannot change a collections visibility', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $collection = GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Colección privada',
    ]);

    $this->actingAs($otherUser)
        ->patch(route('collections.public.toggle', $collection))
        ->assertForbidden();

    $this->assertDatabaseHas('game_collections', ['id' => $collection->id, 'is_public' => false]);
    expect($owner->fresh()->catalog_share_token)->toBeNull()
        ->and($otherUser->fresh()->catalog_share_token)->toBeNull();
});

test('guests cannot change a collections visibility', function () {
    $owner = User::factory()->create();
    $collection = GameCollection::create([
        'created_by' => $owner->id,
        'name' => 'Colección privada',
    ]);

    $this->patch(route('collections.public.toggle', $collection))
        ->assertRedirect(route('login'));

    $this->assertDatabaseHas('game_collections', ['id' => $collection->id, 'is_public' => false]);
    expect($owner->fresh()->catalog_share_token)->toBeNull();
});
