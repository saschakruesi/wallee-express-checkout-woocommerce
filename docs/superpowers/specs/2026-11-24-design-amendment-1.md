# Design amendment 1: what the real API does after wallet approval

Date: 2026-11-24. Amends `2026-11-24-wallee-express-checkout-design.md`
sections 3.1, 5.3, 5.4, 5.5 and the plan's Tasks 14 and 15. Where this file
and the design disagree, this file wins. Facts come from
`docs/reference/spike-findings.md` (real session 335, transaction 629017977).

## Facts that changed the design

1. A session at `APPROVED` carries **no addresses, no e-mail, no transaction**.
   Keys are absent, not null. Line items are under `lineItemEntities`.
2. The transaction is created by `process`. Only then does the session show
   `linkedTransaction`. Addresses and e-mail are on the **transaction**:
   `GET /v2.0/payment/transactions/{id}?expand=billingAddress,shippingAddress`
   (header `space`). Address fields seen: `givenName`, `familyName`, `street`,
   `postcode` (also `postCode`), `city`, `country`, `emailAddress`; also
   `customerEmailAddress` on the transaction. No phone, state or company with
   Bogus Pay; treat every field as optional.
3. The payment method configuration is not on the transaction before the
   charge attempt completes (3-D Secure step in the browser). It is known once
   the customer comes back through `successUrl`.
4. The selected shipping option on the session has the id our callback
   returned (the WooCommerce rate id, e.g. `flat_rate:1`) and `selected: true`.
   wallee adds a line item `type SHIPPING`, `uniqueId` = `shipping-<option id>`.
   `authorizationAmount` = sum of our line items + selected option amount.
5. A session times out 30 minutes after creation (`timedOutOn`).
6. **The main plugin 3.4.11 cannot read Express Checkout transactions.** Its
   bundled SDK rejects `userInterfaceType: EXPRESS_CHECKOUT`
   (`InvalidArgumentException: Invalid value for enum
   '\Wallee\Sdk\Model\TransactionUserInterfaceType'`). `get_transaction()`
   throws, and so does its webhook handler: the staging log shows the error on
   every webhook delivery for transaction 629017977, with wallee retrying.
   Without a remedy, no status update, no transaction info row and no refund
   would work, and repeated failures can get the space's webhook listener
   deactivated, which would also hit normal orders.

## Decision on fact 6 (author's call, confirmed by the owner on 2026-11-24)

The add-on ships a **compatibility shim**: before the main plugin's SDK
autoloader can load `\Wallee\Sdk\Model\TransactionUserInterfaceType`, the
add-on defines that class itself, identical to the SDK's but with the extra
value `EXPRESS_CHECKOUT`. The main plugin's files are not modified. The shim is
loaded only when the main plugin's own SDK file lacks that value, so it
switches itself off once the main plugin ships a newer SDK. This is a second,
explicit exception to "only `MainPluginAdapter` references the main plugin".
The proper fix is an SDK update in the main plugin; that is reported to the
owner as a follow-up for the main plugin.

Alternative considered and rejected: own webhook listener, status handling and
refunds inside the add-on. Much larger, duplicates the main plugin, and the
main plugin's listener would still fail on every Express Checkout webhook.

## New flow after approval (replaces 5.4 "Return after approval" and "Result")

Shipping callback (Task 13, extended): after computing rates successfully, the
controller stores a **shipping quote** on the session row: destination
(`country`, `state`, `postcode`, `city`) and the rate arrays exactly as
returned by `ShippingOptionsProvider::ratesFor`. The last callback wins. New
column `shipping_quote` (longtext, nullable); `Installer` DB version 2;
`SessionRepository::update` accepts the key `shipping_quote`;
`SessionRecord::shippingQuote(): ?array`.

Return handler (`wc-api=wallee_ec_return`):

1. Verify signature (purpose `return`); load row. Row not in `created`: as
   before (with an order and state `processed`/`completed`, go to its
   order-received URL; otherwise cart with notice). Never continue.
2. Claim `created` to `ordering` (single conditional UPDATE).
3. `GET session`. Require state `APPROVED` and matching space ID.
4. Shipping: if the snapshot needs shipping, the session must have exactly one
   option with `selected: true`; its `id` must be among the stored quote's
   rates and its `amountIncludingTax` must equal that rate's cost + tax
   (currency precision). The order's shipping line uses the stored rate
   (cost, tax, label, method id), not a recalculation.
5. Amount: `Money::equals( snapshot total + selected amount,
   authorizationAmount )` and currencies equal.
6. Stock and coupons re-validated. No e-mail is known at this point, so a
   coupon with an e-mail restriction counts as invalid (stated in the readme).
7. Any failure in 3 to 6: row `failed`, no order, no process, cart with
   notice.
8. Create the order, status `pending`, **without addresses**: items, fees,
   coupons, shipping line, `_wallee_unique_line_item_id` on every item
   (shipping: `shipping-<rate id>`), `payment_method`
   `wallee_express_checkout`, meta `_wallee_ec_session_uuid`,
   `_wallee_ec_wallet` (wallet name from `walletType.name`, first value).
   `$order->get_total()` must equal `authorizationAmount`, else fail the order
   and stop as in 7.
9. `processSession( sessionId, (string) orderId, resultUrl(success),
   resultUrl(failure) )`. `ApiException`: fail the order, row `failed`, cart.
10. Right after process, before redirecting, best effort (errors are logged
    and do not stop the redirect, because the customer has approved):
    `GET session` for `linkedTransaction`; store it on the row and as order
    meta `_wallee_ec_transaction_id`; read the transaction (fact 2) and write
    addresses and e-mail to the order; add a private note if the transaction's
    shipping country differs from the quote's destination country.
11. Row `processed`; redirect to `redirectUrl`.

Result handler (`wc-api=wallee_ec_result`, signature purpose
`result:success` / `result:failure`):

- Success: if the order still has no transaction ID or no billing e-mail,
  repeat step 10. Then link to the main plugin (below). Empty the cart for
  context `cart`, `SessionController::forget()`, row `completed`, redirect to
  the order-received URL. Order status is left to the main plugin's webhook.
- Failure: order `failed` if still `pending`, row `failed`, notice, cart.

E-mail: billing address `emailAddress`, else shipping address, else the
transaction's `customerEmailAddress`, else the logged-in customer's e-mail.
If none exists the order is kept (the payment is approved) and gets a private
note; it is not failed.

## Linking to the main plugin (replaces the timing in 5.5)

`OrderLinker::link( $order, $transaction_id )` runs in the result handler (and
again on an admin order view while unresolved):

1. Read the transaction through the adapter (`readTransaction`, main plugin
   SDK; works with the shim) and call `linkOrder` (transaction info row).
2. Resolve the gateway from the transaction's payment connector configuration
   and set `payment_method` / `payment_method_title`. Unresolved: keep
   `wallee_express_checkout`, add the admin note once, log, return false.

A Transaction webhook that arrives before the result handler ran is still
accepted by the main plugin through its merchantReference fallback (numeric
order ID, `payment_method` contains `wallee`).

## API client additions

- `ApiClient::getTransaction( int $transaction_id ): array` — `GET
  /v2.0/payment/transactions/{id}` with query
  `expand=billingAddress,shippingAddress`.
- Session reads use `lineItemEntities`; every key may be absent.

## Validator / factory signatures (replace Task 14's)

- `OrderValidator::selectedOption( array $session ): ?array`
- `OrderValidator::check( CartSnapshot $s, array $session, ?array $quote ): ?string`
  reason codes: `not_approved`, `currency_mismatch`, `shipping_missing`,
  `shipping_changed`, `amount_mismatch`.
- `OrderValidator::matchedRate( array $session, ?array $quote ): ?array` (the
  stored rate for the selected option, or null).
- `OrderValidator::stockAndCoupons( CartSnapshot $s, string $email ): ?string`
  (`out_of_stock`, `coupon_invalid`).
- `OrderValidator::email( array $transaction, int $customer_id ): ?string`
- `OrderFactory::create( CartSnapshot $s, ?array $rate, string $uuid, string $wallet ): \WC_Order`
- `OrderFactory::applyCustomer( \WC_Order $order, array $transaction, int $customer_id ): void`
- `OrderFactory::fail( \WC_Order $order, string $reason ): void`

## Amendment 2 (owner decisions, 2026-11-24, after the final branch review)

Where this section and the text above disagree, this section wins.

1. **Price edits during the wallet flow** (plan Review Focus 2). The price
   offered when the buttons loaded applies. A price edit in the shop after
   that is honoured at the approved price: the order is created from the
   snapshot and the customer pays what they approved. No refusal, no code
   change. Stated in readme.txt "Known limits".
2. **No e-mail address** (replaces "E-mail ... If none exists the order is
   kept" above, and plan Review Focus 5). In the return handler, after
   `process`, when the transaction was read and no valid e-mail can be
   determined (billing, shipping, `customerEmailAddress`, logged-in
   customer): the order fails (reason `no_email`, private note), the row is
   `failed`, the customer is not sent to wallee's payment step but to the
   cart with a generic notice that the wallet provided no e-mail address.
   If the transaction is then already `AUTHORIZED`, `COMPLETED` or
   `FULFILL`, the order still fails and gets a prominent private note plus
   an error log line naming the transaction to void or refund in the
   wallee portal. A failure to read the transaction is not "no e-mail": the
   customer goes on to wallee and the data is read later. In the result
   handler and the background link (the customer may have paid) a missing
   e-mail keeps the order and adds a private note once.
3. **Settings location** (replaces design 5.6 "Payments area, own
   section"). The add-on has its own WooCommerce settings tab
   "wallee Express Checkout" (id `wallee_ec`), right after the main
   plugin's "wallee" tab, and a "Settings" link on the Plugins screen. The
   Payments section is gone.
4. **Linking every order** (replaces the timing in "Linking to the main
   plugin"). After `process` an Action Scheduler job
   (`wallee_ec_link_order`, group `wallee-ec`) links the order with
   back-off 5, 10, 20, 40, 80, 160 minutes; only then, or once the
   transaction is in a final authorised or completed state with an
   unresolvable gateway, the order is flagged with the admin note. The
   result handler is the fast path; an admin view of any express order
   still on the provisional gateway retries.
5. **Session lifetime.** The session endpoint returns `expiresAt` (row
   `created_at` + 30 minutes, ISO-8601 UTC); the page renews 15 minutes
   before it and never while the wallet overlay is open. Reuse window 5
   minutes.
6. **Rounding fails closed.** A snapshot that does not reproduce the cart
   total, or line items that do not add up to the snapshot total, give no
   session (`unavailable`) instead of a shifted line.
7. **Nothing to ship (temporary).** Buttons are hidden (reason
   `no_shipping_needed`) when nothing in the cart, or the viewed product or
   variation, needs shipping, because wallee's sheet currently asks for a
   shipping address for such sessions and then rejects it. The filter
   `wallee_ec_allow_without_shipping` (default false) switches them back on
   once wallee has fixed the sheet.

## Amendment 3 (security review, version 0.1.1)

Where this section and the text above disagree, this section wins.

1. **Variation data (product context).** A simple product accepts no
   variation map. For a variation, only the parent's variation attributes
   (`attribute_<slug>`) are accepted; each value must equal the variation's
   own value or, where the variation has "any", be one of the parent's
   options. The resolved set is what the snapshot stores. `CartSnapshot`
   accepts only keys matching `/^attribute_[a-z0-9_%\-]{1,100}$/i` (cart
   context and stored rows included). `OrderFactory` passes only those keys
   to the order line and fails the order creation (no order remains) when a
   built line does not refer to the snapshot's product and variation.
2. **Environment.** The return handler requires the session's
   `authorizationEnvironment` to equal the configured environment (reason
   `environment_mismatch`: no order, no process). The order stores it as
   `_wallee_ec_environment`; TEST orders get a private note. While TEST is
   configured and a display switch is on, shop managers see a permanent
   admin warning. The default stays TEST.
3. **Coupons with the customer's e-mail** (replaces step 6's e-mail rule).
   The check before the order ignores e-mail restrictions. After `process`,
   once the transaction's customer data is on the order and before the
   redirect, every coupon is validated again with that e-mail: WooCommerce's
   coupon rules (global limit, per-user limit for accounts, e-mail
   restrictions) and, for guests, the per-user limit counted by billing
   e-mail. A failure takes the `no_email` path with reason `coupon_invalid`.
4. **Destination and tax location** (replaces the private note of step 10
   and design 5.7's note). When taxes are enabled and the tax basis is not
   the shop base, the shipping callback answers `COUNTRY_UNAVAILABLE` for a
   destination outside the snapshot's tax location (country, and state when
   the location has one) and stores no quote. After `process`, an order
   whose shipping (fallback billing) country differs from the quote's
   country, or under the same tax condition from the tax location's
   country, fails before the redirect (reason `destination_changed`, the
   `no_email` path).
5. **Rate limiting and sessions.** The per-client bucket is checked before
   the global one, and only allowed requests count globally. The client is
   `REMOTE_ADDR`, replaceable through the filter `wallee_ec_client_ip` for a
   trusted proxy. No WooCommerce session or cookie is started for a caller
   without one: a cart request is refused with `empty`, a product request is
   served without reuse. Session responses carry no-cache headers.
6. **Validation hooks.** Product context runs
   `woocommerce_add_to_cart_validation` with WooCommerce's argument lists;
   cart context runs the cart page checks (`woocommerce_check_cart_items`,
   which includes `WC_Cart::check_cart_items`). A false result or a new
   error notice refuses the session (`unavailable`); the notices are
   removed again. Product context still has no cart-level fees.
7. **Link lifetime.** Return and shipping requests for rows older than
   2 hours (`created_at`) are refused (cart with the generic notice; the
   shipping callback answers 404); result requests for rows older than
   7 days go to the cart. The signature format is unchanged.
8. **Protected products.** Product context refuses products with a post
   password and products that are not published.
9. **Redirect host.** The wallee redirect URL's host must equal the host of
   the configured wallee base URL; otherwise the order fails before the
   redirect (`redirect_host_mismatch`, the `no_email` path).
