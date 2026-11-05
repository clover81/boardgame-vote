<?php

namespace App\Console\Commands;

use App\Jobs\GenerateGameDescription;
use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class QueueGameDescriptions extends Command
{
    protected $signature = 'games:descriptions-queue
        {--limit=5 : Número máximo de juegos}
        {--apply : Enviar los trabajos a la cola}';

    protected $description = 'Generar descripciones pendientes de BGG';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1 || $limit > 300) {
            $this->error('El límite debe estar entre 1 y 300.');
            return self::FAILURE;
        }

        $path = base_path('bgg_descripciones_completas.json');

        if (!is_file($path)) {
            $this->error('No encuentro el JSON de BGG.');
            return self::FAILURE;
        }

        $items = json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $pending = [];

        foreach ($items as $item) {
            $game = Game::find($item['id']);

            if (!$game) {
                continue;
            }

            // Proteger juegos modificados o ya procesados.
            if (
                (int) $game->bgg_id !== (int) $item['bgg_id']
                || $game->name !== $item['name']
                || $game->description_source !== 'bgg'
                || $game->full_description !== null
                || $game->short_description !== $item['short_description_current']
                || $game->short_description !== Str::limit($item['description_bgg'], 240)
                || trim($item['description_bgg']) === ''
            ) {
                continue;
            }

            $pending[] = [
                'game' => $game,
                'description' => $item['description_bgg'],
            ];
        }

        $this->info('Juegos pendientes aptos: ' . count($pending));

        $selected = array_slice($pending, 0, $limit);

        foreach ($selected as $item) {
            $this->line('- ' . $item['game']->name);
        }

        if (!$this->option('apply')) {
            $this->warn('SOLO VISTA PREVIA. No se ha enviado ningún trabajo.');
            return self::SUCCESS;
        }

        if (!config('services.game_descriptions.enabled')) {
            $this->error('La generación automática está desactivada.');
            return self::FAILURE;
        }

        foreach ($selected as $item) {
            GenerateGameDescription::dispatch(
                $item['game']->id,
                (int) $item['game']->bgg_id,
                $item['description']
            )->onQueue('descriptions');
        }

        $this->info(count($selected) . ' trabajos enviados a la cola.');

        return self::SUCCESS;
    }
}
