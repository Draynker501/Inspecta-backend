<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('role_id', 36);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();

            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['user_id', 'role_id'], 'user_roles_user_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
