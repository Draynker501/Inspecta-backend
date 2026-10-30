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
     * Usa DROP INDEX IF EXISTS porque MariaDB puede auto-eliminar el índice
     * cuando se dropea la FK compuesta que lo referenciaba (paso 2.3 down()).
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
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();

        if ($driver !== 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE `roles` DROP INDEX IF EXISTS `roles_id_org_unique`');
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE `plants` DROP INDEX IF EXISTS `plants_id_org_unique`');
            \Illuminate\Support\Facades\DB::statement('ALTER TABLE `areas` DROP INDEX IF EXISTS `areas_id_org_unique`');
        }
    }
};
