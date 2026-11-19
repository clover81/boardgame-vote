<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\GameDescriptionGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateGameDescription implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 100;
    public int $timeout = 150;

    public function __construct(
        public int $gameId,
        public int $bggId,
        public string $bggDescription,
        public ?int $ownerId = null
    ) {}

    public function uniqueId(): string
    {
        return "game-description:{$this->gameId}";
    }

    public function middleware(): array
    {
        return [
            // Primero comprobar si OpenAI sigue bloqueado.
            (new ThrottlesExceptions(1, 6 * 60 * 60))
                ->by('openai-game-descriptions')
                ->backoff(360)
                ->when(
                    fn (\Throwable $e) =>
                        str_contains(
                            $e->getMessage(),
                            'OpenAI HTTP 429'
                        )
                ),

            // Después aplicar los límites preventivos.
            new RateLimited('game-descriptions-ai'),
        ];
    }

    private function stillInCollection(): bool
    {
        $query = DB::table('game_collection_game as gcg')
            ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
            ->where('gcg.game_id', $this->gameId);

        // Los trabajos antiguos no incluyen ownerId.
        // Para ellos se exige al menos una colección actual.
        if (isset($this->ownerId)) {
            $query->where('gc.created_by', $this->ownerId);
        }

        return $query->exists();
    }

    public function handle(
        GameDescriptionGenerator $generator
    ): void {
        // No generar textos si la función está desactivada.
        if (!config('services.game_descriptions.enabled')) {
            return;
        }

        $game = Game::query()
            ->whereKey($this->gameId)
            ->where('is_active', true)
            ->first();

        if (!$game) {
            return;
        }

        // Verificar identidad y procedencia.
        if (
            (int) $game->bgg_id !== $this->bggId ||
            $game->description_source !== 'bgg' ||
            $game->full_description !== null ||
            trim($this->bggDescription) === '' ||
            !$this->stillInCollection()
        ) {
            return;
        }

        // Recordar el texto existente para detectar
        // posibles modificaciones durante la generación.
        $originalShort = $game->short_description;

        // Generar los dos textos en español.
        $result = $generator->generate(
            $game->name,
            $this->bggDescription
        );

        // Guardar solamente si el juego sigue intacto.
        DB::table('games')
            ->where('id', $this->gameId)
            ->where('bgg_id', $this->bggId)
            ->where('description_source', 'bgg')
            ->whereNull('full_description')
            ->where('short_description', $originalShort)
            ->where('is_active', true)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('game_collection_game as gcg')
                    ->join('game_collections as gc', 'gc.id', '=', 'gcg.game_collection_id')
                    ->whereColumn('gcg.game_id', 'games.id');

                if (isset($this->ownerId)) {
                    $query->where('gc.created_by', $this->ownerId);
                }
            })
            ->update([
                'short_description' =>
                    $result['short_description'],

                'full_description' =>
                    $result['full_description'],

                'description_source' => 'generated',

                'updated_at' => now(),
            ]);
    }
}
