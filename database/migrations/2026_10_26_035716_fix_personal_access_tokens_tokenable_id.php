<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige tokenable_id en personal_access_tokens: de bigInteger a char(36).
     *
     * Sanctum usa bigInteger por defecto para tokenable_id (morphs), pero el
     * proyecto utiliza UUID almacenados como CHAR(36) en todas las tablas,
     * incluyendo users. Este cambio permite guardar tokens de Sanctum
     * asociados a usuarios con ID UUID.
     *
     * Idempotente: si la columna ya es char(36), no realiza ningún cambio.
     * Portable: usa la introspección de esquema de Laravel, válida tanto en
     * MariaDB/MySQL como en SQLite (usado por la suite de pruebas).
     */
    public function up(): void
    {
        if (!Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $column = collect(Schema::getColumns('personal_access_tokens'))
            ->firstWhere('name', 'tokenable_id');

        if ($column === null) {
            return;
        }

        $type = strtolower((string) ($column['type'] ?? $column['type_name'] ?? ''));
        $driver = Schema::getConnection()->getDriverName();

        // Un CHAR es compatible con UUID; en SQLite Laravel materializa CHAR como
        // VARCHAR (sin longitud fija), que también almacena el UUID correctamente.
        if (str_starts_with($type, 'char') || ($driver === 'sqlite' && str_starts_with($type, 'varchar'))) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->char('tokenable_id', 36)->change();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->bigInteger('tokenable_id')->change();
        });
    }
};
