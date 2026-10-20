<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('supervisor_id', 36)->nullable();
            $table->char('checklist_version_id', 36);
            $table->char('plant_id', 36);
            $table->char('area_id', 36);
            $table->char('production_line_id', 36)->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('supervisor_confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('supervisor_observations')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_description', 255)->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('supervisor_id')->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('checklist_version_id')->references('id')->on('checklist_versions')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('plant_id')->references('id')->on('plants')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('area_id')->references('id')->on('areas')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('production_line_id')->references('id')->on('production_lines')->restrictOnDelete()->cascadeOnUpdate();

            $table->index(['plant_id', 'area_id', 'production_line_id'], 'inspections_location_idx');

            $table->index(['user_id', 'status'], 'inspections_user_status_idx');

            $table->index(['supervisor_id', 'status'], 'inspections_supervisor_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
