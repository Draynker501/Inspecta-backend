<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige created_at en corrective_action_updates: de nullable a NOT NULL.
     *
     * Esta tabla es append-only (historial de actualizaciones de acciones
     * correctivas). El campo created_at siempre debe tener un valor, ya que
     * Eloquent lo rellena automáticamente al insertar. No tiene sentido que
     * sea nullable en la base de datos.
     */
    public function up(): void
    {
        Schema::table('corrective_action_updates', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('corrective_action_updates', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->change();
        });
    }
};
