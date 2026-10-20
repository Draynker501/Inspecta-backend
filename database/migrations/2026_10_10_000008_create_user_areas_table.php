<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_areas', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('area_id', 36);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();

            $table->foreign('area_id')->references('id')->on('areas')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['user_id', 'area_id'], 'user_areas_user_area_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_areas');
    }
};
