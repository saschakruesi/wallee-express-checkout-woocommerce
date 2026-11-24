# wallee Express Checkout for WooCommerce: Design

Date: 2026-11-24
Status: draft, awaiting approval

## 1. Intent

Build a working prototype of a WooCommerce add-on plugin that offers wallee
Express Checkout (Apple Pay, PayPal, later Google Pay) on the cart page. The
customer approves the payment in the wallet, the wallet supplies the addresses,
and the shop order is created directly from that, without the customer going
through the WooCommerce checkout form.

Why: wallee has released Express Checkout as an API product and has an existing
WooCommerce plugin (`wallee-payment/woocommerce`, v3.4.11) that does not support
it. The prototype shows how Express Checkout fits into WooCommerce and should
be good enough to grow into a releasable plugin.

## 2. Goals, non-goals, anti-goals

Goals

- A separate plugin that needs no credentials of its own. It uses the space ID,
  application user ID and key already configured in the wallee main plugin.
- Wallet buttons rendered by wallee's widget on the cart page, in both the
  classic (shortcode) cart and the block cart.
- Shipping options offered inside the wallet come from WooCommerce shipping
  zones for the address the customer picked in the wallet.
- After wallet approval the order is created from the session data and paid.
- Orders created this way behave like any other wallee order afterwards: status
  changes through the main plugin's webhooks, and refund, completion and void
  from the WooCommerce admin work.
- Current WordPress/WooCommerce security practice throughout (see section 8).
- A public HTTPS staging shop on AWS to develop and test against, separate from
  everything else in the AWS account.

Non-goals (for this prototype)

- Buttons on product pages, mini cart or checkout page.
- Subscriptions, multi-currency plugins, pre-orders, split shipping packages.
- Own webhook handling, own transaction storage, own refund logic.
- Any change to the main plugin's files.
- Google Pay specific work. wallee lists it as "Planned" for Express Checkout.
  The widget is wallet-agnostic, so it should appear without code changes.
- Publishing to wordpress.org.

Anti-goals (these make it a failure even if it "works")

- A customer is charged an amount that differs from the order total.
- One wallet approval produces two orders or two charges.
- An order exists that the main plugin cannot refund or complete.
- Secrets (application user key, DB passwords) end up in the repository, in
  logs or in chat.
- The staging setup touches or shares resources with `merchant360`.

## 3. Constraints and facts established during research

### 3.1 wallee Express Checkout API (v2.0)

Source: https://app-wallee.com/en-us/doc/payment/express-checkout and the
OpenAPI spec at https://app-wallee.com/api/spec3.json.

Flow as documented:

1. Backend: `POST /api/v2.0/express-checkout/session/create` returns
   `sessionId` and `sessionToken`.
2. Browser: load
   `https://<scope-base-path>/assets/payment/express-checkout-handler.js` and
   call `window.ExpressCheckoutHandler.load(containerId, sessionToken, options)`.
   Options: `maxColumns` (1 to 6, default 2), `maxRows` (1 to 10, default
   unlimited), `onError({source, code, message})`. No other client events are
   documented.
3. wallee to shop, server to server: `POST merchantShippingCallbackUrl` when
   the customer picks or changes the address. Only needed if something in the
   cart needs shipping.
4. After approval the browser is redirected to `merchantRedirectUrl`. The shop
   creates the order here. Optional `GET /api/v2.0/express-checkout/session`
   returns the final data.
5. Backend: `POST /api/v2.0/express-checkout/session/process`, then redirect
   the browser to the returned `redirectUrl` (3DS if needed).

All three endpoints need the header `space: <spaceId>`.

`ExpressCheckoutSession.Create` (required: `authorizationEnvironment`,
`currency`, `lineItems`, `merchantRedirectUrl`): `lineItems[]` (LineItem:
`uniqueId`, `sku`, `name`, `type`, `quantity`, `amountIncludingTax`,
`shippingRequired`, `taxes[]`), `merchantShippingCallbackUrl`,
`merchantRedirectUrl`, `authorizationEnvironment` (`TEST` | `PRODUCTION`),
`currency`, `language`, `timeZone`.

`ExpressCheckoutSession` (GET): `id`, `state` (`PENDING`, `APPROVED`,
`PROCESSING`, `AUTHORIZED`, `FAILED`), `authorizationAmount`, `currency`,
`lineItems[]`, `shippingAddress` (Address), `billingAddress` (Address),
`shippingOptions[]` (`id`, `label`, `description`, `amountIncludingTax`,
`taxes[]`, `currency`, `selected`), `linkedTransaction` (transaction ID),
`transaction`, `walletType`, `failureReason`, `metaData`, `language`,
`timeZone`, `createdOn`, `plannedPurgeDate`.

`ExpressCheckoutSessionProcessRequest` (all required): `sessionId`,
`merchantReference` (non-blank, idempotent, becomes the transaction's
merchantReference), `successUrl`, `failureUrl`. Response: `redirectUrl`.

Shipping callback request (JSON): required `expressCheckoutId`, `spaceId`,
`requestId`, `countryCode`, `currency`, `language`; optional `state`,
`street`, `city`, `postalCode`. No name or e-mail. Response, always HTTP 200
for business results:

```json
{ "shippingOptions": [ { "id": "...", "label": "...", "description": "...",
    "amountIncludingTax": 7.50, "taxes": [ { "title": "VAT", "rate": 8.1 } ],
    "currency": "CHF" } ],
  "error": null }
```

On a business error: empty `shippingOptions` and
`error: { "error": "<CODE>", "message": "<customer-facing>" }` with code one of
`ADDRESS_UNAVAILABLE`, `COUNTRY_UNAVAILABLE`, `STATE_UNAVAILABLE`,
`POSTCODE_UNAVAILABLE`, `SHIPPING_METHOD_UNAVAILABLE`, `STORE_UNAVAILABLE`.
Non-200 only for technical failures.

Limits that shape the design:

- Line items cannot be changed after session creation. Only shipping options
  are dynamic.
- No authentication on the shipping callback is documented.
- Session and token lifetimes are not documented.
- Express Checkout ignores the space's default result URLs. `successUrl` and
  `failureUrl` must be sent on process.
- Wallets: Apple Pay and PayPal available, Google Pay planned, Bogus Pay for
  testing (mock addresses, test cards, 3DS simulation).

### 3.2 Apple Pay Express Checkout

Source: https://app-wallee.com/doc/payment/apple-pay#apple-pay-express-checkout

- Needs a connector that supports Apple Pay and Express Checkout (processors
  Worldline or Concardis, brands Visa and Mastercard) and "Express Checkout"
  enabled under Space > Settings > Wallets > Apple Pay.
- Every host name that shows the button must be registered in the portal
  ("Registered Shop Domains") and must serve the verification file downloaded
  from the portal at
  `https://<host>/.well-known/apple-developer-merchantid-domain-association`:
  exact lower-case name, no extension, HTTPS, no redirect, no login. `example.com`
  and `www.example.com` are separate hosts.
- The file may be served by a route instead of a static file.
- No own Apple developer account, merchant ID or certificate is needed.

### 3.3 The wallee main plugin (v3.4.11)

- Detect with `class_exists( 'WooCommerce_Wallee' )` and
  `class_exists( 'WC_Wallee_Helper' )` on `plugins_loaded` priority 21 or
  later. Its autoloader claims every class starting with `WC_Wallee`, so the
  add-on must not use that prefix.
- Credentials: options `wc_wallee_space_id`, `wc_wallee_application_user_id`,
  `wc_wallee_application_user_key`. Base URL from
  `WC_Wallee_Helper::instance()->get_base_gateway_url()` (option
  `wc_wallee_base_gateway_url`, default `https://app-wallee.com/`).
- API client: `WC_Wallee_Helper::instance()->get_api_client()` returns a
  `\Wallee\Sdk\ApiClient` (SDK 4.9.x) with base path `<base>/api`. The SDK
  only knows v1 services and signs with MAC headers (`x-mac-*`, HMAC-SHA512).
  Its public `callApi( $path, $method, $query, $body, $headers )` can call
  arbitrary paths such as `/v2.0/express-checkout/session/create`. The spec
  lists `macAuth` as an accepted scheme for the v2.0 endpoints, but this has
  not been tried yet.
- Order linkage. The main plugin's webhook handler finds the order through its
  table `{prefix}wallee_transaction_info` (`transaction_id` + `space_id` to
  `order_id`). The Transaction webhook alone has a fallback: the order whose ID
  equals the numeric `merchantReference`. It then ignores the order unless its
  `payment_method` contains `wallee`.
  `WC_Wallee_Service_Transaction::instance()->update_transaction_info( $tx, $order )`
  is public and upserts the row from a v1 SDK Transaction, obtainable through
  `->get_transaction( $space_id, $transaction_id )`.
- Admin refund, completion and void require
  `wc_get_payment_gateway_by_order( $order ) instanceof WC_Wallee_Gateway`.
  Gateway IDs are `wallee_<local id>` where the local ID is the primary key in
  `{prefix}wallee_method_config`, found via
  `WC_Wallee_Entity_Method_Configuration::load_by_configuration( $space_id, $configuration_id )->get_id()`.
- Refunds map order items to transaction line items through the order item
  meta `_wallee_unique_line_item_id`, which must equal the line item's
  `uniqueId`. The main plugin only sets this meta inside `WC_Checkout`, so the
  add-on must set it itself. A mismatch is fatal at refund time.
- The add-on must not preset `_wallee_authorized` or `_wallee_confirmed`.
- Status transitions, stock reduction and e-mails are done by the webhook
  handler (`AUTHORIZED` to on-hold, `FULFILL` to processing, `FAILED` to
  failed, and so on). Webhook listeners are installed when the main plugin's
  settings are saved.
- Conventions: PHP 7.4+, WP 6.0+, WC 8.0+, WordPress coding standards, HPOS
  compatibility declared.

### 3.4 Environment

- AWS account reachable with CLI profile `default`, region `eu-north-1`. It
  contains only `merchant360` (ECS service, ECR repository, Postgres RDS). No
  Route 53 zone. The new project must not share resources with it.
- GitHub CLI is authenticated as the repository owner. Docker is installed
  locally.
- A wallee test space with Express Checkout enabled exists.

## 4. Approaches

1. **Add-on that leans on the main plugin (chosen).** Reuses credentials, API
   client, transaction table, gateways and webhook handling. Smallest surface,
   no duplicated status logic, and exactly what was asked for. Cost: coupled to
   main plugin internals that are not a public API, so a main plugin update can
   break it. Mitigated by one adapter class that holds every call into the main
   plugin, plus a version check.
2. **Standalone plugin with its own API client and webhooks.** Independent of
   the main plugin, but needs its own credentials or duplicates the status and
   refund logic, and two plugins would react to the same space-wide webhooks.
   Rejected: contradicts "no further credentials".
3. **Feature inside the main plugin.** Cleanest long term, but the request is a
   separate plugin and a prototype should not need a main plugin release.
   Rejected for now; the adapter boundary keeps a later merge possible.

Hosting, decided with the owner: a single small AWS instance running Docker
Compose (chosen) over an ECS service like `merchant360`. The single instance
gives a deploy loop of seconds and costs a few dollars a month. ECS would give
an HTTPS URL without a domain but needs a MySQL RDS, persistent storage and an
image build per change. ECS remains an option once the plugin works.

## 5. Design

### 5.1 Repository layout

Private GitHub repository `wallee-express-checkout-woocommerce`.

```
plugin/wallee-express-checkout/   the WordPress plugin (this folder is what gets installed)
  wallee-express-checkout.php     bootstrap, dependency check, compatibility declarations
  src/                            PHP classes, namespace Wallee\ExpressCheckout
  assets/js/                      plain JavaScript, no build step
  assets/css/
  uninstall.php
tests/                            PHPUnit unit tests
infra/                            Docker Compose, Caddy config, provisioning and deploy scripts
docs/                             this design, the plan, API reference excerpts
```

Namespace `Wallee\ExpressCheckout`, options and table prefix `wallee_ec_`,
text domain `wallee-express-checkout`. PSR-4 autoloading through a small
hand-written autoloader so the plugin runs without Composer on the server.
Composer is for development tools only.

### 5.2 Components

Each has one job and can be tested on its own.

| Component | Job |
|---|---|
| `Plugin` | Bootstrap. Checks main plugin and WooCommerce are active and recent enough, shows an admin notice and stays inert otherwise. Declares HPOS and cart/checkout blocks compatibility. |
| `MainPluginAdapter` | The only class that touches main plugin classes and options: credentials, base URL, API client, transaction info upsert, gateway lookup, logging. |
| `ApiClient` | The three express-checkout calls. Returns plain arrays or throws a typed exception. Transport decided by the spike (section 7). |
| `LineItemBuilder` | Cart snapshot to wallee line items, with generated unique IDs. Pure function of the snapshot. |
| `CartSnapshot` | Immutable copy of what the session was created for: items (product ID, variation, quantity, prices, tax class, needs shipping), applied coupons, fees, currency, customer ID, prices-include-tax flag, cart hash. Serialisable. |
| `SessionRepository` | Table `{prefix}wallee_ec_session`. |
| `SessionService` | Creates or reuses a session for the current cart. |
| `ShippingOptionsProvider` | Snapshot plus destination to WooCommerce shipping rates, mapped to the callback response format. |
| `OrderFactory` | Snapshot plus final session data to a `WC_Order`. |
| `ReturnHandler` | The redirect target after wallet approval: verify, create order, check amounts, process, redirect. |
| `ResultHandler` | Targets of `successUrl` and `failureUrl`. |
| `CartButtons` | Outputs the container and enqueues the script on the cart page (classic and block). |
| `DomainAssociation` | Serves the Apple Pay verification file. |
| `Settings` | WooCommerce settings section. |
| `Cleanup` | Daily purge of old session rows. |

### 5.3 Session table

`{prefix}wallee_ec_session`: `id`, `session_uuid` (64 hex chars from
`random_bytes(32)`, unique), `wallee_session_id`, `space_id`, `cart_hash`,
`snapshot` (JSON), `state`, `order_id` (nullable), `transaction_id`
(nullable), `created_at`, `updated_at`.

`state`: `created`, `ordering`, `processed`, `completed`, `failed`. Moving out
of `created` is done with a single conditional
`UPDATE ... SET state='ordering' WHERE id=? AND state='created'`. Only the
request that changes a row may continue. This is what prevents double orders.

The `sessionToken` is not stored in the table. It is returned to the browser
that asked for it and kept in the WooCommerce customer session next to the cart
hash so a page reload can reuse it.

### 5.4 Flow

**Cart page.** `CartButtons` prints an empty container and enqueues wallee's
handler script and the add-on's script. For the classic cart it hooks after
the "proceed to checkout" button. For the block cart it appends the container
to the rendered proceed-to-checkout block through a `render_block` filter, so
no JavaScript build is needed.

**Session creation.** The add-on's script calls `POST /wp-json/wallee-ec/v1/session`
(Store nonce plus the customer's WooCommerce session cookie). `SessionService`:

1. Rejects an empty cart, a currency or cart type the add-on does not support
   (see 5.7), and a missing main plugin configuration. In those cases the
   buttons are simply not shown.
2. If the customer session already holds a token for the current cart hash,
   returns it.
3. Otherwise builds the snapshot and line items, generates `session_uuid`,
   calls session create with `merchantRedirectUrl` and
   `merchantShippingCallbackUrl` (only if something needs shipping), stores
   the row, returns `sessionToken`.

Both URLs carry `ec_session=<uuid>` and `sig=<HMAC-SHA256 of uuid and purpose
with a key derived from wp_salt()>`.

The script then calls `ExpressCheckoutHandler.load(...)`. When the cart changes
(classic: `updated_cart_totals`; blocks: cart store subscription) it clears the
container and requests a session again. `onError` logs to the console and
hides the container; the regular checkout button stays usable.

**Shipping callback.** `POST /wp-json/wallee-ec/v1/shipping-options?ec_session=..&sig=..`.
No cookie exists here, so everything comes from the stored snapshot.

1. Verify signature in constant time, load the row, require
   `expressCheckoutId` and `spaceId` in the body to match the row, require
   state `created`. Otherwise HTTP 403 or 404.
2. Build one shipping package from the snapshot items that need shipping and
   the destination in the request, and ask WooCommerce for rates
   (`WC()->shipping()->calculate_shipping_for_package`), with the snapshot's
   coupons taken into account for free-shipping rules.
3. Map each rate: `id` is the WooCommerce rate ID, `label` the rate label,
   `amountIncludingTax` cost plus tax, `taxes` from the rate's tax IDs.
4. No matching zone or no rates: HTTP 200 with `COUNTRY_UNAVAILABLE` if the
   country is not in the shop's shipping countries, else
   `SHIPPING_METHOD_UNAVAILABLE`.

**Return after approval.** `GET /?wc-api=wallee_ec_return&ec_session=..&sig=..`.

1. Verify signature, load the row.
2. If state is already past `created`: if an order exists, send the customer to
   where that order stands (order-received or the stored redirect); never
   create a second one.
3. Claim the row (`created` to `ordering`).
4. `GET session`. Require state `APPROVED` and matching space. Otherwise mark
   `failed`, redirect to the cart with a notice.
5. `OrderFactory` creates the order with status `pending`:
   - products, fees and coupons from the snapshot, with the same prices and
     taxes the line items were built from;
   - `_wallee_unique_line_item_id` on every item, equal to the line item's
     `uniqueId`;
   - shipping and billing address from the session. If one is missing the
     other is used for both. E-mail from the billing address, else the
     shipping address, else the logged-in customer's e-mail;
   - the shipping line from the option marked `selected`, re-derived from
     WooCommerce for the final address: the rate ID must exist and its amount
     must equal the session's option amount;
   - customer ID from the snapshot if the customer was logged in;
   - order meta `_wallee_ec_session_uuid` and `_wallee_ec_wallet`;
   - `created_via` set to `wallee_express_checkout`.
6. Amount check: order total must equal the session's `authorizationAmount`
   and currencies must match, compared at the currency's precision. On any
   mismatch, missing e-mail, or invalid shipping option: order is set to
   `failed` with a private note stating the reason, the row is marked
   `failed`, process is not called, the customer returns to the cart with a
   notice.
7. Link to the main plugin (see 5.5), then call process with
   `merchantReference` equal to the order ID, `successUrl` and `failureUrl`
   pointing at `ResultHandler` with the signed session reference.
8. Store state `processed`, redirect the browser to `redirectUrl` (validated
   to be an HTTPS URL).

If process fails with an error, the order is set to `failed` and the customer
returns to the cart with a notice. Because `merchantReference` is idempotent
and the row is claimed exactly once, a browser reload cannot charge twice.

**Result.** Success: make sure the link from 5.5 exists, empty the cart, set
row state `completed`, redirect to the order-received page. The order status
itself is not touched; the main plugin's webhook does that. Failure: set the
order to `failed` if it is still `pending`, row state `failed`, redirect to
the cart with a notice.

### 5.5 Linking the order to the main plugin

For webhooks and admin actions to work, three things must be true before the
first webhook can matter:

1. `merchantReference` is the numeric order ID. This is deliberate and
   independent of the main plugin's "order reference" setting, because the
   webhook fallback only works with the numeric ID.
2. The order's `payment_method` is a registered `wallee_<local id>` gateway
   and `payment_method_title` is the gateway title.
3. A row in `wallee_transaction_info` links transaction and order.

Points 2 and 3 need the transaction, which is known through the session's
`linkedTransaction`. Whether that is already set at `APPROVED` or only after
process is to be established by the spike. The rule for the builder: do the
linking at the earliest point the transaction ID is available, and again in
`ResultHandler` as a safety net (the upsert is idempotent). If the transaction
is not available before process, set `payment_method` before process to the
gateway resolved from the wallet type, and correct it once the transaction is
readable. If no matching gateway can be resolved, do not process: fail the
order with a clear admin-facing note, because such an order could not be
refunded.

### 5.6 Settings

WooCommerce > Settings > Payments area, own section "wallee Express Checkout",
capability `manage_woocommerce`:

- Enabled (default off).
- Environment: Test or Production (default Test). Maps to
  `authorizationEnvironment`.
- Buttons per row and maximum rows.
- Apple Pay domain verification file content (textarea, stored as an option,
  served verbatim as `text/plain`). A status line shows the URL it is served
  at.
- Read-only status: main plugin detected, space ID in use.

No credentials fields.

### 5.7 What the prototype supports, and what it refuses

Supported: simple and variable products, virtual and downloadable products
(then no shipping callback is registered), coupons, fees, guest and logged-in
customers, shops with prices entered including or excluding tax.

Product prices including tax are fixed when the session is created, using the
tax location WooCommerce has for the customer at that moment (shop base or
geolocated). Only shipping depends on the wallet address. This is a limit of
the API and is stated in the plugin's readme.

Buttons are not shown when the cart contains subscription products, when the
cart needs more than one shipping package, or when the main plugin is not
configured.

### 5.8 Apple Pay verification file

`DomainAssociation` hooks early (before canonical redirects) and answers the
exact path `/.well-known/apple-developer-merchantid-domain-association` with
HTTP 200, `Content-Type: text/plain`, the stored content, and no redirect.
If nothing is stored it does nothing. The staging web server must hand
`/.well-known/` requests for files that do not exist to WordPress.

## 6. Staging environment on AWS

- One Lightsail instance in `eu-north-1` (smallest size that runs WordPress
  comfortably), Ubuntu LTS, a static IP, firewall open for 80 and 443 and SSH
  only. All resources tagged `project=wallee-express-checkout`.
- Docker Compose with three services: Caddy (automatic HTTPS), WordPress
  (official PHP/Apache image), MariaDB. WP-CLI available for setup.
- Host name: an IP-based wildcard DNS name (`<ip>.sslip.io`) to start with. A
  real subdomain replaces it before Apple Pay is tested, since the Apple
  registration is tied to the host name.
- The plugin folder from the repository is mounted into
  `wp-content/plugins/`. Deploy is `git pull` on the instance through a
  script, using a read-only deploy key.
- Provisioning script installs WordPress, WooCommerce, the wallee main plugin
  (current release from GitHub), sample products, a shipping zone with a flat
  rate and a free-shipping method, and tax rates.
- Secrets live in an `.env` file on the instance only. The wallee application
  user key is entered by the owner in the WordPress admin, never passed
  through the repository or chat.
- A basic-auth or IP restriction is not placed in front of the shop, because
  wallee and Apple must reach it. The WordPress admin gets a strong generated
  password.

## 7. Spike first

Before the plugin is built, a short throwaway test on staging answers what the
documentation leaves open. Results are written to
`docs/reference/spike-findings.md` and the design is adjusted if needed.

1. Do the v2.0 express-checkout endpoints accept the main plugin's SDK client
   (MAC auth) with a `space` header? If not, `ApiClient` signs a JWT (HS256,
   claims `sub`, `iat`, `requestPath`, `requestMethod`) and uses
   `wp_remote_request`.
2. The real handler script URL for the space.
3. With Bogus Pay: which fields the session carries at `APPROVED` (e-mail,
   billing address, names, phone), and when `linkedTransaction` appears.
4. What the shipping callback request really looks like, including headers,
   and how long wallee waits for an answer.
5. How line item taxes and discounts must be shaped for the session total to
   equal the WooCommerce total.
6. Session lifetime, if observable.

## 8. Security

- Every state-changing browser endpoint checks a nonce. Admin settings check
  `manage_woocommerce` and a nonce.
- Callback and return URLs are protected by an unguessable ID plus an HMAC
  signature compared with `hash_equals`, and by matching the wallee session ID
  and space ID in the body. The data that matters (addresses, amounts,
  approval state) is never taken from the browser or the callback body; it is
  fetched from wallee server to server.
- Amounts are verified between order and session before process.
- All input is validated and sanitised, all output escaped, all SQL through
  `$wpdb->prepare`. The WordPress coding standard is enforced with PHPCS.
- Rate limiting on session creation and the shipping callback (per IP and per
  session, transient-based), so the endpoints cannot be used to hammer the
  wallee API or the shipping calculation.
- Redirect targets are validated. Own URLs use `wp_safe_redirect`. The wallee
  `redirectUrl` must be HTTPS.
- No secrets in logs. Logging goes through the WooCommerce logger with a
  dedicated source and records IDs and states, not addresses or tokens.
- `uninstall.php` removes the table and options.

## 9. Testing

- Unit tests (PHPUnit, no WordPress needed, WordPress functions stubbed) for
  the pure parts: signature creation and verification, `LineItemBuilder`,
  shipping rate mapping and error mapping, amount comparison, state
  transitions.
- PHPCS (WordPress standard) and PHP lint in a GitHub Actions workflow on
  push.
- End-to-end on staging by hand with Bogus Pay, following a written checklist:
  shippable cart, virtual cart, coupon, logged-in and guest, address without
  shipping method, failed payment, reload of the return URL, refund and
  completion from the admin.
- PayPal and Apple Pay end-to-end once Bogus Pay passes. Apple Pay needs the
  real host name and a supported connector in the space.

## 10. Order of work

1. Repository, staging environment, base shop. The owner enters the wallee
   credentials in the main plugin's settings.
2. Spike (section 7).
3. Plugin skeleton, adapter, API client, settings.
4. Session creation and buttons on the cart page.
5. Shipping callback.
6. Return, order creation, linking, process, result.
7. Apple Pay verification file, cleanup, uninstall, readme.
8. End-to-end pass with Bogus Pay, then PayPal, then Apple Pay.

Implementation is delegated to subagents task by task, with the plan giving
each one the files, interfaces and acceptance checks it needs.

## 11. Left to the builder

Class internals, exact hook names and priorities, table column types, script
structure, wording of notices, PHPUnit setup, and the Lightsail bundle size.
