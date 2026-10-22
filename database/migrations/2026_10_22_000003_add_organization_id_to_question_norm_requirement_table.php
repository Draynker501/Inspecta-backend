<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega organization_id a la tabla pivote de dominio
     * question_norm_requirement (quedo fuera del lote anterior).
     */
    public function up(): void
    {
        Schema::table('question_norm_requirement', function (Blueprint $table) {
            $table->char('organization_id', 36)->after('id');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['organization_id'], 'question_norm_requirement_organization_idx');
        });
    }

    public function down(): void
    {
        Schema::table('question_norm_requirement', function (Blueprint $table) {
            $table->dropForeign('question_norm_requirement_organization_id_foreign');
            $table->dropIndex('question_norm_requirement_organization_idx');
            $table->dropColumn('organization_id');
        });
    }
};
