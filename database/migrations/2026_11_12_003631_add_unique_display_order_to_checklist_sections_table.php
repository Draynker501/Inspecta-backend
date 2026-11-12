<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('checklist_sections')
            ->select('checklist_version_id', 'display_order')
            ->groupBy('checklist_version_id', 'display_order')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Existen secciones con display_order repetido '
                . 'dentro de una misma versión. '
                . 'Corrige los datos antes de migrar.'
            );
        }

        if (DB::table('checklist_sections')
            ->where('display_order', '<', 1)
            ->exists()) {
            throw new RuntimeException(
                'Existen secciones con display_order menor que 1.'
            );
        }

        Schema::table('checklist_sections', function (Blueprint $table) {
            $table->integer('display_order')
                ->default(1)
                ->change();

            $table->unique(
                ['checklist_version_id', 'display_order'],
                'sections_version_order_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('checklist_sections', function (Blueprint $table) {
            $table->dropUnique('sections_version_order_unique');

            $table->integer('display_order')
                ->default(0)
                ->change();
        });
    }
};