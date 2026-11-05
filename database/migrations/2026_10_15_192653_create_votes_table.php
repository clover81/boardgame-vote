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
        Schema::create('votes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('poll_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('poll_participant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('poll_candidate_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('position');

            $table->timestamps();

            $table->unique([
                'poll_participant_id',
                'position',
            ]);

            $table->unique([
                'poll_participant_id',
                'poll_candidate_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
