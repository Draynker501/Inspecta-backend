<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('checklist_section_id', 36);
            $table->text('question_text');
            $table->text('description')->nullable();
            $table->string('answer_type', 30);
            $table->integer('display_order')->default(0);
            $table->boolean('required')->default(true);
            $table->boolean('allows_evidence')->default(false);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->foreign('checklist_section_id')->references('id')->on('checklist_sections')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
