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
        Schema::create('games', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            $table->string('image_url')->nullable();

            $table->unsignedTinyInteger('min_players');
            $table->unsignedTinyInteger('max_players');

            $table->unsignedSmallInteger('min_playtime')->nullable();
            $table->unsignedSmallInteger('max_playtime')->nullable();

            $table->decimal('complexity', 3, 2)->nullable();

            $table->string('game_mode');

            $table->text('short_description')->nullable();
            $table->text('additional_information')->nullable();

            $table->string('bgg_url')->nullable();
            $table->string('youtube_url')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
