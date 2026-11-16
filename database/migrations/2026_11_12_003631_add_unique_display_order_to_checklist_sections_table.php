<?php

use App\Support\Ehs\SqliteOrderRebuild;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $duplicates = DB::table('checklist_sections')
            ->select('checklist_version_id', 'display_order')
            ->groupBy('checklist_version_id', 'display_order')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Existen secciones con display_order repetido dentro de una misma versión. Corrige los datos antes de migrar.');
        }

        if (DB::table('checklist_sections')->where('display_order', '<', 1)->exists()) {
            throw new RuntimeException('Existen secciones con display_order menor que 1.');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            SqliteOrderRebuild::run(DB::connection(), [[
                'table' => 'checklist_sections', 'default' => [0, 1], 'index' => 'add',
            ]]);

            return;
        }

        Schema::table('checklist_sections', function (Blueprint $table) {
            $table->integer('display_order')->default(1)->change();
            $table->unique(['checklist_version_id', 'display_order'], 'sections_version_order_unique');
        });

        // El índice provisional de down deja de ser necesario al reponer el UNIQUE.
        if ($this->hasMariaDbIndex('ehs_sections_version_fk_support')) {
            Schema::table('checklist_sections', function (Blueprint $table) {
                $table->dropIndex('ehs_sections_version_fk_support');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            SqliteOrderRebuild::run(DB::connection(), [[
                'table' => 'checklist_sections', 'default' => [1, 0], 'index' => 'drop',
            ]]);

            return;
        }

        // MariaDB puede usar el UNIQUE como índice de soporte de la FK. Crear
        // primero uno alternativo evita el error 1553 sin eliminar la FK.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            && ! $this->hasMariaDbIndex('ehs_sections_version_fk_support')) {
            Schema::table('checklist_sections', function (Blueprint $table) {
                $table->index('checklist_version_id', 'ehs_sections_version_fk_support');
            });
        }

        Schema::table('checklist_sections', function (Blueprint $table) {
            $table->dropUnique('sections_version_order_unique');
            $table->integer('display_order')->default(0)->change();
        });
    }

    private function hasMariaDbIndex(string $name): bool
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return false;
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'checklist_sections')
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
