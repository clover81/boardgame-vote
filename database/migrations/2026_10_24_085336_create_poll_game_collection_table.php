<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_game_collection', function (Blueprint $table) {
            $table->id();

            $table->foreignId('poll_id')
                ->constrained('polls')
                ->cascadeOnDelete();

            $table->foreignId('game_collection_id')
                ->constrained('game_collections')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'poll_id',
                'game_collection_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_game_collection');
    }
};
