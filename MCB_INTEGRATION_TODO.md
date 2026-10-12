# MCB payment gateway: remaining steps

Kozy will take card payments through **MCB's online payment gateway**. It runs
on Mastercard's gateway platform (MPGS, "Mastercard Gateway"). Until that is
connected, card payments are **simulated**: the *Pay now* dialog approves any
valid card number and no money moves. See [DEPLOYMENT.md §5](DEPLOYMENT.md).

## 1. Ask MCB for

- [ ] A **merchant account** for e-commerce, settling to Kozy's MCB account in MUR
- [ ] **Test (sandbox) credentials**, and later live ones:
  - Merchant ID
  - API password, generated in the gateway's Merchant Administration portal
  - The **gateway URL** MCB uses (the host for API calls and the checkout script)
  - The **API version** they support
- [ ] Confirmation that **Hosted Checkout** is enabled. This is the payment page
      hosted by the bank, shown on Kozy's page or opened as a redirect. Card
      details then never reach Kozy's server, which keeps Kozy out of PCI card
      handling.
- [ ] Confirmation that **refunds through the API** (full and partial) are
      enabled for the merchant
- [ ] **Webhook notifications** enabled, plus the notification secret, so a
      guest who closes the tab mid-payment still gets confirmed
- [ ] Which **test card numbers** their sandbox accepts
- [ ] Their **fees** and settlement schedule

## 2. Put the values in `.env`

These are already in `.env.example` and in the production template in
`deploy.sh`, and are read by `config/services.php` (`services.mcb`):

```env
MCB_GATEWAY_URL=      # e.g. https://<host given by MCB>
MCB_MERCHANT_ID=      # from MCB
MCB_API_PASSWORD=     # from Merchant Administration, server only
MCB_API_VERSION=      # the version MCB supports
MCB_WEBHOOK_SECRET=   # notification secret from Merchant Administration
```

Then set `KOZY_CARD_PAYMENTS=mcb` once the integration below exists.

## 3. Code to write once the credentials arrive

These are the usual Hosted Checkout steps. Check each one against the
documentation MCB sends.

1. **Start a payment.** The server creates a checkout session
   (`INITIATE_CHECKOUT`) with the booking's amount, currency and an order id,
   and returns the session id.
2. **Show the payment page.** The browser loads MCB's `checkout.min.js` and
   opens the payment page, replacing the test form in
   [card-payment-dialog.tsx](resources/js/components/card-payment-dialog.tsx).
3. **Confirm.** On return, and from the webhook, the server checks the order
   status with the gateway and the amount against the booking. It then calls
   `SettleBookingPayment`, which already handles a payment being reported
   twice.
4. **Refund.** Call the gateway's `REFUND` operation from
   [RefundBookingPayment.php](app/Actions/Payments/RefundBookingPayment.php).
   It is a drop-in for `SimulatedCardGateway::refund()`.
5. **Allow MCB's hosts** in the nginx Content-Security-Policy in `deploy.sh`
   (`script-src`, `frame-src`, `connect-src`), then run `./deploy.sh nginx`.
6. **Register the webhook URL** in Merchant Administration.

The simulated gateway is
[SimulatedCardGateway.php](app/Support/SimulatedCardGateway.php). The rest of
the flow stays as it is: booking, confirmation email, cancellation quote,
refund record and payout queue.

## Resources

- MCB e-commerce: https://mcb.mu/corporate/payment-cash/collect/e-commerce/online-payment-gateway
- Mastercard Gateway developer docs: https://test-gateway.mastercard.com/api/documentation/
