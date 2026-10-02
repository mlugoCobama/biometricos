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
        Schema::table('companies', function (Blueprint $table) {
            // Cantidad de columnas/registros de asistencia a contemplar en reportes: 2 (Entrada/Salida) o 4 (Entrada/Salida Comer/Entrada Comer/Salida)
            $table->integer('report_slots')->default(2)->after('report_emails');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('report_slots');
        });
    }
};
