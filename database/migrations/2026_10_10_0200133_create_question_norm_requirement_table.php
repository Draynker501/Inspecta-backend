<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_norm_requirement', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('question_id', 36);
            $table->char('norm_requirement_id', 36);
            $table->timestamps();

            $table->foreign('question_id')->references('id')->on('questions')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('norm_requirement_id')->references('id')->on('norm_requirements')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['question_id', 'norm_requirement_id'], 'qnr_question_req_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_norm_requirement');
    }
};
