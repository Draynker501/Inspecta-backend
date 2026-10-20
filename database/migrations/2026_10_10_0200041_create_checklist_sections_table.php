<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_sections', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('checklist_version_id', 36);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->foreign('checklist_version_id')->references('id')->on('checklist_versions')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_sections');
    }
};
