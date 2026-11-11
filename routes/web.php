<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PollController;
use App\Http\Controllers\PublicPollController;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ExpansionController;
use App\Http\Controllers\GameCollectionController;
use App\Http\Controllers\GameCollectionMembershipController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\BggImportController;
use App\Http\Controllers\BggController;
/*
|--------------------------------------------------------------------------
| Rutas públicas
|--------------------------------------------------------------------------
*/

Route::redirect('/', '/games')
    ->name('home');

/*
|--------------------------------------------------------------------------
| Votación pública
|--------------------------------------------------------------------------
*/

Route::get(
    '/vote/{token}/{voteToken}',
    [PublicPollController::class, 'showForParticipant']
)->name('vote.participant.show');

Route::post(
    '/vote/{token}/{voteToken}',
    [PublicPollController::class, 'voteForParticipant']
)->name('vote.participant.store');

Route::get(
    '/catalog/{token}',
    [\App\Http\Controllers\PublicCatalogController::class, 'index']
)->name('catalog.public');

Route::get(
    '/catalog/{token}/games/{game}',
    [\App\Http\Controllers\PublicCatalogController::class, 'show']
)
->whereNumber('game')
->name('catalog.games.show');

/*
|--------------------------------------------------------------------------
| Rutas privadas
|--------------------------------------------------------------------------
*/

Route::middleware(Authenticate::class)->group(function () {

    // Vista sencilla de estadisticas de la coleccion y partidas.
    Route::get('/statistics', [\App\Http\Controllers\SimpleStatisticsController::class, 'index'])
        ->name('statistics.index');

    // Compatibilidad con enlaces guardados de las estadísticas anteriores.
    Route::redirect('/statistics/decisions', '/statistics');
    Route::redirect('/statistics/games', '/statistics');

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/dashboard',
        [DashboardController::class, 'index']
    )
        ->middleware('auth')
        ->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Juegos
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/games/bgg/search',
        [BggController::class, 'search']
    )->name('games.bgg.search');

    Route::get(
        '/games/bgg/{bggId}',
        [BggController::class, 'show']
    )
        ->whereNumber('bggId')
        ->name('games.bgg.show');

    Route::get(
        '/games/{game}/bgg-expansions',
        [BggController::class, 'expansions']
    )->name('games.bgg.expansions');

    Route::post(
        '/games/{game}/bgg-expansions/{bggId}',
        [BggController::class, 'importExpansion']
    )
        ->whereNumber('bggId')
        ->name('games.bgg.expansions.import');

    Route::get(
        '/games/{game}/bgg-link/search',
        [BggController::class, 'searchForExistingGame']
    )->name('games.bgg-link.search');

    Route::post(
        '/games/{game}/bgg-link/{bggId}',
        [BggController::class, 'linkExistingGame']
    )
        ->whereNumber('bggId')
        ->name('games.bgg-link.store');

    Route::get(
        '/games',
        [GameController::class, 'index']
    )->name('games.index');

    Route::get(
        '/games/create',
        [GameController::class, 'create']
    )->name('games.create');

    Route::post(
        '/games',
        [GameController::class, 'store']
    )->name('games.store');

    Route::get(
        '/games/import/bgg/search',
        [
            BggImportController::class,
            'search',
        ]
    )->name('games.search-bgg');

    Route::get(
        '/games/import/bgg',
        [
            BggImportController::class,
            'preview',
        ]
    )->name('games.import-bgg');

    Route::get(
        '/games/{game}',
        [GameController::class, 'show']
    )->name('games.show');

    Route::get(
        '/games/{game}/edit',
        [GameController::class, 'edit']
    )->name('games.edit');

    Route::put(
        '/games/{game}',
        [GameController::class, 'update']
    )->name('games.update');

    Route::delete(
        '/games/{game}',
        [GameController::class, 'destroy']
    )->name('games.destroy');

    Route::put(
        '/games/{game}/collections',
        [
            GameCollectionMembershipController::class,
            'update',
        ]
    )->name('games.collections.update');

    Route::post(
        '/games/{game}/collections/attach',
        [
            GameCollectionMembershipController::class,
            'attach',
        ]
    )->name('games.collections.attach');

    Route::delete(
        '/games/{game}/collections/{collection}',
        [GameController::class, 'detachCollection']
    )->name('games.collections.detach');

    /*
    |--------------------------------------------------------------------------
    | Expansiones
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/games/{game}/expansions',
        [ExpansionController::class, 'store']
    )->name('expansions.store');

    Route::patch(
        '/games/{game}/expansions/{expansion}',
        [ExpansionController::class, 'update']
    )->name('games.expansions.update');

    Route::delete(
        '/games/{game}/expansions/{expansion}',
        [ExpansionController::class, 'destroy']
    )->name('games.expansions.destroy');

    /*
    |--------------------------------------------------------------------------
    | Votaciones
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/polls',
        [PollController::class, 'index']
    )->name('polls.index');

    Route::get(
        '/polls/create',
        [PollController::class, 'create']
    )->name('polls.create');

    Route::post(
        '/polls',
        [PollController::class, 'store']
    )->name('polls.store');

    Route::get(
        '/polls/recommendations',
        [
            PollController::class,
            'recommendations',
        ]
    )->name(
        'polls.recommendations'
    );

    Route::get(
        '/polls/{poll}',
        [PollController::class, 'show']
    )->name('polls.show');

    Route::post(
        '/polls/{poll}/candidates',
        [PollController::class, 'addCandidate']
    )->name('polls.candidates.store');

    Route::patch(
        '/polls/{poll}/open',
        [PollController::class, 'open']
    )->name('polls.open');

    Route::patch(
        '/polls/{poll}/close',
        [PollController::class, 'close']
    )->name('polls.close');

    Route::get(
        '/polls/{poll}/register-play',
        [PlayController::class, 'createFromPoll']
    )->name('plays.create-from-poll');

    /*
    |--------------------------------------------------------------------------
    | Partidas jugadas
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/plays',
        [PlayController::class, 'index']
    )->name('plays.index');

    Route::get(
        '/plays/create',
        [PlayController::class, 'create']
    )->name('plays.create');

    Route::post(
        '/plays',
        [PlayController::class, 'store']
    )->name('plays.store');

    Route::get(
        '/plays/{play}/edit',
        [PlayController::class, 'edit']
    )->name('plays.edit');

    Route::put(
        '/plays/{play}',
        [PlayController::class, 'update']
    )->name('plays.update');

    Route::delete(
        '/plays/{play}',
        [PlayController::class, 'destroy']
    )->name('plays.destroy');

    /*
    |--------------------------------------------------------------------------
    | Jugadores
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/players',
        [PlayerController::class, 'index']
    )->name('players.index');

    Route::get(
        '/players/create',
        [PlayerController::class, 'create']
    )->name('players.create');

    Route::post(
        '/players',
        [PlayerController::class, 'store']
    )->name('players.store');

    Route::get(
        '/players/{player}/edit',
        [PlayerController::class, 'edit']
    )->name('players.edit');

    Route::patch(
        '/players/{player}',
        [PlayerController::class, 'update']
    )->name('players.update');

    Route::get(
        '/players/{player}',
        [
            PlayerController::class,
            'show',
        ]
    )->name('players.show');

    Route::patch(
        '/players/{player}/toggle',
        [PlayerController::class, 'toggle']
    )->name('players.toggle');

    /*
    |--------------------------------------------------------------------------
    | Colecciones
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/collections',
        [GameCollectionController::class, 'index']
    )->name('collections.index');

    // Consulta de colecciones ajenas, accesible solo con sesion iniciada.
    // Rutas independientes de las acciones privadas de modificacion.
    Route::get(
        '/friends/collections/{collection}',
        [\App\Http\Controllers\FriendCollectionController::class, 'index']
    )->name('friends.collections.index');

    Route::get(
        '/friends/collections/{collection}/games/{game}',
        [\App\Http\Controllers\FriendCollectionController::class, 'show']
    )->name('friends.collections.games.show');

    Route::post(
        '/collections',
        [GameCollectionController::class, 'store']
    )->name('collections.store');

    Route::patch(
        '/collections/{collection}/default',
        [
            GameCollectionController::class,
            'makeDefault',
        ]
    )->name('collections.default');

    Route::patch(
        '/collections/{collection}/public',
        [GameCollectionController::class, 'togglePublic']
    )->name('collections.public.toggle');

    Route::get(
        '/collections/{collection}/bgg/preview',
        [GameCollectionController::class, 'bggPreview']
    )->name('collections.bgg.preview');

    Route::post(
        '/collections/{collection}/bgg/sync',
        [GameCollectionController::class, 'syncBgg']
    )->name('collections.bgg.sync');

    Route::post(
        '/collections/{collection}/bgg/remove/{game}',
        [GameCollectionController::class, 'confirmBggRemoval']
    )->name('collections.bgg.remove');

    Route::post(
        '/collections/{collection}/bgg/remove-expansion/{expansion}',
        [GameCollectionController::class, 'confirmBggExpansionRemoval']
    )->name('collections.bgg.remove-expansion');

    Route::delete(
        '/collections/{collection}',
        [
            GameCollectionController::class,
            'destroy',
        ]
    )->name('collections.destroy');

});

/*
|--------------------------------------------------------------------------
| Configuración del starter
|--------------------------------------------------------------------------
*/

require __DIR__.'/settings.php';

Route::get('/debug/bgg-config', function () {
    $getenvToken = getenv('BGG_API_TOKEN');

    return response()->json([
        'base_path' => base_path(),

        'environment' => app()->environment(),

        'environment_file' =>
            app()->environmentFile(),

        'env_direct_present' =>
            filled(env('BGG_API_TOKEN')),

        'env_direct_length' =>
            strlen(
                (string) env('BGG_API_TOKEN')
            ),

        'getenv_present' =>
            $getenvToken !== false,

        'getenv_length' =>
            $getenvToken === false
                ? 0
                : strlen($getenvToken),

        'server_present' =>
            array_key_exists(
                'BGG_API_TOKEN',
                $_SERVER
            ),

        'server_length' =>
            strlen(
                (string) (
                    $_SERVER[
                        'BGG_API_TOKEN'
                    ] ?? ''
                )
            ),

        'env_array_present' =>
            array_key_exists(
                'BGG_API_TOKEN',
                $_ENV
            ),

        'env_array_length' =>
            strlen(
                (string) (
                    $_ENV[
                        'BGG_API_TOKEN'
                    ] ?? ''
                )
            ),

        'config_present' =>
            filled(
                config(
                    'services.bgg.token'
                )
            ),

        'config_length' =>
            strlen(
                (string) config(
                    'services.bgg.token'
                )
            ),

        'base_url' =>
            config(
                'services.bgg.base_url'
            ),
    ]);
})->middleware('auth');
