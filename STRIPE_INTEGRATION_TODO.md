# Stripe Checkout integration — remaining steps

Kozy takes booking payments through Stripe Checkout's **embedded form**: a
Stripe-hosted form shown in the *Pay now* dialog on **My bookings**. This file is
the single source of truth for what is left to set up.

## Values to Replace

**No placeholder values remain.** The two `sample_only` parameters from Checkout
Studio already have real values set from the booking being paid.

**Files containing these values:**
- [app/Http/Controllers/Api/PaymentController.php](app/Http/Controllers/Api/PaymentController.php) (`createCheckoutSession`)

| Field | Current Value | What to Set |
|-------|--------------|-------------|
| mode | `payment` | Nothing. A stay is a one-off charge, so `payment` is correct. Only change it to `subscription` if Kozy ever sells recurring plans. |
| line_items | One item built from the booking: `price_data` with the booking's currency, its total in minor units (Rs 13,500 → `1350000`), and a name like "Sea-view villa · 3 nights" | Nothing. Checkout Studio's sample uses a fixed Stripe Price ID (`price_...`). That can't work for bookings, because every stay has its own total, so no catalogue price fits. |

## Configured Parameters

These parameters were configured in Checkout Studio and are already set exactly
as given.

**Files containing these parameters:**
- [app/Http/Controllers/Api/PaymentController.php](app/Http/Controllers/Api/PaymentController.php): the session parameters (`createCheckoutSession`) and the pinned API version (`startCheckoutSession`)
- [resources/js/components/stripe-payment-dialog.tsx](resources/js/components/stripe-payment-dialog.tsx): the form's `appearance` and the `custom_checkout_payment_form_1` beta flag
- [resources/views/app.blade.php](resources/views/app.blade.php): loads Stripe.js (`dahlia` build)

| Parameter | Value |
|-----------|-------|
| ui_mode | `form` (stripe-php is v21.2.0; SDKs older than 21.0.0 call this `custom`) |
| billing_address_collection | `auto` |
| phone_number_collection | `{ enabled: false }` |
| automatic_tax | `{ enabled: false }` |
| submit_type | `auto` |
| name_collection | `individual: { enabled: true }` |
| saved_payment_method_options | `{ payment_method_save: enabled }` |
| integration_identifier | `custom_embedded_web_0001` |
| payment_method_collection | Not sent. It is only valid for subscriptions, and this is payment mode. |
| API version (server) | `2026-03-25.dahlia; custom_checkout_payment_form_preview=v1`, on the Checkout Session client only |
| Stripe.js beta (browser) | `custom_checkout_payment_form_1` |
| Form layout | `expanded` |
| Appearance | `theme: flat`, `labels: auto`, `inputs: spaced`, `borderRadius 4px`, `colorBackground #ffffff`, `colorDanger #df1b41`, `colorPrimary #0570de`, `colorSuccess #00c853`, `colorText #30313d`, `fontFamily default`, `fontSizeBase 16px`, `spacingUnit 4px` |

`shipping_address_collection` and the business option of `name_collection` are
no longer configured in Checkout Studio, so both were removed.

Kozy also adds parameters of its own:

- `client_reference_id`, `metadata.booking_id` and `metadata.booking_reference`
  tie the session to the booking, so the webhook can confirm the right stay.
- `customer_creation: always` is needed by `saved_payment_method_options`.
  Stripe rejects that option on a session that has no Customer (*"requires a
  customer"*). Kozy doesn't store Stripe customer IDs, so Checkout creates one
  for each payment.

> **About saved cards:** because every payment gets a new Customer, a card a
> guest saves is not offered on their next booking. To reuse saved cards, store
> the Stripe customer id on the user, pass it as `customer`, and drop
> `customer_creation`. See *Next steps*.

## Setup

### Environment variables

These go in `.env`, which is gitignored. The names match
[config/services.php](config/services.php):

```env
STRIPE_KEY=pk_test_...            # publishable key
STRIPE_SECRET=sk_test_...         # secret key, server only
STRIPE_WEBHOOK_SECRET=whsec_...   # from `stripe listen` locally, or the dashboard endpoint in production
```

There is no `VITE_` variable. The publishable key is sent to the browser in the
response from `/api/payments/checkout-session`, so it is never compiled into the
JavaScript bundle. Changing keys only needs a config refresh
(`php artisan config:clear`), not an asset rebuild.

The test suite pins all three to empty values in `phpunit.xml`, so tests never
call Stripe.

### Dependencies

- `stripe/stripe-php` v21.2.0, already installed.
- Stripe.js is loaded from `https://js.stripe.com/dahlia/stripe.js` in the page
  `<head>`. It is not bundled or self-hosted (a PCI requirement), so there is no
  npm package for it.
- The Stripe CLI, needed for local webhooks, is in `~/.local/bin/stripe`.

### Local webhooks

Stripe can't reach `localhost`, so run this next to the dev server:

```bash
export PATH="$HOME/.local/bin:$PATH"
export STRIPE_API_KEY=$(grep '^STRIPE_SECRET=' .env | cut -d= -f2-)
stripe listen \
  --events checkout.session.completed,payment_intent.succeeded,payment_intent.payment_failed \
  --forward-to localhost:8000/api/payments/stripe/webhook
```

Without it, the payment goes through on Stripe but the booking stays
**pending**, because the webhook is what confirms it.

## Files

**New**
- [STRIPE_INTEGRATION_TODO.md](STRIPE_INTEGRATION_TODO.md): this file
- [tests/Feature/Bookings/StripeCheckoutTest.php](tests/Feature/Bookings/StripeCheckoutTest.php): the endpoint and webhook

**Changed**
- [app/Http/Controllers/Api/PaymentController.php](app/Http/Controllers/Api/PaymentController.php): `createCheckoutSession`, `checkoutCompleted`, `startCheckoutSession`
- [routes/api.php](routes/api.php): `POST /api/payments/checkout-session`, signed-in guests only
- [resources/js/components/stripe-payment-dialog.tsx](resources/js/components/stripe-payment-dialog.tsx): the embedded form replaces the Payment Element
- [resources/js/lib/bookings.ts](resources/js/lib/bookings.ts): `createCheckoutSession`, `fetchClientBooking`
- [resources/views/app.blade.php](resources/views/app.blade.php): Stripe.js `<script>`
- [deploy.sh](deploy.sh): the production CSP now allows Stripe's Checkout and Link hosts
- `package.json`: removed `@stripe/stripe-js` and `@stripe/react-stripe-js`, which are no longer used

## How it works

1. The guest clicks **Pay now**. The dialog calls `POST /api/payments/checkout-session`.
2. The server checks the booking is the guest's, isn't cancelled and isn't
   already paid. It opens a Checkout Session for the booking's total, stores the
   session id on the payment, and returns `{ client_secret, publishable_key }`.
3. The browser runs `Stripe(pk, { betas: ['custom_checkout_payment_form_1'] })`,
   then `initCheckoutFormSdk({ clientSecret, appearance })`, and mounts the form
   into `#checkout-form`.
4. The guest pays. The form's `confirm` event calls `actions.confirm(...)`, and
   card details go straight to Stripe.
5. Stripe sends **`checkout.session.completed`**. The webhook verifies the
   signature, finds the payment (by session id, or failing that by
   `metadata.booking_id`), checks the total matches, and confirms the booking.
   It also stores the PaymentIntent id, which refunds are issued against, and
   queues the confirmation email.
6. The dialog checks the booking every 1.5 s for up to 18 s and shows
   "confirmed" once the webhook has landed. If it's slower, the guest is told
   confirmation will follow by email.

Replayed events and the follow-up `payment_intent.succeeded` are idempotent, so
a booking is never settled twice. Cancellation refunds go back through Stripe
automatically (see DEPLOYMENT.md §5b).

## Testing

Use these test cards with any future expiry date, any CVC and any postcode:

| Card | Result |
|------|--------|
| `4242 4242 4242 4242` | Succeeds |
| `4000 0025 0000 3155` | Asks for 3-D Secure authentication |
| `4000 0000 0000 9995` | Declined (insufficient funds) |
| `4000 0000 0000 0002` | Declined (generic) |

To run through it: start the dev server and `stripe listen`, make a booking, go
to **My bookings → Pay now**, and pay with `4242…`. The booking should show as
confirmed within a couple of seconds.

`php artisan test` covers the endpoint and the webhook offline (223 tests).

## Next steps

- [ ] **Click through the form once in a browser.** Everything server-side was
      checked against your real test account: session creation with every
      parameter above, and a real signed `checkout.session.completed` reaching
      the new handler. The form itself is a preview Stripe.js API, and its
      browser behaviour can only be checked in a browser.
- [ ] **Register the production webhook.** In the dashboard, add
      `https://kozy.mu/api/payments/stripe/webhook` for
      `checkout.session.completed`, `payment_intent.succeeded` and
      `payment_intent.payment_failed`. Put that endpoint's signing secret in the
      server's `.env`; the local `stripe listen` secret won't work there.
- [ ] **Activate the Stripe account.** Stripe currently reports
      `charges_enabled: false`, so live keys can't take money yet. The account
      is registered in the US, and Stripe does not onboard businesses based in
      Mauritius, so activating needs a real US entity (for example through
      Stripe Atlas). The alternatives are in the conversation notes: Peach
      Payments or MIPS.
- [ ] **Swap to live keys** (`pk_live_` / `sk_live_`) on the server once activated.
- [ ] **Roll the test secret key.** It was pasted into a chat. Roll it in the
      dashboard and update `.env`.
- [ ] **Decide on saved cards.** `payment_method_save` is on, as configured, but
      saved cards are only offered again if the guest keeps the same Stripe
      Customer. To make that work, add a `stripe_customer_id` column to `users`,
      create the Customer on the guest's first payment, and pass
      `'customer' => $id` instead of `customer_creation`. If you don't want saved
      cards, turn the option off in Checkout Studio and remove both lines from
      `createCheckoutSession`.
- [ ] **Redirect-based payment methods:** if you enable any (bank redirects,
      some wallets), add `return_url` to the session. The current setup doesn't
      need one and Stripe accepts the session without it.
- [ ] **Delayed payment methods:** these complete the session as `unpaid` and
      settle later. If you enable one, also handle
      `checkout.session.async_payment_succeeded` in the webhook.
- [ ] **Optional cleanup:** `POST /api/payments/create-intent` (the previous
      Payment Element flow) is no longer used by the UI. It is still tested and
      harmless, and can be removed.

## Resources

- Stripe support: https://support.stripe.com
- Stripe MCP server: https://docs.stripe.com/mcp
- Content Security Policy for Stripe: https://docs.stripe.com/security/guide#content-security-policy
- Testing: https://docs.stripe.com/testing
