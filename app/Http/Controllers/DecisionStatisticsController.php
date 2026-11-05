<?php

namespace App\Http\Controllers;

use App\Models\Poll;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DecisionStatisticsController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $userId =
            $request->user()->id;

        /*
        |--------------------------------------------------------------------------
        | Votaciones cerradas que terminaron en una partida registrada
        |--------------------------------------------------------------------------
        */

        $polls =
            Poll::query()
            ->where(
                'created_by',
                $userId
            )
            ->where(
                'status',
                'closed'
            )
            ->whereHas(
                'play',
                function ($query) use (
                    $userId
                ) {
                    $query->where(
                        'created_by',
                        $userId
                    );
                }
            )
            ->with([
                'candidates.game',

                'candidates.votes',

                'play' =>
                function ($query) use (
                    $userId
                ) {
                    $query
                        ->where(
                            'created_by',
                            $userId
                        )
                        ->with([
                            'game',
                            'expansion',
                        ]);
                },

                'plays' => function ($query) use ($userId) {
                    $query
                        ->where('created_by', $userId)
                        ->with([
                            'game',
                            'expansion',
                        ]);
                },
            ])
            ->latest()
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Analizar cada decisión
        |--------------------------------------------------------------------------
        */

        $decisions =
            $polls
            ->map(
                fn(Poll $poll) =>
                $this
                    ->analysePoll(
                        $poll
                    )
            )
            ->filter()
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Sesiones con ganador único
        |--------------------------------------------------------------------------
        |
        | Los empates exactos en:
        |
        | - puntos
        | - primeras opciones
        |
        | quedan fuera del porcentaje de "se jugó el ganador".
        |
        */

        $decisiveDecisions =
            $decisions
            ->where(
                'has_clear_winner',
                true
            )
            ->values();

        $analysedSessions =
            $decisions->count();

        $decisiveSessions =
            $decisiveDecisions->count();

        $tieSessions =
            $decisions
            ->where(
                'decision_type',
                'tie'
            )
            ->count();

        $winnerPlayed =
            $decisiveDecisions
            ->where(
                'decision_type',
                'winner'
            )
            ->count();

        $otherCandidatePlayed =
            $decisiveDecisions
            ->where(
                'decision_type',
                'other_candidate'
            )
            ->count();

        $outsidePlayed =
            $decisiveDecisions
            ->where(
                'decision_type',
                'outside'
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Porcentajes
        |--------------------------------------------------------------------------
        */

        $percentage =
            function (
                int $value
            ) use (
                $decisiveSessions
            ): float {
                if (
                    $decisiveSessions === 0
                ) {
                    return 0;
                }

                return round(
                    (
                        $value
                        /
                        $decisiveSessions
                    )
                        * 100,
                    1
                );
            };

        /*
        |--------------------------------------------------------------------------
        | Ganadores que luego no se jugaron
        |--------------------------------------------------------------------------
        */

        $discardedWinners =
            $decisiveDecisions
            ->filter(
                fn(
                    array $decision
                ) =>
                $decision['decision_type']
                    !== 'winner'
            )
            ->groupBy(
                fn(
                    array $decision
                ) =>
                $decision['winner']['id']
            )
            ->map(
                function (
                    $group
                ) {
                    $winner =
                        $group
                            ->first()['winner'];

                    return [
                        'game' =>
                        $winner,

                        'times_not_played' =>
                        $group->count(),
                    ];
                }
            )
            ->sortByDesc(
                'times_not_played'
            )
            ->take(5)
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Juegos elegidos fuera de la votación
        |--------------------------------------------------------------------------
        */

        $outsideChoices =
            $decisiveDecisions
            ->where(
                'decision_type',
                'outside'
            )
            ->groupBy(
                fn(
                    array $decision
                ) =>
                $decision['played_game']['id']
            )
            ->map(
                function (
                    $group
                ) {
                    $game =
                        $group
                            ->first()['played_game'];

                    return [
                        'game' =>
                        $game,

                        'times_chosen' =>
                        $group->count(),
                    ];
                }
            )
            ->sortByDesc(
                'times_chosen'
            )
            ->take(5)
            ->values();

        return Inertia::render(
            'statistics/decisions',
            [
                'stats' => [
                    'analysed_sessions' =>
                    $analysedSessions,

                    'decisive_sessions' =>
                    $decisiveSessions,

                    'tie_sessions' =>
                    $tieSessions,

                    'winner_played' =>
                    $winnerPlayed,

                    'winner_played_percentage' =>
                    $percentage(
                        $winnerPlayed
                    ),

                    'other_candidate_played' =>
                    $otherCandidatePlayed,

                    'other_candidate_percentage' =>
                    $percentage(
                        $otherCandidatePlayed
                    ),

                    'outside_played' =>
                    $outsidePlayed,

                    'outside_percentage' =>
                    $percentage(
                        $outsidePlayed
                    ),
                ],

                'discarded_winners' =>
                $discardedWinners,

                'outside_choices' =>
                $outsideChoices,

                'decisions' =>
                $decisions
                    ->take(20)
                    ->values(),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Analizar una votación concreta
    |--------------------------------------------------------------------------
    */

    private function analysePoll(
        Poll $poll
    ): ?array {
        if (!$poll->play) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Resultado de cada candidato
        |--------------------------------------------------------------------------
        */

        $results =
            $poll
            ->candidates
            ->map(
                function (
                    $candidate
                ) {
                    $firstVotes =
                        $candidate
                        ->votes
                        ->where(
                            'position',
                            1
                        )
                        ->count();

                    $secondVotes =
                        $candidate
                        ->votes
                        ->where(
                            'position',
                            2
                        )
                        ->count();

                    return [
                        'candidate_id' =>
                        $candidate->id,

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
                        ],

                        'first_votes' =>
                        $firstVotes,

                        'second_votes' =>
                        $secondVotes,

                        'points' => (
                            $firstVotes
                            * 2
                        )
                            +
                            $secondVotes,
                    ];
                }
            )
            ->sort(
                function (
                    array $a,
                    array $b
                ) {
                    if (
                        $a['points']
                        !==
                        $b['points']
                    ) {
                        return
                            $b['points']
                            <=>
                            $a['points'];
                    }

                    if (
                        $a['first_votes']
                        !==
                        $b['first_votes']
                    ) {
                        return
                            $b['first_votes']
                            <=>
                            $a['first_votes'];
                    }

                    /*
                         * Solo estabiliza el orden
                         * visual.
                         *
                         * NO se considera un
                         * desempate real.
                         */
                    return strcasecmp(
                        $a['game']['name'],
                        $b['game']['name']
                    );
                }
            )
            ->values();

        if (
            $results->isEmpty()
        ) {
            return null;
        }

        $firstResult =
            $results->first();

        /*
        |--------------------------------------------------------------------------
        | Detectar empate real
        |--------------------------------------------------------------------------
        |
        | Empate si varios candidatos tienen:
        |
        | - los mismos puntos
        | - el mismo número de primeras opciones
        |
        */

        $topResults =
            $results
            ->filter(
                fn(
                    array $result
                ) =>
                $result['points']
                    ===
                    $firstResult['points']
                    &&
                    $result['first_votes']
                    ===
                    $firstResult['first_votes']
            )
            ->values();

        $hasClearWinner =
            $topResults->count()
            === 1;

        $winner =
            $hasClearWinner
            ? $firstResult['game']
            : null;

        /*
        |--------------------------------------------------------------------------
        | Juego realmente jugado
        |--------------------------------------------------------------------------
        */

        $playedGame = [
            'id' =>
            $poll
                ->play
                ->game
                ->id,

            'name' =>
            $poll
                ->play
                ->game
                ->name,

            'image_url' =>
            $poll
                ->play
                ->game
                ->image_url,
        ];

        $candidateGameIds =
            $poll
            ->candidates
            ->pluck(
                'game_id'
            );

        /*
        |--------------------------------------------------------------------------
        | Tipo de decisión
        |--------------------------------------------------------------------------
        */

        $playedGameIds = $poll->plays
            ->pluck('game_id')
            ->unique();

        if (!$hasClearWinner) {
            // La votación terminó en empate real.
            $decisionType = 'tie';
        } elseif ($playedGameIds->contains($winner['id'])) {
            // El ganador fue uno de los juegos jugados.
            $decisionType = 'winner';
        } elseif ($playedGameIds->intersect($candidateGameIds)->isNotEmpty()) {
            // Se jugó algún candidato distinto del ganador.
            $decisionType = 'other_candidate';
        } else {
            // Ninguno de los juegos jugados era candidato.
            $decisionType = 'outside';
        }

        return [
            'poll' => [
                'id' =>
                $poll->id,

                'name' =>
                $poll->name,
            ],

            'played_at' =>
            $poll
                ->play
                ->played_at
                ->format(
                    'Y-m-d'
                ),

            'decision_type' =>
            $decisionType,

            'has_clear_winner' =>
            $hasClearWinner,

            'winner' =>
            $winner,

            'tied_games' =>
            $hasClearWinner
                ? []
                : $topResults
                ->pluck(
                    'game'
                )
                ->values(),

            'played_game' =>
            $playedGame,

            'expansion' =>
            $poll
                ->play
                ->expansion
                ? [
                    'id' =>
                    $poll
                        ->play
                        ->expansion
                        ->id,

                    'name' =>
                    $poll
                        ->play
                        ->expansion
                        ->name,
                ]
                : null,

            'candidates_count' =>
            $poll
                ->candidates
                ->count(),
        ];
    }
}
