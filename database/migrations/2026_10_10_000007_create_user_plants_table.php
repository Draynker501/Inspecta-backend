<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_plants', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->char('plant_id', 36);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();

            $table->foreign('plant_id')->references('id')->on('plants')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['user_id', 'plant_id'], 'user_plants_user_plant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_plants');
    }
};
