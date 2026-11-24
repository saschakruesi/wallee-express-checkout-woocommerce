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
