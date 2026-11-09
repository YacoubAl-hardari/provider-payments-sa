# Provider Payments SA

![Provider Payments SA ? Laravel payment integrations for Saudi Arabia](Laravel%20Payments%20SA%20Hub.png)

One Laravel API for **ClickPay, Telr, Tabby, Tamara, MisPay, AlinmaPay and AlRajhi**. Create hosted checkout sessions, process authenticated notifications and track payments in a SQL ledger. Optional capture, refunds and promotional widgets depend on the gateway.

**PHP 8.3+  Laravel 12 / 13  MIT**

> Local automated tests use HTTP fakes. Complete merchant sandbox acceptance before going live; the banner is illustrative and does not establish provider certification.

## Start here

1. [Install the package](#installation).
2. [Choose and configure your gateway](#choose-your-payment-gateway).
3. [Create a checkout](#create-a-checkout).
4. [Confirm payment and update your order](#confirm-payment).
5. [Explore refunds, capture, widgets and advanced integration](#more-features).

## Installation

Run these commands in your **Laravel application**, with a configured SQL database and a public HTTPS application URL. You need merchant credentials for every enabled gateway.

```sh
composer require yacoubalhaidari/provider-payments-sa
```

This command requires the package to be indexed on Packagist or available through
your configured Composer repository. If it is not yet indexed, register the GitHub
repository first, then install the first tagged release:

```sh
composer config repositories.provider-payments-sa vcs https://github.com/YacoubAl-hardari/provider-payments-sa.git
composer require yacoubalhaidari/provider-payments-sa:^1.0
```

<details>
<summary>Install from a local checkout or private repository</summary>

Add a path repository to the Laravel application's existing `composer.json`. Merge this entry with your current configuration; do not replace the entire file:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../../packages/provider-payments-sa",
            "options": {
                "symlink": true,
                "versions": {
                    "yacoubalhaidari/provider-payments-sa": "dev-main"
                }
            }
        }
    ]
}
```

Set `url` to the package path relative to your Laravel application directory, or use its absolute path. The example assumes an application two directories below the same workspace root. Forward slashes work on Windows; keep spaces and Arabic characters inside the JSON string.

```sh
composer require yacoubalhaidari/provider-payments-sa:dev-main
```

The explicit path-repository version makes this example work independently of the local branch name. A symlink/junction reflects local package edits immediately; use a reviewed VCS tag or a non-symlink deployment artifact for production.

If the package is available from your own Composer/VCS repository, configure that repository and require its actual reviewed version instead. This guide does not assume a public Packagist release exists.

</details>

Publish configuration and the ledger migrations:

```sh
php artisan payments:install
php artisan vendor:publish --tag=provider-payments-migrations
php artisan migrate
```

The ledger stores payment attempts, operations and webhook receipts. Your application still owns orders, pricing, inventory and fulfillment.

Add these shared settings to your application's `.env`, then configure one gateway below:

```dotenv
PAYMENTS_ENABLED=true
PAYMENT_PROVIDER=clickpay
PAYMENTS_PERSISTENCE_ENABLED=true
PAYMENTS_ROUTES_ENABLED=true
PAYMENTS_ROUTE_PREFIX=payments
```

All gateways start disabled. Enable only those you use. To generate missing ENV placeholders without replacing existing values, run `php artisan payments:env:sync --dry-run`, review the output, then run `php artisan payments:env:sync --write`.

## Choose your payment gateway

Select a gateway to jump to its setup, then expand its credentials and integration notes:

- [ClickPay](#clickpay) ? hosted checkout and inquiry.
- [Telr](#telr) ? hosted checkout and inquiry.
- [Tabby](#tabby) ? checkout, inquiry, full capture, refunds, guarded cancellation and promotional widgets.
- [Tamara](#tamara) ? checkout, inquiry, authorization, full capture, refunds, cancellation and promotional widgets.
- [MisPay](#mispay) ? checkout, authenticated notifications and promotional widgets; no inquiry or refund API.
- [AlinmaPay](#alinmapay) ? checkout, inquiry, full authorized capture and refunds.
- [AlRajhi](#alrajhi) ? hosted checkout and inquiry.

Every gateway supports authenticated notifications with provider-specific checks. See the [capability matrix](docs/integration-guide.md#2-supported-providers) for exact limits.

### ClickPay

<details>
<summary>Configure ClickPay credentials and callbacks</summary>

```dotenv
CLICKPAY_ENABLED=true
CLICKPAY_PROFILE_ID=
CLICKPAY_SERVER_KEY=
CLICKPAY_CURRENCY=SAR
CLICKPAY_CALLBACK_URL=https://your-app.example/payments/clickpay/webhook
```

Use a positive integer merchant profile ID and its paired server key. The configured API host is `https://secure.clickpay.com.sa`; sandbox/live behavior follows your merchant profile. Do not substitute PayTabs credentials. Browser return URL is supplied per attempt in the request DTO.

Use `Payment::gateway('clickpay')` in the [shared checkout example](#create-a-checkout). The notification endpoint is `POST /payments/clickpay/webhook`; register it with your merchant account where supported.

</details>

### Telr

<details>
<summary>Configure Telr credentials and callbacks</summary>

```dotenv
TELR_ENABLED=false
TELR_STORE_ID=
TELR_AUTH_KEY=
TELR_SECRET_KEY=
TELR_BASE_URL=https://secure.telr.com
TELR_TEST_MODE=true
TELR_TIMEOUT=30
TELR_CURRENCY=SAR
TELR_RETURN_AUTHORISED=https://your-app.example/checkout/return
TELR_RETURN_DECLINED=https://your-app.example/checkout/return
TELR_RETURN_CANCELLED=https://your-app.example/checkout/return
```

Store/auth key authenticate hosted checkout and inquiry. Secret key verifies transaction advice. Register the package webhook URL in the merchant dashboard. The same API host uses the test flag; switch to live only after validating the merchant account. Attempt reference is limited to 63 bytes.

The static return URLs above are optional fallback examples. In an order integration, pass actual per-order return URLs explicitly as in the [complete order integration](docs/integration-guide.md#8-complete-order-to-checkout-example).

Use `Payment::gateway('telr')` in the [shared checkout example](#create-a-checkout). The notification endpoint is `POST /payments/telr/webhook`; register it with your merchant account where supported.

</details>

### Tabby

<details>
<summary>Configure Tabby credentials and callbacks</summary>

```dotenv
TABBY_ENABLED=false
TABBY_BASE_URL=https://api.tabby.ai
TABBY_MERCHANT_CODE=
TABBY_SECRET_KEY=
TABBY_PUBLIC_KEY=
TABBY_CURRENCY=SAR
TABBY_WEBHOOK_HEADER_TITLE=X-Tabby-Secret
TABBY_WEBHOOK_HEADER_VALUE=
TABBY_WEBHOOK_URL=https://your-app.example/payments/tabby/webhook
TABBY_SUCCESS_URL=
TABBY_CANCEL_URL=
TABBY_FAILURE_URL=
```

Use the merchant-approved regional endpoint, such as `api.tabby.ai` or `api.tabby.sa`. Server API calls use the **secret** key; the public key is for promotional widgets. Register the exact webhook header title/value with Tabby. Test keys beginning `sk_test_` require an approved test email; production buyers use name/phone and may omit email. Supply real buyer/order history through metadata when required by your account.

Use `Payment::gateway('tabby')` in the [shared checkout example](#create-a-checkout). The notification endpoint is `POST /payments/tabby/webhook`; register it with your merchant account where supported.

</details>

### Tamara

<details>
<summary>Configure Tamara credentials and callbacks</summary>

```dotenv
TAMARA_ENABLED=false
TAMARA_BASE_URL=https://api-sandbox.tamara.co
TAMARA_API_TOKEN=
TAMARA_NOTIFICATION_TOKEN=
TAMARA_PUBLIC_KEY=
TAMARA_AUTO_AUTHORISATION=true
TAMARA_WEBHOOK_URL=https://your-app.example/payments/tamara/webhook
TAMARA_SUCCESS_URL=
TAMARA_CANCEL_URL=
TAMARA_FAILURE_URL=
TAMARA_PRECHECK_ENABLED=true
TAMARA_PRECHECK_TIMEOUT_MS=200
TAMARA_PRECHECK_CACHE_SECONDS=60
TAMARA_PRECHECK_FAILURE_COOLDOWN_SECONDS=10
```

Production base URL is `https://api.tamara.co`. Pair API and notification tokens with the selected account/environment. API token authenticates server requests; notification token verifies HS256 notifications. Eligibility failures may keep the payment option visible, but never establish payment success. Automatic authorization is not automatic capture.

Use `Payment::gateway('tamara')` in the [shared checkout example](#create-a-checkout). The notification endpoint is `POST /payments/tamara/webhook`; register it with your merchant account where supported.

</details>

### MisPay

<details>
<summary>Configure MisPay credentials and callbacks</summary>

```dotenv
MISPAY_ENABLED=false
MISPAY_BASE_URL=https://api-sandbox.mispay.dev/v1/api
MISPAY_APP_ID=
MISPAY_APP_SECRET=
MISPAY_WEBHOOK_SECRET=
MISPAY_WHITELIST_IP=
MISPAY_WEBHOOK_URL=https://your-app.example/payments/mispay/webhook
MISPAY_SUCCESS_URL=
MISPAY_CANCEL_URL=
MISPAY_FAILURE_URL=
MISPAY_SEND_CALLBACK_OVERRIDE=false
MISPAY_WIDGET_KEY=
MISPAY_WIDGET_URL=https://widget-sandbox.mispay.dev/v1/sdk.js
```

The audited checkout uses SAR and v1.2 payloads. Obtain the production API base directly from your merchant provider; do not guess it from widget hostnames. Configure a shared webhook secret with the provider, or set MISPAY_WHITELIST_IP to the exact public source IP addresses confirmed for your merchant integration. The allowlist is optional, defaults empty, and accepts comma/space/semicolon-separated public addresses. With an allowlist, an exact matching request IP authenticates notifications without a shared header. Unlisted requests still require a valid shared secret. Laravel trusted-proxy settings must reflect your actual infrastructure; never trust client-supplied forwarding headers from arbitrary peers. Saved order and checkout identity validation still applies after authentication. If the secret variable is entirely unset, config can fall back to the app secret; an explicitly blank variable does not provide a valid secret.

Keep callback override false unless the merchant profile confirms `callbackUrl` support. Optional widget production script is `https://widget.mispay.co/v1/sdk.js`; its widget key is a public browser credential, unlike the server app secret.

Use `Payment::gateway('mispay')` in the [shared checkout example](#create-a-checkout). The notification endpoint is `POST /payments/mispay/webhook`; register it with your merchant account where supported.

</details>

### AlinmaPay

<details>
<summary>Configure AlinmaPay credentials and callbacks</summary>

```dotenv
ALINMAPAY_ENABLED=false
ALINMAPAY_ENV=sandbox
ALINMAPAY_TERMINAL_ID=
ALINMAPAY_TERMINAL_PASSWORD=
ALINMAPAY_MERCHANT_KEY=
ALINMAPAY_PAYMENT_TYPE=1
ALINMAPAY_RECEIPT_URL=
ALINMAPAY_CANCEL_URL=
ALINMAPAY_FAILURE_URL=
```

The gateway slug is **`alinmapay`**. Existing `ALINMAPAY_ENV`, terminal ID/password and merchant key names are retained. Sandbox uses `https://pg.alinmapay.com.sa/SB_Transactions/v2/payments/pay-request`; production uses `/Transactions/v2/payments/pay-request` on the same host. Set `ALINMAPAY_ENV=production` only for a live merchant account. The merchant key must be a 32- or 64-character hexadecimal string; request signatures use its original text. Receipt decryption follows the source AES-128-ECB protocol using the first 16 decoded key bytes.

Checkout types are `1` purchase, `4` preauthorization and `15` payment link. Keep the intended mode stable during checkout. Preauthorization is confirmed by bank inquiry, then captured separately; it is not an instruction to fulfill an order. The source's HTTP timeout for outbound webhook delivery is not a checkout API timeout: use `PAYMENTS_TIMEOUT` for this package's bank requests.

Only SAR is supported by this integration. The DTO's `successUrl` becomes `receiptUrl` inside `additionalDetails.userData`. The source protocol has one receipt callback rather than three independently registered browser outcomes; `cancelUrl` remains part of the unified DTO contract but is not sent as an invented AlinmaPay field. The separate package notification endpoint is `POST /payments/alinmapay/webhook`, to be configured with the merchant account if notifications are available.

Plain notifications require the exact `X-AlinmaPay-Signature` header or a body `signature`, calculated by the source response formula `SHA256(transactionId|merchantKey|responseCode|amount)`. AES/base64 receipts arrive in `data`. Both forms must match a saved order/transaction, amount and currency, then pass a fresh bank inquiry. A signature does not authenticate the notification's arbitrary event/result fields; those fields never settle an order directly.

Inquiry requires `transactionId`, original `orderId`, amount and currency. A generic `SUCCESS`/`000` must also identify `paymentType` (`4` is authorized; `1`, `5` and `15` are paid); explicit financial statuses such as `AUTHORIZED` or `CAPTURED` are also recognized. A response lacking enough information to distinguish authorization from payment is rejected for reconciliation, rather than guessed from the application's current checkout mode. Verify the exact inquiry response supplied by your merchant environment before going live.

Use `Payment::gateway('alinmapay')` in the [shared checkout example](#create-a-checkout). The notification endpoint is `POST /payments/alinmapay/webhook`; register it with your merchant account where supported.

</details>

### AlRajhi

<details>
<summary>Configure AlRajhi credentials and callbacks</summary>

```dotenv
ALRAJHI_ENABLED=false
ALRAJHI_ENVIRONMENT=sandbox
ALRAJHI_BASE_URL=https://securepayments.alrajhibank.com.sa
ALRAJHI_TRANPORTAL_ID=
ALRAJHI_TRANPORTAL_PASSWORD=
ALRAJHI_RESOURCE_KEY=
ALRAJHI_MERCHANT_IP=
ALRAJHI_ACTION=1
ALRAJHI_TRANDATA_FORMAT=array
ALRAJHI_URL_ENCODE_BEFORE_ENCRYPT=false
ALRAJHI_URL_DECODE_AFTER_DECRYPT=false
ALRAJHI_RESPONSE_URL=
ALRAJHI_ERROR_URL=
```

The slug is **`alrajhi`**. Existing terminal/resource-key and callback names are retained. Configure a valid `ALRAJHI_MERCHANT_IP` for background inquiries/operations. A saved `CustomerDTO::ip` overrides it during checkout; resolve customer IP through Laravel's trusted-proxy configuration, not an arbitrary untrusted forwarded header.

The resource key is **exactly 32 literal bytes**. Do not hex-decode it merely because it looks hexadecimal. The protocol uses AES-256-CBC, IV `PGKEYENCDECIVSPC`, uppercase hexadecimal ciphertext and JSON-array request envelopes. `ALRAJHI_TRANDATA_FORMAT=object` selects a merchant-approved object inside the encrypted envelope; the outer envelope remains an array. Set URL encode/decode flags to the format approved for your terminal. The package makes one request per operation and does not automatically retry alternative encryption formats after an error.

Only SAR (`currencyCode=682`) is supported. `ALRAJHI_ACTION=1` starts a sale; `4` starts a hosted authorization. Initiation wrapper `status=1` indicates checkout creation, not payment. Inquiry uses action `8`, `udf5=PaymentID` and the saved hosted `PaymentID`. The bank `transId` is a different identifier; do not substitute it for the hosted payment ID. `APPROVED` maps conservatively to `authorized`; fulfillment requires an explicit captured/paid state.

For production set both `ALRAJHI_ENVIRONMENT=production` and `ALRAJHI_BASE_URL=https://digitalpayments.neoleap.com.sa` when this is the merchant-approved endpoint. An explicit `ALRAJHI_BASE_URL` overrides environment selection; do not leave the sandbox URL in ENV when switching environments. These two exact HTTPS hosts are allowed.

`successUrl` maps to `responseURL`; `failureUrl` (or `cancelUrl`) maps to `errorURL`. There is no invented separate webhook parameter in the bank-hosted request. The package supports encrypted `trandata` at `POST /payments/alrajhi/webhook`; it requires original track, hosted payment ID, amount and currency and performs a bank inquiry before applying any transition. Plain unsigned callback fields are rejected. CBC encryption is not a MAC and never serves as sole settlement evidence.

Use `Payment::gateway('alrajhi')` in the [shared checkout example](#create-a-checkout). The notification endpoint is `POST /payments/alrajhi/webhook`; register it with your merchant account where supported.

</details>

After setting credentials, verify your setup:

```sh
php artisan payments:list
php artisan payments:diagnose
php artisan route:list --path=payments
php artisan config:cache
php artisan queue:restart
```

Diagnostics check local configuration, routes and tables; they do not validate credentials with the provider. Rebuild configuration and restart long-lived workers whenever credentials change. See [upgrading an existing application](docs/integration-guide.md#existing-application-upgrade) if you already published configuration.

## Create a checkout

The same DTO works across gateways. This API example uses ClickPay; replace the gateway slug and notification URL with your selection above. Use real customer data and HTTPS routes from your application.

**Persist the unpaid order, selected gateway, immutable attempt reference and request snapshot before calling the provider.** Reuse that snapshot for retries. The [complete order integration](docs/integration-guide.md#8-complete-order-to-checkout-example) shows models, routes, checkout and return handling together.

```php
use YacoubAlhaidari\ProviderPayments\DTOs\CustomerDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\OrderItemDTO;
use YacoubAlhaidari\ProviderPayments\DTOs\PaymentRequestDTO;
use YacoubAlhaidari\ProviderPayments\Facades\Payment;

$result = Payment::gateway('clickpay')->createPayment(new PaymentRequestDTO(
    orderReference: 'PAY-immutable-attempt-1001',
    amount: 16000,
    currency: 'SAR',
    customer: new CustomerDTO(
        name: 'Customer Name',
        email: 'customer@example.com',
        phone: '+966500000000',
        street: 'Customer street',
        city: 'Riyadh',
        country: 'SA',
        zip: '12345',
    ),
    successUrl: 'https://your-app.example/checkout/return',
    cancelUrl: 'https://your-app.example/checkout/return',
    failureUrl: 'https://your-app.example/checkout/return',
    webhookUrl: 'https://your-app.example/payments/clickpay/webhook',
    description: 'Order #1001',
    items: [new OrderItemDTO('Item', 7500, 2, sku: 'SKU-1')],
    shippingAmount: 1000,
));

if ($result->requiresRedirect()) {
    return redirect()->away($result->checkoutUrl);
}
```

To use another enabled provider, change the gateway slug and matching webhook URL, retaining the required provider customer/items/account settings. MisPay cannot be verified with `getPaymentStatus`; its confirmation comes from authenticated notifications.

`isSuccessful()` is true only for `paid`/`captured`; `authorized` is not paid. Most successful create calls return `requires_action`, not a successful payment.

Amounts are **integer minor units**: `16000` means SAR 160.00. Calculate totals on the server; items plus shipping and tax minus discount must equal the payment amount. Use a new attempt reference only for a genuinely new attempt. See [amounts and customer data](docs/integration-guide.md#6-amounts-items-and-request-data) and the [bank checkout example](docs/integration-guide.md#bank-checkout-example).

## Confirm payment

A checkout URL or browser success return does **not** prove payment. Keep the SQL ledger enabled and let the package authenticate notifications and verify payment identity/state.

- The default notification route is `POST /payments/{gateway}/webhook`. Providers must reach it over HTTPS without customer login or web/CSRF middleware.
- Correlate your order using the saved attempt/provider reference. Use the [event listener and fulfillment example](docs/integration-guide.md#9-webhooks-and-order-updates) to update it idempotently.
- For gateways supporting inquiry, use `Payment::verifyPayment($savedProviderReference, $savedGateway)` on a saved payment. MisPay relies on authenticated notifications and merchant reconciliation.
- `authorized` means authorized funds; fulfill only after the required paid/captured confirmation.
- If a request times out, preserve the attempt and operation reservations. Follow [verification and recovery](docs/integration-guide.md#10-verification-retries-and-recovery) before resending a financial request.

Your return page should show the saved/verified status. It must never mark an order paid from browser-supplied fields.

## More features

Use the detailed guides as needed:

- [Complete order integration](docs/integration-guide.md#8-complete-order-to-checkout-example) ? order schema, server-side prices, immutable snapshots, routes and returns.
- [Authorization, capture, refunds and cancellation](docs/integration-guide.md#11-authorization-capture-refunds-and-cancellation) ? provider-specific examples and stable operation references.
- [Promotional widgets](docs/integration-guide.md#12-promotional-widgets) ? Tabby, Tamara and MisPay Blade examples; widgets do not collect or confirm payment.
- [Events, storage and custom gateways](docs/integration-guide.md#13-events-storage-and-extensibility).
- [Configuration defaults and routes](docs/integration-guide.md#59-request-defaults-and-routes).
- [Testing and troubleshooting](docs/integration-guide.md#14-testing-and-troubleshooting).
- [Operational limits and licensing](docs/integration-guide.md#15-operational-limits-and-license).

Before optional operations, check `$gateway->supports('refund')`, `supports('capture')` or `supports('cancel')`. Merchant permissions and payment state still determine whether an operation is allowed.

## Package development

Run from this package directory:

```sh
composer install
composer validate --strict
composer test
composer lint
```

Tests, fixtures and CI are maintained with the source. Existing dependency and generated cache directories are excluded through `.gitignore`; development files are excluded from Git release archives through `.gitattributes`. Keep merchant credentials outside version control.

## License

[MIT](LICENSE). Provider APIs, trademarks and merchant agreements retain their own terms.
