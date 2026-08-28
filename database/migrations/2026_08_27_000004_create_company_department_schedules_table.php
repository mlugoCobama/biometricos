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
        Schema::create('company_department_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index(); // null = Horario de departamento global compartido
            $table->string('department_name'); // ej: Administración, Operaciones, Ventas
            $table->time('schedule_entry')->default('08:00:00'); // Hora oficial de entrada
            $table->time('schedule_exit')->default('17:00:00');  // Hora oficial de salida
            $table->time('meal_start')->nullable();              // Inicio comida opcional (ej: 13:00:00)
            $table->time('meal_end')->nullable();                // Fin comida opcional (ej: 14:00:00)
            $table->integer('tolerance_minutes')->default(15);    // Tolerancia en minutos para retardo
            $table->decimal('expected_daily_hours', 4, 2)->default(8.00); // Horas diarias requeridas (ej: 8.00)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_department_schedules');
    }
};
