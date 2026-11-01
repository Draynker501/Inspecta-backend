<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill de membresías (M1.5, P1.3).
     *
     * Invoca MembershipBackfill::run() para poblar organization_memberships
     * a partir de users.organization_id. Es idempotente.
     *
     * down() no hace nada: la tabla organization_memberships se elimina con
     * el down() de la migración del paso 1.1 (create_organization_memberships_table).
     */
    public function up(): void
    {
        \App\Support\Tenancy\MembershipBackfill::run();
    }

    public function down(): void
    {
        // Sin operación: la tabla se elimina con el down() del paso 1.1.
        // El backfill solo escribe en organization_memberships (tabla nueva).
    }
};
