<?php

namespace App\Http\Controllers;

use App\Models\Expansion;
use App\Models\Game;
use App\Models\GameCollection;
use App\Models\Player;
use App\Models\Poll;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class PollController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LISTADO DE VOTACIONES
    |--------------------------------------------------------------------------
    */

    public function index(): Response
    {
        $userId = request()->user()->id;

        $collections = GameCollection::query()
            ->where('created_by', $userId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);

        $collectionId = request()->integer('collection_id') ?: null;
        if ($collectionId !== null && !$collections->contains('id', $collectionId)) {
            $collectionId = null;
        }
        $status = request()->string('status')->toString();
        if (!in_array($status, ['draft', 'open', 'closed'], true)) {
            $status = '';
        }
        $search = trim(request()->string('search')->toString());

        $polls = Poll::query()
            ->where('created_by', $userId)
            ->withCount(['participants', 'candidates'])
            ->with([
                'gameCollections' => fn ($query) => $query
                    ->where('created_by', $userId),
            ])
            ->when($collectionId, fn ($query, $collectionId) => $query
                ->whereHas('gameCollections', fn ($collectionQuery) => $collectionQuery
                    ->where('game_collections.id', $collectionId)
                    ->where('game_collections.created_by', $userId)))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%' . $search . '%'))
            ->latest()
            ->get();

        // Una sola consulta adicional para las partidas registradas del usuario.
        $playedPollIds = DB::table('plays')
            ->where('created_by', $userId)
            ->whereNotNull('poll_id')
            ->pluck('poll_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $polls = $polls->map(fn (Poll $poll) => [
            'id' => $poll->id,
            'name' => $poll->name,
            'token' => $poll->token,
            'status' => $poll->status,
            'participants_count' => $poll->participants_count,
            'candidates_count' => $poll->candidates_count,
            'has_registered_play' => in_array($poll->id, $playedPollIds, true),
            'created_at' => $poll->created_at?->toIso8601String(),
            'collections' => $poll->gameCollections
                ->map(fn ($collection) => [
                    'id' => $collection->id,
                    'name' => $collection->name,
                ])
                ->values(),
        ]);

        return Inertia::render('polls/index', [
            'polls' => $polls,
            'collections' => $collections,
            'filters' => [
                'collection_id' => $collectionId,
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR VOTACIÓN
    |--------------------------------------------------------------------------
    */

    public function create(): Response
    {
        $userId =
            request()->user()->id;

        /*
    |--------------------------------------------------------------------------
    | Jugadores
    |--------------------------------------------------------------------------
    */

        $players = Player::query()
            ->where('created_by', $userId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);

        /*
    |--------------------------------------------------------------------------
    | Colecciones del usuario
    |--------------------------------------------------------------------------
    */

        $collections =
            GameCollection::query()
            ->where(
                'created_by',
                $userId
            )
            ->withCount('games')
            ->orderByDesc(
                'is_default'
            )
            ->orderBy('name')
            ->get();

        /*
    |--------------------------------------------------------------------------
    | Juegos que pertenecen al menos a una colección del usuario
    |--------------------------------------------------------------------------
    */

        $games = Game::query()
            ->where('is_active', true)

            ->whereHas(
                'collections',
                fn ($query) =>
                    $query->where(
                        'game_collections.created_by',
                        $userId
                    )
            )

            ->with([
                /*
             * Necesitamos saber a qué colecciones
             * pertenece cada juego.
             */
                'collections' =>
                fn ($query) =>
                    $query->where(
                        'game_collections.created_by',
                        $userId
                    ),

                'idealPlayerCounts',

                'expansions' =>
                function ($query) {
                    $query
                        ->where(
                            'is_active',
                            true
                        )
                        ->orderBy('name');
                },

                'plays' =>
                function ($query) use (
                    $userId
                ) {
                    $query
                        ->where(
                            'created_by',
                            $userId
                        )
                        ->orderByDesc(
                            'played_at'
                        );
                },
            ])

            ->orderBy('name')
            ->get()

            ->map(
                function (Game $game) {
                    return [
                        'id' =>
                        $game->id,

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

                        /*
                    |--------------------------------------------------------------------------
                    | Colecciones de este juego
                    |--------------------------------------------------------------------------
                    */

                        'collection_ids' =>
                        $game
                            ->collections
                            ->pluck('id')
                            ->values(),

                        'ideal_player_counts' =>
                        $game
                            ->idealPlayerCounts
                            ->pluck(
                                'player_count'
                            )
                            ->values(),

                        /*
                    |--------------------------------------------------------------------------
                    | Expansiones
                    |--------------------------------------------------------------------------
                    */

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

                        /*
                    |--------------------------------------------------------------------------
                    | Historial
                    |--------------------------------------------------------------------------
                    */

                        'total_plays' =>
                        $game
                            ->plays
                            ->count(),

                        'last_played_at' =>
                        $game
                            ->plays
                            ->first()
                            ?->played_at
                            ?->format(
                                'Y-m-d'
                            ),
                    ];
                }
            )
            ->values();

        return Inertia::render(
            'polls/create',
            [
                'players' =>
                $players,

                'collections' =>
                $collections
                    ->map(
                        fn($collection) => [
                            'id' =>
                            $collection->id,

                            'name' =>
                            $collection->name,

                            'is_default' =>
                            $collection
                                ->is_default,

                            'games_count' =>
                            $collection
                                ->games_count,
                        ]
                    )
                    ->values(),

                'games' =>
                $games,
            ]
        );
    }
    /*
    |--------------------------------------------------------------------------
    | GUARDAR NUEVA VOTACIÓN
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request
    ): RedirectResponse {
        $userId =
            (int) $request->user()->id;

        $validated =
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'single_choice' => ['sometimes', 'boolean'],

                'collection_ids' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'collection_ids.*' => [
                    'integer',
                    'distinct',
                    'exists:game_collections,id',
                ],

                'player_ids' => [
                    'required',
                    'array',
                    'min:2',
                ],

                'player_ids.*' => [
                    'integer',
                    'distinct',
                    'exists:players,id',
                ],

                'candidate_ids' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'candidate_ids.*' => [
                    'integer',
                    'distinct',
                    'exists:games,id',
                ],

                'candidate_notes' => [
                    'nullable',
                    'array',
                ],

                'candidate_notes.*' => [
                    'nullable',
                    'string',
                    'max:1000',
                ],

                'candidate_expansion_ids' => [
                    'nullable',
                    'array',
                ],

                'candidate_expansion_ids.*' => [
                    'nullable',
                    'integer',
                    'exists:expansions,id',
                ],
            ]);

        /*
         * Normalizamos IDs.
         */
        $collectionIds =
            collect(
                $validated[
                    'collection_ids'
                ]
            )
                ->map(
                    fn ($id) =>
                        (int) $id
                )
                ->unique()
                ->values();

        $playerIds =
            collect(
                $validated[
                    'player_ids'
                ]
            )
                ->map(
                    fn ($id) =>
                        (int) $id
                )
                ->unique()
                ->values();

        $candidateIds =
            collect(
                $validated[
                    'candidate_ids'
                ]
            )
                ->map(
                    fn ($id) =>
                        (int) $id
                )
                ->unique()
                ->values();

        /*
         * 1. Las colecciones tienen que ser
         * realmente del usuario.
         */
        $ownedCollectionIds =
            GameCollection::query()
                ->where(
                    'created_by',
                    $userId
                )
                ->whereIn(
                    'id',
                    $collectionIds
                )
                ->pluck('id')
                ->map(
                    fn ($id) =>
                        (int) $id
                );

        if (
            $ownedCollectionIds->count()
            !== $collectionIds->count()
        ) {
            return back()
                ->withErrors([
                    'collection_ids' =>
                        'Alguna de las colecciones seleccionadas no está disponible.',
                ])
                ->withInput();
        }

        /*
         * 2. Los jugadores deben pertenecer al usuario,
         * existir y estar activos.
         */
        $validPlayerIds =
            Player::query()
                ->where('created_by', $userId)
                ->where(
                    'is_active',
                    true
                )
                ->whereIn(
                    'id',
                    $playerIds
                )
                ->pluck('id');

        if (
            $validPlayerIds->count()
            !== $playerIds->count()
        ) {
            return back()
                ->withErrors([
                    'player_ids' =>
                        'Alguno de los jugadores seleccionados no está disponible.',
                ])
                ->withInput();
        }

        /*
         * 3. Cada juego candidato debe:
         *
         * - estar activo
         * - estar dentro de alguna de las
         *   colecciones seleccionadas
         * - pertenecer al contexto del usuario
         */
        $validCandidateIds =
            Game::query()
                ->where(
                    'is_active',
                    true
                )
                ->whereIn(
                    'id',
                    $candidateIds
                )
                ->whereHas(
                    'collections',
                    function (
                        $query
                    ) use (
                        $ownedCollectionIds,
                        $userId
                    ) {
                        $query
                            ->where(
                                'game_collections.created_by',
                                $userId
                            )
                            ->whereIn(
                                'game_collections.id',
                                $ownedCollectionIds
                            );
                    }
                )
                ->pluck('id')
                ->map(
                    fn ($id) =>
                        (int) $id
                );

        if (
            $validCandidateIds->count()
            !== $candidateIds->count()
        ) {
            return back()
                ->withErrors([
                    'candidate_ids' =>
                        'Algún juego candidato no pertenece a las colecciones seleccionadas.',
                ])
                ->withInput();
        }

        /*
         * 4. Validamos juego ↔ expansión.
         *
         * No basta con que la expansión exista:
         * tiene que pertenecer precisamente
         * al juego candidato.
         */
        foreach (
            $candidateIds
            as $gameId
        ) {
            $expansionId =
                $validated[
                    'candidate_expansion_ids'
                ][$gameId]
                ?? null;

            if (!$expansionId) {
                continue;
            }

            $validExpansion =
                Expansion::query()
                    ->whereKey(
                        $expansionId
                    )
                    ->where(
                        'game_id',
                        $gameId
                    )
                    ->where(
                        'is_active',
                        true
                    )
                    ->exists();

            if (!$validExpansion) {
                return back()
                    ->withErrors([
                        "candidate_expansion_ids.$gameId" =>
                            'La expansión seleccionada no pertenece a este juego.',
                    ])
                    ->withInput();
            }
        }

        /*
         * Todo validado.
         */
        $poll = DB::transaction(
            function () use (
                $validated,
                $userId,
                $ownedCollectionIds,
                $playerIds,
                $candidateIds
            ) {
                do {
                    $token =
                        Str::upper(
                            Str::random(6)
                        );
                } while (
                    Poll::where(
                        'token',
                        $token
                    )->exists()
                );

                $poll =
                    Poll::create([
                        'created_by' =>
                            $userId,

                        'name' =>
                            $validated[
                                'name'
                            ],

                        'token' =>
                            $token,

                        'status' =>
                            'draft',

                        'single_choice' => $validated['single_choice'] ?? false,
                    ]);

                /*
                 * Guardamos las colecciones
                 * usadas en esta sesión.
                 */
                $collectionRows =
                    $ownedCollectionIds
                        ->map(
                            fn (
                                $collectionId
                            ) => [
                                'poll_id' =>
                                    $poll->id,

                                'game_collection_id' =>
                                    $collectionId,
                            ]
                        )
                        ->all();

                DB::table(
                    'poll_game_collection'
                )->insertOrIgnore(
                    $collectionRows
                );

                /*
                 * Participantes.
                 */
                $players =
                    Player::query()
                        ->whereIn(
                            'id',
                            $playerIds
                        )
                        ->get();

                foreach ($players as $player) {
                    $poll
                        ->participants()
                        ->create([
                            'player_id' =>
                                $player->id,

                            'display_name' =>
                                $player->name,
                        ]);
                }

                /*
                 * Candidatos.
                 */
                foreach (
                    $candidateIds
                    as $gameId
                ) {
                    $poll
                        ->candidates()
                        ->create([
                            'game_id' =>
                                $gameId,

                            'expansion_id' =>
                                $validated[
                                    'candidate_expansion_ids'
                                ][$gameId]
                                ?? null,

                            'note' =>
                                $validated[
                                    'candidate_notes'
                                ][$gameId]
                                ?? null,
                        ]);
                }

                return $poll;
            }
        );

        return redirect()
            ->route(
                'polls.show',
                $poll
            )
            ->with(
                'success',
                'Votación creada correctamente.'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | VER VOTACIÓN EN ADMINISTRACIÓN
    |--------------------------------------------------------------------------
    */

    public function show(
        Poll $poll
    ): Response {
        /*
    |--------------------------------------------------------------------------
    | Seguridad
    |--------------------------------------------------------------------------
    */

        abort_unless(
            $poll->created_by ===
                request()->user()->id,
            403
        );

        /*
    |--------------------------------------------------------------------------
    | Cargar datos
    |--------------------------------------------------------------------------
    */

        $poll->load([
            'participants.votes',

            'gameCollections',

            'candidates.game.idealPlayerCounts',

            /*
         * Expansión prevista para el candidato.
         */
            'candidates.expansion',

            'candidates.votes',

            'play.game',
            'play.expansion',
            'play.players',

            'plays.game',
            'plays.expansion',
            'plays.players',
        ]);

        /*
    |--------------------------------------------------------------------------
    | Participantes que ya han votado completamente
    |--------------------------------------------------------------------------
    */

        $candidateCount = $poll->candidates->count();
        $requiredVoteCount = $poll->requiredVoteCount($candidateCount);

        $hasCompletedVote = function ($participant) use ($requiredVoteCount): bool {
            if ($requiredVoteCount === 0) {
                return false;
            }

            $positions = $participant->votes
                ->pluck('position')
                ->map(fn ($position) => (int) $position)
                ->unique();

            for ($position = 1; $position <= $requiredVoteCount; $position++) {
                if (!$positions->contains($position)) {
                    return false;
                }
            }

            return true;
        };

        $votedParticipants = $poll->participants
            ->filter($hasCompletedVote)
            ->count();

        /*
    |--------------------------------------------------------------------------
    | Resultados
    |--------------------------------------------------------------------------
    */

        $results = collect();
        $hasClearWinner = false;

        $hasRecordedVotes = $poll->candidates->contains(fn ($candidate) => $candidate->votes->isNotEmpty());

        if ($poll->status === 'closed' && $hasRecordedVotes) {
            $results = $poll->candidates
                ->map(function ($candidate) use ($poll) {
                    $firstVotes = $candidate->votes->where('position', 1)->count();
                    $secondVotes = $candidate->votes->where('position', 2)->count();

                    return [
                        'candidate_id' => $candidate->id,
                        'game' => [
                            'id' => $candidate->game->id,
                            'name' => $candidate->game->name,
                            'image_url' => $candidate->game->image_url,
                        ],
                        'first_votes' => $firstVotes,
                        'second_votes' => $secondVotes,
                        'points' => $poll->votePoints($firstVotes, $secondVotes),
                    ];
                })
                // El nombre estabiliza el orden visual; no decide el ganador.
                ->sort(function (array $a, array $b) {
                    if ($a['points'] !== $b['points']) {
                        return $b['points'] <=> $a['points'];
                    }

                    if ($a['first_votes'] !== $b['first_votes']) {
                        return $b['first_votes'] <=> $a['first_votes'];
                    }

                    return strcasecmp($a['game']['name'], $b['game']['name']);
                })
                ->values();

            // Ranking de competición: 1, 1, 3 o 1, 2, 2, 4.
            $previousPoints = null;
            $previousFirstVotes = null;
            $previousRank = null;

            $results = $results->map(function (array $result, int $index) use (
                &$previousPoints,
                &$previousFirstVotes,
                &$previousRank
            ) {
                $sameAsPrevious = $index > 0
                    && $result['points'] === $previousPoints
                    && $result['first_votes'] === $previousFirstVotes;

                if ($sameAsPrevious) {
                    $rank = $previousRank;
                } else {
                    $rank = $index + 1;
                }

                $previousPoints = $result['points'];
                $previousFirstVotes = $result['first_votes'];
                $previousRank = $rank;

                $result['rank'] = $rank;

                return $result;
            });

            $topCount = $results->where('rank', 1)->count();
            $hasClearWinner = $topCount === 1;

            $results = $results
                ->map(function (array $result) use ($hasClearWinner) {
                    $result['is_winner'] = $hasClearWinner && $result['rank'] === 1;
                    $result['is_top_tie'] = !$hasClearWinner && $result['rank'] === 1;

                    return $result;
                })
                ->values();
        }

        $winnerGameId = $hasClearWinner
            ? ($results->first()['game']['id'] ?? null)
            : null;
        $candidateGameIds = $poll->candidates->pluck('game_id');
        $registeredPlay = $poll->play;

        /*
    |--------------------------------------------------------------------------
    | Vista
    |--------------------------------------------------------------------------
    */

        // Ampliar una votación solo antes de recibir el primer voto.
        $canAddCandidates = in_array($poll->status, ['draft', 'open'], true)
            && !$poll->votes()->exists();

        $availableGames = collect();
        if ($canAddCandidates) {
            $collectionIds = $poll->gameCollections
                ->where('created_by', request()->user()->id)
                ->pluck('id');

            $availableGames = Game::query()
                ->where('is_active', true)
                ->whereNotIn('id', $candidateGameIds)
                ->whereHas('collections', fn ($query) => $query
                    ->where('game_collections.created_by', request()->user()->id)
                    ->whereIn('game_collections.id', $collectionIds))
                ->orderBy('name')
                ->get(['id', 'name', 'image_url']);
        }

        return Inertia::render(
            'polls/show',
            [
                'canAddCandidates' => $canAddCandidates,
                'canChangeVotingMode' => $canAddCandidates,
                'availableGames' => $availableGames,
                'poll' => [
                    'id' =>
                    $poll->id,

                    'name' =>
                    $poll->name,

                    'token' =>
                    $poll->token,

                    'status' =>
                    $poll->status,

                    'participants_count' =>
                    $poll
                        ->participants
                        ->count(),

                    'voted_participants_count' =>
                    $votedParticipants,

                    'required_votes' =>
                    $requiredVoteCount,

                    'candidate_count' =>
                    $candidateCount,

                    'single_choice' => (bool) $poll->single_choice,

                    'required_vote_count' =>
                    $requiredVoteCount,

                    'has_clear_winner' =>
                    $hasClearWinner,

                    'plays' => $poll->plays->map(fn ($play) => [
                        'id' => $play->id,
                        'played_at' => $play->played_at->format('Y-m-d'),
                        'game' => [
                            'id' => $play->game->id,
                            'name' => $play->game->name,
                        ],
                        'expansion' => $play->expansion
                            ? [
                                'id' => $play->expansion->id,
                                'name' => $play->expansion->name,
                            ]
                            : null,
                        'players' => $play->players->map(fn ($player) => [
                            'id' => $player->id,
                            'name' => $player->name,
                        ])->values(),
                    ])->values(),

                    'registered_play' => $registeredPlay
                        ? [
                            'id' => $registeredPlay->id,
                            'played_at' => $registeredPlay->played_at->format('Y-m-d'),
                            'game' => [
                                'id' => $registeredPlay->game->id,
                                'name' => $registeredPlay->game->name,
                                'image_url' => $registeredPlay->game->image_url,
                            ],
                            'expansion' => $registeredPlay->expansion
                                ? [
                                    'id' => $registeredPlay->expansion->id,
                                    'name' => $registeredPlay->expansion->name,
                                ]
                                : null,
                            'players' => $registeredPlay->players
                                ->map(fn ($player) => [
                                    'id' => $player->id,
                                    'name' => $player->name,
                                ])
                                ->values(),
                            'was_candidate' => $candidateGameIds->contains($registeredPlay->game_id),
                            'was_winner' => $winnerGameId !== null
                                && $winnerGameId === $registeredPlay->game_id,
                        ]
                        : null,

                    'collections' =>
                    $poll
                        ->gameCollections
                        ->map(
                            fn ($collection) => [
                                'id' =>
                                $collection->id,

                                'name' =>
                                $collection->name,
                            ]
                        )
                        ->values(),

                    /*
                |--------------------------------------------------------------------------
                | PARTICIPANTES
                |--------------------------------------------------------------------------
                */

                    'participants' =>
                    $poll
                        ->participants
                        ->map(
                            function (
                                $participant
                            ) use (
                                $hasCompletedVote
                            ) {
                                return [
                                    'id' =>
                                    $participant
                                        ->id,

                                    'display_name' =>
                                    $participant
                                        ->display_name,

                                    'vote_token' =>
                                    $participant
                                        ->vote_token,

                                    'has_voted' =>
                                    $hasCompletedVote($participant),
                                ];
                            }
                        )
                        ->values(),

                    /*
                |--------------------------------------------------------------------------
                | CANDIDATOS
                |--------------------------------------------------------------------------
                */

                    'candidates' =>
                    $poll
                        ->candidates
                        ->map(
                            function (
                                $candidate
                            ) {
                                return [
                                    'id' =>
                                    $candidate
                                        ->id,

                                    'note' =>
                                    $candidate
                                        ->note,

                                    /*
                                     * Expansión prevista.
                                     */
                                    'expansion' =>
                                    $candidate
                                        ->expansion
                                        ? [
                                            'id' =>
                                            $candidate
                                                ->expansion
                                                ->id,

                                            'name' =>
                                            $candidate
                                                ->expansion
                                                ->name,
                                        ]
                                        : null,

                                    'game' => [
                                        'id' =>
                                        $candidate
                                            ->game
                                            ->id,

                                        'name' =>
                                        $candidate
                                            ->game
                                            ->name,

                                        'image_url' =>
                                        $candidate
                                            ->game
                                            ->image_url,

                                        'min_players' =>
                                        $candidate
                                            ->game
                                            ->min_players,

                                        'max_players' =>
                                        $candidate
                                            ->game
                                            ->max_players,

                                        'min_playtime' =>
                                        $candidate
                                            ->game
                                            ->min_playtime,

                                        'max_playtime' =>
                                        $candidate
                                            ->game
                                            ->max_playtime,

                                        'complexity' =>
                                        $candidate
                                            ->game
                                            ->complexity,

                                        'game_mode' =>
                                        $candidate
                                            ->game
                                            ->game_mode,

                                        'short_description' =>
                                        $candidate
                                            ->game
                                            ->short_description,

                                        'ideal_player_counts' =>
                                        $candidate
                                            ->game
                                            ->idealPlayerCounts
                                            ->pluck(
                                                'player_count'
                                            )
                                            ->values(),
                                    ],
                                ];
                            }
                        )
                        ->values(),
                ],

                'has_clear_winner' =>
                $hasClearWinner,

                'results' =>
                $results,
            ]
        );
    }

    public function updateVotingMode(Request $request, Poll $poll): RedirectResponse
    {
        abort_unless((int) $poll->created_by === (int) $request->user()->id, 403);
        $validated = $request->validate(['single_choice' => ['required', 'boolean']]);

        return DB::transaction(function () use ($poll, $validated): RedirectResponse {
            $current = Poll::query()->lockForUpdate()->findOrFail($poll->id);

            if (! in_array($current->status, ['draft', 'open'], true) || $current->votes()->exists()) {
                return back()->withErrors([
                    'poll' => 'Solo puedes cambiar el modo de una votación sin votos que esté en borrador o abierta.',
                ]);
            }

            $current->update(['single_choice' => $validated['single_choice']]);

            return redirect()->route('polls.show', $current)
                ->with('success', 'Modo de votación actualizado.');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | AÑADIR JUEGO CANDIDATO A UNA VOTACIÓN EXISTENTE
    |--------------------------------------------------------------------------
    */

    public function addCandidate(Request $request, Poll $poll): RedirectResponse
    {
        $userId = (int) $request->user()->id;
        abort_unless((int) $poll->created_by === $userId, 403);

        if (!in_array($poll->status, ['draft', 'open'], true)) {
            return back()->withErrors([
                'game_id' => 'No puedes añadir candidatos a una votación cerrada.',
            ]);
        }

        // No se alteran las opciones de una votación una vez emitidos votos.
        if ($poll->votes()->exists()) {
            return back()->withErrors([
                'game_id' => 'Ya se han recibido votos. No puedes añadir candidatos.',
            ]);
        }

        $validated = $request->validate([
            'game_id' => ['required', 'integer', Rule::exists('games', 'id')],
        ]);

        $ownedCollectionIds = $poll->gameCollections()
            ->where('game_collections.created_by', $userId)
            ->pluck('game_collections.id');

        $game = Game::query()
            ->whereKey((int) $validated['game_id'])
            ->where('is_active', true)
            ->whereHas('collections', fn ($query) => $query
                ->where('game_collections.created_by', $userId)
                ->whereIn('game_collections.id', $ownedCollectionIds))
            ->first();

        if (!$game) {
            return back()->withErrors([
                'game_id' => 'El juego no pertenece a las colecciones de esta votación.',
            ]);
        }

        if ($poll->candidates()->where('game_id', $game->id)->exists()) {
            return back()->withErrors([
                'game_id' => 'Este juego ya está incluido en la votación.',
            ]);
        }

        $poll->candidates()->create(['game_id' => $game->id]);

        $back = $request->query('back');
        $parameters = ['poll' => $poll];

        if (is_string($back) && str_starts_with($back, '/polls?')) {
            $parameters['back'] = $back;
        }

        return redirect()->route('polls.show', $parameters)
            ->with('success', 'Juego añadido a la votación.');
    }

    /*
    |--------------------------------------------------------------------------
    | ABRIR VOTACIÓN
    |--------------------------------------------------------------------------
    */

    public function open(
        Poll $poll
    ): RedirectResponse {
        /*
         * Solo el propietario.
         */
        abort_unless(
            $poll->created_by ===
                request()->user()->id,
            403
        );

        /*
         * Solo podemos abrir un borrador.
         */
        if (
            $poll->status !== 'draft'
        ) {
            return back();
        }

        /*
         * Necesitamos un mínimo de
         * 2 participantes y 1 juego.
         */
        if (
            $poll
            ->participants()
            ->count() < 2
            ||
            $poll
            ->candidates()
            ->count() < 1
        ) {
            return back()
                ->withErrors([
                    'poll' =>
                    'La votación necesita al menos 2 participantes y 1 juego.',
                ]);
        }

        $poll->update([
            'status' => 'open',
        ]);

        return redirect()
            ->route(
                'polls.show',
                $poll
            )
            ->with(
                'success',
                'Votación abierta correctamente.'
            );
    }


    public function recommendations(
        Request $request
    ): JsonResponse {
        $validated =
            $request->validate([
                'player_ids' => [
                    'required',
                    'array',
                    'min:2',
                ],

                'player_ids.*' => [
                    'integer',
                    'distinct',

                    Rule::exists(
                        'players',
                        'id'
                    )->where(
                        fn($query) =>
                        $query->where(
                            'is_active',
                            true
                        )
                            ->where('created_by', $request->user()->id)
                    ),
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
            ]);

        $userId =
            $request->user()->id;

        /*
    |--------------------------------------------------------------------------
    | COLECCIONES VÁLIDAS
    |--------------------------------------------------------------------------
    */

        $collectionIds =
            GameCollection::query()
            ->where(
                'created_by',
                $userId
            )
            ->whereIn(
                'id',
                $validated['collection_ids']
            )
            ->pluck('id');

        if (
            $collectionIds->count()
            !==
            count(
                $validated['collection_ids']
            )
        ) {
            throw ValidationException::withMessages([
                'collection_ids' =>
                'Una de las colecciones seleccionadas no es válida.',
            ]);
        }

        $playerIds =
            collect(
                $validated['player_ids']
            )
            ->map(
                fn($id) =>
                (int) $id
            )
            ->values();

        $playerCount =
            $playerIds->count();

        /*
    |--------------------------------------------------------------------------
    | JUEGOS COMPATIBLES
    |--------------------------------------------------------------------------
    */

        $games =
            Game::query()
            ->where(
                'is_active',
                true
            )
            ->where(
                'min_players',
                '<=',
                $playerCount
            )
            ->where(
                'max_players',
                '>=',
                $playerCount
            )
            ->whereHas(
                'collections',
                function ($query) use (
                    $collectionIds
                ) {
                    $query->whereIn(
                        'game_collections.id',
                        $collectionIds
                    );
                }
            )
            ->with([
                'plays' =>
                function ($query) use (
                    $userId
                ) {
                    $query
                        ->where(
                            'created_by',
                            $userId
                        )
                        ->with([
                            'players:id,name',
                        ])
                        ->orderByDesc(
                            'played_at'
                        )
                        ->orderByDesc('id');
                },
            ])
            ->get();

        /*
    |--------------------------------------------------------------------------
    | ANALIZAR HISTORIAL DEL GRUPO
    |--------------------------------------------------------------------------
    */

        $recommendations =
            $games
            ->map(
                function (
                    Game $game
                ) use (
                    $playerIds
                ) {
                    $plays =
                        $game->plays;

                    /*
                     * Partidas en las que estaban
                     * TODOS los jugadores elegidos.
                     *
                     * Puede haber además otras
                     * personas en esa partida.
                     */
                    $groupPlays =
                        $plays
                        ->filter(
                            function (
                                $play
                            ) use (
                                $playerIds
                            ) {
                                $playPlayerIds =
                                    $play
                                    ->players
                                    ->pluck(
                                        'id'
                                    );

                                return
                                    $playerIds
                                    ->diff(
                                        $playPlayerIds
                                    )
                                    ->isEmpty();
                            }
                        )
                        ->values();

                    /*
                     * Qué jugadores seleccionados
                     * han jugado alguna vez a este
                     * juego.
                     */
                    $familiarPlayerIds =
                        $plays
                        ->flatMap(
                            fn($play) =>
                            $play
                                ->players
                                ->pluck(
                                    'id'
                                )
                        )
                        ->intersect(
                            $playerIds
                        )
                        ->unique()
                        ->values();

                    /*
                     * Partidas en las que estaba
                     * al menos uno de los jugadores.
                     */
                    $playsWithAnySelected =
                        $plays
                        ->filter(
                            function (
                                $play
                            ) use (
                                $playerIds
                            ) {
                                return
                                    $play
                                    ->players
                                    ->pluck(
                                        'id'
                                    )
                                    ->intersect(
                                        $playerIds
                                    )
                                    ->isNotEmpty();
                            }
                        )
                        ->values();

                    return [
                        'game_id' =>
                        $game->id,

                        'name' =>
                        $game->name,

                        /*
                         * Veces que ESTE grupo
                         * completo lo ha jugado.
                         */
                        'group_plays' =>
                        $groupPlays
                            ->count(),

                        /*
                         * Cuántos de los jugadores
                         * seleccionados conocen ya
                         * el juego según el historial.
                         */
                        'familiar_players' =>
                        $familiarPlayerIds
                            ->count(),

                        'selected_players' =>
                        $playerIds
                            ->count(),

                        /*
                         * Partidas totales del juego
                         * registradas por el usuario.
                         */
                        'total_plays' =>
                        $plays->count(),

                        'last_group_played_at' =>
                        $groupPlays
                            ->first()
                            ?->played_at
                            ?->format(
                                'Y-m-d'
                            ),

                        'last_selected_played_at' =>
                        $playsWithAnySelected
                            ->first()
                            ?->played_at
                            ?->format(
                                'Y-m-d'
                            ),
                    ];
                }
            );

        /*
    |--------------------------------------------------------------------------
    | ORDEN EXPLICABLE
    |--------------------------------------------------------------------------
    |
    | 1. El grupo nunca lo ha jugado.
    | 2. Menos personas del grupo lo conocen.
    | 3. Lleva más tiempo sin jugarse.
    | 4. Menos partidas totales.
    | 5. Nombre.
    |
    */

        $recommendations =
            $recommendations
            ->sort(
                function (
                    array $a,
                    array $b
                ) {
                    /*
                     * Nunca jugado por el grupo.
                     */
                    $aNever =
                        $a['group_plays'] === 0;

                    $bNever =
                        $b['group_plays'] === 0;

                    if (
                        $aNever !==
                        $bNever
                    ) {
                        return $aNever
                            ? -1
                            : 1;
                    }

                    /*
                     * Menos personas lo conocen.
                     */
                    if (
                        $a['familiar_players']
                        !==
                        $b['familiar_players']
                    ) {
                        return
                            $a['familiar_players']
                            <=>
                            $b['familiar_players'];
                    }

                    /*
                     * Fecha relevante.
                     */
                    $aDate =
                        $a['last_group_played_at']
                        ??
                        $a['last_selected_played_at'];

                    $bDate =
                        $b['last_group_played_at']
                        ??
                        $b['last_selected_played_at'];

                    /*
                     * Nunca jugado por nadie
                     * seleccionado.
                     */
                    if (
                        $aDate === null &&
                        $bDate !== null
                    ) {
                        return -1;
                    }

                    if (
                        $aDate !== null &&
                        $bDate === null
                    ) {
                        return 1;
                    }

                    if (
                        $aDate !== null &&
                        $bDate !== null &&
                        $aDate !== $bDate
                    ) {
                        /*
                         * Fecha más antigua primero.
                         */
                        return strcmp(
                            $aDate,
                            $bDate
                        );
                    }

                    /*
                     * Menos repetido.
                     */
                    if (
                        $a['total_plays']
                        !==
                        $b['total_plays']
                    ) {
                        return
                            $a['total_plays']
                            <=>
                            $b['total_plays'];
                    }

                    return strcasecmp(
                        $a['name'],
                        $b['name']
                    );
                }
            )
            ->values()
            ->map(
                function (
                    array $game,
                    int $index
                ) {
                    return [
                        ...$game,

                        'rank' =>
                        $index + 1,
                    ];
                }
            );

        return response()->json([
            'recommendations' =>
            $recommendations,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CERRAR VOTACIÓN
    |--------------------------------------------------------------------------
    */

    public function close(
        Poll $poll
    ): RedirectResponse {
        /*
         * Solo el propietario.
         */
        abort_unless(
            $poll->created_by ===
                request()->user()->id,
            403
        );

        /*
         * Solo podemos cerrar
         * una votación abierta.
         */
        if (
            $poll->status !== 'open'
        ) {
            return back();
        }

        $poll->load([
            'participants.votes',
            'candidates',
        ]);

        $candidateCount = $poll->candidates->count();
        $requiredVoteCount = $poll->requiredVoteCount($candidateCount);

        if ($requiredVoteCount === 0) {
            return back()->withErrors([
                'poll' => 'La votación no tiene juegos candidatos.',
            ]);
        }

        $completedVotes = $poll->participants
            ->filter(function ($participant) use ($requiredVoteCount) {
                $positions = $participant->votes
                    ->pluck('position')
                    ->map(fn ($position) => (int) $position)
                    ->unique();

                for ($position = 1; $position <= $requiredVoteCount; $position++) {
                    if (!$positions->contains($position)) {
                        return false;
                    }
                }

                return true;
            })
            ->count();

        if (
            $completedVotes === 0
        ) {
            return back()
                ->withErrors([
                    'poll' =>
                    'Todavía no ha completado su voto ningún participante.',
                ]);
        }

        $poll->update([
            'status' => 'closed',
        ]);

        return redirect()
            ->route(
                'polls.show',
                $poll
            )
            ->with(
                'success',
                'Votación cerrada. Ya puedes ver los resultados.'
            );
    }
}
