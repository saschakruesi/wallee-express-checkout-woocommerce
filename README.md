# wallee Express Checkout for WooCommerce

[![CI](https://github.com/saschakruesi/wallee-express-checkout-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/saschakruesi/wallee-express-checkout-woocommerce/actions/workflows/ci.yml)
[![License: Apache-2.0](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](plugin/wallee-express-checkout/license.txt)

Wallet buttons on the cart page and on product pages of a WooCommerce shop. The customer pays with Apple Pay, PayPal or another wallet configured in wallee, and the order is created directly from the address and e-mail the wallet provides. No checkout form.

This is an independent, privately developed project by Sascha Krüsi. It is not an official wallee product and is not supported by wallee AG.

The add-on works on top of the [wallee plugin for WooCommerce](https://wordpress.org/plugins/woo-wallee/). It has no credentials of its own: it uses the space and application user configured there, and refunds, completions, voids and status updates keep running through the wallee plugin.

## Features

- Express buttons on the cart page (classic and block-based cart) and on product pages, each switched on separately.
- The wallets shown are the ones enabled for Express Checkout in your wallee space; the add-on needs no wallet-specific setup.
- Shipping methods and costs come from your WooCommerce shipping zones and are offered inside the wallet.
- Orders appear in WooCommerce like any other wallee order: Pending payment, On hold, Processing, with the payment method the customer actually used.
- Coupons, taxes, variable products and stock are honoured; the price offered in the wallet is the price charged.
- Physical and virtual products: a delivery address is requested only when something needs shipping.
- Clear messages for the customer: every refusal says what happened, whether they were charged and what to do next.
- If the customer closes the browser after paying, a background job links the payment to the order.
- Test mode with an "Express TEST" badge on test orders.
- Personal data exporter and eraser for WordPress' privacy tools.
- Compatible with High-Performance Order Storage (HPOS).

## Requirements

| | Version |
|---|---|
| PHP | 7.4 or newer |
| WordPress | 6.0 or newer |
| WooCommerce | 8.0 or newer (tested up to 11.0.1) |
| wallee plugin (`woo-wallee`) | tested with 3.4.11 |

You also need a wallee space with an application user and at least one wallet enabled for Express Checkout, and a shop reachable over public HTTPS (wallee calls the shop for shipping options).

## Installation

1. Install and configure the [wallee plugin](https://wordpress.org/plugins/woo-wallee/) (space ID, application user ID and key) and make sure regular wallee payments work.
2. Download `wallee-express-checkout-<version>.zip` from the [latest release](https://github.com/saschakruesi/wallee-express-checkout-woocommerce/releases/latest).
3. In WordPress, go to **Plugins > Add New > Upload Plugin**, upload the zip and activate it.
4. Open **WooCommerce > Settings > wallee Express Checkout**.

## Configuration

| Setting | Meaning |
|---|---|
| Cart page | Show the express buttons on the cart page. |
| Product pages | Show the express buttons below "Add to cart"; the purchase contains only that product. |
| Environment | `Test` (default) or `Production`. In Test no real money moves; an admin warning stays visible while buttons are switched on in Test. |
| Buttons per row / Maximum rows | Layout of the button grid. |
| Apple Pay domain verification file | Content of the file wallee gives you for Apple Pay; the add-on serves it at `/.well-known/apple-developer-merchantid-domain-association`. |

The **Configure** button on the settings page verifies the wallee plugin's credentials and sets up webhooks and payment methods through the wallee plugin.

Going live: enable the wallets for Express Checkout in your wallee space, switch the environment to `Production`, and place one real order.

### Apple Pay

Apple Pay needs your shop domain registered with wallee and the domain association file served by the shop. Paste the file content into the setting above and complete the domain verification in the wallee portal. See the [wallee Apple Pay documentation](https://app-wallee.com/doc/payment/apple-pay#apple-pay-express-checkout).

## How it works

1. The shop creates an Express Checkout session at wallee with the cart's line items and loads wallee's button script.
2. The customer picks a wallet. wallee asks the shop for shipping options for the chosen address; the shop answers from its shipping zones.
3. After the customer approves, the shop creates the order, starts the payment at wallee and reads the address and e-mail from the transaction.
4. The wallee plugin's webhooks move the order to On hold and Processing, exactly as for a regular wallee payment.

An order fails, with a clear message, when no e-mail address arrives, a coupon is no longer valid or a delivery address is taxed differently from the offer (normally the wallet already refuses such an address before payment). If money was already reserved it is released automatically; if it was already taken, the order gets an "ACTION REQUIRED" note.

## For developers

Filters:

| Filter | Default | Purpose |
|---|---|---|
| `wallee_ec_allow_without_shipping` | `true` | Return `false` to hide the buttons when nothing in the cart needs shipping. |
| `wallee_ec_client_ip` | `REMOTE_ADDR` | Client address used for rate limiting. Set this behind a CDN or reverse proxy. |
| `wallee_ec_enable_sdk_shim` | `true` | Compatibility layer for the wallee plugin's SDK. |
| `wallee_ec_handler_script_url` | wallee's script | URL of the Express Checkout handler script. |

Tests and lint need only Docker:

```bash
bin/test
```

```bash
bin/lint
```

`bin/test` runs PHPUnit, `bin/lint` runs `php -l` on PHP 7.4 and PHPCS with the WordPress standard. CI runs both on PHP 7.4 and 8.2.

Repository layout:

| Path | Content |
|---|---|
| `plugin/wallee-express-checkout/` | The plugin as shipped in the release zip |
| `tests/` | PHPUnit tests (no WordPress needed) |
| `docs/` | Design documents, API findings and the end-to-end test checklist |
| `infra/` | Scripts for the project's own staging shop on AWS; not needed to use the plugin |

## Known limits

- Virtual goods: for a cart in which nothing needs shipping the wallet asks for no delivery address. If the customer's address turns out to be taxed differently, the order is kept at the offered price and gets an "ACTION REQUIRED: check tax" note. wallee's Bogus Pay test wallet currently fails for such carts; Apple Pay and PayPal are not affected.
- The buttons are not shown on the checkout page.
- Product page purchases do not apply cart-level fees or hooks tied to the checkout form.
- Behind a CDN or reverse proxy, set `wallee_ec_client_ip`; otherwise all customers share one rate limit.
- The automatic release of a reserved amount is verified against the wallee API specification, not yet against a live payment.

The full list is in the FAQ of [`readme.txt`](plugin/wallee-express-checkout/readme.txt).

## External services and privacy

The add-on sends cart line items, amounts and the currency to wallee's API and loads wallee's handler script from `app-wallee.com` in the shopper's browser. Addresses and e-mail come from the wallet through wallee. Details, including links to wallee's terms and privacy policy, are in the "External services" section of [`readme.txt`](plugin/wallee-express-checkout/readme.txt).

## Security

Please report vulnerabilities privately through [GitHub's vulnerability reporting](https://github.com/saschakruesi/wallee-express-checkout-woocommerce/security/advisories/new) rather than in a public issue. Version 0.1.0 has known weaknesses that 1.0.0 fixes; do not use 0.1.0.

## Documentation

- [Changelog and FAQ](plugin/wallee-express-checkout/readme.txt)
- [End-to-end test checklist](docs/testing/e2e-checklist.md)
- [Design](docs/superpowers/specs/2026-11-24-wallee-express-checkout-design.md) and [amendment](docs/superpowers/specs/2026-11-24-design-amendment-1.md) (the flow as built)
- [wallee Express Checkout documentation](https://app-wallee.com/en-us/doc/payment/express-checkout)

## License

[Apache License 2.0](plugin/wallee-express-checkout/license.txt), the same license as the wallee plugin.
