<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who cancelled a stay, when, and why. Without this the status alone says
     * a booking ended but never explains it, which is the first thing anyone
     * asks when a guest disputes a fee.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('check_in_reminder_sent_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->foreignId('cancelled_by_id')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
