<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much of a payment has been given back.
     *
     * A partly refunded payment is still a paid payment, so the status column
     * is left alone until the whole amount is returned. This is what makes the
     * difference between the two readable.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
        });
    }
};
