<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de membresías usuario–organización (M1.5, P1).
     *
     * Una fila por par (user_id, organization_id). Fuente de pertenencia
     * paralela a users.organization_id (que se conserva como adscripción de
     * origen y no se usa para autorizar en esta fase).
     *
     * Sin soft delete: status + revoked_at, fila única permanente.
     */
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('organization_id', 36);
            $table->string('status', 20)->notNull()->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['user_id', 'organization_id'], 'organization_memberships_user_org_unique');
            $table->index(['organization_id', 'status'], 'organization_memberships_org_status_idx');
            $table->index(['user_id', 'status'], 'organization_memberships_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
    }
};
