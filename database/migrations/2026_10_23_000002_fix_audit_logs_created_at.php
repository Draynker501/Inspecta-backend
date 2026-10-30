<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige created_at en audit_logs: de nullable a NOT NULL.
     *
     * La tabla de auditoría siempre debe registrar la fecha de creación del
     * evento. Eloquent lo rellena automáticamente al insertar, por lo que
     * no tiene sentido que sea nullable en la base de datos.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->change();
        });
    }
};
