<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Únicos auxiliares (id, organization_id) en roles, plants y areas.
     *
     * Garantiza que cada fila de pivote referencie a un rol/planta/área
     * que pertenezca a la misma organización del usuario, mediante la
     * FK compuesta que se añade en el paso 2.3 sobre organization_memberships.
     *
     * down() elimina estos únicos sin tocar nada más.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id'], 'roles_id_org_unique');
        });

        Schema::table('plants', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id'], 'plants_id_org_unique');
        });

        Schema::table('areas', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id'], 'areas_id_org_unique');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_id_org_unique');
        });

        Schema::table('plants', function (Blueprint $table): void {
            $table->dropUnique('plants_id_org_unique');
        });

        Schema::table('areas', function (Blueprint $table): void {
            $table->dropUnique('areas_id_org_unique');
        });
    }
};
