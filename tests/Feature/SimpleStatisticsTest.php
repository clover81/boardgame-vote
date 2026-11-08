<?php

use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('estadisticas sencillas requieren autenticacion', function () {
    $this->get(route('statistics.index'))->assertRedirect(route('login'));
});

test('las estadisticas cuentan partidas propias y juegos de colecciones propias sin duplicados', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $game = fn (string $name) => Game::create([
        'name' => $name, 'min_players' => 1, 'max_players' => 4,
        'game_mode' => 'competitive', 'is_active' => true,
    ]);
    $played = $game('Juego jugado');
    $withoutPlay = $game('Juego sin partidas');
    $uncollected = $game('Juego fuera de colección');
    $otherGame = $game('Juego ajeno');

    $first = GameCollection::create(['created_by' => $user->id, 'name' => 'Colección 1']);
    $second = GameCollection::create(['created_by' => $user->id, 'name' => 'Colección 2']);
    $foreign = GameCollection::create(['created_by' => $otherUser->id, 'name' => 'Ajena']);
    $first->games()->attach([$played->id, $withoutPlay->id]);
    $second->games()->attach($played->id);
    $foreign->games()->attach([$played->id, $otherGame->id]);

    Play::create(['created_by' => $user->id, 'game_id' => $played->id, 'played_at' => '2026-11-01']);
    Play::create(['created_by' => $user->id, 'game_id' => $played->id, 'played_at' => '2026-11-03']);
    Play::create(['created_by' => $user->id, 'game_id' => $uncollected->id, 'played_at' => '2026-11-04']);
    Play::create(['created_by' => $otherUser->id, 'game_id' => $played->id, 'played_at' => '2026-11-05']);

    $response = $this->actingAs($user)->get(route('statistics.index'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('statistics/index')
        ->where('summary', [
            'total_plays' => 3,
            'games_count' => 2,
            'games_with_plays' => 1,
            'games_without_plays' => 1,
        ])
        ->has('mostPlayed', 1)
        ->where('mostPlayed.0.id', $played->id)
        ->where('mostPlayed.0.times_played', 2)
        ->where('mostPlayed.0.last_played_at', '2026-11-03')
        ->has('withoutPlays', 1)
        ->where('withoutPlays.0.id', $withoutPlay->id)
    );
});

test('un usuario sin coleccion no ve datos de otros', function () {
    $this->withoutVite();
    $other = User::factory()->create();
    $game = Game::create([
        'name' => 'Ajeno', 'min_players' => 1, 'max_players' => 4,
        'game_mode' => 'competitive', 'is_active' => true,
    ]);
    $collection = GameCollection::create(['created_by' => $other->id, 'name' => 'Ajena']);
    $collection->games()->attach($game->id);
    Play::create(['created_by' => $other->id, 'game_id' => $game->id, 'played_at' => '2026-11-01']);

    $this->actingAs(User::factory()->create())->get(route('statistics.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('summary', [
                'total_plays' => 0,
                'games_count' => 0,
                'games_with_plays' => 0,
                'games_without_plays' => 0,
            ])
            ->where('mostPlayed', [])
            ->where('withoutPlays', [])
        );
});

// Los marcadores antiguos siguen funcionando, sin mantener las estadísticas obsoletas.
test('rutas antiguas de estadisticas redirigen al resumen actual', function () {
    foreach (['/statistics/decisions', '/statistics/games'] as $oldUrl) {
        $this->get($oldUrl)->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->create());
    foreach (['/statistics/decisions', '/statistics/games'] as $oldUrl) {
        $this->get($oldUrl)->assertRedirect('/statistics');
    }
});
