<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->enum('system_key', ['admin', 'supervisor', 'inspector'])
                ->nullable()
                ->after('name');

            $table->unique(
                ['organization_id', 'system_key'],
                'roles_org_system_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_org_system_key_unique');
            $table->dropColumn('system_key');
        });
    }
};
