<?php

namespace App\Http\Controllers;

use App\Models\Expansion;
use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Play;
use App\Models\Player;
use App\Services\BggService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class GameController extends Controller
{
    public function show(
        Request $request,
        Game $game
    ): Response {
        $this->authorizeGame($request, $game);

        $userId = (int) $request->user()->id;

        $game->load([
            'idealPlayerCounts',

            'collections' => fn ($query) => $query
                ->where('game_collections.created_by', $userId)
                ->orderBy('name'),

            'expansions' =>
            function ($query) {
                $query->orderBy(
                    'name'
                );
            },
        ]);

        $plays = $game
            ->plays()
            ->where('created_by', $userId)
            ->with(['players', 'expansion'])
            ->orderByDesc('played_at')
            ->get();

        return Inertia::render(
            'games/show',
            [
                'game' => [
                    'id' =>
                    $game->id,

                    'bgg_id' =>
                    $game->bgg_id,

                    'name' =>
                    $game->name,

                    'image_url' =>
                    $game->image_url,

                    'min_players' =>
                    $game->min_players,

                    'max_players' =>
                    $game->max_players,

                    'min_playtime' =>
                    $game->min_playtime,

                    'max_playtime' =>
                    $game->max_playtime,

                    'complexity' =>
                    $game->complexity,

                    'game_mode' =>
                    $game->game_mode,

                    'game_modes' => $game->game_modes
                        ?? ($game->game_mode ? [$game->game_mode] : []),

                    'short_description' =>
                    $game
                        ->short_description,

                    'full_description' => $game->full_description,

                    'additional_information' =>
                    $game
                        ->additional_information,

                    'bgg_url' =>
                    $game->bgg_url,

                    'youtube_url' =>
                    $game->youtube_url,

                    'ideal_player_counts' =>
                    $game
                        ->idealPlayerCounts
                        ->pluck(
                            'player_count'
                        )
                        ->values(),

                    'expansions' =>
                    $game
                        ->expansions
                        ->map(
                            fn($expansion) => [
                                'id' =>
                                $expansion->id,

                                'bgg_id' =>
                                $expansion->bgg_id,

                                'name' =>
                                $expansion->name,

                                'image_url' =>
                                $expansion
                                    ->image_url,

                                'bgg_url' =>
                                $expansion
                                    ->bgg_url,

                                'youtube_url' =>
                                $expansion
                                    ->youtube_url,

                                'notes' =>
                                $expansion
                                    ->notes,

                                'is_active' =>
                                $expansion->is_active,
                            ]
                        )
                        ->values(),
                ],

                /*
            |--------------------------------------------------------------------------
            | MULTI-COLECCIÓN
            |--------------------------------------------------------------------------
            */

                'collections' =>
                $game
                    ->collections
                    ->map(
                        fn ($collection) => [
                            'id' =>
                            $collection->id,

                            'name' =>
                            $collection->name,

                            'is_default' =>
                            $collection
                                ->is_default,
                        ]
                    )
                    ->values(),

                /*
            |--------------------------------------------------------------------------
            | HISTORIAL
            |--------------------------------------------------------------------------
            */

                'history' => [
                    'total_plays' =>
                    $plays->count(),

                    'last_played_at' =>
                    $plays
                        ->first()
                        ?->played_at
                        ?->format(
                            'Y-m-d'
                        ),

                    'plays' =>
                    $plays
                        ->map(
                            function (
                                $play
                            ) {
                                return [
                                    'id' =>
                                    $play->id,

                                    'played_at' =>
                                    $play
                                        ->played_at
                                        ->format(
                                            'Y-m-d'
                                        ),

                                    'notes' =>
                                    $play->notes,

                                    'expansion' =>
                                    $play
                                        ->expansion
                                        ? [
                                            'id' =>
                                            $play
                                                ->expansion
                                                ->id,

                                            'name' =>
                                            $play
                                                ->expansion
                                                ->name,
                                        ]
                                        : null,

                                    'players' =>
                                    $play
                                        ->players
                                        ->map(
                                            fn(
                                                $player
                                            ) => [
                                                'id' =>
                                                $player
                                                    ->id,

                                                'name' =>
                                                $player
                                                    ->name,
                                            ]
                                        )
                                        ->values(),
                                ];
                            }
                        )
                        ->values(),
                ],
            ]
        );
    }

    public function edit(
        Request $request,
        Game $game
    ): Response {
        $this->authorizeGame($request, $game);

        $userId = (int) $request->user()->id;

        $game->load([
            'idealPlayerCounts',
            'collections' => fn ($query) => $query
                ->where('game_collections.created_by', $userId),
            'expansions' => fn ($query) => $query->orderBy('name'),
        ]);

        $collections =
            GameCollection::query()
            ->where(
                'created_by',
                $userId
            )
            ->orderByDesc(
                'is_default'
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'is_default',
            ]);

        /*
     * Solo las colecciones de ESTE usuario
     * donde está incluido el juego.
     */
        $gameCollectionIds =
            $game
            ->collections()
            ->whereIn(
                'game_collections.id',
                $collections
                    ->pluck('id')
            )
            ->pluck(
                'game_collections.id'
            )
            ->values();

        return Inertia::render(
            'games/edit',
            [
                'game' => [
                    'id' =>
                    $game->id,

                    'bgg_id' =>
                    $game->bgg_id,

                    'name' =>
                    $game->name,

                    'image_url' =>
                    $game->image_url,

                    'min_players' =>
                    $game->min_players,

                    'max_players' =>
                    $game->max_players,

                    'min_playtime' =>
                    $game->min_playtime,

                    'max_playtime' =>
                    $game->max_playtime,

                    'complexity' =>
                    $game->complexity,

                    'game_mode' =>
                    $game->game_mode,

                    'game_modes' =>
                    $game->game_modes
                    ?? ($game->game_mode ? [$game->game_mode] : []),

                    'short_description' =>
                    $game
                        ->short_description,

                    'additional_information' =>
                    $game
                        ->additional_information,

                    'bgg_url' =>
                    $game->bgg_url,

                    'youtube_url' =>
                    $game->youtube_url,

                    'ideal_player_counts' =>
                    $game
                        ->idealPlayerCounts
                        ->pluck(
                            'player_count'
                        )
                        ->values(),

                    'expansions' => $game->expansions
                        ->map(fn ($expansion) => [
                            'id' => $expansion->id,
                            'bgg_id' => $expansion->bgg_id,
                            'name' => $expansion->name,
                            'image_url' => $expansion->image_url,
                            'bgg_url' => $expansion->bgg_url,
                            'youtube_url' => $expansion->youtube_url,
                            'notes' => $expansion->notes,
                            'is_active' => $expansion->is_active,
                        ])
                        ->values(),
                ],

                'collections' =>
                $collections,

                'game_collection_ids' =>
                $gameCollectionIds,
            ]
        );
    }

    public function update(Request $request, Game $game): RedirectResponse
    {
        $this->authorizeGame($request, $game);

        $userId = (int) $request->user()->id;

        // Una ficha global compartida no puede modificarse desde otra cuenta.
        $usedByAnotherUser = DB::table('game_collection_game as gcg')
            ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
            ->where('gcg.game_id', $game->id)
            ->where('gc.created_by', '<>', $userId)
            ->exists();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            'image_url' => ['nullable', 'url'],

            'min_players' => ['required', 'integer', 'min:1'],
            'max_players' => ['required', 'integer', 'gte:min_players'],

            'min_playtime' => ['nullable', 'integer', 'min:1'],
            'max_playtime' => ['nullable', 'integer', 'gte:min_playtime'],

            'complexity' => ['nullable', 'numeric', 'between:1,5'],

            'game_modes' => [
                'present',
                'array',
                'max:3',
            ],

            'game_modes.*' => [
                'string',
                'in:competitive,cooperative,semi_cooperative',
                'distinct',
            ],

            'short_description' => ['nullable', 'string'],
            'additional_information' => ['nullable', 'string'],

            'bgg_url' => ['nullable', 'url'],
            'youtube_url' => ['nullable', 'url'],

            'ideal_player_counts' => ['required', 'array', 'min:1'],
            'ideal_player_counts.*' => [
                'required',
                'integer',
                'min:1',
                'max:20',
                'distinct',
            ],
            'collection_ids' => [
                'nullable',
                'array',
            ],
            'collection_ids.*' => [
                'integer',
                'distinct',
                'exists:game_collections,id',
            ],
        ]);

        $requestedCollectionIds = collect($validated['collection_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $ownedCollectionIds = GameCollection::query()
            ->where('created_by', $userId)
            ->whereIn('id', $requestedCollectionIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if ($ownedCollectionIds->count() !== $requestedCollectionIds->count()) {
            return back()->withErrors([
                'collection_ids' =>
                    'Alguna colección seleccionada no te pertenece.',
            ])->withInput();
        }

        // Mantener una modalidad principal para las pantallas antiguas.
        // Si la anterior sigue seleccionada, la conservamos.
        $modes = $validated['game_modes'];

        $validated['game_mode'] = in_array(
            $game->game_mode,
            $modes,
            true
        )
            ? $game->game_mode
            : ($modes[0] ?? null);

        $idealPlayerCounts = $validated['ideal_player_counts'];

        /*
        |--------------------------------------------------------------------------
        | Proteger las descripciones modificadas manualmente
        |--------------------------------------------------------------------------
        */

        if (
            array_key_exists('short_description', $validated)
            && (string) $validated['short_description']
                !== (string) $game->short_description
        ) {
            $validated['description_source'] = 'manual';
        }

        unset($validated['ideal_player_counts'], $validated['collection_ids']);

        DB::transaction(function () use (
            $game,
            $validated,
            $idealPlayerCounts,
            $userId,
            $ownedCollectionIds,
            $usedByAnotherUser
        ) {
            if (! $usedByAnotherUser) {
                $game->update($validated);
            }

            /*
             * Solo modificamos las colecciones del usuario actual.
             * Conservamos las pertenencias a colecciones de otros usuarios.
             */
            $userCollectionIds = GameCollection::query()
                ->where('created_by', $userId)
                ->pluck('id');

            DB::table('game_collection_game')
                ->where('game_id', $game->id)
                ->whereIn('game_collection_id', $userCollectionIds)
                ->delete();

            $rows = $ownedCollectionIds
                ->map(fn ($collectionId) => [
                    'game_id' => $game->id,
                    'game_collection_id' => $collectionId,
                ])
                ->all();

            if ($rows) {
                DB::table('game_collection_game')->insertOrIgnore($rows);
            }

            if (! $usedByAnotherUser) {
                $game->idealPlayerCounts()->delete();

                foreach ($idealPlayerCounts as $playerCount) {
                    $game->idealPlayerCounts()->create([
                        'player_count' => $playerCount,
                    ]);
                }
            }
        });

        if ($usedByAnotherUser) {
            return redirect()->route('games.index')->with(
                'success',
                'Se han actualizado tus colecciones. La ficha compartida no se ha modificado.'
            );
        }

        return redirect()
            ->route('games.index')
            ->with('success', 'Juego actualizado correctamente.');
    }

    public function index(
        Request $request
    ): Response {
        $userId = $request->user()->id;
        $collectionId = $request->integer('collection_id') ?: null;
        $search = trim((string) $request->input('q'));
        $playerCount = $request->integer('players') ?: null;

        $durationLimit = $request->integer('duration') ?: null;
        if (!in_array($durationLimit, [30, 60, 90, 120, 180], true)) {
            $durationLimit = null;
        }

        $difficulty = (string) $request->query('difficulty', '');
        if (!in_array($difficulty, ['light', 'medium', 'heavy'], true)) {
            $difficulty = '';
        }

        $gameMode = (string) $request->query('mode', '');
        $requestedSort = $request->query('sort', 'name');
        $allowedSorts = ['name', 'name_desc', 'recent', 'most_played'];
        $sort = is_string($requestedSort) && in_array($requestedSort, $allowedSorts, true)
            ? $requestedSort
            : 'name';

        $allowedModes = [
            'competitive',
            'cooperative',
            'semi_cooperative',
        ];

        if (!in_array($gameMode, $allowedModes, true)) {
            $gameMode = '';
        }

        $collections = GameCollection::query()
            ->where('created_by', $userId)
            ->withCount('games')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $games = Game::query()
            ->where('is_active', true)
            ->whereHas('collections', fn ($query) => $query
                ->where('game_collections.created_by', $userId))
            ->when($collectionId, fn ($query, $collectionId) => $query
                ->whereHas('collections', fn ($collectionQuery) => $collectionQuery
                    ->where('game_collections.id', $collectionId)
                    ->where('game_collections.created_by', $userId)))
            ->when($playerCount, fn ($query, $players) => $query
                ->whereHas('idealPlayerCounts', fn ($ideal) => $ideal
                    ->where('player_count', $players)))
            ->when($durationLimit, fn ($query, $minutes) => $query
                ->where(fn ($duration) => $duration
                    ->where('max_playtime', '<=', $minutes)
                    ->orWhere(fn ($fallback) => $fallback
                        ->whereNull('max_playtime')
                        ->where('min_playtime', '<=', $minutes))))
            ->when($difficulty !== '', function ($query) use ($difficulty) {
                match ($difficulty) {
                    'light' => $query->where('complexity', '<=', 2),
                    'medium' => $query->where('complexity', '>', 2)->where('complexity', '<=', 3),
                    'heavy' => $query->where('complexity', '>', 3),
                };
            })
            ->when(
                $gameMode !== '',
                fn ($query) => $query->whereJsonContains(
                    'games.game_modes',
                    $gameMode
                )
            )
            ->when($search, function ($query, $search) {
                $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

                // Mientras se escribe, mostrar primero títulos que empiezan por el texto.
                $prefix = implode(' ', $terms);
                if ((clone $query)->where('games.name', 'like', $prefix . '%')->exists()) {
                    $query->where('games.name', 'like', $prefix . '%');

                    return;
                }

                // Si no hay prefijos, buscar títulos que contienen todos los términos.
                $titleQuery = clone $query;
                foreach ($terms as $term) {
                    $titleQuery->where('games.name', 'like', '%' . $term . '%');
                }

                if ($titleQuery->exists()) {
                    foreach ($terms as $term) {
                        $query->where('games.name', 'like', '%' . $term . '%');
                    }

                    return;
                }

                // Si ningún título coincide, permitir búsquedas por temática.
                foreach ($terms as $term) {
                    $query->where(fn ($part) => $part
                        ->where('games.name', 'like', '%' . $term . '%')
                        ->orWhere('games.short_description', 'like', '%' . $term . '%'));
                }
            })
            ->with([
                'idealPlayerCounts',
                'collections' => fn ($query) => $query
                    ->where('game_collections.created_by', $userId)
                    ->orderBy('name'),
            ])
            ->withCount([
                'plays as total_plays' => fn ($query) => $query
                    ->where('created_by', $userId),
            ])
            ->withMax([
                'plays as last_played_at' => fn ($query) => $query
                    ->where('created_by', $userId),
            ], 'played_at')
            ->withCasts(['last_played_at' => 'date'])
            ->when($sort === 'recent', fn ($query) => $query
                ->orderByDesc('last_played_at'))
            ->when($sort === 'most_played', fn ($query) => $query
                ->orderByDesc('total_plays'))
            ->orderBy('name', $sort === 'name_desc' ? 'desc' : 'asc')
            ->get()
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'image_url' => $game->image_url,
                'min_players' => $game->min_players,
                'max_players' => $game->max_players,
                'min_playtime' => $game->min_playtime,
                'max_playtime' => $game->max_playtime,
                'complexity' => $game->complexity,
                'game_mode' => $game->game_mode,
                'game_modes' => $game->game_modes
                    ?? ($game->game_mode ? [$game->game_mode] : []),
                'short_description' => $game->short_description,
                'bgg_id' => $game->bgg_id,
                'ideal_player_counts' => $game->idealPlayerCounts
                    ->pluck('player_count')
                    ->values(),
                'collections' => $game->collections
                    ->map(fn ($collection) => [
                        'id' => $collection->id,
                        'name' => $collection->name,
                    ])
                    ->values(),
                'total_plays' => $game->total_plays,
                'last_played_at' => $game->last_played_at?->format('Y-m-d'),
            ])
            ->values();

        return Inertia::render('games/index', [
            'games' => $games,
            'collections' => $collections
                ->map(fn ($collection) => [
                    'id' => $collection->id,
                    'name' => $collection->name,
                    'is_default' => $collection->is_default,
                    'games_count' => $collection->games_count,
                ])
                ->values(),
            'selected_collection_id' => $collectionId,
            'filters' => [
                'q' => $search,
                'players' => $playerCount,
                'mode' => $gameMode,
                'duration' => $durationLimit,
                'difficulty' => $difficulty,
                'sort' => $sort,
            ],
        ]);
    }

    public function create(): Response
    {
        $collections =
            GameCollection::query()
            ->where(
                'created_by',
                request()->user()->id
            )
            ->orderByDesc(
                'is_default'
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'is_default',
            ]);

        /*
     * Al crear un juego dejamos marcada
     * automáticamente la colección principal.
     */
        $defaultCollectionIds =
            $collections
            ->where(
                'is_default',
                true
            )
            ->pluck('id')
            ->values();

        /*
     * Por seguridad, si por algún motivo
     * no hay principal usamos la primera.
     */
        if (
            $defaultCollectionIds->isEmpty() &&
            $collections->isNotEmpty()
        ) {
            $defaultCollectionIds =
                collect([
                    $collections
                        ->first()
                        ->id,
                ]);
        }

        return Inertia::render(
            'games/create',
            [
                'collections' =>
                $collections,

                'default_collection_ids' =>
                $defaultCollectionIds,
            ]
        );
    }

    public function detachCollection(
        Request $request,
        Game $game,
        GameCollection $collection
    ): RedirectResponse {
        $userId = (int) $request->user()->id;

        abort_unless($collection->created_by === $userId, 403);

        $belongs = DB::table('game_collection_game')
            ->where('game_id', $game->id)
            ->where('game_collection_id', $collection->id)
            ->exists();

        abort_unless($belongs, 404);

        DB::table('game_collection_game')
            ->where('game_id', $game->id)
            ->where('game_collection_id', $collection->id)
            ->delete();

        if ($this->userHasGame($userId, (int) $game->id)) {
            return back()->with('success', 'Juego eliminado de la colección.');
        }

        return redirect()
            ->route('games.index')
            ->with('success', 'Juego eliminado de tu colección.');
    }

    public function destroy(
        Request $request,
        Game $game
    ): RedirectResponse {
        $userId = (int) $request->user()->id;

        $this->authorizeGame($request, $game);

        /*
         * El juego es global: solo podemos borrarlo si ninguna otra
         * cuenta lo tiene en sus colecciones.
         */
        $usedByAnotherUser = DB::table('game_collection_game as gcg')
            ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
            ->where('gcg.game_id', $game->id)
            ->where('gc.created_by', '<>', $userId)
            ->exists();

        if ($usedByAnotherUser) {
            return back()->withErrors([
                'game' => 'Este juego también está siendo utilizado por otra colección. Puedes quitarlo de tus colecciones, pero no eliminarlo globalmente.',
            ]);
        }

        /*
         * No permitir eliminar un juego que tenga
         * partidas de otras cuentas o sin propietario.
         */
        $hasForeignPlays = DB::table('plays')
            ->where('game_id', $game->id)
            ->where(function ($query) use ($userId) {
                $query->where('created_by', '<>', $userId)
                    ->orWhereNull('created_by');
            })
            ->exists();

        if ($hasForeignPlays) {
            return back()->withErrors([
                'game' => 'No puedes eliminar este juego porque tiene partidas históricas de otras cuentas. Puedes quitarlo de tus colecciones.',
            ]);
        }

        /*
         * Impedir eliminar juegos que aparecen
         * en votaciones de otras cuentas.
         */
        $hasForeignPolls = DB::table('poll_candidates as pc')
            ->join('polls as p', 'p.id', '=', 'pc.poll_id')
            ->where('pc.game_id', $game->id)
            ->where('p.created_by', '<>', $userId)
            ->exists();

        if ($hasForeignPolls) {
            return back()->withErrors([
                'game' => 'No puedes eliminar este juego porque forma parte de votaciones de otras cuentas. Puedes quitarlo de tus colecciones.',
            ]);
        }

        DB::transaction(function () use ($game) {
            /*
             * 1. Votos asociados a candidatos de este juego.
             */
            $candidateIds = DB::table('poll_candidates')
                ->where('game_id', $game->id)
                ->pluck('id');

            if ($candidateIds->isNotEmpty()) {
                DB::table('votes')
                    ->whereIn('poll_candidate_id', $candidateIds)
                    ->delete();
            }

            /*
             * 2. Candidatos de votaciones.
             */
            DB::table('poll_candidates')
                ->where('game_id', $game->id)
                ->delete();

            /*
             * 3. Partidas y sus relaciones con jugadores.
             */
            $playIds = DB::table('plays')
                ->where('game_id', $game->id)
                ->pluck('id');

            if ($playIds->isNotEmpty()) {
                DB::table('play_player')
                    ->whereIn('play_id', $playIds)
                    ->delete();

                DB::table('plays')
                    ->whereIn('id', $playIds)
                    ->delete();
            }

            /*
             * 4. Jugadores ideales.
             */
            DB::table('game_ideal_player_counts')
                ->where('game_id', $game->id)
                ->delete();

            /*
             * 5. Relaciones con colecciones.
             */
            DB::table('game_collection_game')
                ->where('game_id', $game->id)
                ->delete();

            /*
             * 6. Expansiones.
             */
            DB::table('expansions')
                ->where('game_id', $game->id)
                ->delete();

            /*
             * 7. Juego.
             */
            $game->delete();
        });

        return redirect()
            ->route('games.index')
            ->with('success', 'Juego eliminado definitivamente.');
    }

    public function store(
        Request $request,
        BggService $bggService
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],

            'image_url' => ['nullable', 'url'],

            'min_players' => ['required', 'integer', 'min:1'],
            'max_players' => ['required', 'integer', 'gte:min_players'],

            'min_playtime' => ['nullable', 'integer', 'min:1'],
            'max_playtime' => ['nullable', 'integer', 'gte:min_playtime'],

            'complexity' => ['nullable', 'numeric', 'between:1,5'],

            'game_modes' => [
                'present',
                'array',
                'max:3',
            ],

            'game_modes.*' => [
                'string',
                'in:competitive,cooperative,semi_cooperative',
                'distinct',
            ],

            'short_description' => ['nullable', 'string'],
            'additional_information' => ['nullable', 'string'],

            'bgg_url' => ['nullable', 'url'],
            'youtube_url' => ['nullable', 'url'],

            'ideal_player_counts' => ['required', 'array', 'min:1'],
            'ideal_player_counts.*' => [
                'required',
                'integer',
                'min:1',
                'max:20',
                'distinct',
            ],
            'collection_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'collection_ids.*' => [
                'integer',
                'distinct',
            ],
            'bgg_id' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'bgg_expansion_ids' => [
                'nullable',
                'array',
            ],
            'bgg_expansion_ids.*' => [
                'integer',
                'distinct',
                'min:1',
            ],
        ]);

        $userId = (int) $request->user()->id;

        $requestedCollectionIds = collect($validated['collection_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $ownedCollectionIds = GameCollection::query()
            ->where('created_by', $userId)
            ->whereIn('id', $requestedCollectionIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if ($ownedCollectionIds->count() !== $requestedCollectionIds->count()) {
            return back()->withErrors([
                'collection_ids' => 'Alguna colección seleccionada no te pertenece.',
            ])->withInput();
        }

        /*
        |--------------------------------------------------------------------------
        | EXTRAER BGG ID DE LA URL SI HACE FALTA
        |--------------------------------------------------------------------------
        */

        if (
            empty($validated['bgg_id']) &&
            ! empty($validated['bgg_url']) &&
            preg_match('#/boardgame/(\d+)#', $validated['bgg_url'], $matches)
        ) {
            $validated['bgg_id'] = (int) $matches[1];
        }

        /*
        |--------------------------------------------------------------------------
        | Evitar duplicados al importar desde BGG
        |--------------------------------------------------------------------------
        |
        | Primero buscamos por bgg_id. Después, por el nombre exacto de un
        | juego sin bgg_id perteneciente a las colecciones del usuario.
        |
        */

        $bggId = isset($validated['bgg_id']) ? (int) $validated['bgg_id'] : null;

        if ($bggId) {
            $existingBggGame = Game::query()
                ->where('bgg_id', $bggId)
                ->first();

            if ($existingBggGame) {
                $this->attachGameToCollections($existingBggGame, $ownedCollectionIds);

                return redirect()
                    ->route('games.show', $existingBggGame)
                    ->with('success', 'El juego ya estaba importado desde BGG y se ha añadido a tu colección.');
            }

            $legacyGame = Game::query()
                ->whereNull('bgg_id')
                ->where('name', trim($validated['name']))
                ->whereExists(function ($query) use ($userId) {
                    $query->select(DB::raw(1))
                        ->from('game_collection_game as gcg')
                        ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
                        ->whereColumn('gcg.game_id', 'games.id')
                        ->where('gc.created_by', $userId);
                })
                ->first();

            if ($legacyGame) {
                DB::transaction(function () use (
                    $legacyGame,
                    $validated,
                    $bggId,
                    $ownedCollectionIds
                ) {
                    $legacyGame->bgg_id = $bggId;

                    // Rellenamos huecos sin sustituir datos introducidos manualmente.
                    foreach (['image_url', 'bgg_url'] as $field) {
                        if (! $legacyGame->{$field} && ! empty($validated[$field])) {
                            $legacyGame->{$field} = $validated[$field];
                        }
                    }

                    foreach (['min_players', 'max_players', 'min_playtime', 'max_playtime', 'complexity'] as $field) {
                        if ($legacyGame->{$field} === null) {
                            $legacyGame->{$field} = $validated[$field] ?? null;
                        }
                    }

                    $legacyGame->save();

                    $this->attachGameToCollections($legacyGame, $ownedCollectionIds);
                });

                return redirect()
                    ->route('games.show', $legacyGame)
                    ->with('success', 'El juego que ya tenías se ha vinculado con BoardGameGeek.');
            }
        }

        /*
        |--------------------------------------------------------------------------
        | EXPANSIONES BGG SELECCIONADAS
        |--------------------------------------------------------------------------
        */

        $selectedBggExpansions = collect();

        if (!empty($validated['bgg_id']) && !empty($validated['bgg_expansion_ids'])) {
            try {
                $availableBggExpansions = collect(
                    $bggService->expansionsForGame(
                        (int) $validated['bgg_id']
                    )
                )->keyBy('bgg_id');
            } catch (RuntimeException $exception) {
                report($exception);

                throw ValidationException::withMessages([
                    'bgg_expansion_ids' =>
                        'No se han podido consultar las expansiones en BoardGameGeek.',
                ]);
            }

            $selectedBggExpansions = collect($validated['bgg_expansion_ids'])
                ->map(fn ($id) => $availableBggExpansions->get((int) $id))
                ->filter()
                ->values();

            if ($selectedBggExpansions->count() !== count($validated['bgg_expansion_ids'])) {
                throw ValidationException::withMessages([
                    'bgg_expansion_ids' =>
                        'Una de las expansiones seleccionadas no pertenece a este juego en BoardGameGeek.',
                ]);
            }
        }

        $game = DB::transaction(function () use (
            $validated,
            $ownedCollectionIds,
            $selectedBggExpansions
        ) {
            $game = Game::create([
                'bgg_id' => $validated['bgg_id'] ?? null,
                'name' => $validated['name'],
                'image_url' => $validated['image_url'] ?? null,
                'min_players' => $validated['min_players'],
                'max_players' => $validated['max_players'],
                'min_playtime' => $validated['min_playtime'] ?? null,
                'max_playtime' => $validated['max_playtime'] ?? null,
                'complexity' => $validated['complexity'] ?? null,
                'game_mode' => $validated['game_modes'][0] ?? null,
                'game_modes' => $validated['game_modes'],
                'short_description' => $validated['short_description'] ?? null,
                'additional_information' => $validated['additional_information'] ?? null,
                'bgg_url' => $validated['bgg_url'] ?? null,
                'youtube_url' => $validated['youtube_url'] ?? null,
                'is_active' => true,
            ]);

            $this->attachGameToCollections($game, $ownedCollectionIds);

            foreach ($validated['ideal_player_counts'] ?? [] as $playerCount) {
                $game->idealPlayerCounts()->create([
                    'player_count' => (int) $playerCount,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | CREAR EXPANSIONES IMPORTADAS DE BGG
            |--------------------------------------------------------------------------
            */

            foreach ($selectedBggExpansions as $expansion) {
                $game->expansions()->firstOrCreate(
                    ['name' => $expansion['name']],
                    [
                        'bgg_id' => $expansion['bgg_id'],
                        'bgg_url' => $expansion['bgg_url'] ?? null,
                        'image_url' => $expansion['image_url'] ?? null,
                        'youtube_url' => null,
                        'notes' => null,
                        'is_active' => true,
                    ]
                );
            }

            return $game;
        });

        return redirect()
            ->route('games.index')
            ->with('success', 'Juego añadido correctamente.');
    }

    private function attachGameToCollections(Game $game, $collectionIds): void
    {
        $rows = collect($collectionIds)
            ->map(fn ($collectionId) => [
                'game_id' => $game->id,
                'game_collection_id' => (int) $collectionId,
            ])
            ->all();

        if (! $rows) {
            return;
        }

        DB::table('game_collection_game')->insertOrIgnore($rows);
    }

    private function userHasGame(int $userId, int $gameId): bool
    {
        return DB::table('game_collection_game as gcg')
            ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
            ->where('gcg.game_id', $gameId)
            ->where('gc.created_by', $userId)
            ->exists();
    }

    private function authorizeGame(Request $request, Game $game): void
    {
        abort_unless(
            $this->userHasGame((int) $request->user()->id, (int) $game->id),
            403
        );
    }
}
