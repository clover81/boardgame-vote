<?php

namespace App\Http\Controllers;

use App\Models\Poll;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GameVotingStatisticsController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $userId =
            $request->user()->id;

        /*
        |--------------------------------------------------------------------------
        | Votaciones cerradas
        |--------------------------------------------------------------------------
        |
        | Para votos y victorias no necesitamos que exista una partida
        | registrada después.
        |
        | Para saber qué acabó jugándose, sí utilizaremos poll->plays.
        |
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
            ->with([
                'candidates.game',
                'candidates.votes',

                'plays' =>
                function ($query) use (
                    $userId
                ) {
                    $query
                        ->where(
                            'created_by',
                            $userId
                        )
                        ->with('game');
                },
            ])
            ->latest()
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Acumulador por juego
        |--------------------------------------------------------------------------
        */

        $gameStats = [];

        /*
         * Crea la entrada de estadísticas
         * solo cuando hace falta.
         */
        $ensureGame =
            function (
                $game
            ) use (
                &$gameStats
            ): void {
                if (
                    isset(
                        $gameStats[$game->id]
                    )
                ) {
                    return;
                }

                $gameStats[$game->id] = [
                    'game' => [
                        'id' =>
                        $game->id,

                        'name' =>
                        $game->name,

                        'image_url' =>
                        $game->image_url,
                    ],

                    /*
                     * Veces que apareció entre
                     * los candidatos.
                     */
                    'appearances' =>
                    0,

                    'first_votes' =>
                    0,

                    'second_votes' =>
                    0,

                    'points' =>
                    0,

                    /*
                     * Victorias con ganador único.
                     */
                    'wins' =>
                    0,

                    /*
                     * Veces que, siendo candidato,
                     * terminó siendo el juego
                     * realmente jugado.
                     */
                    'played_as_candidate' =>
                    0,

                    /*
                     * Veces que ganó y después
                     * realmente se jugó.
                     */
                    'wins_played' =>
                    0,

                    /*
                     * Todas las veces que acabó
                     * jugándose después de una
                     * votación.
                     */
                    'played_after_poll' =>
                    0,

                    /*
                     * De las anteriores, veces que
                     * ni siquiera era candidato.
                     */
                    'played_from_outside' =>
                    0,
                ];
            };

        /*
        |--------------------------------------------------------------------------
        | Analizar cada votación
        |--------------------------------------------------------------------------
        */

        foreach (
            $polls as $poll
        ) {
            /*
             * Calculamos los resultados.
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
                            'candidate' =>
                            $candidate,

                            'game_id' =>
                            $candidate
                                ->game_id,

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

                        return strcasecmp(
                            $a['candidate']
                                ->game
                                ->name,

                            $b['candidate']
                                ->game
                                ->name
                        );
                    }
                )
                ->values();

            /*
             * Determinar si existe ganador
             * único.
             */
            $winnerGameId =
                null;

            if (
                $results->isNotEmpty()
            ) {
                $first =
                    $results->first();

                $topResults =
                    $results->filter(
                        fn(
                            array $result
                        ) =>
                        $result['points']
                            ===
                            $first['points']
                            &&
                            $result['first_votes']
                            ===
                            $first['first_votes']
                    );

                if (
                    $topResults->count()
                    === 1
                ) {
                    $winnerGameId =
                        $first['game_id'];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Candidatos
            |--------------------------------------------------------------------------
            */

            $playedGameIds = $poll->plays
                ->pluck('game_id')
                ->unique();

            foreach (
                $results as $result
            ) {
                $candidate =
                    $result['candidate'];

                $game =
                    $candidate->game;

                $ensureGame(
                    $game
                );

                $gameId =
                    $game->id;

                $gameStats[$gameId]['appearances']++;

                $gameStats[$gameId]['first_votes'] +=
                    $result['first_votes'];

                $gameStats[$gameId]['second_votes'] +=
                    $result['second_votes'];

                $gameStats[$gameId]['points'] +=
                    $result['points'];

                /*
                 * Ganador único.
                 */
                if (
                    $winnerGameId ===
                    $gameId
                ) {
                    $gameStats[$gameId]['wins']++;

                    /*
                     * Ganó Y además se jugó.
                     */
                    if ($playedGameIds->contains($gameId)) {
                        $gameStats[$gameId]['wins_played']++;
                    }
                }

                /*
                 * Era candidato y terminó
                 * jugándose.
                 */
                if ($playedGameIds->contains($gameId)) {
                    $gameStats[$gameId]['played_as_candidate']++;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Juego realmente jugado
            |--------------------------------------------------------------------------
            */

            $candidateGameIds = $poll->candidates
                ->pluck('game_id');

            foreach ($poll->plays as $play) {
                $playedGame = $play->game;

                $ensureGame(
                    $playedGame
                );

                $playedGameId =
                    $playedGame->id;

                $gameStats[$playedGameId]['played_after_poll']++;

                /*
                 * Se eligió algo que ni
                 * siquiera estaba propuesto.
                 */
                if (
                    !$candidateGameIds
                        ->contains(
                            $playedGameId
                        )
                ) {
                    $gameStats[$playedGameId]['played_from_outside']++;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Calcular porcentajes
        |--------------------------------------------------------------------------
        */

        $games =
            collect(
                $gameStats
            )
            ->map(
                function (
                    array $stats
                ) {
                    $appearances =
                        $stats['appearances'];

                    $wins =
                        $stats['wins'];

                    $stats['win_rate'] =
                        $appearances > 0
                        ? round(
                            (
                                $wins
                                /
                                $appearances
                            )
                                * 100,
                            1
                        )
                        : 0;

                    /*
                         * De las veces que fue
                         * candidato, ¿cuántas
                         * terminó jugándose?
                         */
                    $stats['candidate_conversion'] =
                        $appearances > 0
                        ? round(
                            (
                                $stats['played_as_candidate']
                                /
                                $appearances
                            )
                                * 100,
                            1
                        )
                        : 0;

                    /*
                         * Cuando gana,
                         * ¿se respeta el resultado?
                         */
                    $stats['winner_conversion'] =
                        $wins > 0
                        ? round(
                            (
                                $stats['wins_played']
                                /
                                $wins
                            )
                                * 100,
                            1
                        )
                        : null;

                    return $stats;
                }
            )
            ->sortByDesc(
                'first_votes'
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Resumen
        |--------------------------------------------------------------------------
        */

        $mostFirstVotes =
            $games
            ->sortByDesc(
                'first_votes'
            )
            ->first();

        $mostWins =
            $games
            ->sortByDesc(
                'wins'
            )
            ->first();

        $mostPlayedAfterPoll =
            $games
            ->sortByDesc(
                'played_after_poll'
            )
            ->first();

        $biggestPlanB =
            $games
            ->sortByDesc(
                'played_from_outside'
            )
            ->first();

        return Inertia::render(
            'statistics/games',
            [
                'summary' => [
                    'closed_polls' =>
                    $polls->count(),

                    'polls_with_play' =>
                    $polls
                        ->filter(
                            fn($poll) =>
                            $poll->plays->isNotEmpty()
                        )
                        ->count(),

                    'candidate_appearances' =>
                    $games
                        ->sum(
                            'appearances'
                        ),

                    'most_first_votes' =>
                    $this
                        ->summaryGame(
                            $mostFirstVotes,
                            'first_votes'
                        ),

                    'most_wins' =>
                    $this
                        ->summaryGame(
                            $mostWins,
                            'wins'
                        ),

                    'most_played_after_poll' =>
                    $this
                        ->summaryGame(
                            $mostPlayedAfterPoll,
                            'played_after_poll'
                        ),

                    'biggest_plan_b' =>
                    $this
                        ->summaryGame(
                            $biggestPlanB,
                            'played_from_outside'
                        ),
                ],

                'games' =>
                $games,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Tarjeta resumen
    |--------------------------------------------------------------------------
    */

    private function summaryGame(
        ?array $stats,
        string $field
    ): ?array {
        if (
            !$stats ||
            $stats[$field] <= 0
        ) {
            return null;
        }

        return [
            'game' =>
            $stats['game'],

            'value' =>
            $stats[$field],
        ];
    }
}
