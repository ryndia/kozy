<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The first cut of the default cancellation text said "more than 14 days"
     * for a full refund, while the tiers refund in full *at* 14 days, and it
     * quoted a flat 25% when the fee is per-property. Both are now fixed in
     * config, but properties carry their own copy of the text.
     *
     * Only rows still holding the old wording verbatim are rewritten - anything
     * an owner has edited is theirs, and is left exactly as they wrote it.
     */
    public function up(): void
    {
        DB::table('properties')
            ->where('cancellation_policy', self::PREVIOUS_DEFAULT)
            ->update(['cancellation_policy' => config('kozy.policies.cancellation')]);
    }

    public function down(): void
    {
        DB::table('properties')
            ->where('cancellation_policy', config('kozy.policies.cancellation'))
            ->update(['cancellation_policy' => self::PREVIOUS_DEFAULT]);
    }

    private const PREVIOUS_DEFAULT = <<<'TEXT'
    ## Cancelling your stay

    - Cancel more than 14 days before check-in for a full refund.
    - Cancel between 14 and 7 days before check-in and a 25% fee applies.
    - Cancel within 7 days of check-in and the first night is non-refundable.
    - Cancellations on or after the check-in date are not refunded.

    ## How refunds are made

    Refunds are returned to the method used to pay, and usually reach you
    within 5 to 10 working days of being approved.
    TEXT;
};
