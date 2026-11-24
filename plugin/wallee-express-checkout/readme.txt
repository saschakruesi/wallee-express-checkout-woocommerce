=== wallee Express Checkout ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
Requires Plugins: woocommerce
License: GPL-2.0-or-later

Express checkout buttons for WooCommerce, powered by wallee. Prototype.

== Description ==

Adds wallet buttons (rendered by wallee's Express Checkout widget) to the WooCommerce cart page and to product pages. The customer approves the payment in the wallet, passes the 3-D Secure step and lands on the order-received page.

The add-on has no credentials of its own. It uses the space ID and application user already configured in the wallee main plugin, and it needs that plugin (tested with 3.4.11) and WooCommerce 8.0 or newer.

How an order is made: the wallet approves a payment session. When the customer returns, the add-on re-checks stock, coupons, shipping and the approved amount, creates the order as "Pending payment", starts the payment at wallee and reads the customer's addresses and e-mail from the wallee transaction. The main plugin's webhook then moves the order status.

Linking to the main plugin: refunds, completion and void from the WooCommerce order screen work once the order carries the main plugin's wallee payment method and its transaction record. The add-on links the order when the customer comes back from the payment step and, independently of the browser, in a background job (WooCommerce's Action Scheduler, hook `wallee_ec_link_order`) that retries after 5, 10, 20, 40, 80 and 160 minutes. Opening a not yet linked express order in the admin also tries again. If an order still cannot be linked, it keeps the payment method "wallee Express Checkout" and gets a private note: complete and refund it in the wallee portal. Refunds have not yet been verified end to end on staging (e2e cases 9 and 10).

This is a prototype. It is not published on wordpress.org.

== Installation ==

1. Install and configure the wallee main plugin (space ID, application user ID and key). Activate WooCommerce and the main plugin.
2. Copy the folder `wallee-express-checkout` to `wp-content/plugins/` and activate it.
3. Go to WooCommerce > Settings > wallee Express Checkout (own tab next to the "wallee" tab; the plugin's row on the Plugins screen has a "Settings" link).
4. Press "Configure". This checks the main plugin's credentials against the space, makes sure the main plugin's webhook listeners exist and synchronises the payment methods. It reports the result as notices. It does not save the other fields on the page; save those separately.
5. Tick "Cart page" and/or "Product pages", then save.
6. In the wallee portal, enable Express Checkout for the wallet (Space > Settings > Wallets).

If the main plugin or WooCommerce is missing, the add-on stays inactive and shows an admin notice. If the main plugin has no space ID, user ID or key yet, or the SDK compatibility shim is unavailable, the buttons stay hidden and an admin notice says why.

== Settings ==

All settings are on WooCommerce > Settings > wallee Express Checkout.

* Cart page: show buttons on the cart page (block and classic cart). Off by default.
* Product pages: show buttons on single product pages. Off by default. The two switches are independent.
* Environment: Test or Production (default Test). Use the one that matches your space's mode.
* Buttons per row (1 to 6, default 2) and Maximum rows (0 = no limit, up to 10).
* Apple Pay domain verification file: the content of the file downloaded from the wallee portal, stored as entered and served as plain text.
* Status block (read-only): main plugin version, space ID in use, state of the SDK compatibility shim, and the URL the Apple Pay file is served at.

== Known limits ==

* Tax: product prices including tax are fixed when the buttons load, using the tax location WooCommerce has for the customer at that moment. Only the shipping rates follow the address picked in the wallet. If the wallet address is in another country than that tax location, the order gets a private note. The API does not allow changing line items after the session is created.
* Coupons with an e-mail restriction are not accepted: the e-mail is not known when the order is checked, so the payment is refused before the order is created and the customer returns to the cart with a notice. Other coupons are re-checked at that point.
* A wallee session lives 30 minutes from its creation. The server tells the page when the session expires and the page renews it 15 minutes before that (a wallet flow can take over 10 minutes); a page reload reuses a session only while it is less than 5 minutes old. A renewal never happens while the wallet overlay is open; it waits until the overlay closes. If a customer approves after the session expired, they return to the cart with a notice.
* Price changes: the price shown when the buttons loaded is the price the customer approves and pays. A price edit in the shop after that does not change an approval in progress; the order is created at the approved price.
* E-mail address: the order needs one. If wallee returns none (billing address, shipping address, transaction customer e-mail or the logged-in customer's account) when the order is placed, the order fails, the customer is not sent on to the payment step and returns to the cart with a notice. If wallee had already reserved or taken the money at that point, the failed order gets an "ACTION REQUIRED" note and the log an error: void or refund that transaction in the wallee portal. When the customer comes back after paying and the e-mail is still missing, the paid order is kept with a private note.
* Nothing to ship (temporary): the buttons are hidden when nothing in the cart, or the viewed product or variation, needs shipping. wallee's wallet sheet currently asks for a shipping address for such sessions and then rejects it, so these purchases cannot complete. Once wallee has fixed this, a filter switches the buttons back on: `add_filter( 'wallee_ec_allow_without_shipping', '__return_true' );`.
* Rounding: if the amounts the add-on sends to wallee would not add up exactly to the WooCommerce cart total (for example with "Round tax at subtotal level" and several lines), the buttons are not shown for that cart rather than charging a total that differs by a cent.
* Unclear answer when starting the payment: if wallee does not answer clearly (timeout, server error), the add-on reads the session again. If the payment was started, the order is kept and the customer sees the order-received page; if the session is unchanged, the call is repeated once; only a clear refusal fails the order.
* Abandoned orders: an express order that was created but never handed to wallee is cancelled by WooCommerce's unpaid-order cleanup (Settings > Products > Inventory > Hold stock). Orders handed to wallee are left to the main plugin's webhooks; an abandoned 3-D Secure step relies on wallee failing the transaction. There is no stock reservation during the wallet flow.
* Buttons are hidden when: the cart is empty, nothing needs shipping (see above), it contains a subscription product, it needs more than one shipping package, guest checkout is disabled and the customer is not logged in, the main plugin is not configured, or the SDK compatibility shim is unavailable.
* Not supported: mini cart, checkout page, multi-currency plugins, pre-orders.
* Google Pay: wallee does not yet offer it for Express Checkout. The widget shows the wallets wallee enables, so it should appear without changes here once wallee does.
* Stock is checked again after approval. If an item became unavailable, no payment is started and the customer returns to the cart.
* Compatibility shim: the SDK bundled in wallee plugin 3.4.11 rejects transactions of the user interface type `EXPRESS_CHECKOUT`, which would break the main plugin's webhook handling for these orders. The add-on therefore loads a copy of that one SDK enum class with the extra value. It does this only if the main plugin's SDK file lacks the value, so it switches itself off when the main plugin ships a newer SDK. A filter `wallee_ec_enable_sdk_shim` (return false) turns it off; express checkout is then disabled (no buttons, admin notice), because the main plugin's webhooks for these transactions would fail. The proper fix belongs in the main plugin.
* Newer main plugin versions than 3.4.11 run with an admin warning until tested.

== Apple Pay ==

Apple Pay Express Checkout needs the shop's host name to be registered in the wallee portal:

1. In the portal enable Express Checkout under Space > Settings > Wallets > Apple Pay (needs a connector that supports Apple Pay Express Checkout).
2. Add the shop's host name under "Registered Shop Domains" and download the verification file.
3. Paste the file's content into "Apple Pay domain verification file" in the add-on's settings and save.
4. The add-on serves it at `https://<your host>/.well-known/apple-developer-merchantid-domain-association` as plain text with no redirect. Open that URL to check it, then complete the registration in the portal.

Each host name (with and without `www`) is registered separately. Apple Pay is not tested in this prototype.

== Changelog ==

= 0.1.0 =
* Fix wave after the branch review: background linking of every order, robust handling of unclear process answers, orders without e-mail fail before payment, own settings tab, session renewal from the real expiry, fail-closed rounding check, buttons off without the SDK shim or without shippable items.
* First prototype: cart page and product page buttons, shipping options from WooCommerce zones, order creation after wallet approval, Configure button, SDK compatibility shim, Apple Pay verification file route.
