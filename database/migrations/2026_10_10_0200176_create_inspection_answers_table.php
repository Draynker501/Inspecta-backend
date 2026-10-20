<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_answers', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('inspection_id', 36);
            $table->char('question_id', 36);
            $table->text('answer')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->foreign('inspection_id')->references('id')->on('inspections')->cascadeOnDelete()->cascadeOnUpdate();

            $table->foreign('question_id')->references('id')->on('questions')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['inspection_id', 'question_id'], 'inspection_answers_inspection_question_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_answers');
    }
};
