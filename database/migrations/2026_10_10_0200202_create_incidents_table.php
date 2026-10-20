<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('inspection_id', 36)->nullable();
            $table->char('question_id', 36)->nullable();
            $table->char('reported_by', 36);
            $table->char('plant_id', 36);
            $table->char('area_id', 36);
            $table->char('production_line_id', 36)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('title', 200);
            $table->text('description');
            $table->string('risk_level', 30);
            $table->string('status', 30)->default('open');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_description', 255)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->foreign('inspection_id')->references('id')->on('inspections')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('question_id')->references('id')->on('questions')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('reported_by')->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('plant_id')->references('id')->on('plants')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('area_id')->references('id')->on('areas')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('production_line_id')->references('id')->on('production_lines')->restrictOnDelete()->cascadeOnUpdate();

            $table->index(['plant_id', 'area_id', 'production_line_id'], 'incidents_location_idx');

            $table->index(['status', 'risk_level'], 'incidents_status_risk_idx');

            $table->index(['reported_by', 'status'], 'incidents_reporter_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
