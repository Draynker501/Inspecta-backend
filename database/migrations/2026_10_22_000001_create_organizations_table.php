<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de organizaciones / empresas.
     *
     * Sirve como raíz de la separación de responsabilidades por organización:
     * toda entidad operativa de dominio vincula a una organización mediante
     * organization_id.
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

                $table->index(['code'], 'organizations_code_unique');
                $table->index(['status'], 'organizations_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
