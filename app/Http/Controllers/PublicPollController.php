<?php

namespace App\Http\Controllers;

use App\Models\Poll;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PublicPollController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | MOSTRAR VOTACIÓN PÚBLICA
    |--------------------------------------------------------------------------
    */

    public function showForParticipant(
        string $token,
        string $voteToken
    ): Response {
        $poll = Poll::where('token', $token)->firstOrFail();

        $participant = $poll->participants()
            ->where('vote_token', $voteToken)
            ->firstOrFail();

        $participant->load('votes');

        // El historial de cada juego necesita a TODOS los jugadores de la
        // votación, no únicamente al participante que abre este enlace.
        // Por privacidad, solo incluimos los votos de quien está autenticado
        // mediante su token personal; nunca enviamos los votos de los demás.
        $allParticipants = $poll->participants()->with('player:id,color')->get();

        $allParticipants->each(function ($pollParticipant) use ($participant) {
            $pollParticipant->setRelation(
                'votes',
                $pollParticipant->id === $participant->id
                    ? $participant->votes
                    : $participant->votes->take(0)
            );
        });

        $poll->setRelation('participants', $allParticipants);

        return $this->renderPoll($poll)->with([
            'currentParticipantId' => $participant->id,
            'voteToken' => $participant->vote_token,
        ]);
    }

    private function renderPoll(Poll $poll): Response
    {
        /*
        |--------------------------------------------------------------------------
        | Cargar relaciones
        |--------------------------------------------------------------------------
        */

        $poll->loadMissing([
            'candidates.game.idealPlayerCounts',

            /*
             * Expansión prevista.
             */
            'candidates.expansion',

            /*
             * Historial personal del propietario
             * de la votación.
             */
            'candidates.game.plays' =>
            function ($query) use (
                $poll
            ) {
                $query
                    ->where(
                        'created_by',
                        $poll->created_by
                    )
                    ->with(
                        'players'
                    )
                    ->orderByDesc(
                        'played_at'
                    );
            },

            'candidates.votes',
        ]);

        /*
        |--------------------------------------------------------------------------
        | RESULTADOS
        |--------------------------------------------------------------------------
        */

        $results = collect();
        $hasClearWinner = false;

        if ($poll->status === 'closed') {
            $sortedResults =
                $poll->candidates
                    ->map(function ($candidate) {
                        $firstVotes =
                            $candidate->votes
                                ->where('position', 1)
                                ->count();

                        $secondVotes =
                            $candidate->votes
                                ->where('position', 2)
                                ->count();

                        return [
                            'candidate_id' =>
                                $candidate->id,

                            'game' => [
                                'id' =>
                                    $candidate->game->id,

                                'name' =>
                                    $candidate->game->name,

                                'image_url' =>
                                    $candidate->game->image_url,
                            ],

                            'first_votes' =>
                                $firstVotes,

                            'second_votes' =>
                                $secondVotes,

                            'points' =>
                                ($firstVotes * 2)
                                + $secondVotes,
                        ];
                    })
                    ->sort(function (
                        array $a,
                        array $b
                    ) {
                        if (
                            $a['points']
                            !== $b['points']
                        ) {
                            return
                                $b['points']
                                <=> $a['points'];
                        }

                        if (
                            $a['first_votes']
                            !== $b['first_votes']
                        ) {
                            return
                                $b['first_votes']
                                <=> $a['first_votes'];
                        }

                        /*
                         * Solo estabiliza visualmente
                         * el orden.
                         *
                         * El nombre NO rompe
                         * un empate real.
                         */
                        return strcasecmp(
                            $a['game']['name'],
                            $b['game']['name']
                        );
                    })
                    ->values();

            /*
            |--------------------------------------------------------------------------
            | Ganador único
            |--------------------------------------------------------------------------
            */

            if ($sortedResults->isNotEmpty()) {
                $first =
                    $sortedResults->first();

                $topResults =
                    $sortedResults->filter(
                        fn (array $result) =>
                            $result['points']
                                === $first['points']
                            &&
                            $result['first_votes']
                                === $first['first_votes']
                    );

                $hasClearWinner =
                    $topResults->count() === 1;
            }

            /*
            |--------------------------------------------------------------------------
            | Ranking
            |--------------------------------------------------------------------------
            */

            $previousKey = null;
            $currentRank = 0;

            $results =
                $sortedResults
                    ->map(
                        function (
                            array $result,
                            int $index
                        ) use (
                            &$previousKey,
                            &$currentRank,
                            $hasClearWinner
                        ) {
                            $key =
                                $result['points']
                                . '-'
                                . $result['first_votes'];

                            /*
                             * Ranking tipo:
                             *
                             * 1, 1, 3
                             *
                             * si hay empate entre
                             * los dos primeros.
                             */
                            if ($key !== $previousKey) {
                                $currentRank =
                                    $index + 1;
                            }

                            $previousKey =
                                $key;

                            $result['rank'] =
                                $currentRank;

                            $result['is_winner'] =
                                $hasClearWinner
                                &&
                                $currentRank === 1;

                            $result['is_top_tie'] =
                                !$hasClearWinner
                                &&
                                $currentRank === 1;

                            return $result;
                        }
                    )
                    ->values();
        }

        /*
        |--------------------------------------------------------------------------
        | VISTA
        |--------------------------------------------------------------------------
        */

        return Inertia::render(
            'vote/show',
            [
                'poll' => [
                    'id' =>
                    $poll->id,

                    'name' =>
                    $poll->name,

                    'token' =>
                    $poll->token,

                    'status' =>
                    $poll->status,

                    'has_clear_winner' =>
                    $hasClearWinner,

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
                            ) {
                                return [
                                    'id' =>
                                    $participant
                                        ->id,

                                    'display_name' =>
                                    $participant
                                        ->display_name,

                                    // Solo se comparte el color visual del jugador,
                                    // sin exponer la ficha privada ni su identificador.
                                    'color' => $participant->player?->color,

                                    /*
                                         * Necesitamos recuperar
                                         * los votos si entra de nuevo.
                                         */
                                    'votes' =>
                                    $participant
                                        ->votes
                                        ->map(
                                            fn(
                                                $vote
                                            ) => [
                                                'candidate_id' =>
                                                $vote
                                                    ->poll_candidate_id,

                                                'position' =>
                                                $vote
                                                    ->position,
                                            ]
                                        )
                                        ->values(),
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
                            ) use (
                                $poll
                            ) {
                                $gamePlays =
                                    $candidate
                                    ->game
                                    ->plays;

                                /*
                                    |--------------------------------------------------------------------------
                                    | HISTORIAL DE LOS JUGADORES DE HOY
                                    |--------------------------------------------------------------------------
                                    */

                                $participantHistory =
                                    $poll
                                    ->participants
                                    ->map(
                                        function (
                                            $participant
                                        ) use (
                                            $gamePlays
                                        ) {
                                            /*
                                                     * Participantes públicos
                                                     * sin Player asociado.
                                                     */
                                            if (
                                                $participant
                                                ->player_id ===
                                                null
                                            ) {
                                                return [
                                                    'participant_id' =>
                                                    $participant
                                                        ->id,

                                                    'display_name' =>
                                                    $participant
                                                        ->display_name,

                                                    'play_count' =>
                                                    0,

                                                    'tracked' =>
                                                    false,
                                                ];
                                            }

                                            $playCount =
                                                $gamePlays
                                                ->filter(
                                                    fn(
                                                        $play
                                                    ) =>
                                                    $play
                                                        ->players
                                                        ->contains(
                                                            'id',
                                                            $participant
                                                                ->player_id
                                                        )
                                                )
                                                ->count();

                                            return [
                                                'participant_id' =>
                                                $participant
                                                    ->id,

                                                'display_name' =>
                                                $participant
                                                    ->display_name,

                                                'play_count' =>
                                                $playCount,

                                                'tracked' =>
                                                true,
                                            ];
                                        }
                                    )
                                    ->values();

                                return [
                                    'id' =>
                                    $candidate
                                        ->id,

                                    'note' =>
                                    $candidate
                                        ->note,

                                    /*
                                         * Aquí está la nueva
                                         * expansión prevista.
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

                                        'bgg_url' =>
                                        $candidate
                                            ->game
                                            ->bgg_url,

                                        'youtube_url' =>
                                        $candidate
                                            ->game
                                            ->youtube_url,

                                        'ideal_player_counts' =>
                                        $candidate
                                            ->game
                                            ->idealPlayerCounts
                                            ->pluck(
                                                'player_count'
                                            )
                                            ->values(),
                                    ],

                                    /*
                                        |--------------------------------------------------------------------------
                                        | HISTORIAL
                                        |--------------------------------------------------------------------------
                                        */

                                    'history' => [
                                        'total_plays' =>
                                        $gamePlays
                                            ->count(),

                                        'last_played_at' =>
                                        $gamePlays
                                            ->first()
                                            ?->played_at
                                            ?->format(
                                                'Y-m-d'
                                            ),

                                        'participants' =>
                                        $participantHistory,
                                    ],
                                ];
                            }
                        )
                        ->values(),
                ],

                'results' =>
                $results,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VOTAR
    |--------------------------------------------------------------------------
    */

    public function voteForParticipant(
        Request $request,
        string $token,
        string $voteToken
    ): RedirectResponse {
        $poll = Poll::where('token', $token)->firstOrFail();

        $participant = $poll->participants()
            ->where('vote_token', $voteToken)
            ->firstOrFail();

        if ($poll->status !== 'open') {
            return back()->withErrors([
                'vote' => 'La votación ya no está abierta.',
            ]);
        }

        $candidateIds = $poll->candidates()->pluck('id');

        if ($candidateIds->isEmpty()) {
            return back()->withErrors([
                'vote' => 'La votación no tiene juegos candidatos.',
            ]);
        }

        $validated = $request->validate([
            'first_candidate_id' => ['required', 'integer'],
            'second_candidate_id' => ['nullable', 'integer'],
        ]);

        $firstCandidateId = (int) $validated['first_candidate_id'];

        abort_unless($candidateIds->contains($firstCandidateId), 422);

        $needsSecondChoice = $candidateIds->count() >= 2;
        $secondCandidateId = null;

        if ($needsSecondChoice) {
            if (empty($validated['second_candidate_id'])) {
                return back()->withErrors([
                    'second_candidate_id' => 'Debes elegir una segunda opción.',
                ]);
            }

            $secondCandidateId = (int) $validated['second_candidate_id'];

            abort_unless($candidateIds->contains($secondCandidateId), 422);

            if ($firstCandidateId === $secondCandidateId) {
                return back()->withErrors([
                    'second_candidate_id' => 'La primera y segunda opción deben ser distintas.',
                ]);
            }
        }

        DB::transaction(function () use (
            $poll,
            $participant,
            $firstCandidateId,
            $secondCandidateId
        ) {
            // Si ya había votado, sustituimos sus votos.
            $participant->votes()->delete();

            $participant->votes()->create([
                'poll_id' => $poll->id,
                'poll_candidate_id' => $firstCandidateId,
                'position' => 1,
            ]);

            if ($secondCandidateId !== null) {
                $participant->votes()->create([
                    'poll_id' => $poll->id,
                    'poll_candidate_id' => $secondCandidateId,
                    'position' => 2,
                ]);
            }
        });

        return redirect()->route('vote.participant.show', [
            'token' => $token,
            'voteToken' => $voteToken,
        ])->with('success', 'Voto guardado correctamente.');
    }
}
