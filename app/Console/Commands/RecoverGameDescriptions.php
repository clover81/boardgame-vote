<?php

namespace App\Console\Commands;

use App\Jobs\GenerateGameDescription;
use App\Models\Game;
use App\Services\BggService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RecoverGameDescriptions extends Command
{
    protected $signature = 'games:descriptions-recover
        {--owner= : ID del propietario}
        {--limit=3 : Máximo de juegos (1-5)}
        {--after-id=0 : Continuar después de este ID}
        {--apply : Consultar BGG y encolar trabajos}';

    protected $description =
        'Recuperar descripciones BGG de juegos sin trabajo de IA';

    public function handle(): int
    {
        $owner = filter_var($this->option('owner'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5],
        ]);

        $afterId = filter_var($this->option('after-id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        if ($owner === false || $owner === null ||
            $limit === false || $afterId === false) {
            $this->error('Indica --owner, --limit entre 1 y 5 y --after-id válido.');
            return self::FAILURE;
        }

        // Evitar trabajos que ya están preparados.
        $queuedIds = [];

        foreach (DB::table('jobs')
            ->where('queue', 'descriptions')
            ->pluck('payload') as $raw) {

            $payload = json_decode($raw, true);

            if (($payload['displayName'] ?? '') !== GenerateGameDescription::class) {
                continue;
            }

            $command = $payload['data']['command'] ?? '';

            if (preg_match('/s:6:"gameId";i:(\d+);/', $command, $match)) {
                $queuedIds[(int) $match[1]] = true;
            }
        }

        // Buscar exclusivamente juegos activos de este propietario.
        $candidates = Game::query()
            ->where('is_active', true)
            ->whereNotNull('bgg_id')
            ->where('description_source', 'bgg')
            ->whereNull('full_description')
            ->where('id', '>', $afterId)
            ->whereHas('collections', fn ($query) =>
                $query->where('game_collections.created_by', $owner)
            )
            ->orderBy('id')
            ->get()
            ->reject(fn (Game $game) => isset($queuedIds[$game->id]));

        $selected = $candidates->take($limit);

        $this->info('Juegos propios sin trabajo: ' . $candidates->count());

        foreach ($selected as $game) {
            $this->line(
                "ID {$game->id} | BGG {$game->bgg_id} | {$game->name}"
            );
        }

        // Vista previa: sin BGG, OpenAI ni modificaciones.
        if (!$this->option('apply')) {
            $this->warn(
                'SOLO VISTA PREVIA. Sin consultas externas ni trabajos nuevos.'
            );
            return self::SUCCESS;
        }

        if ($selected->isEmpty()) {
            return self::SUCCESS;
        }

        $bgg = app(BggService::class);
        $queued = 0;
        $skipped = 0;

        foreach ($selected->values() as $index => $game) {
            if ($index > 0) {
                sleep(3);
            }

            try {
                $details = $bgg->game((int) $game->bgg_id);
            } catch (Throwable $e) {
                $this->error(
                    "Error BGG para ID {$game->id}: {$e->getMessage()}"
                );
                $this->warn('Detenido para evitar más peticiones.');
                return self::FAILURE;
            }

            if ((int) ($details['bgg_id'] ?? 0) !== (int) $game->bgg_id) {
                $this->warn("ID {$game->id}: BGG devolvió otro juego.");
                $skipped++;
                continue;
            }

            // Misma normalización utilizada por el importador.
            $description = strip_tags(
                html_entity_decode(
                    (string) ($details['description'] ?? ''),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            );

            $description = trim(
                preg_replace('/\s+/u', ' ', $description) ?? ''
            );

            // Revalidar la ficha después de consultar BGG.
            $fresh = Game::query()
                ->whereKey($game->id)
                ->where('is_active', true)
                ->where('bgg_id', $game->bgg_id)
                ->where('description_source', 'bgg')
                ->whereNull('full_description')
                ->whereHas('collections', fn ($query) =>
                    $query->where('game_collections.created_by', $owner)
                )
                ->first();

            if (!$fresh ||
                $fresh->short_description !== $game->short_description ||
                $description === '' ||
                $fresh->short_description !== Str::limit($description, 240)) {

                $this->warn(
                    "ID {$game->id}: texto original ausente o diferente. Omitido."
                );
                $skipped++;
                continue;
            }

            // Encolar no ejecuta OpenAI.
            GenerateGameDescription::dispatch(
                $fresh->id,
                (int) $fresh->bgg_id,
                $description,
                $owner
            )->onQueue('descriptions');

            $queued++;
            $this->info("Encolado: {$fresh->name}");
        }

        $this->info(
            "Resumen: {$queued} encolados, {$skipped} omitidos."
        );

        $this->line(
            'Último ID examinado: ' . $selected->last()->id
        );

        return self::SUCCESS;
    }
}
