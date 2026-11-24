# End-to-end checklist (staging, Bogus Pay)

A tester runs each case by hand and fills in the result row. The result rows are still empty. The owner's first real runs (orders #25 to #28: cart purchases ending Processing with gateway `wallee_7` and a linked transaction record, and one failed payment) correspond to cases 1 and 7 but were made before the final fix wave; re-run and record them.

## Environment

| | |
|---|---|
| Shop | https://d3v94iyn7tizhz.cloudfront.net (admin: `/wp-admin`, credentials from the owner, not in this repository) |
| WordPress | 7.1.5 (read from the staging shop with `infra/bin/wp core version`), PHP 8.2 |
| WooCommerce | 11.0.1 |
| wallee main plugin | 3.4.11 |
| wallee Express Checkout | 1.0.0, branch `prototype`, deployed with `infra/bin/deploy` |
| Wallet | Bogus Pay (Test) |
| Space | wallee test space, environment TEST (setting "Environment" = Test) |

Before starting: WooCommerce > Settings > wallee Express Checkout (own tab, next to "wallee"): "Cart page" and "Product pages" both ticked, Environment Test, "SDK compatibility shim" line reads `active` (or `not needed`), and the Configure button has been pressed once without errors. Empty the cart and log out unless a case says otherwise.

## Shop facts used in the expectations

- Currency CHF. Prices include 8.1 % tax. Taxes are calculated from the shipping address (WooCommerce default) and a guest's location defaults to the shop base, so the tax location of a cart is CH unless the cart's shipping calculator says otherwise. Since 0.1.1 the wallet offers shipping only to addresses with the same WooCommerce tax rates as that tax location (design amendment 3; from the third 0.1.1 fix round compared by rates, not by country): with a CH tax location, DE and other countries get "Express checkout is not available for deliveries to Germany. Please choose another address or use the regular checkout."
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
Steps: cart with Gift card only; also open the Gift card product page.
Expect: NO express buttons, neither on the cart page nor on the product page (temporary owner decision: wallee's sheet asks for a shipping address for sessions without shippable items and then rejects it). The regular checkout works. The session endpoint answers `reason: no_shipping_needed`. A cart with the Gift card and a Notebook shows the buttons.
Note, for when the filter `wallee_ec_allow_without_shipping` returns true (after wallee's fix): then expect the sheet to show no shipping options and no address step for shipping; total 50.00. Order: Gift card 50.00, no shipping line, total 50.00, tax 3.75, billing e-mail present. With taxes following the address and a wallet address taxed differently (for example a US address): the order is NOT failed and nothing is voided or refunded; it goes through at 50.00 and gets the private note "ACTION REQUIRED: check tax. The customer's address is taxed differently from the price offered in express checkout; the order was kept at the offered price." exactly once (also after the background job ran).

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
Expect: the sheet shows "Express checkout is not available for deliveries to France. Please choose another address or use the regular checkout." (the add-on answers `COUNTRY_UNAVAILABLE`) and cannot be approved; a country inside a zone but without a shipping method gets "We cannot deliver to the selected address with express checkout. Please choose another address or use the regular checkout." (`SHIPPING_METHOD_UNAVAILABLE`); switching to a CH address makes the options appear again. A DE address is also refused while the cart's tax location is CH (see case 23). No order is created while the address is unsupported.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 7. Failed payment
Steps: Notebook, wallet button, approve, and in the 3-D Secure simulation (or Bogus Pay) choose the failing outcome.
Expect: browser returns to the cart page with the notice "The payment was not completed. You have not been charged. Please try again or choose another payment method." The cart still contains the Notebook. Admin: the order exists with status `Failed`. No charge. Pressing the wallet button again works.

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
Expect: browser returns to the cart with the notice "An item in your order is no longer available in the requested quantity. You have not been charged. Please review your cart."; no order for this attempt; no charge (no transaction created in the wallee portal). Log: `rejected: out_of_stock`. Restore the stock afterwards.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 16. Coupon with an e-mail restriction
Steps: create coupon `MAILONLY` (10 %). (a) "Allowed emails" set to the e-mail Bogus Pay returns (read it from an earlier Bogus Pay order) or its domain with a wildcard (`*@domain`). Add Notebook, apply `MAILONLY` (as a logged-out guest the cart may refuse it: log in with an account whose e-mail is allowed so the cart accepts it). Wallet button, standard approval. (b) The same with "Allowed emails" set to an address the wallet does not return.
Expect: (a) the order succeeds with the coupon. (b) after approval the browser returns to the cart with the notice "The coupon in your cart cannot be used for this order. You have not been charged. Please remove the coupon or use the regular checkout." (if the transaction was already authorised: "Your payment was received, but we could not complete your order automatically. Please do not pay again. We will contact you about order N."); the order is `Failed` with reason `coupon_invalid`; the customer is not sent to the payment step. Log: `failed: coupon_invalid`. Delete the coupon afterwards.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 17. Session renewal
Steps: (a) open the cart page with a Notebook in the cart and leave it open untouched for at least 20 minutes (a wallee session expires 30 minutes after creation; the session endpoint returns `expiresIn`, the seconds left, and the page renews 15 minutes before the end, so after about 15 minutes). Then click the wallet button and run the standard approval. (b) Load the cart page, wait 4 minutes, reload it (the token is reused; `expiresIn` is then about 26 minutes), wait another 12 minutes, then pay. (c) Open the wallet sheet about 14 minutes after loading the page and keep it open past the 15-minute mark before approving. (d) As (c), but cancel the sheet after the 15-minute mark instead of approving. (e) Set the computer's clock 2 hours ahead (or behind), load the cart page and watch the network tab: the next session request comes after about 15 minutes, not at once and not later; set the clock back afterwards.
Expect: (a) and (b): buttons are still present and the payment succeeds as in case 1; no "expired" notice. (c): the open sheet is not removed; the renewal happens after the sheet closes; the approval succeeds. (d): a new session request is sent right after the cancel; the buttons stay. (e): renewal timing unaffected by the clock.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 18. Reload of the success URL
Steps: complete case 1 and note the URL the browser used after the 3-D Secure step (`...?wc-api=wallee_ec_result&status=success&...`). Open it again in the same browser.
Expect: the same order-received page of the same order; no second order, the cart is not refilled, order status unchanged.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 19. Browser never returns after payment
Steps: Notebook in the cart, wallet button, approve, complete the 3-D Secure step and close the tab before the order-received page loads (or before the 3-D Secure redirect finishes). Wait 10 minutes.
Expect: the order is linked by the background job (WooCommerce > Status > Scheduled Actions, hook `wallee_ec_link_order`, group `wallee-ec`): payment method `wallee_7` (not "wallee Express Checkout"), transaction shown, addresses and e-mail set; the refund button works. If it is not linked after the last attempt (about 5 hours), the order has a private note to use the wallee portal. Variant: close the tab in the 3-D Secure step after choosing the failing outcome; the background job then adds one note "wallee Express Checkout: wallee transaction N ended in state FAILED, so no money was taken for this order. ...", no "complete and refund in the wallee portal" note, and schedules no further attempt (log: `ended in state FAILED; not linked`); the order, if still `Pending payment`, is now `Failed` (the note is the status change note).

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 20. No e-mail from the wallet
Steps: only testable with a wallet that returns no e-mail address (Bogus Pay always returns one: then record "not testable").
Expect: after approval the customer returns to the cart with the notice "The wallet did not provide an e-mail address, which we need for your order. You have not been charged. Please use the regular checkout." (if the transaction was already authorised, completed or fulfilled: "Your payment was received, but we could not complete your order automatically. Please do not pay again. We will contact you about order N."); the order is `Failed` with reason `no_email`; the customer is not sent to the payment step. If the transaction was already authorised, the reservation is voided automatically (note as in case 28; if the void fails, an "ACTION REQUIRED" note); if it was completed or fulfilled, an "ACTION REQUIRED ... A refund is needed" note. Background variant (transaction not readable at return, browser closed after the payment step): the background job fails the order for `no_email` the same way while it is pending or on-hold (log: `no e-mail address from wallee (transaction T, state S); order failed (no_email)`); an order the webhook already set to processing or completed keeps its status, is not voided and gets the note "ACTION REQUIRED: no e-mail address received for this order." once (log: `no e-mail address from wallee; order is <status>, not failed`).

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 21. SDK shim switched off
Steps: add an mu-plugin with `add_filter( 'wallee_ec_enable_sdk_shim', '__return_false' );` (only on a test shop the owner agrees to change). Visit the admin, the cart and a product page. Remove the mu-plugin afterwards.
Expect: admin error notice that express checkout is disabled because the shim is not active; status line "SDK compatibility shim: unavailable"; no buttons; the session endpoint answers `reason: not_configured`. Variant: open a wallet sheet before adding the mu-plugin, add it, then approve: the customer returns to the cart with "Express checkout is currently not available. You have not been charged. Please use the regular checkout."; no order is created, no payment is started (log: `express checkout is not available (main plugin or SDK compatibility shim); session not processed`).

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 22. TEST environment notice and order note
Steps: Environment Test, at least one of "Cart page" / "Product pages" ticked. Open any admin page as a shop manager, then complete case 1.
Expect: a yellow notice on every admin page, without a dismiss button: "wallee Express Checkout runs in the TEST environment. Payments are simulated; do not ship goods for these orders." The new order has the private note "Test payment (wallee Express Checkout TEST environment). No real money was moved." and the meta `_wallee_ec_environment` = `TEST`; its payment method title is unchanged. WooCommerce > Orders shows a column "Express checkout" next to the status with the badge "Express TEST" for this order (check with HPOS on and, if possible, with the legacy posts storage); the order edit screen shows the badge with "Test payment in the wallee TEST environment. No real money was moved. Do not ship this order." Express orders of the Production environment show "Express" in the column; other orders show nothing. Untick both display switches: the admin notice disappears (tick them again afterwards).

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 23. Destination country different from the tax country
Steps: logged out, empty cart (tax location CH). Add Notebook, wallet button, and in the sheet choose or enter a DE shipping address. If Bogus Pay offers no address choice, record "not testable".
Expect: no shipping option is offered for the DE address; the sheet shows "Express checkout is not available for deliveries to Germany. Please choose another address or use the regular checkout." and cannot be approved. Log: `destination taxed differently from the tax location`. A CH address works as in case 1, also with another canton than the tax location (CH rates are national).

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 24. Coupon with a usage limit per user, used twice as a guest
Steps: create coupon `ONCE` (10 %, Usage limit per user = 1). Logged out: add Notebook, apply `ONCE`, standard approval; wait until the order is `On hold` or `Processing`. Then, again logged out and with the same wallet (same e-mail): add Notebook, apply `ONCE`, standard approval.
Expect: the first order succeeds with the coupon. The second returns to the cart after approval with the coupon notice of case 16; its order is `Failed` with reason `coupon_invalid`; no payment step, no charge. Log: `failed: coupon_invalid`. Delete the coupon afterwards.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 25. Return link older than 2 hours
Steps: complete case 1 and note the return URL (`...?wc-api=wallee_ec_return&ec_session=...&sig=...`, visible in the browser history before the 3-D Secure step). Open it again more than 2 hours after the wallet session was created.
Expect: the cart page with the notice "Your express payment session has expired. If an amount was reserved, it will be released. Please start the express payment again." (the row was processed, so the add-on does not claim nothing was charged); not the order-received page; no second order. Log: `too old for its return link`.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 26. Tax rates by postcode (same country)
Steps: WooCommerce > Settings > Tax > Standard rates: add a row CH, postcode `3000`, rate 0 %, shipping ticked; save. Logged out, empty cart (tax location CH 8000 or none). Add Notebook, wallet button, choose a CH address with postcode 3000; then one with postcode 8000. Delete the rate row afterwards. If Bogus Pay offers no address choice, record "not testable".
Expect: postcode 3000 gets no shipping options and "Express checkout is not available for this delivery address. Please choose another address or use the regular checkout." (no country named); postcode 8000 gets the options as in case 1. Log: `destination taxed differently from the tax location`.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 27. Taxes based on the billing address
Steps: WooCommerce > Settings > Tax > "Calculate tax based on" = Customer billing address. Logged out, empty cart. Add Notebook, wallet button, shipping address CH, billing (payment card) address DE if the wallet lets you choose one; standard approval. Then the same with a CH billing address. Set the option back to shipping address afterwards. Record "not testable" if the wallet sends no separate billing address.
Expect: the sheet offers shipping for the CH address in both runs (the callback does not refuse on tax grounds). With a DE billing address the customer returns to the cart after approval with "Express checkout is not available for the selected delivery address. You have not been charged. Please use the regular checkout to complete your order."; the order is `Failed`, reason `destination_changed`. With a CH billing address the order goes through as in case 1.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 28. Owner failure with the money only reserved (automatic void)
Steps: needs a wallet or connector that authorises during the payment start without a browser step (PayPal in authorise-only mode, or a card connector set to deferred completion); with Bogus Pay the transaction stays PROCESSING until the browser step, so record "not testable" there. Create coupon `ONCE` with "Allowed e-mails" set to an address that is not the wallet's; apply it, standard approval.
Expect: the cart with "The coupon in your cart cannot be used for this order. The reservation on your payment method is being released. You will not be charged. Please remove the coupon or use the regular checkout."; the order is `Failed` with the private note "wallee Express Checkout: a void of transaction N was requested automatically because this order failed (coupon_invalid). ..."; in the wallee portal the transaction becomes VOIDED within a few minutes. Log: `void of transaction N requested`. No second void request on reload. Delete the coupon afterwards.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 29. Owner failure with the money taken (no automatic void)
Steps: as case 28 with a wallet that completes the payment itself (PayPal with immediate capture, transaction COMPLETED or FULFILL at return).
Expect: the cart with "Your payment was received, but we could not complete your order automatically. Please do not pay again. We will contact you about order N."; the order is `Failed` with the note "ACTION REQUIRED: wallee transaction N is already COMPLETED (money taken), but this order failed (coupon_invalid). A refund is needed: ..."; no void request in the log; the transaction is not changed in wallee. Refund it in the wallee portal afterwards.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 30. Failed order revived by a late payment update
Steps: after case 28 or 29 (or any express order failed with `coupon_invalid`, `no_email` or `destination_changed`), watch the order for 10 minutes and open it in the admin.
Expect: if the main plugin's webhook moved the order out of `Failed` (to on-hold or processing), the order has the note "ACTION REQUIRED: this express order failed (<reason>), but its status was then set to <status> (for example by a payment update). ..."; an AUTHORIZED transaction of an order the webhook moved to on-hold is voided by the background job or when the order is opened (note as in case 28), after which the webhook sets the order to `Cancelled`. An order that is processing or completed, or whose status the merchant changed after the revival (for example on-hold to processing), is not voided; it gets the note "ACTION REQUIRED: wallee transaction N is AUTHORIZED (money reserved), but this order failed (<reason>) and its status was changed since, so it was not voided automatically. ..." once. A void failure shows "ACTION REQUIRED ... the automatic void failed. Void transaction N manually in the wallee portal."

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 31. Personal data export and erasure
Steps: after case 1 (guest, wallet e-mail E) and case 7: Tools > Export Personal Data, add a request for E and use the request's "Download personal data" action. Then Tools > Erase Personal Data for E, run "Erase personal data". Also open Settings > Privacy > Policy Guide.
Expect: the export contains a group "wallee Express Checkout sessions" (one item per session record of these orders: order, created, state, wallee IDs, tax location, delivery destination) and "wallee Express Checkout order data" (wallet, environment, wallee IDs, failure reason if any). The erasure reports the finished records as removed; a record of an order whose payment is still in progress is reported as retained with a message naming the order. The orders themselves are not deleted by the add-on. The Policy Guide has a "wallee Express Checkout" section.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

### 32. Rate limit under parallel requests
Steps: from one client, send 30 session requests in parallel within a few seconds (for example with a small script reusing the nonces of the cart page). Then check the table: `infra/bin/wp db query "SELECT COUNT(*) FROM wp_wallee_ec_rate"`.
Expect: exactly 20 requests get a session or a normal refusal; the rest get HTTP 429 with `rate_limited`; the table has a row per bucket; after the daily cleanup expired rows are gone.

| Date | Result (pass/fail) | Order ID | Notes |
|---|---|---|---|
| | | | |

## How to diagnose a failed run

Add-on log: WooCommerce > Status > Logs, source `wallee-express-checkout` (newest file by date). It holds IDs, states and reason codes only: no addresses, e-mails or credentials. Typical lines: `Created express checkout session ...`, `Return: session row N created -> ordering`, `... processed for order N`, `Result: success for session row N ...`, `Order N: linked to transaction T, gateway ...`, `Order N: link attempt K failed, next in M minutes.` Background link attempts: WooCommerce > Status > Scheduled Actions, hook `wallee_ec_link_order`.

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
| `process_failed (Class HTTP n ...)` | wallee refused the first `process` call (4xx), or the answer was unclear and the session did not move after one retry (order failed). An unclear answer with the session already `PROCESSING`/`AUTHORIZED` is logged as `probably processed` and the order is kept; so is a 4xx answer to the retry when the session then has a transaction or is `PROCESSING`/`AUTHORIZED` (log: `process attempt 2 refused (...); reading the session`). |
| `order_update_failed (Class)` | The order could not be marked before the process call (order failed). |
| `no_email` | After process, wallee returned no e-mail address (order failed; see case 20). |
| `destination_changed` | After process, the wallet's address is in another country than the shipping quote, or the address taxes are based on (shipping, or billing when taxes follow it) has other tax rates than the tax location (order failed). Never for a cart in which nothing needs shipping: that order is kept at the offered price with the ACTION REQUIRED tax note. |
| `coupon_check_failed` | After process, the coupon check threw an error: order failed unless the money is already reserved or taken, then kept. |
| `redirect_host_unexpected` | wallee's redirect after process points to a host that is neither wallee nor the shop. The customer is never sent there; the order is kept and the customer goes to the success page when the transaction or session shows a payment under way or done, otherwise the order fails. |
| `... but transaction T is STATE; order kept` | A technical reason after process with the money already reserved or taken: the order is never failed for it. |

Each return logs `redirect target: wallee`, `shop` or `other:<host>` (host only).

Customer notices (cart page) by reason: expired session or link: "Your express payment session has expired. ..."; destination (`shipping_missing`, `shipping_changed`, `destination_changed`): "Express checkout is not available for deliveries to <country>. ..." or "... for the selected delivery address. ..."; `out_of_stock`; `coupon_invalid`; cart changed (`amount_mismatch`, `tax_rate_changed`, `order_creation_failed`, `currency_mismatch`, rounding): "Your cart changed while you were paying. ..."; `no_email`; failure URL: "The payment was not completed. ..."; SDK shim unavailable at return (`express_unavailable`): "Express checkout is currently not available. You have not been charged. Please use the regular checkout."; anything else: "The express payment could not be completed because of a technical problem. ...". The middle sentence is "You have not been charged." only where no payment can have happened; where that is not certain it is "If an amount was reserved, it will be released." With the money only reserved and the void requested the middle sentence is "The reservation on your payment method is being released. You will not be charged." With the money taken (or a failed void) the notice is "Your payment was received, but we could not complete your order automatically. Please do not pay again. We will contact you about order N."

Session endpoint refusals (`reason` in the response, no buttons): `disabled`, `not_configured` (main plugin not configured or SDK shim unavailable), `empty`, `no_shipping_needed`, `subscription`, `multiple_packages`, `guest_checkout_disabled`, `unavailable` (snapshot not buildable; logged as `total_mismatch` or `rounding_mismatch` when the amounts would not add up to the cart total).

Other signals: `Return: invalid or missing signature` / `unknown session` (a tampered or foreign return URL); `Session creation failed (HTTP status N)` (wallee API refused, check credentials in the main plugin and run Configure); `transaction details not read` (the shim or the API; the result handler and the background job retry); `link attempt K stopped by an error (Class)` (an unexpected error in the background job; the next attempt is scheduled); `link attempt counter not saved` (the job stops; check the database); `transaction T ended in state S; not linked` (no money taken, job ended); `gateway of transaction T unresolved` (order keeps the generic payment method and a note). The shipping callback answers the wallet with `COUNTRY_UNAVAILABLE` or `SHIPPING_METHOD_UNAVAILABLE` when no rate fits.

SDK shim status: WooCommerce > Settings > wallee Express Checkout > Status, line "SDK compatibility shim": `active` (the add-on's copy is loaded), `not needed` (the main plugin's SDK knows `EXPRESS_CHECKOUT`) or `unavailable` (the main plugin cannot read Express Checkout transactions; express checkout is disabled and an error notice shows in the admin). Also check the main plugin's own log and wallee portal > Transactions for the transaction state.
