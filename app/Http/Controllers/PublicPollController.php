<?php

namespace App\Http\Controllers;

use App\Models\Play;
use App\Models\Poll;
use App\Models\Vote;
use App\Notifications\PollVoteReceived;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        ]);

        $history = Play::query()
            ->where('plays.created_by', $poll->created_by)
            ->whereIn('plays.game_id', $poll->candidates->pluck('game_id')->unique());

        $historyByGame = (clone $history)
            ->selectRaw('plays.game_id, COUNT(*) AS total_plays, MAX(plays.played_at) AS played_at')
            ->groupBy('plays.game_id')
            ->get()
            ->keyBy('game_id');

        $historyByPlayer = $history
            ->join('play_player', 'play_player.play_id', '=', 'plays.id')
            ->whereIn('play_player.player_id', $poll->participants->pluck('player_id')->filter()->unique())
            ->selectRaw('plays.game_id, play_player.player_id, COUNT(DISTINCT plays.id) AS play_count')
            ->groupBy('plays.game_id', 'play_player.player_id')
            ->get()
            ->groupBy('game_id')
            ->map(fn ($counts) => $counts->keyBy('player_id'));

        $voteCounts = $poll->status === 'closed' ? $this->voteCountsByCandidate($poll) : collect();

        /*
        |--------------------------------------------------------------------------
        | RESULTADOS
        |--------------------------------------------------------------------------
        */

        $results = collect();
        $hasClearWinner = false;

        if ($voteCounts->isNotEmpty()) {
            $sortedResults =
                $poll->candidates
                    ->map(function ($candidate) use ($poll, $voteCounts) {
                        $counts = $voteCounts->get($candidate->id);
                        $firstVotes = (int) ($counts?->first_votes ?? 0);
                        $secondVotes = (int) ($counts?->second_votes ?? 0);
                        $thirdVotes = (int) ($counts?->third_votes ?? 0);

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

                            'third_votes' =>
                                $thirdVotes,

                            'points' =>
                                $poll->votePoints(
                                    $firstVotes, $secondVotes, $thirdVotes
                                ),
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

                        if ($a['second_votes'] !== $b['second_votes']) {
                            return $b['second_votes'] <=> $a['second_votes'];
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
                            &&
                            $result['second_votes']
                                === $first['second_votes']
                    );

                $hasClearWinner =
                    $first['points'] > 0 && $topResults->count() === 1;
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
                                . $result['first_votes']
                                . '-' . $result['second_votes'];

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

        $tieBreakOutcome = $poll->status === 'closed'
            && $poll->tie_break_for_poll_id === null
                ? \App\Support\PollTieBreakOutcome::forOriginal($poll)
                : null;

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

                    'session_position' => $poll->parent_poll_id !== null
                        ? 2
                        : ($poll->secondGamePoll()->exists() ? 1 : null),

                    'single_choice' => (bool) $poll->single_choice,
                    'ranking_slots' => $poll->ranking_slots,
                    'required_vote_count' => $poll->requiredVoteCount($poll->candidates->count()),

                    'has_clear_winner' =>
                    $hasClearWinner,

                    'tie_break_outcome' => $tieBreakOutcome,

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
                                $poll,
                                $historyByGame,
                                $historyByPlayer
                            ) {
                                $gameHistory = $historyByGame->get($candidate->game_id);
                                $playerCounts = $historyByPlayer->get($candidate->game_id);

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
                                            $playerCounts
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

                                            $playCount = (int) ($playerCounts
                                                ?->get($participant->player_id)?->play_count ?? 0);

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

                                        'game_modes' =>
                                        $candidate->game->game_modes
                                            ?? ($candidate->game->game_mode
                                                ? [$candidate->game->game_mode]
                                                : []),

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
                                        'total_plays' => (int) ($gameHistory?->total_plays ?? 0),

                                        'last_played_at' => $gameHistory?->played_at?->format('Y-m-d'),

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

                'linkedSession' => $this->linkedSession($poll),
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VOTAR
    |--------------------------------------------------------------------------
    */


    private function voteCountsByCandidate(Poll $poll): Collection
    {
        return Vote::query()
            ->whereIn('poll_candidate_id', $poll->candidates->modelKeys())
            ->select('poll_candidate_id')
            ->selectRaw('SUM(CASE WHEN position = 1 THEN 1 ELSE 0 END) AS first_votes')
            ->selectRaw('SUM(CASE WHEN position = 2 THEN 1 ELSE 0 END) AS second_votes')
            ->selectRaw('SUM(CASE WHEN position = 3 THEN 1 ELSE 0 END) AS third_votes')
            ->groupBy('poll_candidate_id')
            ->get()
            ->keyBy('poll_candidate_id');
    }

    /**
     * Juegos consultables de la otra votación de la sesión.
     * No incluye enlaces personales ni votos individuales.
     */
    private function linkedSession(Poll $poll): ?array
    {
        $relation = $poll->parent_poll_id !== null
            ? $poll->firstGamePoll()
            : $poll->secondGamePoll();

        $linked = $relation
            ->where('created_by', $poll->created_by)
            ->first();

        if (!$linked || !in_array($linked->status, ['open', 'closed'], true)) {
            return null;
        }

        $linked->load([
            'candidates.game',
            'candidates.expansion',
        ]);

        $winnerId = null;

        if ($linked->status === 'closed') {
            $voteCounts = $this->voteCountsByCandidate($linked);

            $scores = $linked->candidates->map(function ($candidate) use ($linked, $voteCounts) {
                $counts = $voteCounts->get($candidate->id);
                $first = (int) ($counts?->first_votes ?? 0);
                $second = (int) ($counts?->second_votes ?? 0);
                $third = (int) ($counts?->third_votes ?? 0);

                return [
                    'id' => $candidate->id,
                    'first' => $first,
                    'second' => $second,
                    'points' => $linked->votePoints($first, $second, $third),
                ];
            })->sort(function ($a, $b) {
                return ($b['points'] <=> $a['points'])
                    ?: ($b['first'] <=> $a['first'])
                    ?: ($b['second'] <=> $a['second']);
            })->values();

            $leader = $scores->first();

            if ($leader && $leader['points'] > 0) {
                $ties = $scores->filter(fn ($score) =>
                    $score['points'] === $leader['points']
                    && $score['first'] === $leader['first']
                    && $score['second'] === $leader['second']
                )->count();

                if ($ties === 1) {
                    $winnerId = $leader['id'];
                }
            }
            // Si la votación original terminó empatada, consultar
            // el ganador definitivo de su ronda de desempate.
            if ($winnerId === null && $linked->tie_break_for_poll_id === null) {
                $resolvedGameId = \App\Support\PollTieBreakOutcome::forOriginal($linked)['winner']['game_id'] ?? null;

                if ($resolvedGameId !== null) {
                    $winnerId = $linked->candidates
                        ->first(fn ($candidate) =>
                            (int) $candidate->game_id === (int) $resolvedGameId
                        )?->id;
                }
            }
        }

        return [
            'name' => $linked->name,
            'status' => $linked->status,
            'session_position' => $poll->parent_poll_id !== null ? 1 : 2,
            'candidates' => $linked->candidates
                ->filter(fn ($candidate) => $candidate->game !== null)
                ->sortByDesc(fn ($candidate) => $candidate->id === $winnerId)
                ->map(fn ($candidate) => [
                    'id' => $candidate->id,
                    'is_winner' => $candidate->id === $winnerId,
                    'note' => $candidate->note,
                    'expansion_name' => $candidate->expansion?->name,
                    'game' => [
                        'name' => $candidate->game->name,
                        'image_url' => $candidate->game->image_url,
                        'min_players' => $candidate->game->min_players,
                        'max_players' => $candidate->game->max_players,
                        'min_playtime' => $candidate->game->min_playtime,
                        'max_playtime' => $candidate->game->max_playtime,
                        'short_description' => $candidate->game->short_description,
                        'youtube_url' => $candidate->game->youtube_url,
                        'bgg_url' => $candidate->game->bgg_url,
                    ],
                ])
                ->values(),
        ];
    }

    public function voteForParticipant(
        Request $request,
        string $token,
        string $voteToken
    ): RedirectResponse {
        return DB::transaction(function () use ($request, $token, $voteToken): RedirectResponse {
            $poll = Poll::where('token', $token)->lockForUpdate()->firstOrFail();

            $participant = $poll->participants()
                ->where('vote_token', $voteToken)
                ->firstOrFail();

            $participantUrl = route('vote.participant.show', [
                'token' => $token,
                'voteToken' => $voteToken,
            ]);

            if ($poll->status !== 'open') {
                return redirect($participantUrl)->withErrors([
                    'vote' => 'La votación ya no está abierta.',
                ]);
            }

            $candidateIds = $poll->candidates()->pluck('id');

            if ($candidateIds->isEmpty()) {
                return redirect($participantUrl)->withErrors([
                    'vote' => 'La votación no tiene juegos candidatos.',
                ]);
            }

            $validated = $request->validate([
                'first_candidate_id' => ['required', 'integer'],
                'second_candidate_id' => ['nullable', 'integer'],
                'third_candidate_id' => ['nullable', 'integer'],
                'single_choice' => ['sometimes', 'boolean'],
                'ranking_slots' => ['sometimes', 'integer', 'between:1,3'],
            ]);

            if (
                isset($validated['single_choice']) &&
                (bool) $validated['single_choice'] !== $poll->single_choice
            ) {
                return redirect($participantUrl)->withErrors([
                    'vote' => 'Ha cambiado el modo de votación. Revisa y confirma de nuevo.',
                ]);
            }

            $requiredCount = $poll->requiredVoteCount($candidateIds->count());

            if (
                isset($validated['ranking_slots']) &&
                (int) $validated['ranking_slots'] !== $requiredCount
            ) {
                return redirect($participantUrl)->withErrors([
                    'vote' => 'Ha cambiado el número de preferencias. Revisa tu voto.',
                ]);
            }

            $fields = [
                1 => 'first_candidate_id',
                2 => 'second_candidate_id',
                3 => 'third_candidate_id',
            ];

            $selected = [];

            for ($position = 1; $position <= $requiredCount; $position++) {
                $field = $fields[$position];

                if (empty($validated[$field])) {
                    return redirect($participantUrl)->withErrors([
                        $field => 'Debes seleccionar esta preferencia.',
                    ]);
                }

                $candidateId = (int) $validated[$field];

                abort_unless($candidateIds->contains($candidateId), 422);

                if (in_array($candidateId, $selected, true)) {
                    return redirect($participantUrl)->withErrors([
                        $field => $position === 2
                            ? 'La primera y segunda opción deben ser distintas.'
                            : 'No puedes elegir dos veces el mismo juego.',
                    ]);
                }

                $selected[] = $candidateId;
            }

            $notifyOrganizer = config('mail.poll_vote_notifications')
                && $participant->votes()->whereBetween('position', [1, $requiredCount])->count() < $requiredCount;

            // Sustituye el voto anterior de forma atómica.
            $participant->votes()->delete();

            foreach ($selected as $index => $candidateId) {
                $participant->votes()->create([
                    'poll_id' => $poll->id,
                    'poll_candidate_id' => $candidateId,
                    'position' => $index + 1,
                ]);
            }

            if ($notifyOrganizer) {
                $completed = $poll->participants()->whereHas(
                    'votes',
                    fn ($query) => $query->whereBetween('position', [1, $requiredCount]),
                    '=',
                    $requiredCount,
                )->count();
                $notification = new PollVoteReceived(
                    $poll->id,
                    $poll->name,
                    $participant->display_name,
                    $completed,
                    $poll->participants()->count(),
                );

                DB::afterCommit(fn () => rescue(fn () => $poll->creator?->notify($notification)));
            }

            return redirect($participantUrl)->with('success', 'Voto guardado correctamente.');
        });
    }
}
