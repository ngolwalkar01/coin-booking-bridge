# Zencoin System Plan

This document is the full architecture plan for the final Zencoin system. The current plugin is an MVP bridge between WooCommerce, Subscriptions, Bookings, Memberships, and Tera Wallet. The final plugin should become the Zencoin rules engine, while WooCommerce remains the product, checkout, order, subscription, and booking platform.

## Goals

- Sell and grant Zencoins from memberships, packages, drop-ins, free trials, gift cards, and auto top-ups.
- Spend Zencoins on classes, Fire & Ice, workshops, events, and services.
- Support expiring credit buckets, monthly membership resets, append-only ledger history, refund-to-original-bucket rules, and member-only missing-ZC top-up pricing.
- Keep wallet display in ZC only.
- Preserve compatibility with Tera Wallet as the visible/mirrored balance layer during migration.

## Fixed Client Rules

- Default value: `1 ZC = EUR 5`.
- Standard session cost: `5 ZC = EUR 25`.
- Standard sessions apply to classes and Fire & Ice.
- Workshop/event tiers:
  - Tier A: `6 ZC = EUR 30`
  - Tier B: `8 ZC = EUR 40`
  - Tier C: `10 ZC = EUR 50`
- Membership credits reset monthly and do not roll over.
- Purchased credits expire by source type.
- Consumption order:
  - Membership credits first.
  - Purchased/top-up credits next, earliest expiry first.
- On-time cancellation is `>= 12h` before start and refunds to the original bucket with original expiry.
- Late cancellation is `< 12h` before start and does not refund.
- Studio cancellation refunds all booked users to original buckets with original expiry.
- Wallet activity is append-only.
- Members may buy normal packages/drop-ins at standard price.
- Active members may buy only missing ZC at member price during booking top-up flow.
- On-hold memberships freeze wallet usage and block booking.

## System Boundaries

### WooCommerce

WooCommerce remains responsible for:

- Product catalog.
- Cart and checkout.
- Orders and refunds.
- Payment gateways.
- Taxes/invoices through existing invoice tooling.

### WooCommerce Subscriptions

Subscriptions remain responsible for:

- Billing cycle.
- Renewal payments.
- Subscription statuses.
- Payment retry/on-hold state.

### WooCommerce Memberships

Memberships remain responsible for:

- Membership access and status.
- Plan association when useful.

### WooCommerce Bookings

Bookings remain responsible for:

- Bookable products.
- Booking objects and calendar slots.
- Availability/capacity where possible.

### Tera Wallet / Woo Wallet

Tera Wallet remains responsible for:

- Existing wallet UI compatibility.
- Mirrored numeric ZC balance during MVP/intermediate phases.

CBB should not rely on Tera Wallet as the final source of truth because Tera Wallet does not natively model expiring buckets, source-specific consumption, or refund-to-original-bucket rules.

### Coin Booking Bridge

CBB becomes responsible for:

- ZC settings and pricing rules.
- Product ZC configuration.
- Credit buckets.
- Ledger entries.
- Booking debit/refund logic.
- Membership monthly allowance buckets.
- Package/drop-in/free-trial grants.
- Gift card redemption into ZC.
- Top-up and insufficient-balance rules.
- Cart/checkout mode classification for Zencoin-aware flows.
- Checkout context data for external UI consumers.
- Admin tools and reporting.

### Zen Checkout Flow

`zen-checkout-flow` remains responsible for:

- Popup checkout rendering and interaction states.
- Conditional presentation of money-payment versus Zencoin-booking flows.
- Guided recovery UX when users are short on Zencoins.
- Theme/auth handoff for logged-out checkout access.

`zen-checkout-flow` should not become the source of truth for wallet, booking-pricing, or Zencoin entitlement rules. It should consume a small CBB context API when CBB is present, and gracefully fall back to generic WooCommerce popup checkout behavior when CBB is absent.

## Core Data Model

### Settings

Store settings in one option, for example `cbb_zencoin_settings`.

Required settings:

- `coin_value_eur`: default `5`.
- `standard_session_cost`: default `5`.
- `workshop_tier_a_cost`: default `6`.
- `workshop_tier_b_cost`: default `8`.
- `workshop_tier_c_cost`: default `10`.
- `auto_calculate_booking_cost`: default `yes`.
- `free_dropin_validity_days`: default `30`.
- `dropin_validity_days`: default `90`.
- `package_small_validity_days`: default `90`.
- `package_medium_validity_days`: default `90`.
- `package_large_validity_days`: default `180`.
- `gift_card_validity_days`: default `1095`.
- `newsletter_discount_validity_days`: default `30`.
- `on_time_cancel_cutoff_hours`: default `12`.
- `wallet_freeze_on_subscription_on_hold`: default `yes`.
- `tera_wallet_mirror_enabled`: default `yes`.

### Product Meta

Existing meta:

- `_cbb_coin_grant_amount`
- `_cbb_booking_coin_cost`
- `_cbb_coin_refund_policy`

New normalized product meta:

- `_cbb_zencoin_product_type`
  - `none`
  - `membership`
  - `package`
  - `drop_in`
  - `free_drop_in`
  - `gift_card`
  - `auto_top_up`
- `_cbb_zencoin_grant_amount`
- `_cbb_zencoin_validity_days`
- `_cbb_zencoin_source_label`
- `_cbb_zencoin_one_time_per_person`
- `_cbb_zencoin_package_size`
  - `small`
  - `medium`
  - `large`
- `_cbb_booking_pricing_mode`
  - `manual`
  - `auto_from_price`
  - `standard_session`
  - `workshop_tier_a`
  - `workshop_tier_b`
  - `workshop_tier_c`
- `_cbb_booking_allowed_credit_sources`
  - reserved for future source restrictions if needed.

Variable subscription variations continue to store membership grant amount on the variation. The selected variation value remains authoritative.

### Credit Buckets Table

Table: `{$wpdb->prefix}cbb_zencoin_buckets`

Suggested columns:

- `id` bigint unsigned primary key.
- `user_id` bigint unsigned not null.
- `source_type` varchar(40) not null.
- `status` varchar(20) not null default `active`.
- `original_amount` decimal(20,6) not null default `0`.
- `remaining_amount` decimal(20,6) not null default `0`.
- `expires_at` datetime null.
- `created_at` datetime not null.
- `updated_at` datetime not null.
- `related_order_id` bigint unsigned null.
- `related_order_item_id` bigint unsigned null.
- `related_product_id` bigint unsigned null.
- `related_subscription_id` bigint unsigned null.
- `related_booking_id` bigint unsigned null.
- `related_coupon_id` bigint unsigned null.
- `source_label` varchar(120) null.
- `metadata` longtext null.

Important statuses:

- `active`
- `consumed`
- `expired`
- `frozen`
- `void`

### Ledger Table

Table: `{$wpdb->prefix}cbb_zencoin_ledger`

Suggested columns:

- `id` bigint unsigned primary key.
- `user_id` bigint unsigned not null.
- `bucket_id` bigint unsigned null.
- `entry_type` varchar(40) not null.
- `direction` varchar(10) not null.
- `amount` decimal(20,6) not null.
- `balance_after` decimal(20,6) null.
- `label` varchar(160) not null.
- `created_at` datetime not null.
- `related_order_id` bigint unsigned null.
- `related_order_item_id` bigint unsigned null.
- `related_product_id` bigint unsigned null.
- `related_subscription_id` bigint unsigned null.
- `related_booking_id` bigint unsigned null.
- `related_coupon_id` bigint unsigned null.
- `metadata` longtext null.

Ledger is append-only. Never edit ledger rows after creation except for emergency migration repair tooling.

Entry types:

- `membership_grant`
- `package_purchase`
- `drop_in_purchase`
- `free_drop_in_trial`
- `gift_card_redeem`
- `auto_top_up`
- `booking_charge`
- `refund_on_time_cancel`
- `refund_studio_cancel`
- `late_cancel`
- `expired`
- `manual_adjustment`

### Consumption Meta

When a booking/order consumes credits, store the exact bucket usage.

Order or booking meta:

- `_cbb_coin_consumption`
- `_cbb_coin_consumption_total`
- `_cbb_coin_consumed_at`

Shape:

```json
[
  {
    "bucket_id": 12,
    "amount": "3.000000",
    "source_type": "membership",
    "expires_at": "2026-06-08 23:59:59"
  },
  {
    "bucket_id": 18,
    "amount": "2.000000",
    "source_type": "package",
    "expires_at": "2026-08-08 23:59:59"
  }
]
```

This is required for refund-to-original-bucket.

## Product Types

### Membership Products

Use subscription products and variable subscription products.

Behavior:

- On initial successful payment, create a membership bucket.
- On renewal payment, expire/reset the previous membership bucket and create the next one.
- Bucket expiry should be the next renewal date when available.
- No rollover.
- If subscription is on-hold, freeze wallet usage if setting enabled.
- If subscription is cancelled/expired, no future membership buckets are created.

### Packages

Use simple products.

Client packages:

- Small: `10 ZC`, `EUR 48`, valid `3 months`.
- Medium: `25 ZC`, `EUR 115`, valid `3 months`.
- Large: `50 ZC`, `EUR 225`, valid `6 months`.

Behavior:

- Paid order creates purchased credit bucket.
- Purchased buckets are consumed by earliest expiry after membership credits.

### Drop-Ins

Use simple products.

Client drop-ins:

- Drop-in: `5 ZC`, `EUR 25`, valid `3 months`.
- Free Drop-in Trial: `5 ZC`, `EUR 0`, valid `1 month`, single use only.

Free trial identity:

- Require a logged-in customer with normalized billing email and phone.
- Require WooPayments card entry even though the order total is zero.
- Use a WooPayments SetupIntent to verify and save the card without charging it.
- Reject a repeat claim when the email, phone, or hashed card fingerprint matches a successful prior trial order.
- Store only identity hashes on the order; raw card details and raw card fingerprints are not stored by CBB.

### Gift Cards

Recommended final approach:

- Use Woo Smart Coupons to sell gift card codes.
- Do not use gift cards as checkout discount for ZC bookings.
- Redeem gift card in the Zencoin wallet area.
- CBB validates code, converts euro value to ZC, creates gift-card bucket, and marks/records redemption.

Current decision:

- Woo Smart Coupons is not installed yet.
- Gift-card work is not needed for Milestone 0/1 unless we decide to pull gift cards into the first release.
- Before Milestone 7 starts, install Smart Coupons or choose a custom CBB voucher-code system.

Conversion:

- `EUR 25 -> 5 ZC`
- `EUR 50 -> 10 ZC`
- `EUR 100 -> 20 ZC`
- `EUR 150 -> 30 ZC`

Expiry:

- Gift card code expires after `36 months`.
- Redeemed ZC expires on the same date as the gift card code.
- Redemption does not reset expiry.

### Auto Top-Up

Auto top-up is not a normal shop product.

It is created dynamically during booking flows when user is short on ZC.

Behavior:

- Standard non-member top-up price uses global `coin_value_eur`.
- Active member booking top-up price uses that member plan's effective price per ZC.
- Member top-up price is available only in booking flow.
- Top-up credits are consumed immediately after membership credits when completing the booking.
- If booking fails after payment, the purchased top-up ZC remain in wallet.

## Booking Pricing

Bookable product pricing modes:

- Manual ZC cost.
- Auto from money price using `coin_value_eur`.
- Standard session.
- Workshop tier A.
- Workshop tier B.
- Workshop tier C.

Formula:

```text
ZC cost = product money price / coin_value_eur
```

Example:

```text
EUR 25 / EUR 5 = 5 ZC
```

Manual override must remain available.

## Booking Flow Rules

### Preconditions

- Logged out: send user to auth/login flow.
- Logged in:
  - Check booking state: open, full, cancelled, ended.
  - Prevent duplicate booking for same session.
  - Check wallet freeze state.
  - Check available ZC balance from active buckets.

### Enough ZC

- One-click booking.
- Deduct credits immediately.
- Confirm booking.
- Send notification.

### Not Enough ZC: Standard Sessions

Standard sessions are classes and Fire & Ice.

- If missing `1-2 ZC`: show missing-ZC popup.
- If missing `>= 3 ZC` or user has `0 ZC`: show choose-your-plan screen.

After successful payment:

- Create purchased/top-up bucket.
- Re-check booking availability.
- Complete booking.
- Deduct full booking cost.
- If booking fails, leave purchased ZC in wallet and show recovery message.

### Not Enough ZC: Workshops / Events

Client notes have two variants. Final rule should be confirmed.

Preferred current interpretation:

- For workshops/events, if user is missing any amount, show workshop checkout screen for missing amount only.
- After payment, auto-book and deduct full workshop/event ZC cost.

Examples:

- Has `0 ZC`, workshop costs `6 ZC`: buy `6 ZC`, then book.
- Has `5 ZC`, workshop costs `6 ZC`: buy `1 ZC`, then book.

### Waitlist

- Full session can allow waitlist.
- No auto-booking from waitlist in v1.
- User receives email and must book manually.

## Refund Rules

### On-Time User Cancellation

- On-time means `>= 12h` before start.
- Restore credits to original buckets.
- Preserve original expiry.
- Add ledger entries with label `Refund (On-time cancellation)`.

### Late User Cancellation

- Late means `< 12h`.
- No credit restore.
- Add ledger entry with label `Late Cancel`.

### Studio Cancellation

- Refund all booked users.
- Restore to original buckets.
- Preserve original expiry.
- No invoices should be created for these ZC refunds.
- Add ledger entries with label `Refund (Class cancelled)`.

## Wallet UI

Final wallet UI should show:

- Available ZC only, no euro balance.
- Bucket summary with expiry dates.
- Activity list from CBB ledger.
- Gift card redemption form.
- Optional invoices link/list from account area.

Tera Wallet UI may need to be hidden or replaced if it cannot match the final design and bucket activity requirements.

Current decision:

- Hide Tera Wallet UI in favor of a custom CBB wallet screen.
- Tera Wallet may remain installed as a balance mirror/compatibility layer, but customer-facing wallet screens should be rendered by CBB.
- CBB wallet UI must be design-driven and override/replace the generic wallet experience.

## Admin UI

Required admin screens:

- Zencoin settings.
- Product ZC fields.
- User bucket inspector.
- Ledger viewer.
- Manual adjustment tool.
- Expiry/cron status.
- Gift card redemption diagnostics.
- Trial duplicate detection report.

Future admin experience layer:

- Create a clean, application-aware admin UI for bookings, bookable products, Zencoin products, wallet activity, and refund actions.
- Hide or de-emphasize WooCommerce/Woo Bookings fields that are not useful for the Zenctuary flow.
- Make Zencoin booking charges/refunds visible as first-class operational actions instead of relying on WooCommerce money refund UI.
- This may live in CBB or in a separate companion admin plugin if separation stays cleaner.

## Public/Internal APIs

Core service functions/classes should eventually expose:

- `get_available_balance( $user_id, $args = array() )`
- `get_active_buckets( $user_id, $args = array() )`
- `create_bucket( $user_id, $amount, $args )`
- `credit_user( $user_id, $amount, $args )`
- `debit_user( $user_id, $amount, $args )`
- `refund_consumption( $user_id, $consumption, $args )`
- `expire_buckets()`
- `sync_tera_wallet_balance( $user_id )`
- `get_booking_coin_cost( $product_or_booking )`
- `can_user_book_with_zc( $user_id, $booking_context )`
- `get_checkout_context( $user_id = 0, $args = array() )`
- `classify_cart_mode( $user_id = 0, $args = array() )`

Design goal: the booking flow, checkout flow, wallet UI, and admin tools should all call the same services.

### Checkout Context Contract

For reusable integrations such as `zen-checkout-flow`, CBB should expose a lightweight checkout context structure.

Suggested fields:

- `mode`
  - `money_purchase`
  - `zencoin_booking`
  - `mixed_recovery`
  - `insufficient_prompt`
- `has_booking_items`
- `has_credit_products`
- `required_zencoins`
- `available_zencoins`
- `missing_zencoins`
- `booking_items`
- `credit_products`
- `allowed_recovery_product_types`
- `wallet_is_frozen`
- `blocking_reason`

Design goal:

- CBB owns the business logic and cart classification.
- `zen-checkout-flow` owns the popup rendering and UI states.
- Both plugins remain independently installable.

## Hook Map

### Product/Admin

- `woocommerce_product_options_general_product_data`
- `woocommerce_process_product_meta`
- `woocommerce_product_after_variable_attributes`
- `woocommerce_save_product_variation`

### Orders

- `woocommerce_payment_complete`
- `woocommerce_order_status_processing`
- `woocommerce_order_status_completed`
- `woocommerce_order_status_cancelled`
- `woocommerce_order_status_refunded`

### Subscriptions

- `woocommerce_subscription_payment_complete`
- `woocommerce_subscription_renewal_payment_complete`
- Subscription status transition hooks for active/on-hold/cancelled/expired.

### Bookings

- Booking created/confirmed/cancelled hooks.
- Booking status transition hooks.
- Existing `woocommerce_bookings_cancelled_booking`.

### Wallet

- Tera Wallet credit/debit APIs for mirror balance only.

### Gift Cards

- Smart Coupons hooks to be confirmed once plugin is installed and inspected.

## Development Milestones

### Milestone 0: Blueprint and Data Model

Goal: prepare the final architecture before deeper implementation.

Tasks:

- Finalize settings.
- Finalize product meta.
- Add DB schema for buckets and ledger.
- Add activation/migration scaffold.
- Add service class structure.
- Define compatibility layer for current meta and Tera Wallet.

### Milestone 1: MVP Credit Grants on Final Architecture

Goal: current working behavior plus packages/drop-ins using buckets and ledger.

Tasks:

- Add central settings page.
- Add product grant type fields.
- Normalize subscription and variation grants.
- Create buckets and ledger entries on subscription payment/renewal.
- Create buckets and ledger entries on package/drop-in/free-trial paid orders.
- Mirror credits to Tera Wallet.
- Enforce free trial one-time rule at checkout.

### Milestone 2: Bucket-Aware Booking Payment

Goal: replace simple wallet debit with correct bucket debit.

Tasks:

- Calculate booking ZC cost from product pricing mode.
- Validate active bucket balance.
- Debit membership first, then purchased credits by earliest expiry.
- Store consumption breakdown on booking/order.
- Mirror debit to Tera Wallet.
- Keep current cart zero-price behavior.

### Milestone 3: Expiry and Membership Reset

Goal: enforce time-based rules.

Tasks:

- Add cron expiry.
- Expire old purchased buckets.
- Reset membership buckets at renewal/no rollover.
- Freeze wallet usage when subscription is on-hold.
- Unfreeze when active again.

### Milestone 4: Cancellation and Refund Rules

Goal: correct refund behavior.

Tasks:

- On-time cancellation restores original bucket amounts.
- Late cancellation records ledger only.
- Studio cancellation refunds all users.
- Preserve original expiry on restored credits.
- Prevent duplicate refunds.

### Milestone 5: Booking UX Rules

Goal: enforce booking state and user booking rules.

Tasks:

- Logged-out auth integration.
- Open/full/cancelled/ended checks.
- Duplicate booking prevention.
- One-click booking when enough ZC.
- Waitlist join when full.
- Booking failure recovery message.
- Expose reusable booking/checkout context for external UI consumers.

### Milestone 6: Insufficient ZC Purchase Flows

Goal: implement client purchase flows during booking.

Tasks:

- Add cart mode detection for:
  - `money_purchase`
  - `zencoin_booking`
  - `mixed_recovery`
  - `insufficient_prompt`
- Classify bookings with insufficient balance as either:
  - prompt-only state before a recovery product is added
  - mixed recovery checkout once a recovery product is in cart
- Missing 1-2 ZC popup for standard sessions.
- Choose-your-plan for standard sessions missing 3+ ZC or 0 ZC.
- Workshop/event missing amount checkout.
- Member-only booking top-up price.
- Auto-complete booking after top-up payment.
- Leave purchased ZC in wallet if booking fails after payment.

### Milestone 6.5: Checkout Integration Contract

Goal: support external checkout UIs without coupling CBB to any one site.

Tasks:

- Publish a stable `get_checkout_context()` API/adapter.
- Mark booking items, credit products, and recovery-eligible items in a reusable way.
- Let external checkout UIs detect when gateways should be hidden.
- Let external checkout UIs detect when recovery purchase UI should be shown.
- Keep fallback behavior sane when `zen-checkout-flow` is inactive.
- Document mixed-cart orchestration: grant purchased ZC first, then complete booking debit.

### Milestone 7: Gift Cards

Goal: redeem gift card value into ZC.

Tasks:

- Inspect Smart Coupons plugin hooks.
- Add wallet redemption form.
- Convert gift card euro value into ZC.
- Create gift-card bucket with original gift card expiry.
- Prevent duplicate redemption.
- Add ledger entries.

### Milestone 8: Wallet and Account UI

Goal: replace generic wallet view with ZC-specific view.

Tasks:

- ZC-only wallet display.
- Bucket expiry summary.
- Ledger activity list.
- Gift card redemption.
- Invoice links/list integration.

### Milestone 9: Admin and Reporting

Goal: make the system maintainable.

Tasks:

- User bucket inspector.
- Ledger search/export.
- Manual adjustment.
- Trial duplicate report.
- Failed booking/payment recovery tools.
- Expiry cron diagnostics.

### Milestone 10: Hardening

Goal: production readiness.

Tasks:

- Idempotency audit for all order/subscription/booking hooks.
- Race-condition handling for capacity and double booking.
- Data migration/backfill.
- Performance review.
- Automated tests where possible.
- Admin documentation.

## Immediate Next Step

Start Milestone 0.

Implementation order:

1. Add plugin constants for DB version and table names.
2. Add activation hook.
3. Create bucket and ledger tables.
4. Add settings skeleton.
5. Add service methods with no behavior changes yet.
6. Add admin visibility for existing current behavior.

Only after Milestone 0 is stable should we move current subscription/package/drop-in granting into the new bucket system.

## Implementation Progress

### Milestone 0.1: Schema Foundation

Status: implemented in plugin version `0.2.0`.

Included:

- Plugin DB version constant.
- Activation hook.
- Buckets table creation.
- Ledger table creation.
- Installed DB version option.
- Lazy admin-side schema upgrade if plugin files are newer than installed DB version.

No runtime credit/debit behavior has been changed yet.

### Milestone 0.2: Central Settings Skeleton

Status: implemented in plugin version `0.2.0`.

Included:

- WooCommerce admin submenu: Zencoin Settings.
- Central settings option: `cbb_zencoin_settings`.
- Default ZC value and booking costs from client rules.
- Validity settings for free drop-in, drop-in, packages, gift cards, and newsletter discounts.
- Rule settings for on-time cancellation cutoff, on-hold wallet freeze, and Tera Wallet mirroring.

These settings are saved but not yet applied to current booking/product behavior.

### Milestone 0.3: Admin System Status Panel

Status: implemented in plugin version `0.2.0`.

Included:

- Read-only system status panel on WooCommerce > Zencoin Settings.
- Plugin version display.
- Installed DB version display.
- Bucket table readiness check.
- Ledger table readiness check.
- Runtime note confirming current MVP credit/debit behavior is still active.

### Milestone 0.4: Product Zencoin Configuration Skeleton

Status: implemented in plugin version `0.2.0`.

Included:

- Product-level Zencoin product type field.
- Product-level grant amount field for future package/drop-in/free-trial/gift-card/top-up grants.
- Optional validity-days override.
- Optional wallet source label.
- Optional package size marker.
- One-time-per-person flag for free trial products.

These fields save product meta only. They do not grant Zencoin buckets yet.

### Milestone 0.5: Bucket and Ledger Service Helpers

Status: implemented in plugin version `0.2.0`.

Included:

- Helper to create Zencoin bucket records.
- Helper to add append-only ledger entries.
- Helper to calculate active bucket balance for a user.
- Utility sanitizers for nullable IDs, datetime values, metadata, ledger direction, and ZC amount formatting.
- System Status panel row counts for buckets and ledger entries.

These helpers are prepared for upcoming grant/debit/refund flows. Current MVP transactions do not call them yet.

### Milestone 1.1: Package and Drop-In Paid Order Grants

Status: implemented in plugin version `0.2.0`.

Included:

- Paid WooCommerce orders can create Zencoin buckets for configured `Package` products.
- Paid WooCommerce orders can create Zencoin buckets for configured `Drop-in` products.
- Grant creates an append-only ledger credit entry.
- Grant can mirror the credit to Tera Wallet when the central setting is enabled.
- Order stores `_cbb_zencoin_product_grants` to prevent duplicate grants.
- Existing subscription and booking MVP behavior remains active.

Not included yet:

- Free drop-in one-time eligibility enforcement.
- Gift card redemption.
- Auto top-up.
- Bucket-aware booking debit.

### Milestone 1.2: Free Drop-In Trial Grants and Eligibility

Status: implemented and extended through plugin version `0.2.22`.

Included:

- Free drop-in trial products can create Zencoin buckets on paid/completed orders.
- Free drop-in trial products create append-only ledger credit entries.
- Free drop-in trial products can mirror credits to Tera Wallet when enabled.
- Checkout requires a logged-in customer with both billing email and billing phone.
- A zero-total trial order is forced through WooPayments card verification via SetupIntent.
- New cards are saved to the WooPayments customer account.
- Repeat claims are blocked by normalized email hash, normalized phone hash, or hashed card fingerprint.
- Used identity hashes are stored on the successful order; raw card fingerprints are never stored by CBB.
- Zencoins are not granted unless card validation has passed.

Not included yet:

- Gift card redemption.
- Bucket-aware booking debit.

### Milestone 1.3: Membership Grant Buckets and Ledger Entries

Status: implemented in plugin version `0.2.0`.

Included:

- Existing subscription payment grants now also create membership Zencoin buckets.
- Existing renewal payment grants now also create membership Zencoin buckets.
- Membership grant buckets expire at the next payment date when available.
- Membership grant buckets fall back to one month expiry if no next payment date is available.
- Membership grants create append-only ledger credit entries.
- Existing Tera Wallet membership credit behavior remains active and idempotent.
- Order stores `_cbb_zencoin_membership_grant` for bucket/ledger diagnostics.

Not included yet:

- No-rollover reset/expiry enforcement.
- Subscription on-hold wallet freeze.
- Bucket-aware booking debit.

### Milestone 2.1: Bucket-Aware Booking Debit

Status: implemented in plugin version `0.2.0`.

Included:

- Booking checkout validation can see both bucket balance and legacy Tera Wallet balance during migration.
- Booking orders with enough active bucket balance debit buckets first.
- Consumption order is membership buckets first, then purchased credits by earliest expiry.
- Bucket debits create append-only ledger debit entries.
- Order stores `_cbb_coin_consumption` with bucket-level debit details.
- Tera Wallet debit still runs as the mirror/legacy visible balance update.
- Existing order-level `_cbb_coins_debited_transaction_id` guard remains the idempotency guard.

Transition behavior:

- If a user has no usable bucket balance but has legacy Tera Wallet balance, the old wallet-only debit path can still process.
- Refund-to-original-bucket is not active yet; refunds still use the existing MVP refund behavior.

Not included yet:

- Bucket-aware refunds.
- Late-cancel/no-refund rules.
- Studio-cancel refund-to-original-bucket.

### Milestone 3.1: Subscription On-Hold Wallet Freeze

Status: implemented in plugin version `0.2.0`.

Included:

- Booking checkout validation blocks Zencoin booking when the customer has an on-hold Zencoin-granting subscription.
- Final booking debit also blocks and moves the order to on-hold if a checkout flow reaches order processing while the wallet is frozen.
- The freeze obeys the central setting `wallet_freeze_on_subscription_on_hold`.

Not included yet:

- Cron expiry for purchased buckets.
- No-rollover membership reset enforcement.
- Bucket-aware refunds.

### Milestone 3.2: Bucket Expiry Maintenance

Status: implemented in plugin version `0.2.0`.

Included:

- Daily WP-Cron event for bucket expiry maintenance.
- Manual admin action in **WooCommerce > Zencoin Settings** to run expiry immediately.
- Active buckets with passed `expires_at` are marked `expired`.
- Unused remaining Zencoins in expired buckets are debited to zero.
- Expiry creates append-only ledger entries with entry type `expired`.
- System Status shows how many active expired buckets are ready for cleanup.

Not included yet:

- No-rollover membership reset enforcement.
- Bucket-aware refunds.

### Milestone 3.3: Membership No-Rollover Reset

Status: implemented in plugin version `0.2.0`.

Included:

- Before a new membership cycle bucket is granted, active remaining membership buckets for the same subscription are reset.
- Reset buckets are marked `expired` and remaining ZC is set to zero.
- Reset creates append-only ledger debit entries with entry type `membership_reset`.
- Reset debits are mirrored to Tera Wallet when mirroring is enabled.
- Renewal grants are protected from the Woo Subscriptions double-hook path where both generic payment-complete and renewal-payment-complete hooks can fire.
- Renewal order notes show how many previous membership buckets were reset.

Not included yet:

- One-active-membership enforcement.
- Bucket-aware refunds.

### Milestone 4.1: Bucket-Aware Order Refunds

Status: implemented in plugin version `0.2.0`.

Included:

- Cancelled/refunded booking orders with `_cbb_coin_consumption` restore ZC to the original consumed buckets.
- Restored buckets preserve their original expiry; if the expiry has already passed, the bucket remains expired.
- Refunds create append-only ledger credit entries with entry type `refund_on_time_cancel`.
- Refunds mirror the credit back to Tera Wallet when mirroring is enabled.
- Existing legacy wallet-only refund behavior remains as a fallback for older orders without bucket consumption data.

Not included yet:

- Booking-level on-time versus late cancellation cutoff.
- Studio-cancel refund reason.
- Partial booking-item bucket refunds.

### Milestone 4.2: Late Cancellation No-Refund Guard

Status: implemented in plugin version `0.2.0`.

Included:

- Booking cancellations now check the central `on_time_cancel_cutoff_hours` setting.
- Late booking cancellations do not restore Zencoins.
- Late cancellations create append-only ledger entries with entry type `late_cancel`.
- Late cancellation amounts are stored on the order so later order-status refund hooks do not accidentally refund those Zencoins.
- On-time booking cancellations can use the bucket-aware refund path from Milestone 4.1.

Not included yet:

- Custom customer-facing cancellation messages.
- Admin booking/order Zencoin refund panel.
- Studio-cancel bulk refund handling.
- Partial multi-booking order refund UI.

### Planned Next Track: Checkout Context and Conditional Flow Support

Not started yet.

Purpose:

- Prepare CBB as the source of truth for conditional checkout modes used by `zen-checkout-flow` and any future checkout UI.

Planned sub-milestones:

#### Milestone 5.1: Checkout Context Detection API

Status: implemented in plugin version `0.2.1`.

Included:

- Add reusable cart classification helpers in CBB.
- Detect whether the cart contains booking items, credit-purchase items, or both.
- Calculate required, available, and missing Zencoins for the current customer/cart.
- Return a normalized checkout context object/array for UI consumers.

Included details:

- Public CBB checkout context API:
  - `CBB_Coin_Booking_Bridge::get_checkout_context()`
  - `CBB_Coin_Booking_Bridge::classify_cart_mode()`
  - `cbb_get_checkout_context()`
  - `cbb_classify_cart_mode()`
- Normalized modes:
  - `money_purchase`
  - `zencoin_booking`
  - `mixed_recovery`
  - `insufficient_prompt`
- Context fields for booking items, credit products, required/available/missing ZC, wallet frozen state, and blocking reason.
- Read-only implementation only; no live checkout rendering or booking/payment behavior changed yet.

Not included yet:

- Recovery product eligibility restrictions beyond basic product-type grouping.
- UI consumption inside `zen-checkout-flow`.
- Mixed-cart orchestration that grants ZC before final booking debit.

#### Milestone 5.2: Recovery Product Eligibility Rules

Status: implemented in plugin version `0.2.2`.

Included:

- Define which product types can satisfy a booking shortage:
  - membership
  - package
  - drop-in
  - later gift-card/top-up flows when ready
- Separate prompt-only insufficient state from actual mixed recovery checkout state.
- Keep non-CBB environments on a safe generic checkout fallback.

Included details:

- Checkout context now distinguishes:
  - `has_credit_products`
  - `has_recovery_products`
  - `recovery_credit_products`
  - `non_recovery_credit_products`
- Current recovery-eligible product types:
  - `membership`
  - `package`
  - `drop_in`
  - `free_drop_in` when the customer remains eligible
- Current non-recovery product types in this phase:
  - `gift_card`
  - `auto_top_up`
  - any other credit product types outside the allowed recovery set
- `mixed_recovery` mode now requires recovery-eligible credit products, not merely any credit product in cart.
- `insufficient_prompt` remains the mode when a booking is short on ZC and no recovery-eligible credit product is present.

Not included yet:

- Dynamic recovery eligibility by booking subtype or member plan.
- Missing-ZC pricing logic.
- Final UI branching inside `zen-checkout-flow`.

#### Milestone 6.1: Mixed Recovery Booking Orchestration

Status: completed.

Implemented first slice in plugin version `0.2.5`.
Implemented second slice in plugin version `0.2.9`.
Implemented third slice in plugin version `0.2.20`.

Included so far:

- Mixed-recovery orders now store checkout intent metadata at order-creation time.
- Order stores `_cbb_checkout_mode` for current checkout classification.
- Mixed-recovery orders store `_cbb_mixed_recovery_intent` snapshot including:
  - required ZC
  - available ZC
  - missing ZC
  - booking items
  - recovery credit products
  - wallet frozen state
  - captured timestamp
- Mixed-recovery orders add an order note recording the captured shortage snapshot.
- Early legacy booking debit attempts are now deferred for mixed-recovery orders until enough credited ZC exists.
- After a real credit grant occurs, mixed-recovery now attempts one controlled booking finalization pass from the grant source:
  - membership subscription grant
  - one-time package/drop-in grant
- If payment succeeds but the credited balance is still insufficient, purchased ZC remain in the wallet and the order gets a single admin note explaining that booking was not finalized automatically.
- Failed mixed-recovery payments are marked as `payment_failed` and do not debit booking ZC.
- After payment and before debit, mixed-recovery orders re-check the attached booking availability.
- If the selected booking is no longer available, the order is marked `booking_full`, booking fulfillment is paused, purchased ZC remain in the wallet, and no booking debit is made.
- If availability passes but ZC debit/finalization fails, the order is marked `booking_failed`, booking fulfillment is paused, purchased ZC remain in the wallet, and the customer can retry booking.
- Mixed-recovery status context is stored in `_cbb_mixed_recovery_context` so checkout/account UIs can choose the correct popup state:
  - `completed` -> purchase-and-booking success popup
  - `payment_failed` -> payment retry popup
  - `booking_full` -> class-full schedule popup
  - `booking_failed` -> technical booking-failed popup
- `zen-checkout-flow` version `0.1.35` can read these result states from WooCommerce result URLs, render the matching popup, and complete enough-ZC `zencoin_booking` carts without payment gateways.
- Site-specific popup mapping is implemented in `zen-checkout-flow`:
  - `completed` shows the purchase-and-booking confirmation layout.
  - `payment_failed` shows retry/cancel guidance.
  - `booking_full` explains that payment was not completed and routes the customer back to schedule.
  - `booking_failed` explains the technical booking failure and routes the customer to schedule/profile.

#### Milestone 6.2: Checkout UI Consumer Documentation

Status: documented.

Purpose:

- Define the contract between CBB and checkout UIs such as `zen-checkout-flow`.
- Keep the contract generic so other checkout UIs can reuse it outside this site.

Public context API:

- Preferred PHP function: `cbb_get_checkout_context()`.
- Class method: `CBB_Coin_Booking_Bridge::get_checkout_context()`.
- The API is read-only and should be safe for rendering decisions. It must not create orders, grant ZC, debit ZC, or mutate bookings.

Context modes:

- `money_purchase`
  - Cart does not need a Zencoin-only booking flow.
  - UI should show the normal money checkout/payment gateway path.
- `zencoin_booking`
  - Cart booking cost is fully covered by the customer's available ZC.
  - UI should hide payment gateways and show a wallet-only booking action.
  - On completion, the UI should create the Woo order and let CBB debit ZC from checkout/order hooks.
- `mixed_recovery`
  - Cart contains booking items plus recovery-eligible credit products that should cover the missing ZC.
  - UI should show the normal payment path for the recovery products.
  - After payment/grant, CBB finalizes the booking debit in one controlled pass.
- `insufficient_prompt`
  - Cart contains a booking shortage but no recovery-eligible credit product.
  - UI should block checkout completion and prompt the customer to add a valid package/membership/drop-in recovery product.
  - `zen-checkout-flow` version `0.1.42` hides gateways in this state and follows the Figma entry flows:
    - 0 ZC customers see an in-popup plan chooser.
    - customers missing a smaller amount see a compact buy-missing-ZC prompt.
    - choosing a recovery product adds it to cart, reloads the page with the popup open, and lands on mixed-recovery payment so the Woo Blocks Store API runtime has fresh cart state.
    - recovery suggestions only include CBB recovery-eligible products: packages, drop-ins, eligible free drop-in trials, and memberships.
    - the popup back arrow removes the selected recovery product and returns to the previous recovery step.
  - `coin-booking-bridge` version `0.2.21` lets mixed-recovery carts proceed to payment when projected ZC after the recovery product covers the booking cost.

Core context fields checkout UIs may consume:

- `mode`: normalized checkout mode.
- `has_booking_items`: whether the cart contains bookable items with CBB ZC cost.
- `has_credit_products`: whether the cart contains any CBB credit product.
- `has_recovery_products`: whether the cart contains credit products that can recover a booking shortage.
- `booking_items`: normalized booking-line data for display and validation.
- `credit_products`: normalized credit-purchase line data.
- `recovery_credit_products`: credit products eligible to resolve a booking shortage.
- `required_zc`: total ZC required by booking items.
- `available_zc`: customer wallet balance currently available for booking.
- `missing_zc`: shortage after applying available balance.
- `wallet_frozen`: whether wallet state blocks checkout.
- `blocking_reason`: machine-readable reason the UI can map to customer guidance.

Order/result metadata checkout UIs may consume after checkout:

- `_cbb_checkout_mode`: mode captured at order creation.
- `_cbb_mixed_recovery_intent`: shortage/recovery snapshot captured at order creation.
- `_cbb_mixed_recovery_status`: final result status for mixed recovery or wallet-only booking flows.
- `_cbb_mixed_recovery_context`: customer-facing result context, including optional message/action data.
- `_cbb_coins_debited_transaction_id`: present when booking ZC debit succeeded.

Result statuses:

- `completed`
  - Purchase/booking completed and ZC debit succeeded.
  - UI should show the success confirmation state.
- `payment_failed`
  - Money payment failed before booking debit.
  - UI should let the customer retry payment or cancel.
- `booking_full`
  - Payment/recovery path reached booking finalization, but the selected booking became unavailable.
  - UI should explain that no booking debit was made and route the customer back to schedule.
- `booking_failed`
  - Availability passed, but debit/finalization failed for a technical reason.
  - UI should explain that purchased ZC remain in the wallet and route the customer to retry booking or view profile.

Consumer responsibilities:

- Treat CBB as the source of truth for checkout mode and ZC arithmetic.
- Do not duplicate booking debit, grant, or availability-finalization logic in the UI layer.
- Use CBB context to decide which checkout controls to show.
- Use WooCommerce order-received/order-pay URLs plus CBB order metadata to render final result states.
- Keep customer-facing copy/design in the UI plugin, while keeping machine-readable statuses in CBB.

## Open Questions

Answered:

1. Free trial eligibility is enforced by normalized email, normalized phone, and a verified WooPayments card fingerprint.
2. Woo Smart Coupons is not installed yet. It is only needed once gift-card work begins, unless gift cards move into an earlier milestone.
3. Tera Wallet UI should be hidden in favor of a custom CBB wallet screen. CBB UI will own the customer-facing wallet experience.

Pending:

1. For workshops/events, should the final behavior always be "buy missing amount only", even when missing 3+ ZC?
2. What is the exact active-member price per ZC source: subscription product price divided by monthly ZC grant, or a manually configured plan rate?
3. Which booking statuses represent "studio cancelled" in the actual Woo Bookings setup?
