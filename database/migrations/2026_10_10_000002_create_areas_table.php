<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('plant_id', 36);
            $table->string('name', 150);
            $table->string('code', 50);
            $table->string('type', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->foreign('plant_id')->references('id')->on('plants')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['plant_id', 'code'], 'areas_plant_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
