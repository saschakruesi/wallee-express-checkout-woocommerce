=== wallee Express Checkout ===
Contributors: wallee
Tags: woocommerce, wallee, express checkout, apple pay, paypal
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce, woo-wallee
Stable tag: 1.0.0
License: Apache-2.0
License URI: http://www.apache.org/licenses/LICENSE-2.0

Adds wallet express checkout buttons (Apple Pay, PayPal) to the WooCommerce cart and product pages, using the wallee plugin.

== Description ==

Adds wallet buttons, rendered by wallee's Express Checkout widget, to the WooCommerce cart page (block and classic cart) and to product pages. The customer approves the payment in the wallet, passes the 3-D Secure step and lands on the order-received page.

The add-on has no credentials of its own. It uses the space ID and application user already configured in the [wallee plugin](https://wordpress.org/plugins/woo-wallee/) and needs that plugin (tested with 3.4.11) and WooCommerce 8.0 or newer. A wallee account is required.

The add-on:

* shows the wallet buttons the space has enabled in the wallee portal, on the cart page and/or on product pages (two independent switches, both off by default);
* checks the environment, stock, coupons, shipping, tax and the approved amount again when the customer returns from the wallet, before it creates the order;
* creates the order as "Pending payment" and lets the main plugin's webhook move the status, so refunds, completion and void work from the WooCommerce order screen;
* supports WooCommerce's high-performance order storage (HPOS) and the block-based cart.

See the FAQ for how an order is made, the settings, Apple Pay and the known limits. Data sent to wallee is listed under "External services".

== Installation ==

1. Install and configure the wallee main plugin (space ID, application user ID and key). Activate WooCommerce and the main plugin.
2. Upload the folder `wallee-express-checkout` to `wp-content/plugins/` (or upload the plugin's zip file under Plugins > Add New) and activate it.
3. Go to WooCommerce > Settings > wallee Express Checkout (own tab next to the "wallee" tab; the plugin's row on the Plugins screen has a "Settings" link).
4. Press "Configure". This checks the main plugin's credentials against the space, makes sure the main plugin's webhook listeners exist and synchronises the payment methods. It reports the result as notices. It does not save the other fields on the page; save those separately.
5. Tick "Cart page" and/or "Product pages", then save.
6. In the wallee portal, enable Express Checkout for the wallet (Space > Settings > Wallets).

If the main plugin or WooCommerce is missing, the add-on stays inactive and shows an admin notice. If the main plugin has no space ID, user ID or key yet, or the SDK compatibility shim is unavailable, the buttons stay hidden and an admin notice says why.

== Frequently Asked Questions ==

= How is an order made? =

How an order is made: the wallet approves a payment session. When the customer returns, the add-on re-checks the environment, stock, coupons, shipping and the approved amount, creates the order as "Pending payment", starts the payment at wallee and reads the customer's addresses and e-mail from the wallee transaction. Before the customer is sent to wallee's payment step, the order is checked once more with that data (e-mail present, coupons valid for that e-mail, address in the country the shipping was calculated for and with the tax rates the prices were calculated with). The main plugin's webhook then moves the order status.

= How is the order linked to the wallee plugin? =

Linking to the main plugin: refunds, completion and void from the WooCommerce order screen work once the order carries the main plugin's wallee payment method and its transaction record. The add-on links the order when the customer comes back from the payment step and, independently of the browser, in a background job (WooCommerce's Action Scheduler, hook `wallee_ec_link_order`) that retries after 5, 10, 20, 40, 80 and 160 minutes. Opening a not yet linked express order in the admin also tries again. If an order still cannot be linked, it keeps the payment method "wallee Express Checkout" and gets a private note: complete and refund it in the wallee portal. If the wallee transaction ended as FAILED, DECLINE or VOIDED, the order is not linked: it gets one private note that no money was taken (and that no refund is needed), and the job stops. An order still "Pending payment" is set to "Failed" with that note (WooCommerce sends its usual e-mails for that status, the add-on sends none); an order in any other status keeps it. An unexpected error in one attempt is logged with its class name and counts as a failed attempt, so the retries go on. Refunds have not yet been verified end to end on staging (e2e cases 9 and 10).

= Which settings are there? =

All settings are on WooCommerce > Settings > wallee Express Checkout.

* Cart page: show buttons on the cart page (block and classic cart). Off by default.
* Product pages: show buttons on single product pages. Off by default. The two switches are independent.
* Environment: Test or Production (default Test). Use the one that matches your space's mode. An approved payment is only turned into an order when it was authorised in this environment. Every express order stores it (order meta `_wallee_ec_environment`); Test orders get the private note "Test payment (wallee Express Checkout TEST environment). No real money was moved." and an "Express TEST" badge in the order list and on the order edit screen. While Test is selected and buttons are switched on, shop managers see a warning on every admin page that cannot be dismissed. Switch to Production before taking real orders.
* Buttons per row (1 to 6, default 2) and Maximum rows (0 = no limit, up to 10).
* Apple Pay domain verification file: the content of the file downloaded from the wallee portal, stored as entered and served as plain text.
* Status block (read-only): main plugin version, space ID in use, state of the SDK compatibility shim, and the URL the Apple Pay file is served at.

= How do I set up Apple Pay? =

Apple Pay Express Checkout needs the shop's host name to be registered in the wallee portal:

1. In the portal enable Express Checkout under Space > Settings > Wallets > Apple Pay (needs a connector that supports Apple Pay Express Checkout).
2. Add the shop's host name under "Registered Shop Domains" and download the verification file.
3. Paste the file's content into "Apple Pay domain verification file" in the add-on's settings and save.
4. The add-on serves it at `https://<your host>/.well-known/apple-developer-merchantid-domain-association` as plain text with no redirect. Open that URL to check it, then complete the registration in the portal.

Each host name (with and without `www`) is registered separately. Apple Pay has not been tested yet.

= What are the known limits? =

Tested so far only on a staging shop in wallee's TEST environment with a test wallet.

* Tax: product prices including tax are fixed when the buttons load, using the tax location WooCommerce has for the customer at that moment. The API does not allow changing line items after the session is created. When WooCommerce taxes by the customer's address (taxes enabled and "Calculate tax based on" not the shop base address), an address is accepted only if WooCommerce finds the same tax rates for it as for that tax location, for every tax class of the cart's lines, the standard class when the cart has fees, and a fixed shipping tax class (country, state, postcode and city rates all count; another state with national rates is accepted). wallee sends the region as free text; it is matched to WooCommerce's state code by code, by name (case-insensitive) or with an ISO prefix such as "US-CA", and the order stores the code. A region that matches no state is stored as sent; where the country's rates depend on the state, it is left out of the comparison, the order is kept and gets the private note "ACTION REQUIRED: check tax (region could not be matched)." With taxes based on the shipping address the wallet offers no shipping to other addresses; they see "Express checkout is not available for deliveries to <country>." or, within the same country, "Express checkout is not available for this delivery address.", followed by "Please choose another address or use the regular checkout." With taxes based on the billing address the wallet cannot check (it only sends the shipping address); the billing address from wallee is checked when the customer returns. An order whose wallet address is in another country than the shipping quote, or whose taxed address has other rates, fails before the payment step and the customer returns to the cart. Customers who want to ship elsewhere set their address in the cart's shipping calculator first or use the regular checkout.
* Coupons are checked twice: when the customer returns from the wallet (amounts, products, usage limits) and again once the wallet's e-mail is on the order, before the payment step: e-mail restrictions, usage limit per user (guests are counted by billing e-mail, as at WooCommerce's checkout) and the global usage limit. If a coupon is not valid for that customer, the order fails, no payment is started and the customer returns to the cart with a notice. Once these checks pass, the order takes WooCommerce's tentative coupon hold for its own coupons and that e-mail (WooCommerce 4.3 and later), as the regular checkout does; coupons that are only in the customer's cart (for example during a product page purchase) are never held. If the hold is refused because a limit is reached, the order fails the same way; a hold that fails for a technical reason never fails an order whose payment is reserved or taken. If the transaction could not be read when the customer returned, these checks run once it is read after payment; a failure then keeps the paid order and adds the private note "ACTION REQUIRED: check before shipping (<reason>)". When WooCommerce has already counted the order's coupon use by then, the coupons cannot be checked again and the order gets that note with the reason `coupons_not_rechecked`, also when nothing is wrong.
* Validation: on the product page the add-on runs WooCommerce's add-to-cart validation (`woocommerce_add_to_cart_validation`) for the product, variation and quantity; on the cart page it runs the cart checks (`woocommerce_check_cart_items`). If either refuses, no buttons are shown. Notices these checks add are removed again. WooCommerce's checkout-form hooks (`woocommerce_checkout_process` and similar) do not run.
* Product page purchases contain only the product: cart-level fees (for example a small-order surcharge added by a plugin) are not applied. Use the cart page buttons, or switch product page buttons off, if your shop relies on such fees.
* Product page: variation data is checked against the product (only the parent's variation attributes, with values valid for the chosen variation). Password-protected and unpublished products get no buttons. Cart page: a cart line may only carry variation data that are variation attributes of its product; otherwise no buttons are shown.
* A wallee session lives 30 minutes from its creation. The server tells the page how many seconds the session has left; the page counts that down with the browser's monotonic clock from the moment it asked, so a wrong computer clock does not matter, and renews the session 15 minutes before it ends (a wallet flow can take over 10 minutes); a page reload reuses a session only while it is less than 5 minutes old. A renewal never happens while the wallet overlay is open; it runs as soon as the overlay closes or the customer cancels the wallet. If a customer approves after the session expired, they return to the cart with a notice.
* Price changes: the price shown when the buttons loaded is the price the customer approves and pays. A price edit in the shop after that does not change an approval in progress; the order is created at the approved price.
* E-mail address: the order needs one. If wallee returns none (billing address, shipping address, transaction customer e-mail or the logged-in customer's account) when the order is placed, the order fails, the customer is not sent on to the payment step and returns to the cart with a notice. If wallee had already reserved or taken the money at that point, see "Failed orders with money at wallee". If the transaction could not be read when the customer returned and the background job then finds no e-mail (for example a payment authorised after the customer's browser left), the order fails the same way, for `no_email`, and its money is handled as described there (void of a reservation, refund note for taken money, a later check while the payment is undecided). When the customer's browser comes back to the success link first and the e-mail is still missing, the order is kept with a private note.
* Nothing to ship (temporary): the buttons are hidden when nothing in the cart, or the viewed product or variation, needs shipping. wallee's wallet sheet currently asks for a shipping address for such sessions and then rejects it, so these purchases cannot complete. Once wallee has fixed this, a filter switches the buttons back on: `add_filter( 'wallee_ec_allow_without_shipping', '__return_true' );`.
* Rounding: if the amounts the add-on sends to wallee would not add up exactly to the WooCommerce cart total (for example with "Round tax at subtotal level" and several lines), the buttons are not shown for that cart rather than charging a total that differs by a cent.
* Unclear answer when starting the payment: if wallee does not answer clearly (timeout, server error), the add-on reads the session again. If the payment was started, the order is kept and the customer sees the order-received page; if the session is unchanged, the call is repeated once. If wallee refuses that repeated call (HTTP 4xx), the session is read once more: when it has a transaction or is PROCESSING or AUTHORIZED, the first call went through and the order goes on as above; otherwise the refusal fails the order, as a refusal of the first call does.
* Abandoned orders: an express order that was created but never handed to wallee is cancelled by WooCommerce's unpaid-order cleanup (Settings > Products > Inventory > Hold stock). Orders handed to wallee are left to the main plugin's webhooks; an abandoned 3-D Secure step relies on wallee failing the transaction. There is no stock reservation during the wallet flow.
* Visitors without a WooCommerce session (no cart cookie and not logged in) get no session from the add-on: on the cart page their cart is empty anyway, on a product page they get a new payment session on every page load instead of a reused one.
* Rate limits: 20 session requests per client and minute and 300 per minute for the whole shop; an IPv6 client is counted by its /64 network; a client over its own limit does not use up the shop-wide limit. The counters live in the add-on's own table (`wp_wallee_ec_rate`) and are counted up by the database in one statement per request, so parallel requests cannot exceed the limits; they do not need a persistent object cache. If that table cannot be used (for example before the database update ran), the add-on falls back to WordPress transients, which are not atomic.
* Customer messages: every failure tells the customer what happened, whether they were charged and what to do next (for example "Your cart changed while you were paying. You have not been charged. Please review your cart and try again."). "You have not been charged." is only said where no payment can have happened; where that is not certain the message says "If an amount was reserved, it will be released." instead. If the money was only reserved and the order still fails, the reservation is voided and the message says "The reservation on your payment method is being released. You will not be charged." If the money was taken (or the void failed), the customer reads "Your payment was received, but we could not complete your order automatically. Please do not pay again. We will contact you about order <number>." Guests get a WooCommerce session for the notice, so it survives the redirect to the cart. Internal reason codes only go to the log and private order notes.
* Links: the return and shipping links of a wallet session work for 2 hours after the session was created, the result links for 7 days. An older return or result link leads to the cart with a notice.
* After the payment start the customer is sent only to the host of the wallee base URL configured in the main plugin or to the add-on's own result links. A redirect to any other host (some wallets complete the payment themselves) is not followed: the customer goes to the order received page when wallee shows the payment under way or done; otherwise the order fails and the customer returns to the cart.
* Buttons are hidden when: the cart is empty, nothing needs shipping (see above), it contains a subscription product, it needs more than one shipping package, guest checkout is disabled and the customer is not logged in, the main plugin is not configured, or the SDK compatibility shim is unavailable.
* Tax destination check: a fee's own tax class is not kept in the cart snapshot; fees are compared with the standard class. The tax class of a line whose product is not taxable is compared too, which can refuse an address with other rates for that class. The region format wallee sends for each wallet has not been verified against real wallet data; a region that cannot be matched gets the ACTION REQUIRED tax note described under "Tax". If wallee returns no country for the address, the tax check is skipped (as before). Local pickup, which WooCommerce taxes at the shop base address, is not treated specially.
* Failed orders with money at wallee: an order that fails for a missing e-mail, an invalid coupon or a changed destination while wallee already holds the money is handled by the transaction state. AUTHORIZED (reserved): while the order is still failed, or on-hold because the main plugin's webhook revived it to on-hold, the add-on asks wallee once to void the transaction (an atomic claim in the database keeps the background job and an admin view from both sending it) and adds a private note; if the void fails, the note says "ACTION REQUIRED ... void failed" and the transaction must be voided manually in the wallee portal, unless wallee shows that the transaction was completed meanwhile: then the refund note below is added instead. An order that is processing or completed, or whose status was changed after it failed or was revived, is not voided; it gets an ACTION REQUIRED note to check it. COMPLETED or FULFILL (taken): never voided automatically; the note "ACTION REQUIRED ... A refund is needed" asks for a refund in the wallee portal. Not decided yet (for example PROCESSING): no void; the background job (`wallee_ec_link_order`) checks the transaction again after 5, 10, 20, 40, 80 and 160 minutes and voids it once it is AUTHORIZED, or adds the refund note once it is taken; opening such an order in the admin runs the same check. Such orders are not linked to the main plugin.
* Late payment updates: the main plugin's webhook can still set a failed express order to on-hold or processing when wallee reports the payment (it finds the order by its reference). The add-on does not block this, because the main plugin offers no single narrow hook for it; it adds the private note "ACTION REQUIRED: this express order failed (<reason>), but its status was then set to <status> (for example by a payment update). ..." instead. WooCommerce may then also send its usual order e-mails. Check such orders before shipping.
* Test environment: orders made while the environment is Test carry an "Express TEST" badge in the "Express checkout" column next to the status in the order list (HPOS and legacy order screens) and on the order edit screen ("No real money was moved. Do not ship this order."), besides the private order note and the order meta `_wallee_ec_environment`. Other express orders show "Express" in that column. The payment method title is not changed; e-mails and other order views do not show the mark.
* Tested so far only on a staging shop in wallee's TEST environment with the Bogus Pay test wallet. Apple Pay, other wallets, refunds, completion and void from the order screen and production use have not been verified end to end.
* Not supported: mini cart, checkout page, multi-currency plugins, pre-orders.
* Google Pay: wallee does not yet offer it for Express Checkout. The widget shows the wallets wallee enables, so it should appear without changes here once wallee does.
* Stock is checked again after approval. If an item became unavailable, no payment is started and the customer returns to the cart.
* Compatibility shim: the SDK bundled in wallee plugin 3.4.11 rejects transactions of the user interface type `EXPRESS_CHECKOUT`, which would break the main plugin's webhook handling for these orders. The add-on therefore loads a copy of that one SDK enum class with the extra value. It does this only if the main plugin's SDK file lacks the value, so it switches itself off when the main plugin ships a newer SDK. A filter `wallee_ec_enable_sdk_shim` (return false) turns it off; express checkout is then disabled (no buttons, admin notice), because the main plugin's webhooks for these transactions would fail. A customer who returns from the wallet while the shim is unavailable is sent back to the cart with "Express checkout is currently not available. You have not been charged. Please use the regular checkout."; no order is created and no payment is started. The proper fix belongs in the main plugin.
* Newer main plugin versions than 3.4.11 run with an admin warning until tested.
* wallee's handler script (`/assets/payment/express-checkout-handler.js` on the wallee host) is loaded without Subresource Integrity: wallee publishes it only at this fixed, unversioned URL whose content changes with wallee's releases, so a pinned hash would switch the buttons off at wallee's next update.

= What security measures does the add-on take? =

Version 1.0.0 fixes weaknesses found in a security review of 0.1.0. Update from 0.1.0; there are no settings to migrate (the add-on adds its rate-limit table by itself on the first request after the update). What changed is listed under "Known limits" and in the changelog. Points to check after updating:

* Environment: keep Test only on test shops (see Settings).
* Rate limiting identifies a client by `REMOTE_ADDR`. If the shop sits behind a reverse proxy or CDN, every request appears to come from the proxy. Return the visitor's address from a header your proxy sets and the visitor cannot forge, with the filter `wallee_ec_client_ip`, for example in a must-use plugin: `add_filter( 'wallee_ec_client_ip', function ( $ip ) { /* read the trusted header, validate it, else return $ip */ } );`. Values that are not IP addresses are ignored.

= Which personal data does the add-on handle? =

* Personal data export and erasure (Tools > Export Personal Data / Erase Personal Data) include the add-on's data of a person's express orders, found like WooCommerce does it (orders with that billing e-mail or of the user account with that e-mail). The export lists each session record (order, creation time, state, wallee session and transaction IDs, the tax location used for the prices and the delivery destination chosen in the wallet) and the add-on's order meta (wallet, environment, wallee IDs, failure reason, void and refund flags). The eraser deletes finished session records (completed or failed); a record whose payment may still be in progress is kept and reported, and is deleted by the daily cleanup like every other record 7 days after its creation. Orders and order meta are not deleted by the add-on; WooCommerce's own eraser handles the order.
* Session records without an order cannot be linked to a person and are only removed by the daily cleanup (7 days).
* The add-on suggests a section for the privacy policy (Settings > Privacy > Policy Guide).

== External services ==

This add-on connects to the wallee payment service (wallee AG, https://wallee.com) in two ways. A wallee account is required. The wallee host is the one configured in the wallee plugin (by default `https://app-wallee.com/`).

= wallee API (server to server) =

* What it is used for: creating an express checkout session, reading the session and the payment transaction, starting the payment and voiding a payment that must not be kept.
* Data sent: the cart's line items (name, SKU, quantity, amounts, tax), the currency, the language, the environment (test or production), and the add-on's return and shipping-callback URLs, signed per session. Later the session ID, the transaction ID, the WooCommerce order ID as merchant reference and the order's result URLs. The shopper's name, address and e-mail are not sent; they are read from wallee's response.
* When: when a shopper opens a cart or product page with the buttons enabled (session creation, renewed while the page stays open), when the shopper returns from the wallet, and from background jobs and the order screen for orders made by this add-on.
* Authentication uses the space ID, application user ID and key of the wallee plugin. The add-on does not store them.

= wallee express checkout handler script (shopper's browser) =

* What it is used for: renders the wallet buttons and runs the wallet flow. The wallet providers (Apple Pay, PayPal) are reached through wallee's iframe, not from this add-on.
* Script: `https://app-wallee.com/assets/payment/express-checkout-handler.js` (host as configured in the wallee plugin).
* Data sent: the shopper's browser requests the script and the wallet widget from wallee, so wallee sees the shopper's IP address and browser data like any web server. The session token created above is passed to the widget.
* When: only on cart pages and product pages where the buttons are enabled, and only when the add-on is active.
* The script is loaded from wallee without Subresource Integrity: wallee publishes it only at this fixed, unversioned URL and updates it in place, so a pinned hash would switch the buttons off at wallee's next update. Other payment plugins load their provider's SDK the same way.

wallee terms of use: https://en.wallee.com/legal/agb
wallee privacy policy: https://en.wallee.com/legal/privacy-policy
Company site: https://wallee.com

== Changelog ==

= 1.0.0 =
* Security fixes: validation of product variation data, environment check, coupon re-validation, destination and tax location checks, rate limiting, link lifetime; staging hardening.
* Fix: wallets that complete the payment themselves (PayPal) no longer fail a paid order. wallee's redirect after the payment start is followed only to the wallee host or to the add-on's own result links; otherwise the customer goes to the add-on's success link. An order whose money is already reserved or taken fails only for a missing e-mail, an invalid coupon or a changed destination (with an ACTION REQUIRED note), never for a technical reason.
* Customer messages say what happened, whether the customer was charged and what to do next.
* Rate limiting counts an IPv6 client by its /64 network.
* Fix: product page purchases with attribute names containing non-ASCII characters work again.
* Variation data is checked by the item meta name it becomes, and cart lines are checked against their product's variation attributes.
* Coupons: WooCommerce's tentative coupon hold is taken for the order's own coupons when the customer returns, never for other coupons in the cart.
* Fix: the coupon hold keys are saved with the order, so the tentative hold is released when the coupon use is recorded.
* Coupon and destination checks skipped at return because the transaction could not be read run after payment and add an ACTION REQUIRED note on failure.
* An error thrown by a third-party validation hook now hides the buttons instead of causing a server error.
* Orders that fail for a missing e-mail, an invalid coupon or a changed destination: a reserved amount (AUTHORIZED) is voided automatically once; taken money gets an ACTION REQUIRED note that a refund is needed; a failed order revived by a later payment update gets an ACTION REQUIRED note.
* The automatic void is claimed atomically, is only sent while the order is still failed or on-hold after a revival by a payment update (not when processing, completed or changed by the merchant), and a void refused because the payment was completed meanwhile asks for a refund.
* A failure while settling a failed order no longer tells a customer whose payment is not decided that the payment was received.
* The note for a revived failed order can no longer break the main plugin's payment update.
* Tax rates are compared by percentage and flags, not by rate ID.
* The tax destination check compares WooCommerce's tax rates for the address with those of the tax location (state, postcode and city rates; billing address when taxes follow it) instead of the country.
* wallee's free-text region is matched to WooCommerce's state code for the order address, the shipping callback and the tax check; an unmatched region no longer fails the order but gets an ACTION REQUIRED note.
* Privacy: personal data exporter and eraser for the add-on's session records and order meta, and a suggested privacy policy text.
* Rate limits are counted atomically in the add-on's own table.
* A return from the wallet is refused (no order, no payment) while the SDK compatibility shim is unavailable.
* A refused repeat of an unclear payment start no longer fails the order when wallee shows that the first call went through.
* Background link job: a transaction that ended FAILED, DECLINE or VOIDED gets one clear note and ends the retries; an error in one attempt is logged and retried; a payment authorised after the browser left without an e-mail address fails the order like at the return (void or refund note).
* Background link job: an order still pending whose transaction ended FAILED, DECLINE or VOIDED is set to failed with that one note.
* "Express TEST" badge for test orders in the order list and on the order edit screen.
* Session renewal no longer depends on the browser's clock and also runs after the customer cancels the wallet.

= 0.1.0 =
* Fix wave after the branch review: background linking of every order, robust handling of unclear process answers, orders without e-mail fail before payment, own settings tab, session renewal from the real expiry, fail-closed rounding check, buttons off without the SDK shim or without shippable items.
* First prototype: cart page and product page buttons, shipping options from WooCommerce zones, order creation after wallet approval, Configure button, SDK compatibility shim, Apple Pay verification file route.
