<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
     */
    public function up(): void
    {
        if (!Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $columns = collect(DB::select("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'personal_access_tokens' AND COLUMN_NAME = 'tokenable_id'"));

        if ($columns->isNotEmpty() && !($columns->first()->DATA_TYPE === 'char' && $columns->first()->CHARACTER_MAXIMUM_LENGTH === 36)) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->char('tokenable_id', 36)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->bigInteger('tokenable_id')->change();
        });
    }
};
