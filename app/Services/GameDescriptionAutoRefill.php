<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class GameDescriptionAutoRefill
{
    public function refillIfNeeded(): void
    {
        if (!config('services.game_descriptions.auto_refill', false)) {
            return;
        }

        if (
            DB::table('jobs')
                ->where('queue', 'descriptions')
                ->exists()
        ) {
            return;
        }

        if (
            DB::table('failed_jobs')
                ->where('queue', 'descriptions')
                ->where('failed_at', '>=', now()->subDay())
                ->exists()
        ) {
            return;
        }

        Artisan::call('games:descriptions-queue', [
            '--limit' => 1,
            '--apply' => true,
        ]);
    }
}
