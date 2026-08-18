<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->integer('finger_index')->default(0);
            $table->text('template_data');
            $table->integer('template_version')->default(10);
            $table->integer('size')->default(0);
            $table->boolean('valid')->default(true);
            $table->timestamps();

            $table->unique(['employee_id', 'finger_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_fingerprints');
    }
};
