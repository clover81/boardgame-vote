<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GameCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameCollectionMembershipController extends Controller
{
    public function attach(
        Request $request,
        Game $game
    ): RedirectResponse {
        $validated = $request->validate([
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

        $collectionIds = GameCollection::query()
            ->where('created_by', $request->user()->id)
            ->whereIn('id', $validated['collection_ids'])
            ->pluck('id');

        if ($collectionIds->count() !== count($validated['collection_ids'])) {
            throw ValidationException::withMessages([
                'collection_ids' =>
                    'Una de las colecciones seleccionadas no es válida.',
            ]);
        }

        /*
         * Solo añadimos. Nunca quitamos colecciones existentes.
         */
        $game->collections()->syncWithoutDetaching($collectionIds);

        return redirect()
            ->route('games.show', $game)
            ->with('success', 'Juego añadido a las colecciones seleccionadas.');
    }

    public function update(
        Request $request,
        Game $game
    ): RedirectResponse {
        $validated = $request->validate([
            'collection_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'collection_ids.*' => [
                'required',
                'integer',
                'distinct',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Colecciones que realmente pertenecen al usuario
        |--------------------------------------------------------------------------
        */

        $ownedCollectionIds =
            GameCollection::query()
            ->where(
                'created_by',
                $request->user()->id
            )
            ->whereIn(
                'id',
                $validated['collection_ids']
            )
            ->pluck('id');

        /*
         * Si ha llegado algún ID que no pertenece
         * al usuario, rechazamos la petición.
         */
        if (
            $ownedCollectionIds->count() !==
            count($validated['collection_ids'])
        ) {
            throw ValidationException::withMessages([
                'collection_ids' =>
                'Una de las colecciones seleccionadas no es válida.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | IMPORTANTE
        |--------------------------------------------------------------------------
        |
        | No hacemos:
        |
        | $game->collections()->sync(...)
        |
        | porque en el futuro el mismo juego podría estar
        | también en colecciones pertenecientes a otros usuarios.
        |
        | Solo modificamos las relaciones del usuario actual.
        |
        */

        $allUserCollectionIds =
            GameCollection::query()
            ->where(
                'created_by',
                $request->user()->id
            )
            ->pluck('id');

        DB::transaction(
            function () use (
                $game,
                $allUserCollectionIds,
                $ownedCollectionIds
            ) {
                /*
                 * Quitamos únicamente sus colecciones.
                 */
                $game
                    ->collections()
                    ->detach(
                        $allUserCollectionIds
                    );

                /*
                 * Añadimos la nueva selección.
                 */
                $game
                    ->collections()
                    ->attach(
                        $ownedCollectionIds
                    );
            }
        );

        return back()->with(
            'success',
            'Colecciones del juego actualizadas.'
        );
    }
}
