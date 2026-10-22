<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega organization_id (FK -> organizations.id) a todas las tablas
     * de dominio para implementar la separacion de responsabilidades por
     * empresa / organizacion.
     *
     * Todas las columnas son NOT NULL y referencian organizations.id con
     * ON DELETE RESTRICT (una organizacion no se elimina mientras tenga
     * registros asociados) y ON UPDATE CASCADE.
     *
     * Las tablas de dominio estan vacias en este entorno de desarrollo,
     * por lo que NOT NULL no requiere valor predeterminado ni backfill.
     */
    public function up(): void
    {
        $tables = [
            'plants',
            'users',
            'roles',
            'checklists',
            'norms',
            'areas',
            'production_lines',
            'user_roles',
            'user_plants',
            'user_areas',
            'checklist_versions',
            'norm_requirements',
            'checklist_sections',
            'questions',
            'inspections',
            'inspection_answers',
            'incidents',
            'evidences',
            'corrective_actions',
            'corrective_action_updates',
            'audit_logs',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->char('organization_id', 36)->after('id');

                $blueprint->foreign('organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();

                $blueprint->index(['organization_id'], "{$table}_organization_idx");
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'audit_logs',
            'corrective_action_updates',
            'corrective_actions',
            'evidences',
            'incidents',
            'inspection_answers',
            'inspections',
            'questions',
            'checklist_sections',
            'norm_requirements',
            'checklist_versions',
            'user_areas',
            'user_plants',
            'user_roles',
            'production_lines',
            'areas',
            'norms',
            'checklists',
            'roles',
            'users',
            'plants',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign("{$table}_organization_id_foreign");
                $blueprint->dropIndex("{$table}_organization_idx");
                $blueprint->dropColumn('organization_id');
            });
        }
    }
};
