<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corrective_actions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('incident_id', 36);
            $table->char('assigned_to', 36);
            $table->text('description');
            $table->string('priority', 30)->default('medium');
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('observations')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('incident_id')->references('id')->on('incidents')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('assigned_to')->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();

            $table->index(['incident_id', 'status'], 'corrective_actions_incident_status_idx');

            $table->index(['assigned_to', 'status'], 'corrective_actions_assignee_status_idx');

            $table->index(['due_date', 'status'], 'corrective_actions_due_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrective_actions');
    }
};
