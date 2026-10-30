<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de dispositivos móviles para sincronización.
     *
     * Cada dispositivo tiene un `device_id` persistente generado por la
     * PWA. Permite revocar acceso y rastrear la última sincronización.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('device_id', 36)->unique();
            $table->char('user_id', 36)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['device_id'], 'devices_device_id_idx');
            $table->index(['user_id'], 'devices_user_idx');
            $table->index(['revoked_at'], 'devices_revoked_idx');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
