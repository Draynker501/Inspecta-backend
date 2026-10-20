<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corrective_action_updates', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('corrective_action_id', 36);
            $table->char('user_id', 36);
            $table->string('status', 30);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('corrective_action_id')->references('id')->on('corrective_actions')->cascadeOnDelete()->cascadeOnUpdate();

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();

            $table->index(['corrective_action_id', 'created_at'], 'action_updates_action_created_idx');

            $table->index(['user_id', 'created_at'], 'action_updates_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrective_action_updates');
    }
};
