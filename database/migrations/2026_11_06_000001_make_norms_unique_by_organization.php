<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $hasDuplicates = DB::table('norms')
            ->select('code', 'version')
            ->groupBy('code', 'version')
            ->havingRaw('COUNT(DISTINCT organization_id) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                'No se puede revertir la migración: existen normas con el mismo (code, version) en distintas organizaciones.'
            );
        }

        Schema::table('norms', function (Blueprint $table): void {
            $table->dropUnique('norms_org_code_version_unique');
            $table->unique(['code', 'version'], 'norms_code_version_unique');
        });
    }
};
