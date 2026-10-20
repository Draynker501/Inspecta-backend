<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidences', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('inspection_id', 36);
            $table->char('inspection_answer_id', 36)->nullable();
            $table->char('incident_id', 36)->nullable();
            $table->char('uploaded_by', 36);
            $table->string('file_name', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->foreign('inspection_id')->references('id')->on('inspections')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('inspection_answer_id')->references('id')->on('inspection_answers')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('incident_id')->references('id')->on('incidents')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();

            $table->index(['inspection_id'], 'evidences_inspection_idx');

            $table->index(['inspection_answer_id'], 'evidences_answer_idx');

            $table->index(['incident_id'], 'evidences_incident_idx');

            $table->index(['uploaded_by'], 'evidences_uploader_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidences');
    }
};
