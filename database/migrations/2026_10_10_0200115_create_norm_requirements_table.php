<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('norm_requirements', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('norm_id', 36);
            $table->string('code', 100);
            $table->string('section', 100)->nullable();
            $table->text('description');
            $table->timestamps();

            $table->foreign('norm_id')->references('id')->on('norms')->restrictOnDelete()->cascadeOnUpdate();

            $table->unique(['norm_id', 'code'], 'norm_req_norm_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('norm_requirements');
    }
};
