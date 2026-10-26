<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige auditable_type en audit_logs: de varchar(100) a varchar(191).
     *
     * El campo auditable_type almacena el nombre del modelo o entidad
     * afectada (FQCN en morph maps). Un largo de 100 caracteres puede
     * quedarse corto para namespaces extensos de la aplicación.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('auditable_type', 191)->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('auditable_type', 100)->change();
        });
    }
};
