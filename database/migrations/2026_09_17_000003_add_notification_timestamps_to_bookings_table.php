<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest mail is sent from a scheduled command and from the payment path,
     * both of which can run more than once for the same stay. These stamps are
     * what stops a guest being mailed the same thing twice.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('confirmation_sent_at')->nullable()->after('total_price');
            $table->timestamp('check_in_reminder_sent_at')->nullable()->after('confirmation_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['confirmation_sent_at', 'check_in_reminder_sent_at']);
        });
    }
};
