<?php

namespace App\Services;

use App\Jobs\SyncBggCollection;
use App\Models\GameCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BggSyncRecoveryService
{
    public function recoverForUser(int $userId): void
    {
        // Solo conocemos el estado real de los trabajos de una cola database.
        if (config('queue.default') !== 'database') {
            return;
        }

        // El trabajo tiene 30 min de timeout, 1900 s de retry_after y
        // un bloqueo de unicidad de una hora. No recuperamos antes de 70 min.
        $cutoff = now()->subSeconds(max(
            4200,
            (int) config('queue.connections.database.retry_after', 1900) + 600
        ));

        $stale = GameCollection::query()
            ->where('created_by', $userId)
            ->whereNotNull('bgg_username')
            ->where(function (Builder $query) use ($cutoff) {
                $query->where(function (Builder $pending) use ($cutoff) {
                    $pending->where('bgg_sync_status', 'pending')
                        ->where('updated_at', '<=', $cutoff);
                })->orWhere(function (Builder $running) use ($cutoff) {
                    $running->where('bgg_sync_status', 'running')
                        ->where(function (Builder $dated) use ($cutoff) {
                            $dated->where('bgg_sync_started_at', '<=', $cutoff)
                                ->orWhere(function (Builder $missing) use ($cutoff) {
                                    $missing->whereNull('bgg_sync_started_at')
                                        ->where('updated_at', '<=', $cutoff);
                                });
                        });
                });
            })
            ->get(['id', 'bgg_sync_status', 'bgg_sync_started_at', 'updated_at']);

        if ($stale->isEmpty()) {
            return;
        }

        try {
            $queuedCollections = [];
            $jobs = DB::connection(config('queue.connections.database.connection'))
                ->table(config('queue.connections.database.table', 'jobs'))
                ->where('queue', config('queue.connections.database.queue', 'default'))
                ->cursor();

            foreach ($jobs as $job) {
                $payload = json_decode($job->payload, true);
                $command = $payload['data']['commandName'] ?? null;

                // Si el trabajo no se puede identificar, conservar la protección anterior.
                if (! is_string($command) || $command === '') {
                    return;
                }

                if ($command !== SyncBggCollection::class) {
                    continue;
                }

                $name = $payload['displayName'] ?? null;
                if (! is_string($name) || ! preg_match('/^SyncBggCollection bgg-collection-([1-9][0-9]*)$/D', $name, $match)) {
                    return;
                }

                $queuedCollections[$match[1]] = true;
            }
        } catch (Throwable $exception) {
            // Si no podemos consultar la cola, tampoco podemos afirmar
            // que la sincronizacion haya desaparecido.
            Log::warning('No se pudo comprobar la cola para recuperar BGG', [
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        foreach ($stale as $collection) {
            if (isset($queuedCollections[$collection->id])) {
                continue;
            }

            // Evita pisar un estado mas reciente si se ha modificado
            // durante la comprobacion.
            $updated = GameCollection::query()
                ->whereKey($collection->id)
                ->where('bgg_sync_status', $collection->bgg_sync_status)
                ->where('updated_at', $collection->getRawOriginal('updated_at'))
                ->update([
                    'bgg_sync_status' => 'failed',
                    'bgg_sync_error' => 'La sincronización anterior se interrumpió y ya no tiene trabajo en cola. Puedes volver a intentarla.',
                ]);

            if ($updated > 0) {
                Log::warning('Estado BGG antiguo recuperado', [
                    'collection_id' => $collection->id,
                    'previous_status' => $collection->bgg_sync_status,
                ]);
            }
        }
    }
}
