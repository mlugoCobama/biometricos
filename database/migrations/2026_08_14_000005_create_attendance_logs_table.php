<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');

            $table->foreignId('device_id')->constrained('devices')->onDelete('cascade');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->string('pin');
            $table->dateTime('punch_time');
            $table->integer('punch_type')->default(0);
            $table->integer('verify_type')->default(1);
            $table->string('work_code')->nullable();
            $table->string('raw_line')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'punch_time']);
            $table->unique(['device_id', 'pin', 'punch_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
