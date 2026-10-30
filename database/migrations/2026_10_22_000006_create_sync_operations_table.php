<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de operaciones de sincronización aplicadas.
     *
     * Permite idempotencia: si la PWA reenvía la misma `operation_id`,
     * el servidor devuelve el mismo resultado sin duplicar efectos.
     * Cada operación puede tener un estado individual
     * (applied, duplicate, conflict, rejected, forbidden, skipped).
     */
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('operation_id', 36)->unique();
            $table->char('device_id', 36);
            $table->string('resource', 50);
            $table->char('resource_id', 36)->nullable();
            $table->enum('operation', ['create', 'update', 'delete']);
            $table->enum('status', [
                'applied',
                'duplicate',
                'conflict',
                'rejected',
                'forbidden',
                'skipped',
            ]);
            $table->longText('payload')->nullable();
            $table->longText('server_state')->nullable();
            $table->longText('errors')->nullable();
            $table->timestamp('created_at');

            // Índices para consulta por dispositivo y por operación
            $table->index(['device_id'], 'sync_operations_device_idx');
            $table->index(['operation_id'], 'sync_operations_operation_idx');
            $table->index(['resource', 'resource_id'], 'sync_operations_resource_idx');
            $table->index(['status'], 'sync_operations_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
    }
};
