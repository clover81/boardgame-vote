<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_collection_game', function (Blueprint $table) {
            $table->id();

            $table->foreignId('game_collection_id')
                ->constrained('game_collections')
                ->cascadeOnDelete();

            $table->foreignId('game_id')
                ->constrained('games')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'game_collection_id',
                'game_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_collection_game');
    }
};
