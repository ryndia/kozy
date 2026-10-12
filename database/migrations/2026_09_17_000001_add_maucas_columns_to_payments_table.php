<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MauCAS settles out of band: the guest scans a QR in their banking app and
     * the switch tells us about it afterwards, through a callback or a status
     * poll. That needs somewhere to keep the QR we handed out, the reference the
     * acquirer knows the transaction by, and the raw notification we acted on.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_reference')->nullable()->after('transaction_ref');
            $table->text('qr_payload')->nullable()->after('provider_reference');
            $table->timestamp('expires_at')->nullable()->after('qr_payload');
            $table->json('provider_payload')->nullable()->after('expires_at');

            // Callbacks arrive keyed by the acquirer's reference, and they can
            // arrive more than once for the same transaction.
            $table->unique('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['provider_reference']);
            $table->dropColumn(['provider_reference', 'qr_payload', 'expires_at', 'provider_payload']);
        });
    }
};
