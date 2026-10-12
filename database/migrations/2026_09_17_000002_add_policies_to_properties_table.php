<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Terms and the cancellation policy belong to the property, not the site:
     * a studio and a villa rarely carry the same rules, and an owner must be
     * able to change theirs without touching anyone else's.
     *
     * Existing properties are backfilled with the house defaults so no listing
     * is left showing no terms at all.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('terms_and_conditions')->nullable()->after('features');
            $table->text('cancellation_policy')->nullable()->after('terms_and_conditions');
            $table->decimal('cancellation_fee_percent', 5, 2)->nullable()->after('cancellation_policy');
        });

        DB::table('properties')->update([
            'terms_and_conditions' => config('kozy.policies.terms'),
            'cancellation_policy' => config('kozy.policies.cancellation'),
            'cancellation_fee_percent' => config('kozy.policies.cancellation_fee_percent'),
        ]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'terms_and_conditions',
                'cancellation_policy',
                'cancellation_fee_percent',
            ]);
        });
    }
};
