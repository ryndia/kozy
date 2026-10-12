<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest booking a stay pays first and chooses a password afterwards, so
     * for a while their account holds only a random placeholder. This marks
     * those accounts. It defaults to false, so every other way an account is
     * created - registration, the admin panel, the console - is unaffected.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_set_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_set_password');
        });
    }
};
