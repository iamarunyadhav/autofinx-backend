# Wallet Card Deposit (Stripe Hosted Checkout)

AutoFinX does not collect raw card numbers in-app.  
For PCI safety, users are redirected to Stripe hosted checkout.

## 1) Environment

Set in `backend/.env`:

```env
BILLING_MODE=sandbox

STRIPE_SECRET_KEY_SANDBOX=sk_test_xxx
STRIPE_WEBHOOK_SECRET_SANDBOX=whsec_xxx
STRIPE_CHECKOUT_SUCCESS_URL_SANDBOX=http://localhost:8080/wallet
STRIPE_CHECKOUT_CANCEL_URL_SANDBOX=http://localhost:8080/wallet
```

For live:

```env
BILLING_MODE=live

STRIPE_SECRET_KEY_LIVE=sk_live_xxx
STRIPE_WEBHOOK_SECRET_LIVE=whsec_live_xxx
STRIPE_CHECKOUT_SUCCESS_URL_LIVE=https://your-frontend-domain/wallet
STRIPE_CHECKOUT_CANCEL_URL_LIVE=https://your-frontend-domain/wallet
```

## 2) Stripe Webhook

Create a webhook endpoint in Stripe dashboard:

- URL: `https://your-api-domain/api/v1/wallet/stripe/webhook`
- Events:
  - `checkout.session.completed`
  - `checkout.session.async_payment_succeeded`
  - `checkout.session.expired`
  - `checkout.session.async_payment_failed`

## 3) Runtime Flow

1. User opens Wallet -> Deposit.
2. Selects `Credit / Debit Card`.
3. Backend creates Stripe checkout session.
4. Frontend redirects to Stripe hosted checkout.
5. Stripe webhook calls backend on success.
6. Backend credits wallet ledger and marks payment `succeeded`.

## 4) Notes

- If webhook is not configured, payment may complete on Stripe but wallet credit will stay pending.
- `card`, `credit_card`, and `debit_card` all map to Stripe in backend.
