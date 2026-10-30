<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla de cambios append-only (change log).
     *
     * Cada fila representa un cambio ocurrido en un recurso de negocio.
     * Sirve como cursor de sincronización incremental para la PWA:
     * el cliente guarda el último `seq` recibido y solicita deltas a
     * partir de ese punto.
     *
     * Las operaciones `delete` actúan como tombstones: el cliente debe
     * eliminar su copia local del recurso indicado.
     */
    public function up(): void
    {
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->bigInteger('seq')->unique();
            $table->string('resource', 50);
            $table->char('resource_id', 36);
            $table->char('organization_id', 36);
            $table->enum('operation', ['upsert', 'delete']);
            $table->longText('payload')->nullable();
            $table->timestamp('changed_at');

            // Índices para consulta incremental por cursor y por recurso
            $table->index(['seq'], 'sync_changes_seq_idx');
            $table->index(['resource', 'resource_id'], 'sync_changes_resource_idx');
            $table->index(['organization_id', 'seq'], 'sync_changes_org_seq_idx');
            $table->index(['changed_at'], 'sync_changes_changed_at_idx');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
    }
};
