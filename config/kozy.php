<?php

return [

    /*
    |--------------------------------------------------------------------------
    | VAT
    |--------------------------------------------------------------------------
    |
    | Listed nightly rates are VAT inclusive: what a guest sees is what they
    | pay. The rate below is only used to show how much of that total is tax,
    | which Mauritian guests expect to see itemised on a receipt. Changing it
    | changes the split shown, never the amount charged.
    |
    */

    'vat' => [
        'rate' => (float) env('KOZY_VAT_RATE', 0.15),
        'label' => env('KOZY_VAT_LABEL', 'VAT'),
        'inclusive' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Display currencies
    |--------------------------------------------------------------------------
    |
    | A guest may browse in a familiar currency, but the booking is always
    | priced, charged and refunded in the property's own currency - these rates
    | are a convenience for reading prices, not an exchange service, and the UI
    | says so wherever a converted figure appears.
    |
    | Rates are expressed per one unit of `base`. They are edited by hand, so
    | keep them approximate and review them periodically.
    |
    */

    'currency' => [
        'base' => 'MUR',

        'rates' => [
            'MUR' => 1.0,
            'USD' => 0.022,
            'EUR' => 0.020,
            'GBP' => 0.017,
            'ZAR' => 0.40,
        ],

        'labels' => [
            'MUR' => 'Mauritian rupee',
            'USD' => 'US dollar',
            'EUR' => 'Euro',
            'GBP' => 'Pound sterling',
            'ZAR' => 'South African rand',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest reminders
    |--------------------------------------------------------------------------
    |
    | How many days before check-in the reminder goes out. The scheduled command
    | runs daily and stamps each booking, so changing this affects stays that
    | have not been reminded yet and never re-mails one that has.
    |
    */

    'reminders' => [
        'check_in_days' => (int) env('KOZY_CHECK_IN_REMINDER_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prefilled policy text
    |--------------------------------------------------------------------------
    |
    | Each property carries its own terms and cancellation policy. These are the
    | defaults an owner starts from when they add a property - they are copied
    | into the record on create, not read at display time, so editing them here
    | never silently rewrites the terms a guest already agreed to.
    |
    */

    'policies' => [
        'cancellation_fee_percent' => (float) env('KOZY_CANCELLATION_FEE_PERCENT', 25),

        /*
         * What a guest gets back, by how much notice they gave. Read top down;
         * the first tier whose notice threshold is met wins, and a stay that
         * has already started matches none of them and is refunded nothing.
         *
         * These thresholds are the ones written into the cancellation text
         * prefilled on every property - change one and change the other, or
         * guests will be quoted something their policy does not say.
         */
        'cancellation_tiers' => [
            // More than a fortnight out: nothing is kept.
            ['min_days_notice' => 14, 'rule' => 'full'],
            // Inside a fortnight: the property's own fee percentage is kept.
            ['min_days_notice' => 7, 'rule' => 'fee'],
            // Inside a week: the first night is kept.
            ['min_days_notice' => 0, 'rule' => 'first_night'],
        ],

        'terms' => <<<'TEXT'
        ## House rules

        - Check-in is from the time shown on the listing; check-out is by the stated hour.
        - The number of guests must not exceed the capacity shown on the listing.
        - No parties or events without the owner's written agreement.
        - Smoking is not permitted indoors.
        - Pets are allowed only where the listing says so.

        ## The stay

        - The guest named on the booking is responsible for everyone in the party.
        - Please report any damage before departure; repair costs may be charged.
        - Quiet hours are between 10pm and 7am.

        ## Liability

        - Personal belongings remain the guest's responsibility throughout the stay.
        - The owner is not liable for interruptions to utilities outside their control.
        TEXT,

        'cancellation' => <<<'TEXT'
        ## Cancelling your stay

        - 14 days or more before check-in: full refund.
        - 7 to 13 days before check-in: the cancellation fee shown on this
          listing is kept, and the rest is refunded.
        - Less than 7 days before check-in: the first night is kept, and the
          rest is refunded.
        - On or after the check-in date: no refund.

        ## How refunds are made

        Refunds are returned to the method used to pay, and usually reach you
        within 5 to 10 working days of being approved.
        TEXT,
    ],

];
