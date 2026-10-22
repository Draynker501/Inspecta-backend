<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega eliminacion logica (soft deletes) a las entidades de catalogo
     * operativo: plants, areas y production_lines.
     *
     * Razon: son entidades maestras cuyo historial y relaciones deben
     * conservarse al desactivarlas, sin permitir su eliminacion fisica
     * mientras dependan de registros operativos.
     *
     * organizations ya cuenta con deleted_at desde su migracion propia.
     * No se agrega soft deletes a inspecciones, incidencias, evidencias,
     * acciones correctivas ni auditoria: son registros historicos que
     * deben conservarse sin posibilidad de borrado logico.
     */
    public function up(): void
    {
        foreach (['plants', 'areas', 'production_lines'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['production_lines', 'areas', 'plants'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
