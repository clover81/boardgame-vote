<?php

namespace App\Http\Controllers;

use App\Jobs\SyncBggCollection;
use App\Models\Expansion;
use App\Models\Game;
use App\Models\GameCollection;
use App\Services\BggCollectionService;
use App\Services\BggSyncRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class GameCollectionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LISTADO
    |--------------------------------------------------------------------------
    */

    public function index(
        Request $request,
        BggSyncRecoveryService $recovery
    ): Response {
        // Recuperar solicitudes antiguas sin trabajo asociado solo cuando
        // el propietario consulta sus colecciones. No hace falta un cron.
        $recovery->recoverForUser((int) $request->user()->id);

        $collections =
            GameCollection::query()
            ->where(
                'created_by',
                $request->user()->id
            )
            ->withCount('games')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(
                fn($collection) => [
                    'id' =>
                    $collection->id,

                    'name' =>
                    $collection->name,

                    'bgg_username' => $collection->bgg_username,
                    'bgg_sync_status' => $collection->bgg_sync_status ?? 'idle',
                    'bgg_sync_started_at' => $collection->bgg_sync_started_at,
                    'bgg_last_synced_at' => $collection->bgg_last_synced_at,
                    'bgg_sync_error' => $collection->bgg_sync_error,
                    'bgg_sync_games_added' => (int) $collection->bgg_sync_games_added,
                    'bgg_sync_expansions_added' => (int) $collection->bgg_sync_expansions_added,

                    'is_default' =>
                    $collection->is_default,

                    'is_public' => (bool) $collection->is_public,

                    'games_count' =>
                    $collection->games_count,
                ]
            )
            ->values();

        // Solo colecciones publicas de otras cuentas, en modo lectura.
        // No compartimos credenciales BGG ni datos internos de sincronizacion.
        $friendCollections = GameCollection::query()
            ->where('created_by', '!=', $request->user()->id)
            ->where('is_public', true)
            ->with('creator:id,name')
            ->withCount(['games as games_count' => fn ($query) => $query
                ->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn ($collection) => [
                'id' => $collection->id,
                'name' => $collection->name,
                'owner_name' => $collection->creator?->name ?? 'Usuario',
                'games_count' => (int) $collection->games_count,
            ])
            ->values();

        return Inertia::render('collections/index', [
            'collections' => $collections,
            'friendCollections' => $friendCollections,

            'publicCatalogPath' =>
                $collections->contains('is_public', true)
                && $request->user()->catalog_share_token
                    ? route('catalog.public', [
                        'token' => $request->user()->catalog_share_token,
                    ], false)
                    : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request
    ): RedirectResponse {
        $validated =
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',

                    Rule::unique(
                        'game_collections',
                        'name'
                    )->where(
                        fn($query) =>
                        $query->where(
                            'created_by',
                            $request
                                ->user()
                                ->id
                        )
                    ),
                ],
            ]);

        GameCollection::create([
            'created_by' =>
            $request
                ->user()
                ->id,

            'name' =>
            $validated['name'],

            'is_default' =>
            false,
        ]);

        return back()->with(
            'success',
            'Colección creada correctamente.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MARCAR COMO PREDETERMINADA
    |--------------------------------------------------------------------------
    */

    public function makeDefault(
        Request $request,
        GameCollection $collection
    ): RedirectResponse {
        abort_unless(
            $collection->created_by ===
                $request->user()->id,
            403
        );

        DB::transaction(
            function () use (
                $request,
                $collection
            ) {
                /*
                 * Quitamos la marca de las demás.
                 */
                GameCollection::query()
                    ->where(
                        'created_by',
                        $request
                            ->user()
                            ->id
                    )
                    ->update([
                        'is_default' =>
                        false,
                    ]);

                /*
                 * Marcamos esta.
                 */
                $collection->update([
                    'is_default' =>
                    true,
                ]);
            }
        );

        return back()->with(
            'success',
            'Colección predeterminada actualizada.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ELIMINAR
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        GameCollection $collection
    ): RedirectResponse {
        abort_unless(
            $collection->created_by ===
                $request->user()->id,
            403
        );

        /*
         * No permitimos borrar la colección
         * predeterminada.
         */
        if (
            $collection->is_default
        ) {
            return back()->withErrors([
                'collection' =>
                'No puedes eliminar la colección predeterminada. Marca primero otra colección como predeterminada.',
            ]);
        }

        /*
         * Al borrar la colección se eliminan
         * solo sus vínculos con los juegos.
         *
         * Los juegos NO se eliminan.
         */
        $collection->delete();

        return back()->with(
            'success',
            'Colección eliminada correctamente.'
        );
    }

    public function togglePublic(
        Request $request,
        GameCollection $collection
    ): RedirectResponse {
        $user = $request->user();

        // Solo el propietario puede compartir la colección.
        abort_unless(
            (int) $collection->created_by === (int) $user->id,
            403
        );

        DB::transaction(function () use ($user, $collection) {
            $collection->is_public = ! (bool) $collection->is_public;
            $collection->save();

            // Generamos un enlace privado de acceso
            // solo cuando se comparte por primera vez.
            if (
                $collection->is_public &&
                ! $user->catalog_share_token
            ) {
                $user->forceFill([
                    'catalog_share_token' =>
                        (string) \Illuminate\Support\Str::uuid(),
                ])->save();
            }
        });

        return back()->with(
            'success',
            $collection->is_public
                ? 'Colección compartida correctamente.'
                : 'Colección marcada como privada.'
        );
    }

    public function bggPreview(
        Request $request,
        GameCollection $collection,
        BggCollectionService $bgg
    ): JsonResponse {
        // Solo el propietario puede consultar
        // la sincronización de esta colección.
        abort_unless(
            (int) $collection->created_by ===
            (int) $request->user()->id,
            403
        );

        $username = trim(
            (string) $collection->bgg_username
        );

        if ($username === '') {
            return response()->json([
                'message' =>
                    'Esta colección no está vinculada a ninguna cuenta de BoardGameGeek.',
            ], 422);
        }

        try {
            // Una vista previa repetida durante unos minutos no debe
            // multiplicar las peticiones a BGG (que aplica límites).
            $preview = Cache::remember(
                $collection->bggPreviewCacheKey(),
                now()->addMinutes(5),
                fn () => $bgg->preview($username)
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
            ],
            'username' => $username,
            ...$preview,

            // Juegos presentes en esta colección de Laravel
            // que ya no aparecen como propios en BGG.
            // Solo se muestran: no se elimina nada.
            'removal_candidates' => $bgg->removalCandidates(
                $collection,
                $preview
            ),

            'expansion_removal_candidates' =>
                $bgg->expansionRemovalCandidates(
                    $collection,
                    $preview
                ),
        ]);
    }

    public function syncBgg(
        Request $request,
        GameCollection $collection
    ): RedirectResponse {
        // Comprobar que la colección pertenece al usuario.
        abort_unless(
            (int) $collection->created_by ===
            (int) $request->user()->id,
            403
        );

        // Comprobar que tiene una cuenta BGG vinculada.
        if (empty($collection->bgg_username)) {
            return back()->withErrors([
                'bgg' => 'Esta colección no está vinculada a BGG.',
            ]);
        }

        // La sincronización necesita una cola real.
        if (config('queue.default') === 'sync') {
            return back()->withErrors([
                'bgg' => 'Debes configurar una cola de trabajos.',
            ]);
        }

        // Cambio atómico: solo se acepta una sincronización
        // si no hay otra pendiente o ejecutándose.
        $updated = GameCollection::query()
            ->whereKey($collection->id)
            ->whereNotIn('bgg_sync_status', [
                'pending',
                'running',
            ])
            ->update([
                'bgg_sync_status' => 'pending',
                'bgg_sync_error' => null,
            ]);

        if ($updated === 0) {
            return back()->withErrors([
                'bgg' => 'Ya hay una sincronización pendiente o en curso.',
            ]);
        }

        try {
            SyncBggCollection::dispatch($collection->id);
        } catch (\Throwable $exception) {
            report($exception);

            GameCollection::query()
                ->whereKey($collection->id)
                ->where('bgg_sync_status', 'pending')
                ->update([
                    'bgg_sync_status' => 'failed',
                    'bgg_sync_error' =>
                        'No se pudo enviar la sincronización a la cola.',
                ]);

            return back()->withErrors([
                'bgg' => 'No se pudo iniciar la sincronización.',
            ]);
        }

        return back()->with(
            'success',
            'Sincronización enviada a la cola.'
        );
    }

    public function confirmBggRemoval(
        Request $request,
        GameCollection $collection,
        Game $game,
        BggCollectionService $bgg
    ): RedirectResponse {
        // Solo el propietario puede modificar su colección.
        abort_unless(
            (int) $collection->created_by ===
            (int) $request->user()->id,
            403
        );

        if (empty($collection->bgg_username)) {
            return back()->withErrors([
                'bgg' => 'La colección no está vinculada a BGG.',
            ]);
        }

        // El juego debe pertenecer a esta colección.
        abort_unless(
            $collection->games()
                ->where('games.id', $game->id)
                ->exists(),
            404
        );

        $bggId = (int) $game->bgg_id;

        if ($bggId <= 0) {
            return back()->withErrors([
                'bgg' => 'Este juego no tiene identificador BGG.',
            ]);
        }

        // Evitar cambios mientras se sincroniza.
        $collection->refresh();

        if (in_array(
            $collection->bgg_sync_status,
            ['pending', 'running'],
            true
        )) {
            return back()->withErrors([
                'bgg' => 'Hay una sincronización en curso.',
            ]);
        }

        try {
            // La vista previa anterior procede de una consulta BGG real.
            // Una consulta fresca confirma la baja sin hacer cuatro
            // peticiones en cada operación. Si no hay caché, verificamos
            // dos veces de forma independiente.
            $first = Cache::get($collection->bggPreviewCacheKey());
            if (!is_array($first)) {
                $first = $bgg->preview($collection->bgg_username);
                sleep(5);
            }

            $second = $bgg->preview($collection->bgg_username);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'bgg' => str_contains($exception->getMessage(), '429')
                    ? 'BGG ha limitado temporalmente las consultas (HTTP 429). Espera unos minutos antes de confirmar la baja. No se ha modificado nada.'
                    : 'No se ha podido verificar la baja con BGG. No se ha modificado nada.',
            ]);
        }

        $firstIds = array_map(
            'intval',
            array_column($first['games'], 'bgg_id')
        );

        $secondIds = array_map(
            'intval',
            array_column($second['games'], 'bgg_id')
        );

        sort($firstIds);
        sort($secondIds);

        // Exigir dos listas completas y coincidentes.
        if (empty($firstIds) || $firstIds !== $secondIds) {
            return back()->withErrors([
                'bgg' => 'BGG ha devuelto resultados diferentes. No se ha aplicado la baja por seguridad.',
            ]);
        }

        // Si vuelve a estar en BGG, no podemos retirarlo.
        if (in_array($bggId, $secondIds, true)) {
            return back()->withErrors([
                'bgg' => 'Este juego vuelve a aparecer como propio en BGG. No se ha retirado.',
            ]);
        }

        // Solo retirar la relación con esta colección.
        // NO eliminar el juego de la base de datos.
        $collection->games()->detach($game->id);

        Cache::put($collection->bggPreviewCacheKey(), $second, now()->addMinutes(5));

        return back()->with(
            'success',
            "El juego {$game->name} se ha retirado de la colección BGG. Su ficha e historial se conservan."
        );
    }

    public function confirmBggExpansionRemoval(
        Request $request,
        GameCollection $collection,
        Expansion $expansion,
        BggCollectionService $bgg
    ): RedirectResponse {
        // Solo el propietario puede modificar su colección.
        abort_unless(
            (int) $collection->created_by ===
            (int) $request->user()->id,
            403
        );

        if (empty($collection->bgg_username)) {
            return back()->withErrors([
                'bgg' => 'Esta colección no está vinculada a BGG.',
            ]);
        }

        // La expansión debe estar vinculada a esta colección.
        abort_unless(
            $collection->expansions()
                ->where('expansions.id', $expansion->id)
                ->exists(),
            404
        );

        $bggId = (int) $expansion->bgg_id;

        if ($bggId <= 0) {
            return back()->withErrors([
                'bgg' => 'La expansión no tiene identificador BGG.',
            ]);
        }

        $collection->refresh();

        if (in_array(
            $collection->bgg_sync_status,
            ['pending', 'running'],
            true
        )) {
            return back()->withErrors([
                'bgg' => 'Hay una sincronización en curso.',
            ]);
        }

        try {
            // Reutilizamos la vista previa reciente como primera prueba;
            // la segunda SIEMPRE se consulta directamente a BGG.
            $first = Cache::get($collection->bggPreviewCacheKey());
            if (!is_array($first)) {
                $first = $bgg->preview($collection->bgg_username);
                sleep(5);
            }

            $second = $bgg->preview($collection->bgg_username);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'bgg' => str_contains($exception->getMessage(), '429')
                    ? 'BGG ha limitado temporalmente las consultas (HTTP 429). Espera unos minutos antes de confirmar la baja. No se ha modificado nada.'
                    : 'No se pudo verificar la baja en BGG. No se ha modificado nada.',
            ]);
        }

        $firstIds = array_map(
            'intval',
            array_column($first['expansions'], 'bgg_id')
        );

        $secondIds = array_map(
            'intval',
            array_column($second['expansions'], 'bgg_id')
        );

        sort($firstIds);
        sort($secondIds);

        // Puede no quedar ninguna expansión en BGG. Eso es válido:
        // exigimos listas de expansiones idénticas y, además, una lista
        // no vacía y estable de juegos propios en ambas respuestas.
        $firstGames = array_map('intval', array_column($first['games'], 'bgg_id'));
        $secondGames = array_map('intval', array_column($second['games'], 'bgg_id'));
        sort($firstGames);
        sort($secondGames);

        if (
            $firstIds !== $secondIds ||
            empty($firstGames) ||
            $firstGames !== $secondGames
        ) {
            return back()->withErrors([
                'bgg' => 'BGG ha devuelto resultados inconsistentes. No se ha aplicado la baja.',
            ]);
        }

        // Si la expansión vuelve a aparecer como propia,
        // no permitir retirarla.
        if (in_array($bggId, $secondIds, true)) {
            return back()->withErrors([
                'bgg' => 'La expansión sigue marcada como propia en BGG.',
            ]);
        }

        // Volver a comprobar el estado antes de desvincular.
        $removed = \Illuminate\Support\Facades\DB::transaction(
            function () use ($collection, $expansion) {
                $current = GameCollection::query()
                    ->lockForUpdate()
                    ->findOrFail($collection->id);

                if (in_array(
                    $current->bgg_sync_status,
                    ['pending', 'running'],
                    true
                )) {
                    return false;
                }

                // Eliminar solo la asociación con esta colección.
                $current->expansions()->detach($expansion->id);

                return true;
            }
        );

        if (!$removed) {
            return back()->withErrors([
                'bgg' => 'Se ha iniciado otra sincronización. No se ha aplicado la baja.',
            ]);
        }

        Cache::put($collection->bggPreviewCacheKey(), $second, now()->addMinutes(5));

        return back()->with(
            'success',
            "La expansión {$expansion->name} se ha retirado de la colección BGG. Su ficha se conserva."
        );
    }
}
