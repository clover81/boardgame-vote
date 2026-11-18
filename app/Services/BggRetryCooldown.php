<?php

namespace App\Services;

use App\Models\GameCollection;
use Illuminate\Support\Facades\Cache;

class BggRetryCooldown
{
    public const SECONDS = 900;

    private function key(GameCollection $collection): string
    {
        return 'bgg:retry-after:collection:'.$collection->id;
    }

    public function retryUntil(GameCollection $collection): ?int
    {
        $until = (int) Cache::get($this->key($collection), 0);

        return $until > now()->timestamp ? $until : null;
    }

    public function secondsRemaining(GameCollection $collection): int
    {
        return max(0, ($this->retryUntil($collection) ?? 0) - now()->timestamp);
    }

    public function register(GameCollection $collection): int
    {
        $until = now()->timestamp + self::SECONDS;

        Cache::put($this->key($collection), $until, self::SECONDS);

        return self::SECONDS;
    }

    public function message(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return 'BoardGameGeek está limitando las consultas (HTTP 429). '
            .'Podrás reintentarlo en aproximadamente '
            .$minutes.' minuto'.($minutes === 1 ? '' : 's').'. '
            .'Ese plazo no garantiza que BGG haya retirado su limitación.';
    }
}
