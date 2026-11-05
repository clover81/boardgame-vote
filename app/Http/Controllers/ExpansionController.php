<?php

namespace App\Http\Controllers;

use App\Models\Expansion;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpansionController extends Controller
{
    public function update(
        Request $request,
        Game $game,
        Expansion $expansion
    ): RedirectResponse {
        $this->authorizeGame($request, $game);

        abort_unless(
            $expansion->game_id === $game->id,
            404
        );

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'image_url' => [
                'nullable',
                'url',
                'max:2048',
            ],

            'bgg_url' => [
                'nullable',
                'url',
                'max:2048',
            ],

            'youtube_url' => [
                'nullable',
                'url',
                'max:2048',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'is_active' => [
                'boolean',
            ],
        ]);

        $expansion->update($validated);

        return back()->with(
            'success',
            'Expansión actualizada.'
        );
    }

    public function destroy(
        Request $request,
        Game $game,
        Expansion $expansion
    ): RedirectResponse {
        $this->authorizeGame($request, $game);

        abort_unless(
            $expansion->game_id === $game->id,
            404
        );

        DB::transaction(function () use ($expansion) {

            /*
             * Conservamos las partidas.
             * Simplemente dejan de indicar
             * esta expansión.
             */
            DB::table('plays')
                ->where(
                    'expansion_id',
                    $expansion->id
                )
                ->update([
                    'expansion_id' => null,
                ]);

            /*
             * Lo mismo para votaciones
             * antiguas.
             */
            DB::table('poll_candidates')
                ->where(
                    'expansion_id',
                    $expansion->id
                )
                ->update([
                    'expansion_id' => null,
                ]);

            $expansion->delete();
        });

        return back()->with(
            'success',
            'Expansión eliminada.'
        );
    }

    private function authorizeGame(
        Request $request,
        Game $game
    ): void {
        $belongsToUser = $game
            ->collections()
            ->where(
                'game_collections.created_by',
                $request->user()->id
            )
            ->exists();

        abort_unless(
            $belongsToUser,
            403
        );
    }
}
