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
- Admin tools and reporting.

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

Free trial identity MVP:

- Enforce by normalized email and phone for now.
- User account ID may also be stored for reporting, but eligibility should not rely only on account ID.
- Longer-term: add stronger duplicate detection using verified phone/payment identity.

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

Design goal: the booking flow, checkout flow, wallet UI, and admin tools should all call the same services.

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

### Milestone 6: Insufficient ZC Purchase Flows

Goal: implement client purchase flows during booking.

Tasks:

- Missing 1-2 ZC popup for standard sessions.
- Choose-your-plan for standard sessions missing 3+ ZC or 0 ZC.
- Workshop/event missing amount checkout.
- Member-only booking top-up price.
- Auto-complete booking after top-up payment.
- Leave purchased ZC in wallet if booking fails after payment.

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

## Open Questions

Answered:

1. Free trial identity should be enforced by normalized email and phone for now.
2. Woo Smart Coupons is not installed yet. It is only needed once gift-card work begins, unless gift cards move into an earlier milestone.
3. Tera Wallet UI should be hidden in favor of a custom CBB wallet screen. CBB UI will own the customer-facing wallet experience.

Pending:

1. For workshops/events, should the final behavior always be "buy missing amount only", even when missing 3+ ZC?
2. What is the exact active-member price per ZC source: subscription product price divided by monthly ZC grant, or a manually configured plan rate?
3. Which booking statuses represent "studio cancelled" in the actual Woo Bookings setup?
