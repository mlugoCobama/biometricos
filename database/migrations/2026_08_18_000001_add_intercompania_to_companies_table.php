<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'intercompania')) {
                $table->string('intercompania')->nullable()->unique()->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'intercompania')) {
                // Drop unique index first if using drivers like sqlite/mysql
                try {
                    $table->dropUnique(['intercompania']);
                } catch (\Throwable $e) {
                    // Ignore if unique index doesn't exist
                }
                $table->dropColumn('intercompania');
            }
        });
    }

};
