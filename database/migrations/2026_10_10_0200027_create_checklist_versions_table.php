<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_versions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('checklist_id', 36);
            $table->string('version', 20);
            $table->string('status', 30)->default('draft');
            $table->char('created_by', 36);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('checklist_id')->references('id')->on('checklists')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['checklist_id', 'version'], 'checklist_versions_checklist_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_versions');
    }
};
