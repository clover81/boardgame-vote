<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('color', 16)->nullable()->after('name');
        });

        // Conservamos los cuatro colores que ya se utilizaban en la interfaz.
        foreach (['alejandro' => 'violet', 'ana' => 'pink', 'miki' => 'yellow', 'alejo' => 'teal'] as $name => $color) {
            DB::table('players')
                ->whereNull('color')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
                ->update(['color' => $color]);
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
