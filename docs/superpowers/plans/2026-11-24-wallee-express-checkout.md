# wallee Express Checkout for WooCommerce Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A WooCommerce add-on plugin that shows wallee Express Checkout wallet buttons on cart and product pages and turns a wallet approval into a paid WooCommerce order, running on a public AWS staging shop.

**Architecture:** The add-on reuses the wallee main plugin (credentials, SDK client, transaction table, gateways, webhooks) through one adapter class. A session is created from an immutable cart snapshot stored in the add-on's own table; the shipping callback, order creation and payment all work from that snapshot plus data fetched server to server from wallee. Staging is one EC2 instance running Docker Compose behind CloudFront.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WooCommerce 8.0+, wallee main plugin 3.4.11, plain JavaScript (no build), PHPUnit 9.6 with Brain Monkey, PHPCS with WordPress standard, Docker Compose, AWS CLI (EC2, CloudFront, SSM), GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-24-wallee-express-checkout-design.md`. Read it before any task. Section numbers below refer to it.

## Global Constraints

- PHP must run on 7.4: no enums, `readonly`, union types, `match`, named arguments or constructor promotion.
- Namespace `Wallee\ExpressCheckout`, PSR-4 from `plugin/wallee-express-checkout/src/`. No class name may start with `WC_Wallee` (the main plugin's autoloader claims that prefix).
- Options, table, transients, REST namespace and script handles use the prefix `wallee_ec_` / `wallee-ec`. Text domain `wallee-express-checkout`.
- Only `MainPluginAdapter` may reference main plugin classes, options or tables.
- No file of the main plugin is modified.
- Every SQL statement goes through `$wpdb->prepare`. All output is escaped, all input validated and sanitised. Signatures are compared with `hash_equals`.
- No secret, token, address or e-mail is written to logs, the repository or commit messages. Logs carry IDs and states only.
- Addresses, amounts and approval state are only ever taken from wallee's API response, never from the browser or the callback body.
- `merchantReference` is always the numeric WooCommerce order ID.
- AWS: profile `default`, region `eu-north-1`, every resource tagged `project=wallee-express-checkout`. Nothing named `merchant360` is read, changed or reused.
- Work happens on branch `prototype`. Commit after every task, messages end with the `Co-Authored-By` line the session provides.
- Local commands run through Docker (no local PHP assumed): `bin/test` and `bin/lint`, defined in Task 1.

## Review Focus

1. The return URL is opened twice (reload, back button, two tabs): exactly one order and one process call. Test in Task 9 (`claim`) and Task 15.
2. The cart or product changed after the wallet sheet opened (price edit, stock sold out, coupon expired): no charge, customer back in the cart with a notice. Tests in Task 14.
3. A forged or replayed shipping callback or return request (wrong signature, right UUID with another session's ID, wrong space): rejected without touching the snapshot or calling wallee. Tests in Tasks 4 and 13.
4. Rounding: prices excluding tax with several quantities and a percentage coupon must give line items whose sum equals the WooCommerce total to the cent. Tests in Task 6.
5. The wallet returns no e-mail, no billing address, or an address WooCommerce has no shipping zone for: clear failure before process, never a half-filled paid order. Tests in Tasks 13 and 14.

---

## File map

```
bin/test, bin/lint                         run PHPUnit / PHPCS + php -l in Docker
composer.json, phpunit.xml.dist, phpcs.xml.dist
.github/workflows/ci.yml
plugin/wallee-express-checkout/
  wallee-express-checkout.php              header, constants, autoloader, boot on plugins_loaded:21
  uninstall.php
  src/Plugin.php                           wiring, dependency check, compatibility declarations
  src/Autoloader.php
  src/MainPluginAdapter.php
  src/Security/UrlSigner.php
  src/Security/RateLimiter.php
  src/Support/Money.php
  src/Cart/CartSnapshot.php
  src/Cart/SnapshotFactory.php
  src/Cart/Eligibility.php
  src/Cart/LineItemBuilder.php
  src/Api/Transport.php                    interface
  src/Api/SdkTransport.php
  src/Api/ApiClient.php
  src/Api/ApiException.php
  src/Session/SessionRecord.php
  src/Session/SessionRepository.php
  src/Session/SessionService.php
  src/Session/Installer.php                table + cron
  src/Shipping/ShippingRateMapper.php
  src/Shipping/ShippingOptionsProvider.php
  src/Order/OrderValidator.php
  src/Order/OrderFactory.php
  src/Order/OrderLinker.php
  src/Http/SessionController.php           POST wallee-ec/v1/session
  src/Http/ShippingController.php          POST wallee-ec/v1/shipping-options
  src/Http/ReturnHandler.php               wc-api=wallee_ec_return
  src/Http/ResultHandler.php               wc-api=wallee_ec_result
  src/Http/DomainAssociation.php
  src/Frontend/CartButtons.php
  src/Frontend/ProductButtons.php
  src/Admin/Settings.php
  src/Admin/SetupService.php
  assets/js/express-checkout.js
  assets/css/express-checkout.css
tests/Unit/...                             mirrors src/
infra/aws/provision.sh, teardown.sh        EC2, security group, EIP, IAM/SSM, CloudFront
infra/docker-compose.yml, infra/wordpress/ wp-config additions, Apache origin-header check
infra/shop/provision-shop.sh               WP-CLI setup of the shop
infra/bin/remote, infra/bin/wp, infra/bin/deploy
docs/reference/express-checkout-api.json   schemas cut from wallee's OpenAPI spec
docs/reference/spike-findings.md
docs/testing/e2e-checklist.md
```

---

## Phase A: Foundation

### Task 1: Repository scaffold and tooling

**Files:** Create `composer.json`, `phpunit.xml.dist`, `phpcs.xml.dist`, `bin/test`, `bin/lint`, `.github/workflows/ci.yml`, `README.md`, `plugin/wallee-express-checkout/wallee-express-checkout.php`, `src/Autoloader.php`, `tests/bootstrap.php`, `tests/Unit/AutoloaderTest.php`.

**Interfaces:**
- Produces: `Wallee\ExpressCheckout\Autoloader::register(string $baseDir): void`; constants `WALLEE_EC_VERSION` (`0.1.0`), `WALLEE_EC_FILE`, `WALLEE_EC_PATH`; `bin/test [phpunit args]`; `bin/lint`.

- [ ] **Step 1:** `composer.json` with dev requirements `phpunit/phpunit:^9.6`, `brain/monkey:^2.6`, `wp-coding-standards/wpcs:^3.1`, `phpcompatibility/phpcompatibility-wp:*`, `dealerdirect/phpcodesniffer-composer-installer:^1.0`; autoload-dev PSR-4 `Wallee\\ExpressCheckout\\Tests\\` to `tests/`.
- [ ] **Step 2:** `bin/test` runs `composer install` once in `composer:2`, then `docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit "$@"`. `bin/lint` runs `php -l` over `plugin/` in `php:7.4-cli` and `vendor/bin/phpcs`. `phpcs.xml.dist`: standard `WordPress`, `testVersion` 7.4-, text domain `wallee-express-checkout`, PSR-4 file names allowed (exclude `WordPress.Files.FileName`), scope `plugin/`.
- [ ] **Step 3:** Failing test `AutoloaderTest::test_loads_class_from_src`: after `Autoloader::register( $pluginDir . '/src' )`, `class_exists( 'Wallee\ExpressCheckout\Support\Money' )` is false and `class_exists( 'Wallee\ExpressCheckout\Autoloader' )` is true; requesting `Other\Vendor\Foo` does not error. Run `bin/test`, expect FAIL.
- [ ] **Step 4:** Implement `Autoloader` and the bootstrap file (plugin header: `Requires Plugins: woocommerce`, `Requires PHP: 7.4`, `Requires at least: 6.0`, `WC requires at least: 8.0`; `defined( 'ABSPATH' ) || exit;`; registers autoloader; hooks `plugins_loaded` priority 21 to `Plugin::boot()` only if that class exists).
- [ ] **Step 5:** `ci.yml`: on push, run `composer install`, `vendor/bin/phpunit`, `vendor/bin/phpcs`, and `php -l` on PHP 7.4 and 8.2.
- [ ] **Step 6:** `bin/test` PASS, `bin/lint` clean. Commit.

### Task 2: Staging shop on AWS

Creates billable AWS resources. The controller confirms with the owner before running `provision.sh`.

**Files:** Create everything under `infra/`.

**Interfaces:**
- Produces: `infra/aws/provision.sh` (idempotent; writes `infra/.state.json` with instance ID, distribution ID, shop URL; file is git-ignored), `infra/aws/teardown.sh`, `infra/bin/remote "<shell command>"` (SSM send-command, prints output), `infra/bin/wp <args>` (WP-CLI in the WordPress container), `infra/bin/deploy` (`git pull` on the instance), shop URL `https://<id>.cloudfront.net`.

- [ ] **Step 1:** `provision.sh` per spec section 6: IAM role and instance profile with `AmazonSSMManagedInstanceCore`; security group allowing TCP 80 only from the managed prefix list `com.amazonaws.global.cloudfront.origin-facing`; one `t4g.small` Ubuntu 24.04 ARM instance, 20 GB gp3, Elastic IP, user data installing Docker and Compose; CloudFront distribution with the instance's public DNS name as HTTP-only origin, viewer protocol redirect-to-https, cache policy `CachingDisabled`, origin request policy `AllViewer`, all HTTP methods allowed, and a custom origin header `X-Origin-Secret` with a value generated by the script and stored in SSM Parameter Store as SecureString `/wallee-express-checkout/origin-secret`.
- [ ] **Step 2:** `docker-compose.yml`: `db` (mariadb:11, named volume), `wordpress` (wordpress:6-php8.2-apache, named volume for `/var/www/html`, bind mount of `plugin/wallee-express-checkout` to `wp-content/plugins/wallee-express-checkout`, port 80), `wpcli` (wordpress:cli, profile `tools`). Secrets from `/opt/wallee-ec/.env` on the instance, generated there. `infra/wordpress/`: Apache config rejecting requests without the right `X-Origin-Secret` (403), and `WORDPRESS_CONFIG_EXTRA` setting `WP_HOME`/`WP_SITEURL` to the CloudFront URL and `$_SERVER['HTTPS']='on'` when `CloudFront-Forwarded-Proto` is `https`.
- [ ] **Step 3:** Read-only deploy key: generate on the instance, add with `gh repo deploy-key add`, clone the repository to `/opt/wallee-ec/repo`.
- [ ] **Step 4:** `provision-shop.sh` through WP-CLI: core install (admin password generated and stored as SSM SecureString `/wallee-express-checkout/wp-admin-password`), WooCommerce latest 9.x or newer stable, wallee main plugin from the GitHub tag `3.4.11` of `wallee-payment/woocommerce` installed as `woo-wallee`, Storefront theme, currency CHF, prices entered including tax, tax rate CH 8.1 % also applied to shipping, shipping zone "Switzerland" with flat rate 7.50 and free shipping from 100.00, zone "Germany" with flat rate 15.00, guest checkout on, a terms page, products: simple "Notebook" 25.00, variable "T-Shirt" (sizes S/M/L, 30.00), virtual "Gift card" 50.00, one coupon `TEN` (10 %). One page with the classic `[woocommerce_cart]` shortcode at `/classic-cart/` in addition to the default block cart. Pretty permalinks.
- [ ] **Step 5:** Verify: `curl -sI https://<id>.cloudfront.net/` is 200; `curl -s https://<id>.cloudfront.net/wp-json/wc/store/v1/products | head -c 200` lists products; `curl -sI http://<elastic-ip>/` times out or is refused; `infra/bin/wp plugin list` shows `woocommerce`, `woo-wallee` active and `wallee-express-checkout` present.
- [ ] **Step 6:** Commit. Controller hands the owner the shop URL and tells them where the admin password is; **owner step:** enter space ID, user ID and key in the main plugin's settings and save.

### Task 3: Spike against the real API

Throwaway code under `spike/` (deleted at the end of the task). Answers spec section 7, items 1 to 7.

**Files:** Create `docs/reference/express-checkout-api.json`, `docs/reference/spike-findings.md`.

- [ ] **Step 1:** Cut the `ExpressCheckout*`, `LineItem`, `LineItemCreate` (if present), `Tax`, `TaxCreate`, `Address` schemas and the three paths from `https://app-wallee.com/api/spec3.json` into `express-checkout-api.json`.
- [ ] **Step 2:** `spike/create.php` run with `infra/bin/wp eval-file`: obtain the client with `WC_Wallee_Helper::instance()->get_api_client()`, call `callApi( '/v2.0/express-checkout/session/create', 'POST', [], $body, [ 'space' => $spaceId, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ] )` with one shippable 25.00 CHF item, `authorizationEnvironment` `TEST`, redirect and callback URLs pointing at two `spike/` mu-plugin endpoints that log the full request (headers and body) to a file. Record whether MAC auth is accepted. If it is rejected with 401/403, repeat with a hand-built JWT (HS256 over the base64-decoded key; header `{"alg":"HS256","typ":"JWT","ver":1}`; claims `sub`, `iat`, `requestPath`, `requestMethod`) via `wp_remote_request`.
- [ ] **Step 3:** A spike page that loads the handler script and calls `ExpressCheckoutHandler.load`. Establish the working script URL. The controller (or owner) clicks through Bogus Pay in a browser once with a Swiss address.
- [ ] **Step 4:** After approval, `GET session` and dump it; then `process`; then `GET session` again and read the linked transaction through the main plugin's `WC_Wallee_Service_Transaction::instance()->get_transaction()`.
- [ ] **Step 5:** Write `spike-findings.md` with a definite answer to each of the seven items, including redacted request and response samples, and a section "Plan changes required" (empty if none). Delete `spike/`. Commit.
- [ ] **Step 6 (controller):** Amend Tasks 6, 8, 13, 14 and 15 where the findings contradict them, before Phase B continues past Task 7.

---

## Phase B: Plugin

Tasks 4 to 7 do not depend on the spike and can run while Task 3 is open.

### Task 4: Signing, money and rate limiting helpers

**Files:** Create `src/Security/UrlSigner.php`, `src/Security/RateLimiter.php`, `src/Support/Money.php`; tests `tests/Unit/Security/UrlSignerTest.php`, `tests/Unit/Support/MoneyTest.php`.

**Interfaces (Produces):**
- `UrlSigner::__construct(string $key)`; `sign(string $uuid, string $purpose): string` (hex HMAC-SHA256 of `"$purpose|$uuid"`); `verify(string $uuid, string $purpose, string $signature): bool`; `static fromWordPress(): self` (key `hash( 'sha256', 'wallee_ec|' . wp_salt( 'auth' ) )`). Purposes are the strings `return`, `shipping`, `result`.
- `UrlSigner::newUuid(): string` returns `bin2hex( random_bytes( 32 ) )`.
- `Money::round($amount, int $decimals): float`; `Money::equals($a, $b, int $decimals): bool`; `Money::decimals(string $currency): int` returns 0 for `JPY`, `KRW`, `VND`, `CLP`, `ISK`, 3 for `BHD`, `KWD`, `OMR`, `JOD`, `TND`, and 2 for everything else (independent of the shop's display setting).
- `RateLimiter::allow(string $bucket, int $max, int $windowSeconds): bool` (transient counter keyed by `wallee_ec_rl_` + md5 of bucket).

- [ ] **Step 1:** Failing tests:

```php
public function test_signature_is_bound_to_uuid_and_purpose() {
    $s   = new UrlSigner( 'k' );
    $sig = $s->sign( 'abc', 'return' );
    $this->assertSame( 64, strlen( $sig ) );
    $this->assertTrue( $s->verify( 'abc', 'return', $sig ) );
    $this->assertFalse( $s->verify( 'abc', 'shipping', $sig ) );
    $this->assertFalse( $s->verify( 'abd', 'return', $sig ) );
    $this->assertFalse( $s->verify( 'abc', 'return', '' ) );
    $this->assertFalse( ( new UrlSigner( 'other' ) )->verify( 'abc', 'return', $sig ) );
}
public function test_new_uuid_is_64_hex_and_unique() { /* two calls differ, ctype_xdigit, strlen 64 */ }
public function test_money_equals_at_currency_precision() {
    $this->assertTrue( Money::equals( 32.5, '32.50', 2 ) );
    $this->assertTrue( Money::equals( 0.1 + 0.2, 0.3, 2 ) );
    $this->assertFalse( Money::equals( 32.50, 32.51, 2 ) );
    $this->assertTrue( Money::equals( 1000.4, 1000, 0 ) );
}
```

- [ ] **Step 2:** `bin/test` FAIL. Implement. `bin/test` PASS. Commit.

### Task 5: Cart snapshot

**Files:** Create `src/Cart/CartSnapshot.php`, `src/Cart/SnapshotFactory.php`, `src/Cart/Eligibility.php`; test `tests/Unit/Cart/CartSnapshotTest.php`.

**Interfaces (Produces):**
- `CartSnapshot` (immutable, getters only): `context(): string` (`cart`|`product`), `currency(): string`, `customerId(): int`, `pricesIncludeTax(): bool`, `taxLocation(): array` (`country`, `state`, `postcode`, `city`), `items(): array`, `fees(): array`, `coupons(): string[]`, `total(): float` (items and fees including tax, after discounts, without shipping), `needsShipping(): bool`, `hash(): string` (sha256 of the canonical JSON), `toArray(): array`, `static fromArray(array $data): self`.
- Item shape: `key`, `product_id`, `variation_id`, `variation` (attribute map), `name`, `sku`, `quantity`, `line_subtotal`, `line_subtotal_tax`, `line_total`, `line_tax`, `tax_class`, `tax_rates` (list of `[ 'label' => string, 'rate' => float ]`), `needs_shipping` (bool). Fee shape: `id`, `name`, `total`, `tax`, `tax_rates`.
- `SnapshotFactory::fromCart(\WC_Cart $cart): CartSnapshot`; `fromProduct(int $productId, int $variationId, array $variation, int $quantity): CartSnapshot` (prices the line in a fresh `WC_Cart` instance that is not the session cart and is never persisted; throws `\InvalidArgumentException` if the product is not purchasable, not in stock for the quantity, or is grouped/external).
- `Eligibility::reason(CartSnapshot $s): ?string` returns null when buttons may be shown, else one of `empty`, `subscription`, `multiple_packages`, `guest_checkout_disabled`, `not_configured`.

- [ ] **Step 1:** Failing tests: `fromArray( $s->toArray() )` equals the original and has the same `hash()`; changing a quantity changes the hash; `needsShipping()` is true if any item needs shipping; `total()` is the sum of `line_total + line_tax` over items plus `total + tax` over fees (fixture: 2 x Notebook line_total 46.25 line_tax 3.75, fee total 1.85 tax 0.15, expect 52.00).
- [ ] **Step 2:** FAIL, implement `CartSnapshot`, PASS.
- [ ] **Step 3:** Implement `SnapshotFactory` and `Eligibility` (not unit tested; verified on staging in Task 11). `taxLocation` from `WC()->customer->get_taxable_address()`.
- [ ] **Step 4:** `bin/lint` clean. Commit.

### Task 6: Line item builder

**Files:** Create `src/Cart/LineItemBuilder.php`; test `tests/Unit/Cart/LineItemBuilderTest.php`.

**Interfaces:**
- Consumes: `CartSnapshot`, `Money`.
- Produces: `LineItemBuilder::build(CartSnapshot $s): array` returning a list of arrays with keys `uniqueId`, `sku`, `name`, `type` (`PRODUCT`|`FEE`|`DISCOUNT`), `quantity`, `amountIncludingTax`, `shippingRequired`, `taxes` (list of `[ 'title' => string, 'rate' => float ]`). `LineItemBuilder::uniqueIdFor(string $kind, string $key): string` returns `ec_` + kind + `_` + first 32 chars of sha1 of key (kinds `item`, `fee`, `shipping`).

Decision: coupons are not separate lines. Each product line carries its amount after discount (`line_total + line_tax`), so the line items sum to the snapshot total and refunds map one to one. A negative fee is type `DISCOUNT`. `sku` falls back to the product ID; `name` and `sku` are cut to 150 and 200 characters. If the rounded sum differs from `Money::round( $s->total() )`, the difference is added to the line with the largest amount (never a separate adjustment line).

- [ ] **Step 1:** Failing tests:

```php
public function test_sum_equals_snapshot_total_with_coupon_and_quantities() {
    // 3 x 19.99 excl. tax 8.1 %, 10 % coupon: line_total 53.973, line_tax 4.371813
    // 1 x 25.00 incl. tax: line_total 23.126735, line_tax 1.873265
    $items = ( new LineItemBuilder() )->build( $snapshot );
    $this->assertSame( 83.34, round( array_sum( array_column( $items, 'amountIncludingTax' ) ), 2 ) );
    $this->assertSame( 58.34, $items[0]['amountIncludingTax'] );
    $this->assertSame( 25.00, $items[1]['amountIncludingTax'] );
}
public function test_unique_ids_are_stable_and_distinct() { /* same snapshot twice gives same ids; two items differ */ }
public function test_virtual_item_is_not_shipping_required() {}
public function test_negative_fee_becomes_discount_positive_fee_becomes_fee() {}
public function test_taxes_carry_title_and_rate() { /* [ [ 'title' => 'VAT', 'rate' => 8.1 ] ] */ }
```

- [ ] **Step 2:** FAIL, implement, PASS, lint, commit.

### Task 7: Main plugin adapter and plugin boot

**Files:** Create `src/MainPluginAdapter.php`, `src/Plugin.php`; test `tests/Unit/MainPluginAdapterTest.php`.

**Interfaces (Produces):**
- `MainPluginAdapter::TESTED_UP_TO = '3.4.11'`
- `missingRequirements(): string[]` (empty when usable). Checks existence of: classes `WooCommerce_Wallee`, `WC_Wallee_Helper`, `WC_Wallee_Service_Transaction`, `WC_Wallee_Service_Webhook`, `WC_Wallee_Service_Method_Configuration`, `WC_Wallee_Entity_Method_Configuration`; methods `WC_Wallee_Helper::get_api_client`, `::get_base_gateway_url`, `WC_Wallee_Service_Transaction::get_transaction`, `::update_transaction_info`, `WC_Wallee_Service_Webhook::install`, `WC_Wallee_Service_Method_Configuration::synchronize`, `WC_Wallee_Entity_Method_Configuration::load_by_configuration`.
- `isConfigured(): bool` (space ID, user ID and key options non-empty); `version(): string`; `isNewerThanTested(): bool`
- `spaceId(): int`; `baseUrl(): string` (with trailing slash); `sdkClient(): object`
- `readTransaction(int $transactionId): object` (v1 SDK Transaction)
- `linkOrder(object $transaction, \WC_Order $order): void` (calls `update_transaction_info`)
- `resolveGatewayId(object $transaction): ?string` (`wallee_<local id>`; on a miss calls `synchronize()` once and retries; null if still unknown or the gateway is not in `WC()->payment_gateways()->payment_gateways()`)
- `installWebhooks(): void`; `syncPaymentMethods(): void`
- `log(string $message, string $level = 'info'): void` (WC logger, source `wallee-express-checkout`)
- `Plugin::boot(): void`; `Plugin::adapter(): MainPluginAdapter`; `Plugin::isActive(): bool` (requirements met and configured).

- [ ] **Step 1:** Failing test with Brain Monkey: with none of the classes defined, `missingRequirements()` lists all six classes; `isNewerThanTested()` is true for `3.5.0` and false for `3.4.11` and `3.4.2` (inject version through a protected method overridden in the test).
- [ ] **Step 2:** FAIL, implement adapter, PASS.
- [ ] **Step 3:** `Plugin::boot()`: if WooCommerce or requirements are missing, register only an admin notice naming what is missing, and return. Otherwise declare `custom_order_tables` and `cart_checkout_blocks` compatibility on `before_woocommerce_init` (registered from the bootstrap file, since boot runs later), show a dismissible warning when `isNewerThanTested()`, and wire the components of later tasks (each task adds its own line in `Plugin::boot()`).
- [ ] **Step 4:** `infra/bin/deploy`, then `infra/bin/wp plugin activate wallee-express-checkout` succeeds and `infra/bin/wp eval 'var_dump( Wallee\ExpressCheckout\Plugin::isActive() );'` prints `bool(true)`. Lint, commit.

### Task 8: API client

**Files:** Create `src/Api/Transport.php`, `SdkTransport.php`, `ApiClient.php`, `ApiException.php`; test `tests/Unit/Api/ApiClientTest.php`.

**Interfaces:**
- Consumes: `MainPluginAdapter::sdkClient()`, `spaceId()`; `docs/reference/spike-findings.md` item 1.
- Produces:
  - `interface Transport { public function request(string $method, string $path, array $query, ?array $body): array; }` returning the decoded JSON as an associative array, throwing `ApiException` (with `getStatusCode(): int`) on non-2xx or transport failure.
  - `SdkTransport::__construct(MainPluginAdapter $adapter)`: uses `callApi` with header `space`. If the spike found MAC is rejected, this task instead creates `JwtTransport` with the same interface using `wp_remote_request` (timeout 15 s) and the JWT described in Task 3 Step 2.
  - `ApiClient::__construct(Transport $t)`; `createSession(array $payload): array` (returns `[ 'sessionId' => int, 'sessionToken' => string ]`, throws if either is missing); `getSession(int $sessionId): array`; `processSession(int $sessionId, string $merchantReference, string $successUrl, string $failureUrl): string` (the redirect URL; throws if it is not an `https://` URL).

- [ ] **Step 1:** Failing tests with a recording fake transport: `createSession` posts to `/v2.0/express-checkout/session/create` with the payload unchanged; `getSession( 5 )` is `GET /v2.0/express-checkout/session` with query `[ 'sessionId' => 5 ]`; `processSession( 5, '812', $s, $f )` posts exactly `[ 'sessionId' => 5, 'merchantReference' => '812', 'successUrl' => $s, 'failureUrl' => $f ]`; a response `[ 'redirectUrl' => 'http://x' ]` or `javascript:` throws `ApiException`; a create response without `sessionToken` throws.
- [ ] **Step 2:** FAIL, implement, PASS, lint, commit.

### Task 9: Session storage

**Files:** Create `src/Session/SessionRecord.php`, `SessionRepository.php`, `Installer.php`, `uninstall.php`; test `tests/Unit/Session/SessionRepositoryTest.php`.

**Interfaces (Produces):**
- States: constants on `SessionRecord`: `CREATED`, `ORDERING`, `PROCESSED`, `COMPLETED`, `FAILED` (lower-case string values).
- `SessionRecord` public getters: `id(): int`, `uuid(): string`, `walleeSessionId(): int`, `spaceId(): int`, `snapshot(): CartSnapshot`, `state(): string`, `orderId(): ?int`, `transactionId(): ?int`, `createdAt(): string`.
- `SessionRepository::__construct(\wpdb $db)`; `insert(string $uuid, int $walleeSessionId, int $spaceId, CartSnapshot $s): int`; `findByUuid(string $uuid): ?SessionRecord` (returns null unless `$uuid` is 64 hex chars); `claim(int $id, string $from, string $to): bool`; `update(int $id, array $fields): void` (allowed keys `state`, `order_id`, `transaction_id`); `purgeOlderThan(int $days): int`.
- `Installer::install(): void` (dbDelta, option `wallee_ec_db_version` = `1`, daily cron `wallee_ec_cleanup`); `Installer::maybeUpgrade(): void` on `admin_init`; cron handler purges rows older than 7 days.
- Table `{prefix}wallee_ec_session`: `id` bigint unsigned PK auto-increment, `session_uuid` char(64) unique, `wallee_session_id` bigint unsigned indexed, `space_id` bigint unsigned, `cart_hash` char(64), `snapshot` longtext, `state` varchar(20) indexed, `order_id` bigint unsigned null, `transaction_id` bigint unsigned null, `created_at` datetime indexed, `updated_at` datetime.

- [ ] **Step 1:** Failing tests with a `wpdb` test double recording queries: `claim( 7, 'created', 'ordering' )` issues one `UPDATE` whose WHERE contains both `id` and `state` placeholders, and returns true only when `rows_affected` is 1, false when 0; `findByUuid( 'not-hex' )` returns null without querying; `update( 1, [ 'snapshot' => 'x' ] )` throws `\InvalidArgumentException`.
- [ ] **Step 2:** FAIL, implement, PASS.
- [ ] **Step 3:** `uninstall.php` drops the table, deletes all `wallee_ec_%` options and clears the cron. Register activation hook in the bootstrap file and wire `maybeUpgrade` in `Plugin::boot()`.
- [ ] **Step 4:** Deploy; `infra/bin/wp db query "DESCRIBE wp_wallee_ec_session"` lists the columns; two concurrent `claim` calls from `wp eval` on the same row give exactly one `true`. Lint, commit.

### Task 10: Settings and Configure action

**Files:** Create `src/Admin/Settings.php`, `src/Admin/SetupService.php`; test `tests/Unit/Admin/SettingsTest.php`.

**Interfaces (Produces):**
- Options and defaults: `wallee_ec_cart_enabled` (`no`), `wallee_ec_product_enabled` (`no`), `wallee_ec_environment` (`TEST`; allowed `TEST`, `PRODUCTION`), `wallee_ec_max_columns` (`2`, 1 to 6), `wallee_ec_max_rows` (`0` meaning no limit, 0 to 10), `wallee_ec_apple_domain_association` (empty).
- `Settings::cartEnabled(): bool`; `productEnabled(): bool`; `environment(): string`; `maxColumns(): int`; `maxRows(): ?int`; `appleDomainAssociation(): string`.
- `SetupService::__construct(MainPluginAdapter $a, ApiClient $c)`; `run(): array` returning a list of `[ 'step' => string, 'ok' => bool, 'message' => string ]` for the steps `credentials` (reads the space through the SDK client), `webhooks` (`installWebhooks()`), `payment_methods` (`syncPaymentMethods()`).

- [ ] **Step 1:** Failing tests (Brain Monkey `get_option` stubs): `environment()` returns `TEST` for any stored value other than `PRODUCTION`; `maxColumns()` clamps `0` to 1 and `9` to 6; `maxRows()` returns null for `0`; `cartEnabled()` and `productEnabled()` are independent.
- [ ] **Step 2:** FAIL, implement getters, PASS.
- [ ] **Step 3:** Section "wallee Express Checkout" under WooCommerce > Settings > Payments via `woocommerce_get_sections_checkout` and `woocommerce_get_settings_checkout`, with the fields of spec 5.6, a sanitise callback for the Apple file content that keeps it verbatim apart from `wp_unslash` and trimming, a read-only status block (main plugin version, space ID, served URL of the verification file) and a "Configure" button posting to `admin-post.php?action=wallee_ec_configure` with a nonce; the handler checks `manage_woocommerce` and the nonce, runs `SetupService::run()`, stores the result in a 60 s transient and redirects back, where it is shown as notices.
- [ ] **Step 4:** Deploy; in the admin both checkboxes save independently; "Configure" shows three green results. Lint, commit.

### Task 11: Session service and endpoint

**Files:** Create `src/Session/SessionService.php`, `src/Http/SessionController.php`; test `tests/Unit/Session/SessionServiceTest.php`.

**Interfaces:**
- Consumes: `SnapshotFactory`, `Eligibility`, `LineItemBuilder`, `ApiClient::createSession`, `SessionRepository::insert`, `UrlSigner`, `Settings`, `MainPluginAdapter`, `RateLimiter`.
- Produces:
  - `SessionService::create(CartSnapshot $s, string $language): array` returning `[ 'sessionToken' => string, 'uuid' => string ]`. Payload: `currency`, `authorizationEnvironment` from settings, `language`, `lineItems`, `merchantRedirectUrl` = `SessionService::returnUrl( $uuid )`, and `merchantShippingCallbackUrl` = `SessionService::shippingUrl( $uuid )` only when `needsShipping()`.
  - `static returnUrl(string $uuid): string` = `home_url( '/' )` with query `wc-api=wallee_ec_return`, `ec_session`, `sig` (purpose `return`); `static shippingUrl(string $uuid): string` = `rest_url( 'wallee-ec/v1/shipping-options' )` with `ec_session`, `sig` (purpose `shipping`); `static resultUrl(string $uuid, string $status): string` = `wc-api=wallee_ec_result`, `status` (`success`|`failure`), `ec_session`, `sig` (purpose `result`).
  - REST `POST /wp-json/wallee-ec/v1/session`, body `{ "context": "cart" }` or `{ "context": "product", "productId": int, "variationId": int, "variation": { ... }, "quantity": int }`. Requires header `X-WP-Nonce` (action `wp_rest`, so logged-in customers are recognised) and header `X-Wallee-Ec-Nonce` valid for action `wallee_ec_session`, both printed with the buttons. A missing or invalid one gives 403. Responses: 200 `{ "sessionToken": "..." }`; 200 `{ "sessionToken": null, "reason": "<Eligibility reason>" }` when not eligible or the setting for that context is off; 429 when `RateLimiter::allow( 'session|' . ip, 20, 60 )` is false; 502 `{ "error": "unavailable" }` on `ApiException` (logged without payload).
  - Reuse: the WooCommerce session key `wallee_ec_session` holds `[ 'context', 'hash', 'token', 'uuid', 'created' ]`; an entry with the same context and hash younger than 15 minutes is returned without calling wallee.

- [ ] **Step 1:** Failing tests with fakes: payload has no `merchantShippingCallbackUrl` for an all-virtual snapshot and has one otherwise; both URLs contain the same `ec_session` and signatures that verify for their own purpose only; the repository receives the `sessionId` returned by the client; an `ApiException` from the client is rethrown and nothing is inserted.
- [ ] **Step 2:** FAIL, implement, PASS.
- [ ] **Step 3:** Controller with `permission_callback` doing the nonce check, strict argument schemas (`absint`, `sanitize_text_field` on attribute keys and values), `wc_load_cart()` before use. Wire in `Plugin::boot()`.
- [ ] **Step 4:** Deploy; with a product in the cart and a nonce from the page, `curl` to the endpoint returns a token; without nonce 403; 21 rapid calls give 429. Lint, commit.

### Task 12: Buttons on cart and product pages

**Files:** Create `src/Frontend/CartButtons.php`, `src/Frontend/ProductButtons.php`, `assets/js/express-checkout.js`, `assets/css/express-checkout.css`.

**Interfaces:**
- Consumes: `Settings`, `Plugin::isActive()`, `MainPluginAdapter::baseUrl()`, endpoint of Task 11, handler script URL from `spike-findings.md` item 2 (default `<baseUrl>assets/payment/express-checkout-handler.js`).
- Produces: container markup `<div class="wallee-ec" data-context="cart|product"><div id="wallee-ec-container-<context>"></div><p class="wallee-ec-terms">…</p></div>`; script handle `wallee-ec`, localised object `walleeEc` with `endpoint`, `nonce`, `restNonce`, `maxColumns`, `maxRows`, `context`.

- [ ] **Step 1:** `CartButtons`: when `cartEnabled()` and `isActive()`, output on `woocommerce_proceed_to_checkout` (priority 20) for the classic cart, and for the block cart append the container to the output of block `woocommerce/proceed-to-checkout-block` through `render_block`. Enqueue only on `is_cart()`.
- [ ] **Step 2:** `ProductButtons`: when `productEnabled()`, output on `woocommerce_after_add_to_cart_button` for simple and variable products only. Enqueue only on `is_product()`.
- [ ] **Step 3:** Terms line shown only when `wc_terms_and_conditions_page_id()` is set, text "By paying you accept our terms and conditions." with the link, translatable.
- [ ] **Step 4:** `express-checkout.js` (plain ES2017, no dependencies besides `jquery` on classic pages where WooCommerce already loads it): `requestSession(context)` posts to the endpoint; on a token, empties the container and calls `window.ExpressCheckoutHandler.load( containerId, token, { maxColumns, maxRows, onError } )`; on `sessionToken: null` or any error hides `.wallee-ec`. Cart context: re-request on jQuery `updated_cart_totals` and, on block pages, on `wp.data.subscribe` when the cart store's totals or item count change, debounced 400 ms. Product context: request on load for simple products; for variable products on `found_variation` (with the variation ID and attributes), hide on `reset_data`; re-request on quantity `change`, debounced 400 ms. A request in flight is aborted (`AbortController`) when a newer one starts, and stale responses are ignored.
- [ ] **Step 5:** Deploy and verify in a browser on staging with both settings on: Bogus Pay button renders on `/cart/` (block), `/classic-cart/`, the Notebook page, and on the T-Shirt page only after a size is chosen; changing quantity re-renders; with a setting off, no markup and no script on that page type. Lint, commit.

### Task 13: Shipping callback

**Files:** Create `src/Shipping/ShippingRateMapper.php`, `ShippingOptionsProvider.php`, `src/Http/ShippingController.php`; tests `tests/Unit/Shipping/ShippingRateMapperTest.php`, `tests/Unit/Http/ShippingControllerTest.php`.

**Interfaces:**
- Consumes: `SessionRepository::findByUuid`, `UrlSigner`, `CartSnapshot`, `RateLimiter`, `spike-findings.md` item 4.
- Produces:
  - `ShippingRateMapper::toOption(array $rate, string $currency): array` where `$rate` is `[ 'id', 'label', 'cost', 'tax', 'tax_rates' ]`, returning `[ 'id', 'label', 'description' => '', 'amountIncludingTax', 'taxes', 'currency' ]`.
  - `ShippingRateMapper::success(array $options): array` = `[ 'shippingOptions' => $options, 'error' => null ]`; `ShippingRateMapper::error(string $code, string $message): array` = `[ 'shippingOptions' => [], 'error' => [ 'error' => $code, 'message' => $message, 'timestamp' => gmdate( 'c' ) ] ]`.
  - `ShippingOptionsProvider::ratesFor(CartSnapshot $s, array $destination): array` (list of rate arrays as above; destination keys `country`, `state`, `postcode`, `city`, `address_1`). Builds one package from the snapshot's shippable items with `contents_cost` after discounts and `applied_coupons` from the snapshot, calls `WC()->shipping()->calculate_shipping_for_package()`. Shipping tax is calculated with the snapshot's `taxLocation`, not the destination (spec 5.7).
  - `ShippingOptionsProvider::errorCodeFor(array $destination): string`: `COUNTRY_UNAVAILABLE` if the country is not in `WC()->countries->get_shipping_countries()`, else `SHIPPING_METHOD_UNAVAILABLE`.
  - REST `POST /wp-json/wallee-ec/v1/shipping-options?ec_session=&sig=`, `permission_callback` `__return_true`, all checks inside: 403 bad signature; 404 unknown session; 409 state not `created`; 403 when body `expressCheckoutId` differs from the row's wallee session ID or body `spaceId` from the row's space ID; 400 when `countryCode` is not two letters; 429 beyond 60 calls per session per minute; otherwise always 200 with the mapper's structure.

- [ ] **Step 1:** Failing mapper tests: rate `[ 'id' => 'flat_rate:1', 'label' => 'Flat rate', 'cost' => 6.94, 'tax' => 0.56, 'tax_rates' => [ [ 'label' => 'VAT', 'rate' => 8.1 ] ] ]` with `CHF` gives `amountIncludingTax` 7.50, `taxes` `[ [ 'title' => 'VAT', 'rate' => 8.1 ] ]`, `currency` `CHF`; a free rate gives `0.0` and empty `taxes`; `error( 'COUNTRY_UNAVAILABLE', 'x' )` has an empty `shippingOptions` list and the code under `error.error`.
- [ ] **Step 2:** Failing controller tests with fakes for repository and provider: wrong signature gives 403 and the provider is never called; a valid signature with a different `expressCheckoutId` gives 403; state `processed` gives 409; no rates gives 200 with `error.error` `SHIPPING_METHOD_UNAVAILABLE`; lower-case `ch` is accepted and passed on as `CH`.
- [ ] **Step 3:** FAIL, implement, PASS.
- [ ] **Step 4:** Deploy; in the Bogus Pay sheet a Swiss address shows "Flat rate 7.50", a cart of 100.00 or more also shows "Free shipping", a German address shows 15.00, a US address shows the wallet's "cannot ship" message. Lint, commit.

### Task 14: Order validation and creation

**Files:** Create `src/Order/OrderValidator.php`, `src/Order/OrderFactory.php`; tests `tests/Unit/Order/OrderValidatorTest.php`.

**Interfaces:**
- Consumes: `CartSnapshot`, `LineItemBuilder::uniqueIdFor`, `ShippingOptionsProvider::ratesFor`, `Money`, session array from `ApiClient::getSession`, `spike-findings.md` items 3, 5 and 7.
- Produces:
  - `OrderValidator::selectedOption(array $session): ?array` (the single entry of `shippingOptions` with `selected` true).
  - `OrderValidator::email(array $session, int $customerId): ?string` (billing `emailAddress`, else shipping, else the user's e-mail; must pass `is_email`).
  - `OrderValidator::check(CartSnapshot $s, array $session, array $rates): ?string` returns null or a reason code: `not_approved` (state is not `APPROVED`), `currency_mismatch`, `no_email`, `no_address` (neither address has a country), `shipping_missing` (snapshot needs shipping and no option is selected), `shipping_changed` (selected ID not in `$rates` or amount differs), `amount_mismatch` (snapshot total plus selected shipping differs from `authorizationAmount`).
  - `OrderValidator::stockAndCoupons(CartSnapshot $s, string $email): ?string` returns null, `out_of_stock` or `coupon_invalid`.
  - `OrderFactory::create(CartSnapshot $s, array $session, array $selectedRate, string $email, string $uuid): \WC_Order` (status `pending`, `created_via` `wallee_express_checkout`, `payment_method` `wallee_express_checkout`, `payment_method_title` "wallee Express Checkout"; product items with snapshot totals and taxes set explicitly, not recalculated from current prices; coupon items; fee items; one shipping item from `$selectedRate`; `_wallee_unique_line_item_id` on every item using `uniqueIdFor` with the same kind and key as Task 6, shipping key from spike item 7; addresses mapped from wallee Address fields `givenName`, `familyName`, `organizationName`, `street`, `city`, `postcode`, `postalState`, `country`, `phoneNumber`, `emailAddress`, the missing address copied from the other; meta `_wallee_ec_session_uuid`, `_wallee_ec_wallet`; private note when the shipping country differs from the snapshot's tax country; `$order->calculate_totals( false )` so stored taxes are kept).
  - `OrderFactory::fail(\WC_Order $order, string $reason): void` (status `failed`, private note with the reason code).

- [ ] **Step 1:** Failing validator tests, one per reason code, using a base fixture (snapshot total 50.00 CHF needing shipping, session `APPROVED`, CHF, `authorizationAmount` 57.50, option `flat_rate:1` 7.50 selected, billing e-mail `a@example.com`, rates containing `flat_rate:1` at 7.50) that returns null, then one mutation each: state `PENDING`; currency `EUR`; no e-mail anywhere and customer 0; both addresses empty; no selected option; selected `flat_rate:9`; selected amount 6.50; `authorizationAmount` 57.49. Plus: all-virtual snapshot with no options and amount 50.00 returns null; shipping e-mail is used when billing has none.
- [ ] **Step 2:** FAIL, implement validator, PASS.
- [ ] **Step 3:** Implement `stockAndCoupons` (products still exist, are purchasable, `has_enough_stock`, sold-individually respected; each coupon passes `WC_Discounts::is_coupon_valid` with the e-mail checked against the coupon's e-mail restrictions and per-user usage limit) and `OrderFactory`.
- [ ] **Step 4:** Deploy; from `wp eval` build a snapshot for 2 x Notebook with coupon `TEN`, a fake session array and create an order: `get_total()` equals snapshot total plus 7.50; every item has `_wallee_unique_line_item_id`; order shows correct tax lines in the admin. Lint, commit.

### Task 15: Return and result handling

**Files:** Create `src/Order/OrderLinker.php`, `src/Http/ReturnHandler.php`, `src/Http/ResultHandler.php`; test `tests/Unit/Http/ReturnHandlerTest.php`.

**Interfaces:**
- Consumes: everything above; `spike-findings.md` item 3 (when `linkedTransaction` appears).
- Produces:
  - `OrderLinker::link(\WC_Order $order, int $transactionId): bool`: reads the transaction, `linkOrder`, resolves the gateway and sets `payment_method` and `payment_method_title`; when the gateway is unresolved keeps the provisional value, adds the admin note from spec 5.5 once (guarded by meta `_wallee_ec_gateway_unresolved`), logs an error, returns false. Idempotent.
  - `ReturnHandler` on `woocommerce_api_wallee_ec_return`; `ResultHandler` on `woocommerce_api_wallee_ec_result`. Both end with a redirect and `exit`.
  - Retry of an unresolved gateway on `woocommerce_admin_order_data_after_order_details` for orders carrying the guard meta.

`ReturnHandler` sequence (spec 5.4): verify signature (purpose `return`), else redirect to the cart with a generic notice. Load the row. If state is not `created`: with an order, redirect to its order-received URL when state is `processed` or `completed`, else to the cart with a notice; never continue. `claim( created -> ordering )`; if false treat as the previous case after re-reading the row. `getSession`; require matching space. `ratesFor` the session's shipping address when the snapshot needs shipping. `OrderValidator::check`, then `email`, then `stockAndCoupons`: on a reason, state `failed`, notice, cart (no order is created for these). `OrderFactory::create`; store `order_id`. If `linkedTransaction` is present, `OrderLinker::link` and store `transaction_id`. `processSession( id, (string) $order->get_id(), resultUrl( uuid, 'success' ), resultUrl( uuid, 'failure' ) )`; on `ApiException`: `OrderFactory::fail`, state `failed`, notice, cart. Otherwise, if not linked yet: `getSession` again and link. State `processed`. `wp_redirect` to the returned URL (already validated HTTPS; add its host to `allowed_redirect_hosts` for this request).

`ResultHandler`: verify signature (purpose `result`), load row and order. `success`: link if `transaction_id` is still empty (`getSession`), empty the cart only when the snapshot context is `cart`, clear the `wallee_ec_session` WooCommerce session entry, state `completed`, redirect to `$order->get_checkout_order_received_url()`. `failure`: if the order is still `pending` set it to `failed`, state `failed`, notice "The payment was not completed.", redirect to the cart. Order status is otherwise left to the main plugin's webhook.

- [ ] **Step 1:** Failing tests with fakes (handlers get their collaborators by constructor and return a redirect URL from a testable `handle(array $query): string` method; the hook callback does the redirect):
  - a second call for a row in state `processed` returns the order-received URL and calls neither `OrderFactory` nor `processSession`;
  - `claim` returning false leads to the same outcome with no order created;
  - a validator reason gives the cart URL, state `failed`, no order, no `processSession`;
  - the happy path calls `processSession` with `merchantReference` equal to the order ID as string and both result URLs carrying signatures valid only for purpose `result`;
  - an `ApiException` from `processSession` fails the order and returns the cart URL;
  - a bad signature returns the cart URL and touches nothing.
- [ ] **Step 2:** FAIL, implement, PASS.
- [ ] **Step 3:** Deploy; full Bogus Pay purchase from the block cart: order appears, moves to the status the main plugin sets, `payment_method` is a `wallee_<n>` gateway, a row exists in `wp_wallee_transaction_info` for the order; reloading the return URL shows the order-received page and creates nothing. Lint, commit.

### Task 16: Apple Pay verification file

**Files:** Create `src/Http/DomainAssociation.php`; test `tests/Unit/Http/DomainAssociationTest.php`.

**Interfaces (Produces):** `DomainAssociation::PATH = '/.well-known/apple-developer-merchantid-domain-association'`; `DomainAssociation::matches(string $requestUri): bool` (exact path, query string ignored, a trailing slash or `.txt` does not match, home paths in a subdirectory are honoured); hook on `parse_request` priority 0 that, when the path matches and content is stored, sends status 200, `Content-Type: text/plain; charset=utf-8`, `X-Robots-Tag: noindex`, the content verbatim, and exits.

- [ ] **Step 1:** Failing tests for `matches`: exact path true; with `?x=1` true; with trailing slash false; with `.txt` false; `/shop/.well-known/...` true only when the home path is `/shop`.
- [ ] **Step 2:** FAIL, implement, PASS. Deploy; with content `test123` saved in the settings, `curl -si https://<shop>/.well-known/apple-developer-merchantid-domain-association` returns 200, `text/plain`, body `test123`, no `Location` header; with empty content it returns WordPress's 404. Lint, commit.

### Task 17: End-to-end pass and readme

**Files:** Create `docs/testing/e2e-checklist.md`, `plugin/wallee-express-checkout/readme.txt`; update `README.md`.

- [ ] **Step 1:** Write the checklist from spec section 9 as numbered cases with expected results: (1) block cart, shippable, guest; (2) classic cart; (3) virtual only, no shipping sheet; (4) coupon `TEN`; (5) logged-in customer, order attached to the account; (6) address without shipping method; (7) failed Bogus Pay payment, order `failed`, cart intact; (8) reload of the return URL; (9) full refund and partial refund from the WooCommerce admin; (10) completion or void from the admin where the connector defers capture; (11) product page, simple product, with another product in the cart that must remain there afterwards; (12) product page, variable product, quantity 2; (13) each setting off hides its buttons; (14) main plugin deactivated: admin notice, no buttons, no fatal error; (15) stock set to 0 after the sheet opened: no charge.
- [ ] **Step 2:** Run every case on staging with Bogus Pay and record pass or fail with the order ID in the checklist. Each failure becomes a fix with a regression test where the failing part is unit-testable, then the case is rerun.
- [ ] **Step 3:** `readme.txt` (requirements, setup through "Configure", the two display settings, the fixed-tax limit from spec 5.7, Apple Pay domain file) and `README.md` (repository layout, `bin/test`, `bin/lint`, staging commands, teardown).
- [ ] **Step 4:** `bin/test` and `bin/lint` green, CI green. Commit, push, open a pull request from `prototype` to `main`.
- [ ] **Step 5 (owner-dependent):** once PayPal is added to the space, rerun cases 1, 3, 7 and 9 with PayPal and record the results.
