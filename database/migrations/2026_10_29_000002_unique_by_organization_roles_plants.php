<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Únicos por organización en roles y plants, eliminando los globales.
     *
     * 1. Añade (organization_id, name) en roles -> roles_org_name_unique
     * 2. Añade (organization_id, code) en plants -> plants_org_code_unique
     * 3. Elimina roles_name_unique y plants_code_unique (únicos globales)
     *
     * down(): recrea los únicos globales y luego elimina los compuestos.
     * El down falla si ya existen duplicados entre organizaciones
     * (mismo rol/planta repetido en distintas organizaciones).
     */
    public function up(): void
    {
        // 1. Únicos compuestos por organización
        Schema::table('roles', function (Blueprint $table): void {
            $table->unique(['organization_id', 'name'], 'roles_org_name_unique');
        });

        Schema::table('plants', function (Blueprint $table): void {
            $table->unique(['organization_id', 'code'], 'plants_org_code_unique');
        });

        // 2. Eliminar únicos globales
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_name_unique');
        });

        Schema::table('plants', function (Blueprint $table): void {
            $table->dropUnique('plants_code_unique');
        });
    }

    public function down(): void
    {
        // Recrear únicos globales primero (puede fallar si hay duplicados entre organizaciones)
        Schema::table('roles', function (Blueprint $table): void {
            $table->unique(['name'], 'roles_name_unique');
        });

        Schema::table('plants', function (Blueprint $table): void {
            $table->unique(['code'], 'plants_code_unique');
        });

        // Luego eliminar los compuestos por organización
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_org_name_unique');
        });

        Schema::table('plants', function (Blueprint $table): void {
            $table->dropUnique('plants_org_code_unique');
        });
    }
};
