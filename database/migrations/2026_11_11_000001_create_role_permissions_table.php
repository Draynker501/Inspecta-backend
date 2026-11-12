<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('role_id', 36);
            $table->char('organization_id', 36);
            $table->string('permission', 50);
            $table->timestamps();

            $table->foreign(['role_id', 'organization_id'])
                ->references(['id', 'organization_id'])
                ->on('roles')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['role_id', 'organization_id', 'permission'], 'role_permissions_role_org_perm_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
