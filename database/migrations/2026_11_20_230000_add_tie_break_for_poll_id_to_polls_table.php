<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->foreignId('tie_break_for_poll_id')
                ->nullable()
                ->unique()
                ->constrained('polls')
                ->cascadeOnDelete();

            $table->foreignId('tie_break_draw_winner_game_id')
                ->nullable()
                ->constrained('games')
                ->nullOnDelete();

            $table->timestamp('tie_break_drawn_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dropForeign(['tie_break_draw_winner_game_id']);
            $table->dropForeign(['tie_break_for_poll_id']);
            $table->dropUnique(
                'polls_tie_break_for_poll_id_unique'
            );
            $table->dropColumn([
                'tie_break_for_poll_id',
                'tie_break_draw_winner_game_id',
                'tie_break_drawn_at',
            ]);
        });
    }
};
