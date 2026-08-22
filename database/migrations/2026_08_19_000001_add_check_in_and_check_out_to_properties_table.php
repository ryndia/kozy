<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (! Schema::hasColumn('properties', 'check_in')) {
                $table->time('check_in')->nullable()->after('lng');
            }

            if (! Schema::hasColumn('properties', 'check_out')) {
                $table->time('check_out')->nullable()->after('check_in');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'check_out')) {
                $table->dropColumn('check_out');
            }

            if (Schema::hasColumn('properties', 'check_in')) {
                $table->dropColumn('check_in');
            }
        });
    }
};
