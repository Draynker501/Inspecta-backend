<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Evitar crear el índice con datos duplicados.
        $duplicates = DB::table('questions')
            ->select('checklist_section_id', 'display_order')
            ->groupBy('checklist_section_id', 'display_order')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Existen preguntas con display_order repetido '
                . 'dentro de una misma sección. '
                . 'Corrige los datos antes de migrar.'
            );
        }

        if (DB::table('questions')
            ->where('display_order', '<', 1)
            ->exists()) {
            throw new RuntimeException(
                'Existen preguntas con display_order menor que 1.'
            );
        }

        Schema::table('questions', function (Blueprint $table) {
            $table->integer('display_order')
                ->default(1)
                ->change();

            $table->unique(
                ['checklist_section_id', 'display_order'],
                'questions_section_order_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropUnique('questions_section_order_unique');

            $table->integer('display_order')
                ->default(0)
                ->change();
        });
    }
};