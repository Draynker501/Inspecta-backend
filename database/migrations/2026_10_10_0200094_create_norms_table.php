<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('norms', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('code', 100);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('issuing_body', 150)->nullable();
            $table->string('version', 50)->nullable();
            $table->date('effective_date')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['code', 'version'], 'norms_code_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('norms');
    }
};
