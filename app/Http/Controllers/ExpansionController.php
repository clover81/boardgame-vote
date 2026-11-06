<?php

namespace App\Http\Controllers;

use App\Models\Expansion;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpansionController extends Controller
{
    public function store(Request $request, Game $game): RedirectResponse
    {
        $this->authorizeGame($request, $game);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('expansions', 'name')
                    ->where('game_id', $game->id),
            ],
            'image_url' => ['nullable', 'url', 'max:255'],
            'bgg_url' => ['nullable', 'url', 'max:255'],
            'youtube_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // La relación fija game_id; nunca aceptar el identificador del formulario.
        // No asignar bgg_id: solo el proceso de importación de BGG puede hacerlo.
        $game->expansions()->create([
            ...$validated,
            'is_active' => true,
        ]);

        return back()->with('success', 'Expansión añadida.');
    }

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

        // Las expansiones son globales. No podemos borrar referencias de otras cuentas.
        // La comprobación y la eliminación se hacen dentro de una sola transacción.
        $blockedReason = DB::transaction(function () use ($request, $game, $expansion): ?string {
            $current = Expansion::query()->lockForUpdate()->findOrFail($expansion->id);
            $userId = (int) $request->user()->id;

            $sharedGame = DB::table('game_collection_game as gcg')
                ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
                ->where('gcg.game_id', $game->id)
                ->where('gc.created_by', '<>', $userId)
                ->exists();

            $sharedExpansion = DB::table('game_collection_expansion as gce')
                ->join('game_collections as gc', 'gc.id', '=', 'gce.game_collection_id')
                ->where('gce.expansion_id', $current->id)
                ->where('gc.created_by', '<>', $userId)
                ->exists();

            if ($sharedGame || $sharedExpansion) {
                return 'No se ha eliminado la expansión: otra cuenta la utiliza en su colección.';
            }

            $foreignPlays = DB::table('plays')
                ->where('expansion_id', $current->id)
                ->where(function ($query) use ($userId) {
                    $query->where('created_by', '<>', $userId)
                        ->orWhereNull('created_by');
                })
                ->exists();

            $foreignPolls = DB::table('poll_candidates as pc')
                ->join('polls as p', 'p.id', '=', 'pc.poll_id')
                ->where('pc.expansion_id', $current->id)
                ->where(function ($query) use ($userId) {
                    $query->where('p.created_by', '<>', $userId)
                        ->orWhereNull('p.created_by');
                })
                ->exists();

            if ($foreignPlays || $foreignPolls) {
                return 'No se ha eliminado la expansión: figura en partidas o votaciones de otra cuenta.';
            }

            // Si no hay referencias ajenas, se conserva el historial de esta cuenta.
            DB::table('plays')
                ->where('expansion_id', $current->id)
                ->update(['expansion_id' => null]);

            DB::table('poll_candidates')
                ->where('expansion_id', $current->id)
                ->update(['expansion_id' => null]);

            $current->delete();

            return null;
        });

        if ($blockedReason !== null) {
            // Flash global: visible desde la ficha y desde la página de edición.
            return back()->with('error', $blockedReason);
        }

        return back()->with('success', 'Expansión eliminada.');
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
