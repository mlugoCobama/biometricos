<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index(); // null = Aplica a todas las empresas
            $table->date('holiday_date'); // Fecha del día festivo (YYYY-MM-DD)
            $table->string('description'); // ej: Día de la Independencia, Navidad, Año Nuevo
            $table->boolean('is_mandatory')->default(true); // Descanso obligatorio
            $table->timestamps();

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->onDelete('cascade');

            $table->unique(['company_id', 'holiday_date'], 'unique_company_holiday');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_holidays');
    }
};
