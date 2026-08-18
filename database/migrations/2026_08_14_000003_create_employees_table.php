<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');

            $table->string('pin');
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('document_number')->nullable();
            $table->string('card_number')->nullable();
            $table->integer('privilege')->default(0);
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['company_id', 'pin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
