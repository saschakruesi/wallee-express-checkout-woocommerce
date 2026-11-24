# Spike findings: wallee Express Checkout API

Status: complete. Items 3 and 7 answered from a real Bogus Pay approval (session 335); item 4 only partly (the shipping callback went to the add-on's own endpoint, which does not log requests).

Date: 2026-11-24. Environment: staging shop, wallee main plugin 3.4.11, space 68410. All values below were observed against the real API at `https://app-wallee.com/api/v2.0`. Tokens are redacted.

## 1. Authentication: MAC via the main plugin's SDK client works

Definite answer: **yes.** `WC_Wallee_Helper::instance()->get_api_client()->callApi( '/v2.0/express-checkout/session/create', 'POST', [], $body, $headers )` returns HTTP 200. No JWT is needed, `wp_remote_request` is not needed. `SdkTransport` is correct as written.

Observations:

- The path passed to `callApi` is `/v2.0/express-checkout/...` (SDK base URL is `https://app-wallee.com/api`). Same for GET `/v2.0/express-checkout/session` with query `sessionId`.
- The space comes from the `space` request header and nothing else (the SDK adds none):
  - header omitted: HTTP 400 `{"code":"space_invalid", "message":"The space ID was not provided or is invalid."}`
  - header `space: 1` (a space the application user cannot access): HTTP 403 `access_denied`, "Permission denied for user ... needs ... Space >> Payment >> Payment Processing >> Transaction >> Create"
  - header = configured space ID (option `wc_wallee_space_id`): 200.
- Error bodies are JSON `{code, date, id, message}`. `ApiException::getResponseBody()` of the SDK carries the raw string. Seen codes: `space_invalid` (400), `access_denied` (403), `resource_missing` (404, "The requested express checkout session does not exist."), and unnamed 422 bodies `{date, id, message}` for validation failures (no `code`).
- The SDK exception message embeds the URL and the body; `SdkTransport` already strips it. Good.

Create response (200), complete:

```json
{ "sessionId": 330, "sessionToken": "17955196…(len 100)" }
```

`sessionId` is an int, `sessionToken` a string of 100 chars with the structure `<13 digit ms timestamp>-<43 char base64url>-<base64 of scope URL, here "https://app-wallee.com">-<sessionId>-<spaceId>` (5 dash-separated parts; the handler script parses it, see item 2). The token contains `=` padding in part 3, so it must be JSON-encoded or URL-encoded when placed in a page or URL.

GET session of a fresh session (state `PENDING`), full key list with redacted/shortened values:

```json
{
  "authorizationAmount": 25,
  "authorizationEnvironment": "TEST",
  "createdOn": "2026-11-24T11:25:03.395+01:00",
  "currency": "CHF",
  "id": 316,
  "language": "en-US",
  "lineItemEntities": [ { "aggregatedTaxRate": 8.1, "amountExcludingTax": 23.13, "amountIncludingTax": 25,
      "attributes": [], "currency": "CHF", "discountExcludingTax": 0, "discountIncludingTax": 0,
      "id": 5948713217, "lineItemsStoredAttributes": [], "name": "Spike product", "quantity": 1,
      "shippingRequired": true, "sku": "SKU1",
      "taxAmount": 1.87, "taxAmountPerUnit": 1.87,
      "taxes": [ { "amount": 1.87, "id": 2536876602, "rate": 8.1, "title": "VAT", "version": 0 } ],
      "type": "PRODUCT",
      "undiscountedAmountExcludingTax": 23.13, "undiscountedAmountIncludingTax": 25,
      "undiscountedUnitPriceExcludingTax": 23.13, "undiscountedUnitPriceIncludingTax": 25,
      "uniqueId": "p-1", "unitPriceExcludingTax": 23.13, "unitPriceIncludingTax": 25, "version": 0 } ],
  "linkedSpaceId": 68410,
  "merchantRedirectUrl": "<as sent>",
  "merchantShippingCallbackUrl": "<as sent>",
  "metaData": [],
  "plannedPurgeDate": "2027-02-22T10:55:03.395Z",
  "shipmentRequired": true,
  "shippingOptions": [],
  "state": "PENDING",
  "timeZone": "Europe/Zurich",
  "timedOutOn": "2026-11-24T11:55:03.395+01:00",
  "version": 1
}
```

Differences from the documentation (spec section 3.1) that matter for the plugin:

- **The line items are under `lineItemEntities`, not `lineItems`.** Each carries read-only computed fields (`amountExcludingTax`, `taxAmount`, `unitPriceIncludingTax`, ids, versions).
- Not present on a PENDING session: `id` is present but `sessionToken`, `shippingAddress`, `billingAddress`, `linkedTransaction`, `transaction`, `walletType`, `failureReason`, `selected...` are absent (keys omitted, not null). Whether they appear later is phase 2.
- Extra keys not in the doc: `linkedSpaceId`, `shipmentRequired` (derived from `shippingRequired` of any line item), `timedOutOn`, `version`, `authorizationEnvironment`.
- Empty collections serialise as `[]` even where the schema says object (`attributes`, `metaData`): treat as arrays.
- Timestamps carry the offset of the `timeZone` given at creation (`+01:00`), while `plannedPurgeDate` is UTC (`Z`).
- The create schema in the OpenAPI spec references the read-only `LineItem` schema for `lineItems` (all fields `readOnly`), but the API in fact validates against `LineItem.Create` semantics (see item 5). Do not trust the generated model's read-only flags.

## 2. Handler script URL

Definite answer: **`https://app-wallee.com/assets/payment/express-checkout-handler.js`**

| Candidate | Result |
|---|---|
| `https://app-wallee.com/assets/payment/express-checkout-handler.js` | 200, `content-type: text/javascript`, 18293 bytes |
| `https://app-wallee.com/s/68410/assets/payment/express-checkout-handler.js` | 404 (HTML) |
| `https://app-wallee.com/s/68410/payment/express-checkout-handler.js` | 404 (HTML) |

How the script works (read from its source, summarised):

- The base URL is **derived from the `src` of the `<script>` tag** (it looks for a script whose `src` contains `/assets/payment/express-checkout-handler.js`). The tag therefore must keep that path.
- `ExpressCheckoutHandler.load( containerId, sessionToken, options )`: `containerId` is an id **without** `#` (the script prepends it). It appends an iframe `<scope>/s/<spaceId>/express-checkout/widget/<sessionToken>?maxColumns=2&embedderOrigin=<origin>[&maxRows=n]`. The scope URL comes from part 3 of the session token (base64), the space ID from part 5. If the token does not parse (not 5 parts) it falls back to the script's own origin.
- Options: `maxColumns` (1-6, default 2), `maxRows` (1-10), `onError(normalized)`, **and `onCancel(normalized)`** (undocumented in the design; the user closing a wallet sheet). `normalized` has a code, a message and a `diagnostics` object. If `onError` is missing the script shows an inline error for about 6 seconds.
- Other API: `openOverlay`, `closeOverlay`, `openLoadingOverlay`. The widget iframe is sandboxed (`allow-scripts allow-popups allow-same-origin allow-forms allow-top-navigation-by-user-activation`).
- The widget posts messages to the page (types `wallee-express-checkout-overlay-open|close`, `...loading-overlay-open|close`, `...redirect`, `...error`, `...cancel`); only messages from the widget origin are honoured. On `redirect` the page does `window.location.href = data.url` (this is how the browser reaches `merchantRedirectUrl`, to be confirmed in phase 2).
- No CSP concern for the script itself was observed on the spike page; the plugin must allow `frame-src https://app-wallee.com` if a CSP is in use.

Observed on the spike page (Browser pane, desktop width): the widget renders two buttons, "Bogus Pay (Test)" and "Apple Pay", for a TEST session with a shippable CHF item. No `onError` call. (Agent could see the wallet list; clicking through is pending.)

## 3. Session fields at APPROVED, and when `linkedTransaction` appears

Observed on real session 335 (Bogus Pay, created through the add-on's buttons: cart context, one 25.00 CHF item, one shipping option).

State `APPROVED` (GET session), key list: `approvedOn`, `authorizationAmount`, `authorizationEnvironment`, `createdOn`, `currency`, `id`, `language`, `lineItemEntities`, `linkedSpaceId`, `merchantRedirectUrl`, `merchantShippingCallbackUrl`, `metaData` (`[]`), `plannedPurgeDate`, `shipmentRequired`, `shippingOptions`, `state`, `timeZone`, `timedOutOn`, `version`, `walletType`.

- **No `shippingAddress`, no `billingAddress`, no e-mail, no names, no phone, no `linkedTransaction`, no `transaction` on the session at APPROVED**, and still none after `process` (the keys are simply absent). An `expand=billingAddress,shippingAddress,transaction` query parameter is ignored on the session GET. The customer data are only obtainable from the **transaction** (see below).
- `walletType`: an object `{ "id": 1756900767164, "name": {"en-US": "Bogus Pay (Test)"}, "description": {"en-US": "Bogus Processor wallet."} }` (localised maps, not a string).
- `shippingOptions[]` holds exactly the options the callback returned, with `selected: true` on the one the customer chose: `{ id: "flat_rate:1", label: "Flat rate", description: "", amountIncludingTax: 8.11, currency: "CHF", selected: true, taxes: [ { id: 0, rate: 8.1, title: "MWST", version: 0 } ] }`. **The option id is the WooCommerce rate id** (the id the add-on returned in the callback), echoed unchanged.
- `authorizationAmount` = 33.11 = 25.00 (product, gross) + 8.11 (selected shipping option, gross). Equals snapshot total (23.13 + 1.87 tax = 25.00) plus the selected option amount. wallee adds the shipping as an extra line item (see item 7).
- Product line: `uniqueId` is the add-on's own value (`ec_item_<hash>`), `sku` `EC-NOTEBOOK`, tax title `MWST` rate 8.1, unchanged from what was sent.
- Timing: created 11:30:22 (+01:00), approved 11:41:52, i.e. the wallet flow took 11 minutes of the 30.
- After `POST session/process`: state `PROCESSING`, **`linkedTransaction` (integer) and `transaction: {id}` appear now, not earlier**, plus `processingStartedOn`. So the transaction does not exist before `process`.

Transaction 629017977 right after `process` (raw GET `/v2.0/payment/transactions/{id}` through `callApi`, plain):

- `state` PROCESSING, `merchantReference` `spike-335` (taken from the process request), `authorizationAmount` 33.11, `authorizationEnvironment` TEST, `environment` LIVE (the space environment; unrelated), `userInterfaceType` **`EXPRESS_CHECKOUT`**, `customersPresence` VIRTUAL_PRESENT, `currency`, `language`, `timeZone`, `successUrl` / `failedUrl` as sent to process, `customerEmailAddress` set, `customerId` set, `group.id`, `metaData` `[]`.
- With `expand=billingAddress,shippingAddress`: both addresses are present with the fields `givenName`, `familyName`, `street`, `postcode` and `postCode` (both spellings), `city`, `country`, `emailAddress`. No phone, no state, no organisation, no salutation keys were returned (Bogus Pay mock data). This is where the order's addresses and e-mail must be read from.
- `paymentConnectorConfiguration` is **absent** at this point (before the 3-D Secure step), and the latest line item version does not exist yet (404 `resource_missing` "The requested line item version does not exist.").

Redirect after `process`: the response is `{ "redirectUrl": "https://app-wallee.com/s/68410/payment/chargeattempt/redirect/<chargeAttemptId>?securityToken=<token>" }` (only that key). Following it with a plain HTTP GET: 302 to `.../payment/chargeattempt/threeds/simulate?chargeAttemptId=...&securityToken=...`, which is an HTML "3-D Secure Simulation" page (merchant reference, amount, masked card xxxx 1111, a password form). **Bogus Pay needs the browser: the 3DS simulation requires a form submit, so session and transaction stay `PROCESSING` and nothing reaches `successUrl` without it.** I did not submit the form.

**Main plugin impact (important):** `WC_Wallee_Service_Transaction::instance()->get_transaction( $space, $id )` **throws** `InvalidArgumentException: Invalid value for enum '\Wallee\Sdk\Model\TransactionUserInterfaceType', must be one of: 'IFRAME', 'LIGHTBOX', 'PAYMENT_PAGE', 'MOBILE_SDK', 'TERMINAL'` for this transaction, because the SDK bundled with main plugin 3.4.11 does not know `EXPRESS_CHECKOUT` (the current spec has `CLOUD_TILL_INTERFACE` and `EXPRESS_CHECKOUT` as well). Any main-plugin code path that deserialises such a transaction (including its own webhook processing and order status sync) fails the same way. The raw `callApi` GET works.

## 4. Shipping callback request, and wallee's timeout

**Not observed.** The real callback went to the add-on's endpoint (`/wp-json/wallee-ec/v1/shipping-options`), which logs only IDs and states: its WooCommerce log shows the session creation and one "No shipping options ... SHIPPING_METHOD_UNAVAILABLE" entry for another session, nothing for 335 (the success path is silent). The response the add-on returned was accepted by wallee: option `flat_rate:1`, gross 8.11, taxes `[{title MWST, rate 8.1}]`, description `""`, and it is echoed on the session. Headers, body shape, user agent, signature handling by wallee and timeout remain unknown; the add-on's callback endpoint should log (without personal data) key names, `x-*` header names and duration in debug mode if this needs to be pinned down. A side observation from the spike log: requests through CloudFront arrive with `x-forwarded-for` and CloudFront `x-amz-cf-id` headers, plus the origin secret header; the callback URL carries its own `ec_session` and `sig` query parameters, which wallee preserves verbatim.

## 5. Line item shape, taxes and discounts

Create body that worked (one shippable item):

```json
{ "currency": "CHF", "authorizationEnvironment": "TEST", "language": "en-US", "timeZone": "Europe/Zurich",
  "merchantRedirectUrl": "https://<shop>/?...", "merchantShippingCallbackUrl": "https://<shop>/?...",
  "lineItems": [ { "uniqueId": "p-1", "sku": "SKU1", "name": "Spike product", "type": "PRODUCT",
                   "quantity": 1, "amountIncludingTax": 25.00, "shippingRequired": true,
                   "taxes": [ { "title": "VAT", "rate": 8.1 } ] } ] }
```

Definite answers (each tested by creating a session and reading it back; "sum" = session `authorizationAmount`):

| Question | Result |
|---|---|
| Taxes as `[{title, rate}]` | Accepted. `rate` is **percent** (8.1 = 8.1 %). wallee computes `taxes[].amount`, `taxAmount`, `amountExcludingTax` itself, rounded to 2 decimals (25.00 at 8.1 % gives 1.87 tax, 23.13 net). |
| Tax without `title` | 422 "The tax requires a title." |
| Tax `rate` as fraction (0.081) | Accepted silently and interpreted as 0.081 %. Never send fractions. |
| `taxes` omitted or `[]` | Accepted; item is untaxed (rate 0). |
| Two taxes (7 + 1) | Accepted; `aggregatedTaxRate` 8, amounts per tax (1.62 + 0.23). They are summed, not compounded. |
| `type` values | Enum is `PRODUCT`, `FEE`, `DISCOUNT`, `SHIPPING`, **`TIP`**. All of PRODUCT, FEE, DISCOUNT, SHIPPING accepted. Lowercase rejected: 422 "Value 'product' is not one of the valid values for the 'type' enum: SHIPPING, DISCOUNT, FEE, PRODUCT, TIP." |
| Negative-amount `DISCOUNT` line | **Accepted.** 50.00 product + (-5.00) discount gives sum 45.00; the discount line carries negative net, tax and amount (-4.63 / -0.37 / -5.00) with its own taxes. |
| Mixed PRODUCT + FEE 2.50 + SHIPPING 7.50 (all with 8.1 %) | Accepted, sum 35.00. A `SHIPPING` item can be sent at creation. |
| `amountIncludingTax` with 2 decimals, quantity 3 (37.05) | Accepted; `unitPriceIncludingTax` = 12.35, sum 37.05. |
| `amountIncludingTax` is the line total, not the unit price | Yes. Quantity 2 with only `unitPriceIncludingTax` (no amount): 422 "The line item requires the amount including tax." |
| Fractional quantity 1.5, amount 15.00 | Accepted; unit price 10.00. |
| 3 x 19.99 = 59.97 | Sum 59.97 exact. |
| Amount as string "25.00" | Accepted (coerced). Send numbers anyway. |
| Zero-amount line | Accepted. |
| `sku` omitted | Accepted (key absent on read-back). `name` is required, 1 to 150 chars. |
| `uniqueId` omitted | 422 "The line item requires the unique id." |
| `type` omitted | 422 "The line item requires a type." |
| Only non-shippable items | Accepted; `shipmentRequired` is false on the session. |

Does the session total equal what was sent? **Yes in every case:** `authorizationAmount` = sum of the `amountIncludingTax` of all lines (including negatives), exactly, no re-rounding of the gross amount. wallee derives the net and tax from the gross, so only the tax split can differ by a cent from WooCommerce's own rounding. Plan: send gross line totals from WooCommerce (`line_total + line_tax` per item) and an extra negative `DISCOUNT` line if needed; the sum will match the WooCommerce total.

Not tested: a total of 0 or negative, more than 100 lines, `currency` other than CHF, `discountIncludingTax` / `attributes` fields.

## 6. Session lifetime

- `timedOutOn` = `createdOn` + **30 minutes** (11:25:03 to 11:55:03 for one session). Behaviour after expiry was not observed (an expired session was not read back). The real session 335 was approved after 11 of the 30 minutes.
- `plannedPurgeDate` = creation + 90 days (2026-11-24 to 2027-02-22).
- A GET of a non-existent session gives 404 `resource_missing`.
- The session token has no `exp` field of its own; it embeds the creation time in milliseconds (first segment).

## 7. Shipping line item `uniqueId`, and the payment method configuration ID

- **Shipping line on the session** (added by wallee from the selected option): `type` `SHIPPING`, **`uniqueId` = `"shipping-" + <option id>`** (here `shipping-flat_rate:1`), `name` = option label (`Flat rate`), `sku` = option id (`flat_rate:1`), `quantity` 1, `amountIncludingTax` 8.11 (option gross), net 7.50, tax 0.61 at 8.1 %, `shippingRequired` false.
- **On the transaction this could not be confirmed**: before the 3-D Secure step the transaction has no line item version (404), and the main plugin's reader throws (item 3). The shipping order item's `_wallee_unique_line_item_id` should therefore be `shipping-<rate id>`, to be verified once a transaction completes. 
- **Payment method configuration**: not on the transaction before the charge attempt. It sits on the charge attempt: GET `/v2.0/payment/charge-attempts/<chargeAttemptId>` (id is in the `redirectUrl`) gives `connectorConfiguration.id` 783364 ("Bogus Express - Visa"), and with `expand=connectorConfiguration` its `paymentMethodConfiguration.id` is **312647**, which is the main plugin's local row id 7 "Credit / Debit Card" (`wp_wallee_method_config.configuration_id` = 312647), i.e. gateway **`wallee_7`**. The mapping is by `configuration_id`, and Bogus Pay shows up as the card method (Visa, card xxxx 1111). The transaction's own `paymentConnectorConfiguration` is expected after the charge attempt; not observed.

## Plan changes required

1. Read session line items from `lineItemEntities`; absent keys on PENDING (no addresses, token, transaction).
2. Session lifetime is 30 minutes from creation (`timedOutOn`), and the wallet flow itself can take 10+ minutes; handle an expired session (fresh session, clear message). Purge window of the add-on's own table must exceed 30 minutes.
3. Pass `onCancel` as well as `onError`; keep the script URL `https://app-wallee.com/assets/payment/express-checkout-handler.js`.
4. **The session never carries addresses or e-mail.** Customer data must be read from the transaction (`expand=billingAddress,shippingAddress`), which exists only after `process`. Design 5.4/5.5 assume the order is created at the return URL with address data from the session: that is not possible. Options: create the order at return with minimal data, call `process`, then fill addresses from the transaction before redirecting to `redirectUrl`; or read the transaction after `process` and before the redirect. Fields: `givenName`, `familyName`, `street`, `postcode`/`postCode`, `city`, `country`, `emailAddress` (no phone observed).
5. `linkedTransaction` first appears after `process`, so the order can only be linked to the transaction at that time (set `merchantReference` to the order ID in `process`).
6. **The main plugin's SDK cannot deserialise an `EXPRESS_CHECKOUT` transaction** (`get_transaction` throws). The adapter must not use `WC_Wallee_Service_Transaction::get_transaction` for these; use the raw `callApi` GET with `expand`. Whether the main plugin's webhook and status sync survive such a transaction must be tested in Task 13/14 (likely they fail the same way); this may require `userInterfaceType` handling or leaving the wallee plugin's own sync out of the loop.
7. 3-D Secure/Bogus Pay needs the browser redirect to `redirectUrl`; success and failure then return via the `successUrl` / `failureUrl` given to `process`. The transaction stays `PROCESSING` until then.
8. Shipping option ids echo the WooCommerce rate id; the shipping line has `uniqueId` `shipping-<rate id>` (to be confirmed on the transaction); the product line keeps the add-on's own `uniqueId`.
9. Payment method configuration: resolved through the charge attempt (`connectorConfiguration.paymentMethodConfiguration.id`), mapped to `wp_wallee_method_config.configuration_id` (Bogus Pay: 312647, local `wallee_7`). Not available on the transaction before the charge attempt.
10. Tax `rate` is percent (never fractions); gross line amounts sum exactly to `authorizationAmount`; discounts as negative DISCOUNT lines work. Add `TIP` to the known line item types.
