=== TeePay for WooCommerce ===
Contributors: teepay
Tags: mobile money, m-pesa, tanzania, payments, woocommerce
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept M-Pesa, Airtel Money, Mixx by Yas and HaloPesa in Tanzania with TeePay hosted checkout or a mobile money prompt.

== Description ==

TeePay for WooCommerce lets customers in Tanzania pay for orders with mobile money: M-Pesa, Airtel Money, Mixx by Yas and HaloPesa, in Tanzanian shillings (TZS).

**Two payment flows**

* **Hosted checkout (recommended).** The customer is sent to a TeePay checkout page, enters their number, approves the prompt on their phone and returns to your store. You never handle payment details.
* **Mobile money prompt.** The customer enters their Tanzanian mobile number at checkout and approves the prompt on their phone, without leaving your store. Your TeePay account must have mobile money push enabled.

**Orders are only paid when TeePay says so**

An order is marked paid only after the plugin reads the payment from the TeePay API with your secret key and TeePay reports it as paid, with an amount and currency equal to the order's. A signed webhook tells the store to check; the customer's return to your store also triggers a check, and a background job checks recent unpaid orders every 15 minutes in case a webhook is missed. Links and query strings in the customer's browser are never trusted.

**Features**

* Works with the classic checkout and the Cart and Checkout blocks.
* Compatible with High-Performance Order Storage (HPOS).
* Webhook signatures verified (HMAC-SHA256, five minute replay window); each event is processed once.
* Payments with a different amount or currency are put on hold for review, never completed.
* Secret keys and webhook secrets are stored as password fields and never shown again.
* Optional debug log in WooCommerce > Status > Logs, with keys, secrets and phone numbers removed.
* Clear notice for shop managers if TeePay restricts the account; customers only see that mobile money is unavailable.
* Translation ready.

**Refunds**

The TeePay API does not offer refunds yet, so this plugin cannot refund from WooCommerce. Contact TeePay to arrange a refund, then record it in WooCommerce as a manual refund.

**External service**

This plugin connects to the TeePay API at `https://api.teepay.me` to create checkout sessions, send mobile money prompts and read payment status, and it sends customers to TeePay's hosted checkout. It sends the order amount, currency, order number, item names and, in the prompt flow, the customer's name, email and mobile number. Data is sent only when a customer chooses TeePay at checkout, when payment status is checked, and when you save the settings (to check your key).

* TeePay: https://teepay.me
* Terms of service: https://teepay.me/legal/terms
* Privacy policy: https://teepay.me/legal/privacy

== Installation ==

1. Install and activate WooCommerce.
2. Install this plugin from Plugins > Add New, or upload the `teepay-for-woocommerce` folder to `/wp-content/plugins/`, and activate it.
3. Go to WooCommerce > Settings > Payments > TeePay.
4. Paste your live secret key (`tp_live_...`). Create it in the TeePay dashboard under Developers with the `collection:create` and `collection:read` scopes.
5. In the TeePay dashboard, open Payment profiles and set your profile's **Webhook URL** and **Return URL** to the two addresses shown on the settings screen.
6. Under Developers > Webhooks, create the webhook signing secret (`whsec_...`) and paste it into the plugin.
7. Save. The plugin checks your key with TeePay and shows the result.

Full guide: https://docs.teepay.me/docs/plugins/woocommerce

== Frequently Asked Questions ==

= Which currencies and countries are supported? =

Tanzania (TZ) in Tanzanian shillings (TZS). The method only appears at checkout when your store currency is one your TeePay key can collect in.

= Can I test without real money? =

Sandbox keys (`tp_test_`) check your connection and settings, but TeePay moves money in live mode only. Before launch, take one real payment with a small live amount and confirm the order is marked paid.

= The customer paid but the order is still pending. =

Check that the webhook URL and signing secret are set, and look at Developers > Webhooks in the TeePay dashboard for delivery errors. Pending orders are also checked every 15 minutes. Turn on the debug log for details.

= An order is on hold with a note about the amount. =

TeePay reported a payment whose amount or currency does not match the order (for example, the order was edited after the customer paid). The order is never completed automatically in this case; check the payment in the TeePay dashboard.

= Customers see "Mobile money payment is unavailable right now". =

TeePay could not start the payment. If your account is restricted, shop managers see a notice in the WordPress admin with the next steps; customers are never told why.

= Can I use one TeePay account for several stores? =

Yes. Create a payment profile for each store and enter its ID in the plugin settings, so each store gets its own webhooks and return URL. References include a store tag so one store never confirms another store's orders.

== Screenshots ==

1. TeePay settings in WooCommerce.
2. TeePay at checkout.
3. TeePay hosted checkout.
4. Order received page waiting for confirmation.

== Changelog ==

= 1.0.0 =
* First release: hosted checkout, mobile money prompt, webhooks, Checkout block support, HPOS support.

== Upgrade Notice ==

= 1.0.0 =
First release.
