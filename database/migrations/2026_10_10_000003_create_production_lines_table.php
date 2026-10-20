<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_lines', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('area_id', 36);
            $table->string('name', 150);
            $table->string('code', 50);
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->foreign('area_id')->references('id')->on('areas')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['area_id', 'code'], 'prod_lines_area_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_lines');
    }
};
