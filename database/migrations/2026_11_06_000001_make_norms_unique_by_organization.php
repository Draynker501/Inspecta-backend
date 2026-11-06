<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('norms', function (Blueprint $table): void {
            $table->dropUnique('norms_code_version_unique');
            $table->unique(['organization_id', 'code', 'version'], 'norms_org_code_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('norms', function (Blueprint $table): void {
            $table->dropUnique('norms_org_code_version_unique');
            $table->unique(['code', 'version'], 'norms_code_version_unique');
        });
    }
};
