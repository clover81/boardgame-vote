<?php

namespace App\Http\Controllers;

use App\Models\Expansion;
use App\Models\Game;
use App\Models\Play;
use App\Models\Player;
use App\Models\Poll;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PlayController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | HISTORIAL GENERAL
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): Response
    {
        $plays = Play::query()
            ->where(
                'created_by',
                $request->user()->id
            )
            ->with([
                'game',
                'players',
                'expansion',
                'poll' => fn ($query) => $query
                    ->where('created_by', $request->user()->id)
                    ->select('id', 'name'),
            ])
            ->orderByDesc('played_at')
            ->orderByDesc('id')
            ->get();

        $uniqueGames = $plays
            ->pluck('game_id')
            ->unique()
            ->count();

        $uniquePlayers = $plays
            ->flatMap(
                fn($play) =>
                $play->players->pluck('id')
            )
            ->unique()
            ->count();

        return Inertia::render('plays/index', [
            'plays' => $plays
                ->map(function ($play) {
                    return [
                        'id' =>
                        $play->id,

                        'played_at' =>
                        $play
                            ->played_at
                            ->format('Y-m-d'),

                        'notes' =>
                        $play->notes,

                        'poll' => $play->poll?->only(['id', 'name']),

                        'game' => [
                            'id' =>
                            $play->game->id,

                            'name' =>
                            $play->game->name,

                            'image_url' =>
                            $play
                                ->game
                                ->image_url,
                        ],

                        'expansion' =>
                        $play->expansion
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
                        $play->players
                            ->map(
                                fn($player) => [
                                    'id' =>
                                    $player->id,

                                    'name' =>
                                    $player->name,
                                ]
                            )
                            ->values(),
                    ];
                })
                ->values(),

            'stats' => [
                'total_plays' =>
                $plays->count(),

                'unique_games' =>
                $uniqueGames,

                'unique_players' =>
                $uniquePlayers,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRAR PARTIDA DESDE VOTACIÓN
    |--------------------------------------------------------------------------
    */
    /*
|--------------------------------------------------------------------------
| REGISTRAR PARTIDA MANUALMENTE
|--------------------------------------------------------------------------
*/

    public function create(
        Request $request
    ): Response {
        /*
    |--------------------------------------------------------------------------
    | Juegos
    |--------------------------------------------------------------------------
    */

        $games = Game::query()
            ->where('is_active', true)
            ->whereHas('collections', function ($query) use ($request) {
                $query->where('game_collections.created_by', $request->user()->id);
            })
            ->with([
                'expansions' =>
                function ($query) {
                    $query
                        ->where(
                            'is_active',
                            true
                        )
                        ->orderBy('name');
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(
                function ($game) {
                    return [
                        'id' =>
                        $game->id,

                        'name' =>
                        $game->name,

                        'image_url' =>
                        $game->image_url,

                        'expansions' =>
                        $game
                            ->expansions
                            ->map(
                                fn($expansion) => [
                                    'id' =>
                                    $expansion->id,

                                    'name' =>
                                    $expansion->name,
                                ]
                            )
                            ->values(),
                    ];
                }
            )
            ->values();

        /*
    |--------------------------------------------------------------------------
    | Jugadores
    |--------------------------------------------------------------------------
    */

        $players = Player::query()
            ->where('created_by', $request->user()->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        /*
    |--------------------------------------------------------------------------
    | Juego preseleccionado
    |--------------------------------------------------------------------------
    |
    | Esto nos permitirá entrar desde la ficha:
    |
    | /plays/create?game_id=4
    |
    */

        $selectedGameId =
            $request->integer(
                'game_id'
            ) ?: null;

        /*
     * No aceptamos un ID que no forme parte
     * de los juegos disponibles.
     */
        if (
            $selectedGameId !== null &&
            !$games->contains(
                'id',
                $selectedGameId
            )
        ) {
            $selectedGameId = null;
        }

        // Solo preseleccionar jugadores activos de la cuenta actual.
        $selectedPlayerId = $request->integer('player_id') ?: null;
        if (
            $selectedPlayerId !== null &&
            ! $players->contains('id', $selectedPlayerId)
        ) {
            $selectedPlayerId = null;
        }

        return Inertia::render(
            'plays/create-manual',
            [
                'games' =>
                $games,

                'players' =>
                $players,

                'selected_game_id' =>
                $selectedGameId,
                'selected_player_id' => $selectedPlayerId,
            ]
        );
    }
    public function createFromPoll(
        Request $request,
        Poll $poll
    ): Response|RedirectResponse {
        $userId = (int) $request->user()->id;

        abort_unless(
            $poll->created_by ===
                $userId,
            403
        );

        abort_unless(
            $poll->status ===
                'closed',
            404
        );

        if (Play::where('poll_id', $poll->id)->exists()) {
            return redirect()
                ->route('polls.show', $poll)
                ->withErrors([
                    'play' => 'Las partidas de esta votación ya han sido registradas.',
                ]);
        }

        /*
    |--------------------------------------------------------------------------
    | Cargar candidatos + expansión prevista
    |--------------------------------------------------------------------------
    */

        $poll->load([
            'participants.player',

            'candidates.expansion',

            'candidates.votes',

            'candidates.game',
        ]);

        $ranking = $poll->candidates
            ->map(function ($candidate) use ($poll) {
                $firstVotes = $candidate->votes->where('position', 1)->count();
                $secondVotes = $candidate->votes->where('position', 2)->count();

                return [
                    'candidate' => $candidate,
                    'first_votes' => $firstVotes,
                    'points' => $poll->votePoints($firstVotes, $secondVotes),
                ];
            })
            ->sort(function (array $a, array $b) {
                if ($a['points'] !== $b['points']) {
                    return $b['points'] <=> $a['points'];
                }

                return $b['first_votes'] <=> $a['first_votes'];
            })
            ->values();

        $winnerCandidate = null;
        $hasClearWinner = false;

        $hasRecordedVotes = $ranking->isNotEmpty() && $ranking->first()['points'] > 0;

        if ($hasRecordedVotes) {
            $first = $ranking->get(0);
            $second = $ranking->get(1);

            $hasClearWinner = $second === null
                || $first['points'] !== $second['points']
                || $first['first_votes'] !== $second['first_votes'];

            if ($hasClearWinner) {
                $winnerCandidate = $first['candidate'];
            }
        }

        $initialGameId = $winnerCandidate?->game_id;
        $initialExpansionId = $winnerCandidate?->expansion?->is_active
            ? $winnerCandidate->expansion_id
            : null;

        $initialPlayerIds = $poll->participants
            ->pluck('player_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        // Mantener los participantes de la votación (incluidos los inactivos)
        // y ofrecer además los jugadores habituales activos de esta cuenta.
        // Los participantes iniciales siguen siendo exclusivamente los de la votación.
        $pollPlayers = $poll->participants
            ->filter(fn ($participant) => $participant->player_id !== null)
            ->map(fn ($participant) => [
                'id' => (int) $participant->player_id,
                'name' => $participant->display_name,
            ])
            ->values();

        $otherActivePlayers = Player::query()
            ->where('created_by', $userId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($player) => [
                'id' => (int) $player->id,
                'name' => $player->name,
            ]);

        $players = $pollPlayers
            ->concat($otherActivePlayers)
            ->unique('id')
            ->values();

        $candidates = $poll->candidates
            ->map(fn ($candidate) => [
                'id' => $candidate->id,
                'game' => [
                    'id' => $candidate->game_id,
                    'name' => $candidate->game->name,
                ],
                'planned_expansion_id' => $candidate->expansion?->is_active
                    ? $candidate->expansion_id
                    : null,
            ])
            ->values();

        $candidatesByGame = $poll->candidates->keyBy('game_id');

        $games = Game::query()
            ->where('is_active', true)
            ->whereHas('collections', fn ($query) => $query
                ->where('game_collections.created_by', $userId))
            ->with(['expansions' => function ($query) {
                $query->where('is_active', true)->orderBy('name');
            }])
            ->orderBy('name')
            ->get()
            ->map(function ($game) use ($candidatesByGame) {
                $candidate = $candidatesByGame->get($game->id);

                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'image_url' => $game->image_url,
                    'planned_expansion_id' => $candidate?->expansion?->is_active
                        ? $candidate->expansion_id
                        : null,
                    'expansions' => $game->expansions
                        ->map(fn ($expansion) => [
                            'id' => $expansion->id,
                            'name' => $expansion->name,
                        ])
                        ->values(),
                ];
            })
            ->values();

        if (! $games->contains('id', $initialGameId)) {
            $initialGameId = null;
            $initialExpansionId = null;
        }

        return Inertia::render('plays/create', [
            'poll' => [
                'id' => $poll->id,
                'name' => $poll->name,
                'has_clear_winner' => $hasClearWinner,
                'has_recorded_votes' => $hasRecordedVotes,
            ],
            'candidates' => $candidates,
            'players' => $players,
            'games' => $games,
            'defaults' => [
                'game_ids' => $initialGameId !== null
                    ? [$initialGameId]
                    : [],
                'expansion_ids' => $initialGameId !== null && $initialExpansionId !== null
                    ? [$initialGameId => $initialExpansionId]
                    : [],
                'player_ids' => $initialPlayerIds,
                'played_at' => now()->toDateString(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GUARDAR PARTIDA
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request
    ): RedirectResponse {
        $userId =
            (int) $request->user()->id;

        $validated =
            $request->validate([
                'game_ids' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'game_ids.*' => [
                    'integer',
                    'distinct',
                    'exists:games,id',
                ],

                'expansion_ids' => [
                    'nullable',
                    'array',
                ],

                'expansion_ids.*' => [
                    'nullable',
                    'integer',
                    'exists:expansions,id',
                ],

                'played_at' => [
                    'required',
                    'date',
                ],

                'player_ids' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'player_ids.*' => [
                    'integer',
                    'distinct',
                    'exists:players,id',
                ],

                'notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],

                'poll_id' => [
                    'nullable',
                    'integer',
                    'exists:polls,id',
                ],
            ]);

        $gameIds = collect($validated['game_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        /*
         * Todos los juegos deben pertenecer a alguna
         * colección del usuario.
         */
        $ownedGameCount =
            Game::query()
                ->whereKey(
                    $gameIds->all()
                )
                ->whereHas(
                    'collections',
                    fn ($query) =>
                        $query->where(
                            'game_collections.created_by',
                            $userId
                        )
                )
                ->count();

        if ($ownedGameCount !== $gameIds->count()) {
            throw ValidationException::withMessages([
                'game_ids' =>
                    'El juego seleccionado no pertenece a tu colección.',
            ]);
        }

        /*
         * Todos los jugadores deben existir
         * y pertenecer al usuario autenticado.
         */
        $playerIds =
            collect(
                $validated['player_ids']
            )
                ->map(
                    fn ($id) =>
                        (int) $id
                )
                ->unique()
                ->values();

        $existingPlayers =
            Player::query()
                ->where('created_by', $userId)
                ->whereIn(
                    'id',
                    $playerIds
                )
                ->count();

        if (
            $existingPlayers
            !== $playerIds->count()
        ) {
            throw ValidationException::withMessages([
                'player_ids' =>
                    'Alguno de los jugadores seleccionados no existe.',
            ]);
        }

        /*
         * Si procede de una votación:
         *
         * - debe ser nuestra
         * - debe estar cerrada
         */
        $pollId =
            isset($validated['poll_id'])
                ? (int) $validated['poll_id']
                : null;

        $registered = DB::transaction(function () use (
            $userId,
            $gameIds,
            $pollId,
            $validated,
            $playerIds
        ): bool {
            if ($pollId) {
                $poll = Poll::query()
                    ->whereKey($pollId)
                    ->where('created_by', $userId)
                    ->where('status', 'closed')
                    ->lockForUpdate()
                    ->first();

                if (!$poll) {
                    throw ValidationException::withMessages([
                        'poll_id' => 'La votación seleccionada no está disponible.',
                    ]);
                }

                if ($poll->plays()->exists()) {
                    return false;
                }
            }

            $expansionIds = $validated['expansion_ids'] ?? [];

            foreach ($gameIds as $gameId) {
                $expansionId = $expansionIds[$gameId] ?? null;

                if ($expansionId !== null) {
                    $expansionId = (int) $expansionId;
                    $belongsToGame = Expansion::whereKey($expansionId)
                        ->where('game_id', $gameId)
                        ->exists();

                    if (!$belongsToGame) {
                        $expansionId = null;
                    }
                }

                $play = Play::create([
                    'created_by' => $userId,
                    'game_id' => $gameId,
                    'expansion_id' => $expansionId,
                    'poll_id' => $pollId,
                    'played_at' => $validated['played_at'],
                    'notes' => $validated['notes'] ?? null,
                ]);

                $play->players()->sync($playerIds->all());
            }

            return true;
        });

        if (!$registered) {
            return redirect()
                ->route('polls.show', $pollId)
                ->withErrors([
                    'play' => 'Las partidas de esta votación ya han sido registradas.',
                ]);
        }

        if ($pollId) {
            return redirect()
                ->route('polls.show', $pollId)
                ->with(
                    'success',
                    $gameIds->count() > 1
                        ? 'Partidas registradas correctamente.'
                        : 'Partida registrada correctamente.'
                );
        }

        return redirect()
            ->route(
                'games.index'
            )
            ->with(
                'success',
                'Partida registrada correctamente.'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | EDITAR PARTIDA
    |--------------------------------------------------------------------------
    */

    public function edit(
        Request $request,
        Play $play
    ): Response {
        abort_unless(
            $play->created_by ===
                $request->user()->id,
            403
        );

        $play->load([
            'players',
            'game',
            'expansion',
        ]);

        $userId = (int) $request->user()->id;

        $games = Game::query()
            ->whereHas('collections', fn ($query) => $query
                ->where('game_collections.created_by', $userId))
            ->with([
                'expansions' => function ($query) use ($play) {
                    $query
                        ->where(fn ($expansions) => $expansions
                            ->where('is_active', true)
                            ->orWhere('id', $play->expansion_id))
                        ->orderBy('name');
                },
            ])
            ->orderBy('name')
            ->get()
            ->map(
                function ($game) {
                    return [
                        'id' =>
                        $game->id,

                        'name' =>
                        $game->name,

                        'image_url' =>
                        $game->image_url,

                        'expansions' =>
                        $game->expansions
                            ->map(
                                fn(
                                    $expansion
                                ) => [
                                    'id' =>
                                    $expansion
                                        ->id,

                                    'name' =>
                                    $expansion
                                        ->name,
                                ]
                            )
                            ->values(),
                    ];
                }
            )
            ->values();

        $players = Player::query()
            ->where('created_by', $userId)
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        return Inertia::render(
            'plays/edit',
            [
                'play' => [
                    'id' =>
                    $play->id,

                    'game_id' =>
                    $play->game_id,

                    'expansion_id' =>
                    $play->expansion_id,

                    'played_at' =>
                    $play
                        ->played_at
                        ->format(
                            'Y-m-d'
                        ),

                    'notes' =>
                    $play->notes,

                    'player_ids' =>
                    $play->players
                        ->pluck('id')
                        ->values(),
                ],

                'games' =>
                $games,

                'players' =>
                $players,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR PARTIDA
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Play $play
    ): RedirectResponse {
        $userId = (int) $request->user()->id;

        abort_unless(
            $play->created_by ===
                $userId,
            403
        );

        $validated =
            $request->validate([
                'game_id' => [
                    'required',
                    'integer',
                    'exists:games,id',
                ],

                'expansion_id' => [
                    'nullable',
                    'integer',
                    'exists:expansions,id',
                ],

                'played_at' => [
                    'required',
                    'date',
                ],

                'player_ids' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'player_ids.*' => [
                    'integer',
                    'distinct',
                    'exists:players,id',
                ],

                'notes' => [
                    'nullable',
                    'string',
                    'max:2000',
                ],
            ]);

        /*
         * Comprobar que todos los jugadores
         * pertenecen al usuario autenticado.
         */
        $ownedPlayersCount = Player::query()
            ->where('created_by', $userId)
            ->whereIn('id', $validated['player_ids'])
            ->count();

        if ($ownedPlayersCount !== count($validated['player_ids'])) {
            throw ValidationException::withMessages([
                'player_ids' => 'Solo puedes seleccionar jugadores de tu cuenta.',
            ]);
        }

        $game = Game::query()
            ->whereKey($validated['game_id'])
            ->whereHas('collections', fn ($query) => $query
                ->where('game_collections.created_by', $userId))
            ->firstOrFail();

        $expansionId = $validated['expansion_id'] ?? null;

        if ($expansionId) {
            $validExpansion = $game
                ->expansions()
                ->whereKey($expansionId)
                ->exists();

            if (! $validExpansion) {
                throw ValidationException::withMessages([
                    'expansion_id' => 'La expansión seleccionada no pertenece a este juego.',
                ]);
            }
        }

        DB::transaction(
            function () use (
                $play,
                $game,
                $expansionId,
                $validated
            ) {
                $play->update([
                    'game_id' =>
                    $game->id,

                    'expansion_id' =>
                    $expansionId,

                    'played_at' =>
                    $validated['played_at'],

                    'notes' =>
                    $validated['notes'] ?? null,
                ]);

                $play
                    ->players()
                    ->sync(
                        $validated['player_ids']
                    );
            }
        );

        return redirect()
            ->route('plays.index')
            ->with(
                'success',
                'Partida actualizada correctamente.'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | ELIMINAR PARTIDA
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        Play $play
    ): RedirectResponse {
        abort_unless(
            $play->created_by ===
                $request->user()->id,
            403
        );

        DB::transaction(
            function () use ($play) {
                $play
                    ->players()
                    ->detach();

                $play->delete();
            }
        );

        return redirect()
            ->route('plays.index')
            ->with(
                'success',
                'Partida eliminada correctamente.'
            );
    }
}
