<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->onDelete('cascade');

            $table->string('name');
            $table->string('serial_number')->unique();
            $table->string('ip_address')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('push_version')->nullable();
            $table->string('firmware_version')->nullable();
            $table->integer('user_count')->default(0);
            $table->integer('fingerprint_count')->default(0);
            $table->integer('att_log_count')->default(0);
            $table->timestamp('last_heartbeat')->nullable();
            $table->string('status')->default('offline');
            $table->string('location')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
