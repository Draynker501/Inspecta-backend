<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade columna type a organizations (M1.5, P1.2).
     *
     * NOT NULL con default 'client': seguro sobre filas existentes, todas
     * pasan a cliente. El índice (type, status) sirve al resolver de tenant
     * y al selector de organizaciones.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('type', 20)->notNull()->default('client')->after('code');
            $table->index(['type', 'status'], 'organizations_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex('organizations_type_status_idx');
            $table->dropColumn('type');
        });
    }
};
