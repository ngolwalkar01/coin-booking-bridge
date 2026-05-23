# Coin Booking Bridge MVP

Custom bridge plugin for:

- WooCommerce
- WooCommerce Memberships
- WooCommerce Subscriptions
- WooCommerce Bookings
- Tera Wallet / Woo Wallet

## MVP Goal

Customers buy recurring memberships. Successful subscription payments credit coins into the customer's Tera Wallet balance. Bookable products can be configured to cost coins instead of money. During checkout, the booking line item is made zero-price, the wallet balance is validated, and coins are debited once the order is created.

## MVP Features

1. Product-level coin grant field
   - Field: `Coins granted per paid subscription cycle`
   - Meta key: `_cbb_coin_grant_amount`
   - Used on simple subscription products only.
   - Variable subscriptions use the same field on each variation instead.

2. Variation-level coin grant field
   - Field: `Coins granted per paid subscription cycle`
   - Meta key: `_cbb_coin_grant_amount`
   - Used on each variation of a variable subscription product.
   - The selected variation value is authoritative. It does not fall back to the parent variable subscription value.

3. Product-level booking coin cost field
   - Field: `Booking coin cost`
   - Meta key: `_cbb_booking_coin_cost`
   - Used on WooCommerce Bookings products.

4. Product-level refund policy field
   - Field: `Coin refund policy`
   - Meta key: `_cbb_coin_refund_policy`
   - MVP options: full refund or no automatic refund.

5. Subscription coin crediting
   - Hooks:
     - `woocommerce_subscription_payment_complete`
     - `woocommerce_subscription_renewal_payment_complete`
   - Credits Tera Wallet with `woo_wallet()->wallet->credit()`.
   - Stores `_cbb_coins_credited_transaction_id` on the paid order to prevent duplicate credits.

6. Coin booking checkout
   - Booking cart item money price is set to zero when the product has a booking coin cost.
   - Cart and checkout validate wallet balance.
   - Coin cost is stored on the order item using `_cbb_coin_item_cost`.

7. Booking coin debit
   - Hook: `woocommerce_checkout_order_processed`
   - Also guarded on Store API checkout, payment complete, and processing/completed order status hooks.
   - Debits Tera Wallet with `woo_wallet()->wallet->debit()`.
   - Stores `_cbb_coins_debited_transaction_id` on the order to prevent duplicate debits.

8. Booking coin refund
   - Hooks:
     - `woocommerce_order_status_cancelled`
     - `woocommerce_order_status_refunded`
     - `woocommerce_bookings_cancelled_booking`
   - Credits coins back if the product refund policy allows it.
   - Tracks `_cbb_coins_refunded_total` on the order so later order-level refunds do not double-refund coins.
   - Individual booking cancellations refund the matching order item's coin cost when possible.

9. Global Zencoin display
   - The frontend loads shared `.zen-coin-global` coin styling from this plugin.
   - Admins can edit the global Zencoin tooltip in WooCommerce > Zencoin Settings.
   - Other plugins can render the shared badge with `cbb_render_zencoin_coin( 5 )`, `[zencoin_coin value="5"]`, or by outputting an element with `data-cbb-zencoin-value="5"`.
   - The frontend enhancer also normalizes known Zencoin badge classes used by existing Zenctuary blocks.

## Not In MVP

- Coin expiry by batch.
- Membership-plan-level fields.
- Drop-ins, gift cards, and top-up packs beyond Tera Wallet's existing top-up flow.
- Per-resource/person dynamic coin pricing.
- Admin reports.
- Booking products that require manual confirmation before payment.
- Advanced partial refund rules such as late-cancel penalties.

## Setup

1. Copy the `coin-booking-bridge` folder into `wp-content/plugins/`.
2. Activate `Coin Booking Bridge`.
3. Edit the subscription product that grants membership access.
4. For simple subscriptions, set `Coins granted per paid subscription cycle` on the product.
5. For variable subscriptions, set `Coins granted per paid subscription cycle` on each variation.
6. Edit each bookable product that should be coin-paid.
7. Set `Booking coin cost`.
8. Set `Coin refund policy`.

## Test Checklist

1. Buy a simple subscription product with a product-level coin grant.
2. Confirm the order is paid.
3. Confirm the customer's wallet is credited once.
4. Buy a variable subscription variation with a variation-level coin grant.
5. Confirm the selected variation's coins are credited once.
6. Trigger or manually process a renewal payment.
7. Confirm renewal coins are credited once.
8. Add a coin-priced booking product to cart.
9. Confirm the booking money price is zero and coin cost appears in cart.
10. Try checkout with insufficient wallet balance.
11. Confirm checkout is blocked.
12. Try checkout with sufficient wallet balance.
13. Confirm the order is created and coins are debited once.
14. Cancel or refund the booking order.
15. Confirm coins are refunded once when the policy is `Full refund`.

## Important Notes

This MVP treats Tera Wallet as the authoritative coin ledger. The bridge adds metadata to orders and order items so every credit, debit, and refund is idempotent.

Use normal WooCommerce money payments for membership subscriptions. Use coins only for booking redemptions.
