<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money owed back to a guest after a cancellation.
     *
     * A refund is recorded before it is paid, because the two do not always
     * happen together: a card refund settles inside the request, while a
     * payment taken any other way has to be returned by hand. Either way the
     * amount is computed once, at cancellation, and this row is what an admin
     * works from.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('amount', 10, 2);
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('MUR');

            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('method')->nullable();
            $table->string('provider_reference')->nullable();

            // Why the money is going back, and who decided so. A null actor is
            // the guest cancelling their own stay.
            $table->text('reason')->nullable();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
