<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de organizaciones / empresas.
     *
     * Sirve como raíz de la separación de responsabilidades por organización:
     * toda entidad operativa de dominio vincula a una organización mediante
     * organization_id.
     *
     * Idempotente: si la tabla ya existe (BD real previa), solo agrega lo
     * que falte; si no existe, la crea completa.
     */
    public function up(): void
    {
        if (!Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->string('name', 150);
                $table->string('code', 50)->unique();
                $table->string('tax_id', 50)->nullable();
                $table->text('address')->nullable();
                $table->text('description')->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('email', 150)->nullable();
                $table->boolean('status')->default(true);
                $table->softDeletes();
                $table->timestamps();

                $table->index(['status'], 'organizations_status_idx');
            });
        } else {
            // Tabla ya existe: asegurar columnas e índices faltantes sin destruir datos.
            $columns = collect(Schema::getColumnListing('organizations'));
            foreach (['tax_id', 'address', 'description', 'phone', 'email'] as $col) {
                if (!$columns->contains($col)) {
                    Schema::table('organizations', function (Blueprint $table) use ($col) {
                        $table->{$col === 'tax_id' || $col === 'phone' ? 'string' : 'text'}($col, $col === 'tax_id' ? 50 : null)->nullable();
                    });
                }
            }
            if (!$columns->contains('status')) {
                Schema::table('organizations', function (Blueprint $table) {
                    $table->boolean('status')->default(true);
                });
            }
            if (!$columns->contains('deleted_at')) {
                Schema::table('organizations', function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
            if (!$columns->contains('created_at') || !$columns->contains('updated_at')) {
                Schema::table('organizations', function (Blueprint $table) {
                    $table->timestamps();
                });
            }
        }

        // Asegurar índice único sobre code (idempotente, consulta directa por MariaDB)
        $hasUniqueIdx = DB::select("SHOW INDEX FROM `organizations` WHERE Key_name = 'organizations_code_unique'");
        if (empty($hasUniqueIdx)) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->unique(['code'], 'organizations_code_unique');
            });
        }
        $hasStatusIdx = DB::select("SHOW INDEX FROM `organizations` WHERE Key_name = 'organizations_status_idx'");
        if (empty($hasStatusIdx)) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->index(['status'], 'organizations_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
