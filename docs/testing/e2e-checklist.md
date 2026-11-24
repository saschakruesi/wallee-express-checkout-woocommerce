# End-to-end checklist (staging, Bogus Pay)

A tester runs each case by hand and fills in the result row. Nothing here has been run yet; all result rows are empty.

## Environment

| | |
|---|---|
| Shop | https://d3v94iyn7tizhz.cloudfront.net (admin: `/wp-admin`, credentials from the owner, not in this repository) |
| WordPress | 7.1.5 (read from the staging shop with `infra/bin/wp core version`), PHP 8.2 |
| WooCommerce | 11.0.1 |
| wallee main plugin | 3.4.11 |
| wallee Express Checkout | 0.1.0, branch `prototype`, deployed with `infra/bin/deploy` |
| Wallet | Bogus Pay (Test) |
| Space | wallee test space, environment TEST (setting "Environment" = Test) |

Before starting: WooCommerce > Settings > Payments > wallee Express Checkout: "Cart page" and "Product pages" both ticked, Environment Test, "SDK compatibility shim" line reads `active` (or `not needed`), and the Configure button has been pressed once without errors. Empty the cart and log out unless a case says otherwise.

## Shop facts used in the expectations

- Currency CHF. Prices include 8.1 % tax. Tax and shipping tax use the Swiss rate for every destination (the add-on uses the tax location of the cart, not the wallet address).
- Products: Notebook 25.00 (simple), T-Shirt 30.00 (variable, sizes S, M, L), Gift card 50.00 (virtual).
- Coupon `TEN`: 10 % off the products (not shipping).
- Shipping: zone CH has flat rate 8.11 incl. tax (7.50 + 0.61 tax) and free shipping from 100.00; zone DE has flat rate 16.22 incl. tax. No zone elsewhere.
- Tax included in a CH order with one Notebook and flat rate: 2.48 (1.87 on the item, 0.61 on shipping).

## The usual path (referred to as "the standard approval")

1. Click the wallet button, choose Bogus Pay. The wallee sheet opens showing the line items, the shipping options (when the cart needs shipping) and the total.
2. Approve in the sheet. The browser goes to the 3-D Secure simulation; complete it as successful.
3. The browser lands on the WooCommerce order-received page of the new order. The cart is empty (cart page cases).
4. Admin: WooCommerce > Orders. The order is first `Pending payment`; after the main plugin's webhook has arrived (seconds) its status is the one the main plugin sets for an authorised or completed payment, expected `Processing`. Record the status you see.
5. Order details to check in every successful case: payment method is the wallee payment method of the wallet (if unresolved it stays "wallee Express Checkout" and a note says so), the wallee transaction link/ID is shown, billing (and shipping, if shippable) address and e-mail are those of the wallet, the shipping line and totals match the case.

Wallet name in order meta: `_wallee_ec_wallet`.

## Cases

### 1. Block cart, shippable, guest
Steps: logged out. Add Notebook. Open the cart page (block cart). Buttons appear. Click, standard approval, choose the CH flat rate.
Expect: sheet total 33.11 (25.00 + 8.11). Order: 1 x Notebook 25.00, shipping line "Flat rate" 8.11 incl. tax, total 33.11, tax 2.48, guest (no customer), addresses and e-mail from the wallet.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 2. Classic cart
Steps: edit the cart page so it contains the `[woocommerce_cart]` shortcode instead of the cart block. Repeat case 1.
Expect: buttons appear under the cart totals; same result as case 1. Restore the block cart afterwards if the other cases need it (any cart works for the rest).

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 3. Virtual product only
Steps: cart with Gift card only. Click the wallet button.
Expect: the sheet shows no shipping options and no address step for shipping; total 50.00. Order: Gift card 50.00, no shipping line, total 50.00, tax 3.75, billing e-mail present.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 4. Coupon `TEN`
Steps: add Notebook, apply `TEN` in the cart, wallet button.
Expect: sheet shows the discounted item: total 30.61 (22.50 + 8.11). Order has coupon `TEN`, discount 2.50, shipping 8.11, total 30.61.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 5. Logged-in customer
Steps: log in as a customer account (create one with My Account > Register if needed). Add Notebook, cart page, standard approval.
Expect: order is attached to that account (WooCommerce > Orders shows the customer; it is in the customer's My Account > Orders). Addresses are those from the wallet.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 6. Address without a shipping method
Steps: Notebook in the cart. In the wallet sheet choose or enter a shipping address in a country without a zone (for example France). If Bogus Pay offers no address choice, record "not testable" and the reason.
Expect: the sheet reports that shipping is not available to that address (the add-on answers `COUNTRY_UNAVAILABLE`) and cannot be approved; switching to a CH or DE address makes the options appear again (DE: flat rate 16.22, total 41.22). No order is created while the address is unsupported.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 7. Failed payment
Steps: Notebook, wallet button, approve, and in the 3-D Secure simulation (or Bogus Pay) choose the failing outcome.
Expect: browser returns to the cart page with a notice "The payment was not completed." The cart still contains the Notebook. Admin: the order exists with status `Failed`. No charge. Pressing the wallet button again works.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 8. Reload of the return URL
Steps: complete case 1. When the order-received page shows, press back until the wallee redirect, or copy the first URL the browser used after approval (`...?wc-api=wallee_ec_return&...`, visible in the browser history/network tab) and open it again.
Expect: the customer is sent to the order-received page of the same order. No second order, no second transaction. (See also case 18.)

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 9. Refunds from the admin
Steps: on a paid order from case 1, WooCommerce > Orders > open > Refund. First a partial refund (for example 5.00, via the wallee gateway), then the rest.
Expect: each refund is accepted without error and appears as a refund line; the wallee transaction (portal, TEST space) shows the refund; after the full amount the order is `Refunded`. Repeat the full refund on a second paid order and record both.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 10. Completion or void
Steps: only if the Bogus Pay connector leaves the transaction authorised (the order has "Complete" / "Void" actions from the main plugin). Use Complete on one order and Void on another.
Expect: the actions work as for a normal wallee order and the order status follows the main plugin's webhook. If the connector captures immediately, record "not applicable" with the transaction state seen in the wallee portal.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 11. Product page, simple product, other item in the cart
Steps: put one T-Shirt (M) in the cart. Open the Notebook product page and use the wallet button there; standard approval.
Expect: sheet shows only the Notebook (25.00 + shipping). Order contains only the Notebook. Afterwards the cart still contains the T-Shirt (M), untouched.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 12. Product page, variable product, quantity 2
Steps: open the T-Shirt page, select size L, quantity 2. Before choosing a size the buttons must not start a payment. Then use the wallet button; also change the quantity to 3 and back to 2 before clicking.
Expect: sheet total 68.11 (60.00 + 8.11). Order: 2 x T-Shirt L, 60.00, shipping 8.11. The cart is unchanged.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 13. Display settings
Steps: (a) untick "Cart page", keep "Product pages": cart page has no buttons, product pages have. (b) the reverse. (c) both off: no buttons anywhere. Save each time and reload the page.
Expect: buttons follow each switch independently.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 14. Main plugin deactivated
Steps: Plugins > deactivate "wallee" (the main plugin). Visit the admin, the cart and a product page. Reactivate it afterwards.
Expect: admin notice that the add-on is inactive and what is missing; no buttons on the storefront; no fatal error or white page anywhere; after reactivation everything works again.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 15. Stock drops to 0 after the sheet opened
Steps: Notebook with stock management on and stock 5. Open the cart, click the wallet button so the sheet is open. In another browser or tab set the Notebook's stock to 0. Approve in the sheet.
Expect: browser returns to the cart with an error notice; no order for this attempt; no charge (no transaction created in the wallee portal). Log: `rejected: out_of_stock`. Restore the stock afterwards.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 16. Coupon with an e-mail restriction
Steps: create coupon `MAILONLY` (10 %, "Allowed emails" set to any address). Add Notebook, apply `MAILONLY` (as a logged-out guest it may be refused in the cart: log in with an account whose e-mail is allowed so the cart accepts it). Wallet button, standard approval.
Expect: the add-on does not know the wallet e-mail when it validates, so the coupon counts as invalid: browser returns to the cart with an error notice, no order, no charge. Log: `rejected: coupon_invalid`. Delete the coupon afterwards.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 17. Session renewal
Steps: open the cart page with a Notebook in the cart, leave it open untouched for at least 26 minutes (a wallee session expires 30 minutes after creation; the page renews it after 25). Then click the wallet button and run the standard approval.
Expect: buttons are still present and the payment succeeds as in case 1. No "expired" notice.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 18. Reload of the success URL
Steps: complete case 1 and note the URL the browser used after the 3-D Secure step (`...?wc-api=wallee_ec_result&status=success&...`). Open it again in the same browser.
Expect: the same order-received page of the same order; no second order, the cart is not refilled, order status unchanged.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

## How to diagnose a failed run

Add-on log: WooCommerce > Status > Logs, source `wallee-express-checkout` (newest file by date). It holds IDs, states and reason codes only: no addresses, e-mails or credentials. Typical lines: `Created express checkout session ...`, `Return: session row N created -> ordering`, `... processed for order N`, `Result: success for session row N ...`, `Order N: linked to transaction T, gateway ...`.

Reason codes after `rejected:` (no order, no charge) or `failed:` (order set to `Failed`):

| Code | Meaning |
|---|---|
| `not_approved (STATE)` | The wallee session was not `APPROVED` when the customer returned (expired or abandoned). |
| `currency_mismatch` | Session currency differs from the cart currency. |
| `shipping_missing` | Shippable cart, but no single selected shipping option on the session. |
| `shipping_changed` | The selected option is not among the rates last sent to the wallet, or its amount differs. |
| `amount_mismatch` | Approved amount differs from cart total plus shipping. |
| `out_of_stock` | Stock check failed at return. |
| `coupon_invalid` | A coupon is no longer valid at return (including e-mail restricted coupons). |
| `session_unavailable (Class)` | The wallee session could not be read. |
| `space_mismatch` | The session belongs to a different space than the main plugin's. |
| `validation_error (Class)` | Exception while validating. |
| `order_creation_failed (Class)` | The order could not be created. |
| `session_update_failed` | The add-on's own session row could not be updated (order failed). |
| `process_failed (Class)` | wallee refused the `process` call (order failed). |

Other signals: `Return: invalid or missing signature` / `unknown session` (a tampered or foreign return URL); `Session creation failed (HTTP status N)` (wallee API refused, check credentials in the main plugin and run Configure); `transaction details not read` (the shim or the API; the result handler retries); `gateway of transaction T unresolved` (order keeps the generic payment method and a note). The shipping callback answers the wallet with `COUNTRY_UNAVAILABLE` or `SHIPPING_METHOD_UNAVAILABLE` when no rate fits.

SDK shim status: WooCommerce > Settings > Payments > wallee Express Checkout > Status, line "SDK compatibility shim": `active` (the add-on's copy is loaded), `not needed` (the main plugin's SDK knows `EXPRESS_CHECKOUT`) or `unavailable` (the main plugin cannot read Express Checkout transactions; webhooks for these orders fail and a warning shows in the admin). Also check the main plugin's own log and wallee portal > Transactions for the transaction state.
