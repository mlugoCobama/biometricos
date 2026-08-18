<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (!Schema::hasColumn('devices', 'intercompania')) {
                $table->string('intercompania')->nullable()->index()->after('company_id');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'intercompania')) {
                $table->string('intercompania')->nullable()->index()->after('company_id');
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_logs', 'intercompania')) {
                $table->string('intercompania')->nullable()->index()->after('company_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'intercompania')) {
                try { $table->dropIndex(['intercompania']); } catch (\Throwable $e) {}
                $table->dropColumn('intercompania');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'intercompania')) {
                try { $table->dropIndex(['intercompania']); } catch (\Throwable $e) {}
                $table->dropColumn('intercompania');
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_logs', 'intercompania')) {
                try { $table->dropIndex(['intercompania']); } catch (\Throwable $e) {}
                $table->dropColumn('intercompania');
            }
        });
    }
};
