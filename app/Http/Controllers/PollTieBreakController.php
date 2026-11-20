<?php

namespace App\Http\Controllers;

use App\Models\Poll;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PollTieBreakController extends Controller
{
    public function store(Poll $poll): RedirectResponse
    {
        $userId = (int) request()->user()->id;

        abort_unless((int) $poll->created_by === $userId, 403);

        return DB::transaction(function () use ($poll, $userId) {
            $original = Poll::query()
                ->where('created_by', $userId)
                ->lockForUpdate()
                ->findOrFail($poll->id);

            // Una segunda pulsación no crea otra ronda.
            if ($existing = $original->tieBreakPoll()->first()) {
                return redirect()->route('polls.show', $existing);
            }

            if ($original->status !== 'closed' ||
                $original->tie_break_for_poll_id !== null) {
                return back()->withErrors([
                    'poll' => 'Esta votación no permite crear un desempate.',
                ]);
            }

            $original->load([
                'candidates.votes',
                'participants',
                'gameCollections',
            ]);

            $ranking = $original->candidates->map(function ($candidate) use ($original) {
                $first = $candidate->votes->where('position', 1)->count();
                $second = $candidate->votes->where('position', 2)->count();
                $third = $candidate->votes->where('position', 3)->count();

                return [
                    'candidate' => $candidate,
                    'first' => $first,
                    'second' => $second,
                    'points' => $original->votePoints($first, $second, $third),
                ];
            })->sort(fn ($a, $b) =>
                ($b['points'] <=> $a['points'])
                ?: ($b['first'] <=> $a['first'])
                ?: ($b['second'] <=> $a['second'])
            )->values();

            $leader = $ranking->first();

            if (!$leader || $leader['points'] <= 0) {
                return back()->withErrors([
                    'poll' => 'No hay votos suficientes para desempatar.',
                ]);
            }

            $tied = $ranking->filter(fn ($item) =>
                $item['points'] === $leader['points'] &&
                $item['first'] === $leader['first'] &&
                $item['second'] === $leader['second']
            );

            if ($tied->count() < 2) {
                return back()->withErrors([
                    'poll' => 'La votación ya tiene un ganador único.',
                ]);
            }

            do {
                $token = Str::upper(Str::random(10));
            } while (Poll::where('token', $token)->exists());

            $round = Poll::create([
                'created_by' => $userId,
                'name' => Str::limit('Desempate · ' . $original->name, 255, ''),
                'token' => $token,
                'status' => 'draft',
                'ranking_slots' => 1,
                'single_choice' => true,
                'tie_break_for_poll_id' => $original->id,
            ]);

            foreach ($original->participants as $participant) {
                $round->participants()->create([
                    'player_id' => $participant->player_id,
                    'display_name' => $participant->display_name,
                ]);
            }

            foreach ($tied as $item) {
                $candidate = $item['candidate'];

                $round->candidates()->create([
                    'game_id' => $candidate->game_id,
                    'expansion_id' => $candidate->expansion_id,
                    'note' => $candidate->note,
                ]);
            }

            $round->gameCollections()->sync(
                $original->gameCollections->pluck('id')->all()
            );

            return redirect()
                ->route('polls.show', $round)
                ->with('success', 'Desempate preparado correctamente.');
        });
    }

    public function draw(Poll $poll): RedirectResponse
    {
        $userId = (int) request()->user()->id;

        abort_unless((int) $poll->created_by === $userId, 403);

        return DB::transaction(function () use ($poll, $userId): RedirectResponse {
            $original = Poll::query()
                ->whereKey($poll->id)
                ->where('created_by', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $original->status !== 'closed'
                || $original->tie_break_for_poll_id !== null
            ) {
                return back()->withErrors([
                    'poll' => 'No se puede sortear el ganador de esta votación.',
                ]);
            }

            $round = $original->tieBreakPoll()
                ->lockForUpdate()
                ->first();

            if ($round === null || $round->status !== 'closed') {
                return back()->withErrors([
                    'poll' => 'La ronda de desempate todavía no ha terminado.',
                ]);
            }

            // El sorteo es irreversible desde la interfaz.
            if ($original->tie_break_draw_winner_game_id !== null) {
                return redirect()->route('polls.show', $original)
                    ->with('success', 'El sorteo ya estaba registrado.');
            }

            $round->load('candidates.votes');

            $scores = $round->candidates->map(function ($candidate) {
                return [
                    'game_id' => (int) $candidate->game_id,
                    'votes' => $candidate->votes->where('position', 1)->count(),
                ];
            });

            $maximum = $scores->max('votes');

            $finalists = $scores->filter(
                fn (array $score) => $score['votes'] === $maximum
            )->values();

            if (
                $maximum === null
                || $maximum <= 0
                || $finalists->count() < 2
            ) {
                return back()->withErrors([
                    'poll' => 'No existe un empate absoluto que requiera sorteo.',
                ]);
            }

            $chosen = $finalists->get(
                random_int(0, $finalists->count() - 1)
            );

            $original->update([
                'tie_break_draw_winner_game_id' => $chosen['game_id'],
                'tie_break_drawn_at' => now(),
            ]);

            return redirect()->route('polls.show', $original)
                ->with('success', 'Sorteo realizado y ganador registrado.');
        });
    }
}
