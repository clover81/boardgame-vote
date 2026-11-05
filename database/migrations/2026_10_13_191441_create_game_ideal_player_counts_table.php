<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('game_ideal_player_counts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('game_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('player_count');

            $table->timestamps();

            $table->unique(['game_id', 'player_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_ideal_player_counts');
    }
};
