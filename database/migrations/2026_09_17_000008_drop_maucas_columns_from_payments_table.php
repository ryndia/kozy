<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Card payments are the only kind now, so the QR-era columns go.
     *
     * `provider_payload` stays - a Stripe webhook body is worth keeping for the
     * same audit reasons a switch notification was - and `transaction_ref`,
     * which holds the PaymentIntent id, gains the index a webhook needs to find
     * its payment by.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropColumn(['provider_reference', 'qr_payload', 'expires_at']);

            $table->index('transaction_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['transaction_ref']);

            $table->string('provider_reference')->nullable()->after('transaction_ref');
            $table->text('qr_payload')->nullable()->after('provider_reference');
            $table->timestamp('expires_at')->nullable()->after('qr_payload');

            $table->unique('provider_reference');
        });
    }
};
