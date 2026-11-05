<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $playerCount = DB::table('players')->count();
        $ownerId = null;

        if ($playerCount > 0) {
            $userIds = DB::table('users')
                ->limit(2)
                ->pluck('id');

            if ($userIds->count() !== 1) {
                throw new RuntimeException(
                    'No se puede asignar automáticamente el propietario de los jugadores.'
                );
            }

            $ownerId = $userIds->first();
        }

        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->restrictOnDelete();
        });

        if ($ownerId !== null) {
            DB::table('players')->update([
                'created_by' => $ownerId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
