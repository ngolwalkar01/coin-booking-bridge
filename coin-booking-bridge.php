<?php
/**
 * Plugin Name: Coin Booking Bridge
 * Description: MVP bridge for WooCommerce Memberships, Subscriptions, Bookings, and Tera Wallet coin-based bookings.
 * Version: 0.1.2
 * Author: Custom
 * Text Domain: coin-booking-bridge
 *
 * @package CoinBookingBridge
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'CBB_Coin_Booking_Bridge' ) ) {
	final class CBB_Coin_Booking_Bridge {

		const META_GRANT_AMOUNT     = '_cbb_coin_grant_amount';
		const META_BOOKING_COST     = '_cbb_booking_coin_cost';
		const META_REFUND_POLICY    = '_cbb_coin_refund_policy';
		const META_CREDIT_TXN       = '_cbb_coins_credited_transaction_id';
		const META_DEBIT_TXN        = '_cbb_coins_debited_transaction_id';
		const META_REFUND_TXN       = '_cbb_coins_refunded_transaction_id';
		const META_COIN_TOTAL       = '_cbb_coin_total';
		const META_COIN_ITEM_COST   = '_cbb_coin_item_cost';
		const META_REFUNDED_TOTAL   = '_cbb_coins_refunded_total';

		/**
		 * Boot plugin hooks.
		 */
		public static function init() {
			add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 20 );
		}

		/**
		 * Register hooks once dependencies are available.
		 */
		public static function register_hooks() {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'maybe_dependency_notice' ) );
				add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_fields' ) );
				add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );
				add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_fields' ), 20, 3 );
				add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 20, 2 );
			}

			if ( ! self::dependencies_loaded() ) {
				return;
			}

			add_action( 'woocommerce_subscription_payment_complete', array( __CLASS__, 'credit_subscription_coins' ), 20, 1 );
			add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'credit_subscription_renewal_coins' ), 20, 2 );

			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'zero_coin_booking_prices' ), 20 );
			add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_coin_balance' ), 20 );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout_coin_balance' ), 20, 2 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'store_order_item_coin_cost' ), 20, 4 );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'debit_order_booking_coins' ), 30, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'debit_order_booking_coins' ), 30, 1 );
			add_action( 'woocommerce_payment_complete', array( __CLASS__, 'debit_order_booking_coins' ), 30, 1 );
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'debit_order_booking_coins' ), 30, 1 );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'debit_order_booking_coins' ), 30, 1 );

			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'refund_order_booking_coins' ), 20 );
			add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'refund_order_booking_coins' ), 20 );
			add_action( 'woocommerce_bookings_cancelled_booking', array( __CLASS__, 'refund_booking_coins' ), 20 );

			add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_coin_cost' ), 20, 2 );
		}

		/**
		 * Check runtime dependencies.
		 *
		 * @return bool
		 */
		private static function dependencies_loaded() {
			return function_exists( 'WC' )
				&& function_exists( 'woo_wallet' )
				&& function_exists( 'wcs_get_subscription' )
				&& function_exists( 'get_wc_booking' );
		}

		/**
		 * Show a dependency notice in wp-admin.
		 */
		public static function maybe_dependency_notice() {
			if ( self::dependencies_loaded() || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Coin Booking Bridge needs WooCommerce, WooCommerce Subscriptions, WooCommerce Bookings, and Tera Wallet active to process coins.', 'coin-booking-bridge' );
			echo '</p></div>';
		}

		/**
		 * Add MVP product fields.
		 */
		public static function render_product_fields() {
			echo '<div class="options_group">';

			woocommerce_wp_text_input(
				array(
					'id'                => self::META_GRANT_AMOUNT,
					'label'             => __( 'Coins granted per paid subscription cycle', 'coin-booking-bridge' ),
					'description'       => __( 'Used on subscription products that grant membership coins.', 'coin-booking-bridge' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'wrapper_class'     => 'show_if_subscription',
					'custom_attributes' => array(
						'step' => '0.01',
						'min'  => '0',
					),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => self::META_BOOKING_COST,
					'label'             => __( 'Booking coin cost', 'coin-booking-bridge' ),
					'description'       => __( 'If set, this bookable product is paid with coins and its money price is set to zero in cart.', 'coin-booking-bridge' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'wrapper_class'     => 'show_if_booking show_if_bookable_event show_if_bookable_service',
					'custom_attributes' => array(
						'step' => '0.01',
						'min'  => '0',
					),
				)
			);

			woocommerce_wp_select(
				array(
					'id'          => self::META_REFUND_POLICY,
					'label'       => __( 'Coin refund policy', 'coin-booking-bridge' ),
					'description' => __( 'MVP refund handling for cancelled/refunded booking orders.', 'coin-booking-bridge' ),
					'desc_tip'    => true,
					'wrapper_class' => 'show_if_booking show_if_bookable_event show_if_bookable_service',
					'options'     => array(
						'full' => __( 'Full refund', 'coin-booking-bridge' ),
						'none' => __( 'No automatic refund', 'coin-booking-bridge' ),
					),
				)
			);

			echo '</div>';
		}

		/**
		 * Add MVP variation fields.
		 *
		 * @param int     $loop           Variation loop index.
		 * @param array   $variation_data Variation data.
		 * @param WP_Post $variation      Variation post object.
		 */
		public static function render_variation_fields( $loop, $variation_data, $variation ) {
			$parent_product = wc_get_product( $variation->post_parent );

			if ( ! $parent_product || ! $parent_product->is_type( 'variable-subscription' ) ) {
				return;
			}

			woocommerce_wp_text_input(
				array(
					'id'                => self::META_GRANT_AMOUNT . '_' . $loop,
					'name'              => self::META_GRANT_AMOUNT . '[' . $loop . ']',
					'value'             => get_post_meta( $variation->ID, self::META_GRANT_AMOUNT, true ),
					'label'             => __( 'Coins granted per paid subscription cycle', 'coin-booking-bridge' ),
					'description'       => __( 'Used for this subscription variation.', 'coin-booking-bridge' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'wrapper_class'     => 'form-row form-row-full',
					'custom_attributes' => array(
						'step' => '0.01',
						'min'  => '0',
					),
				)
			);
		}

		/**
		 * Save MVP product fields.
		 *
		 * @param int $post_id Product ID.
		 */
		public static function save_product_fields( $post_id ) {
			$grant_amount = isset( $_POST[ self::META_GRANT_AMOUNT ] ) ? wc_format_decimal( wp_unslash( $_POST[ self::META_GRANT_AMOUNT ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$booking_cost = isset( $_POST[ self::META_BOOKING_COST ] ) ? wc_format_decimal( wp_unslash( $_POST[ self::META_BOOKING_COST ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$policy       = isset( $_POST[ self::META_REFUND_POLICY ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_REFUND_POLICY ] ) ) : 'full'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			update_post_meta( $post_id, self::META_GRANT_AMOUNT, $grant_amount );
			update_post_meta( $post_id, self::META_BOOKING_COST, $booking_cost );
			update_post_meta( $post_id, self::META_REFUND_POLICY, in_array( $policy, array( 'full', 'none' ), true ) ? $policy : 'full' );
		}

		/**
		 * Save MVP variation fields.
		 *
		 * @param int $variation_id Variation ID.
		 * @param int $loop         Variation loop index.
		 */
		public static function save_variation_fields( $variation_id, $loop ) {
			$variation = wc_get_product( $variation_id );
			$parent    = $variation ? wc_get_product( $variation->get_parent_id() ) : null;

			if ( ! $parent || ! $parent->is_type( 'variable-subscription' ) ) {
				return;
			}

			$amounts = isset( $_POST[ self::META_GRANT_AMOUNT ] ) && is_array( $_POST[ self::META_GRANT_AMOUNT ] ) ? wp_unslash( $_POST[ self::META_GRANT_AMOUNT ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$amount  = isset( $amounts[ $loop ] ) ? wc_format_decimal( $amounts[ $loop ] ) : '';

			update_post_meta( $variation_id, self::META_GRANT_AMOUNT, $amount );
		}

		/**
		 * Credit coins after subscription payment.
		 *
		 * @param WC_Subscription $subscription Subscription object.
		 */
		public static function credit_subscription_coins( $subscription ) {
			$order = self::get_subscription_last_order( $subscription );
			self::credit_coins_for_order( $subscription, $order );
		}

		/**
		 * Credit coins after renewal payment.
		 *
		 * @param WC_Subscription $subscription Subscription object.
		 * @param WC_Order        $last_order   Paid renewal order.
		 */
		public static function credit_subscription_renewal_coins( $subscription, $last_order ) {
			self::credit_coins_for_order( $subscription, $last_order );
		}

		/**
		 * Credit coins for the paid subscription order.
		 *
		 * @param WC_Subscription $subscription Subscription object.
		 * @param WC_Order|null   $order        Paid order.
		 */
		private static function credit_coins_for_order( $subscription, $order ) {
			if ( ! $subscription || ! $order || ! is_a( $order, 'WC_Order' ) || $order->get_meta( self::META_CREDIT_TXN, true ) ) {
				return;
			}

			$user_id = (int) $subscription->get_user_id();
			$coins   = self::get_coin_grant_total_from_order( $order );

			if ( $user_id <= 0 || $coins <= 0 ) {
				return;
			}

			$transaction_id = woo_wallet()->wallet->credit(
				$user_id,
				$coins,
				sprintf( __( 'Membership coins for subscription #%s', 'coin-booking-bridge' ), $subscription->get_order_number() ),
				array(
					'for'      => 'membership_grant',
					'currency' => $order->get_currency( 'edit' ),
				)
			);

			if ( $transaction_id ) {
				$order->update_meta_data( self::META_CREDIT_TXN, $transaction_id );
				$order->update_meta_data( self::META_COIN_TOTAL, $coins );
				$order->save();

				$subscription->add_order_note(
					sprintf(
						/* translators: 1: coin amount, 2: transaction id */
						__( 'Credited %1$s coins to wallet. Wallet transaction: %2$s.', 'coin-booking-bridge' ),
						wc_format_decimal( $coins ),
						$transaction_id
					)
				);
			}
		}

		/**
		 * Get latest subscription order defensively across WCS versions.
		 *
		 * @param WC_Subscription $subscription Subscription object.
		 * @return WC_Order|null
		 */
		private static function get_subscription_last_order( $subscription ) {
			if ( ! $subscription || ! is_object( $subscription ) ) {
				return null;
			}

			if ( method_exists( $subscription, 'get_last_order' ) ) {
				$order = $subscription->get_last_order( 'all' );
				if ( $order instanceof WC_Order ) {
					return $order;
				}
			}

			if ( method_exists( $subscription, 'get_parent_id' ) ) {
				$order = wc_get_order( $subscription->get_parent_id() );
				return $order instanceof WC_Order ? $order : null;
			}

			return null;
		}

		/**
		 * Sum grant amounts from order items.
		 *
		 * @param WC_Order $order Order.
		 * @return float
		 */
		private static function get_coin_grant_total_from_order( $order ) {
			$total = 0.0;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$amount = self::get_item_coin_grant_amount( $item );

				if ( $amount > 0 ) {
					$total += $amount * max( 1, (float) $item->get_quantity() );
				}
			}

			return (float) apply_filters( 'cbb_coin_grant_total_from_order', $total, $order );
		}

		/**
		 * Get coin grant amount for an order item.
		 *
		 * Variable subscriptions use the selected variation value only.
		 * Simple subscriptions use the product-level value.
		 *
		 * @param WC_Order_Item_Product $item Order item.
		 * @return float
		 */
		private static function get_item_coin_grant_amount( $item ) {
			$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;

			if ( $variation_id ) {
				return (float) get_post_meta( $variation_id, self::META_GRANT_AMOUNT, true );
			}

			$product_id = (int) $item->get_product_id();

			return $product_id ? (float) get_post_meta( $product_id, self::META_GRANT_AMOUNT, true ) : 0.0;
		}

		/**
		 * Set money price to zero for coin-paid booking cart items.
		 *
		 * @param WC_Cart $cart Cart.
		 */
		public static function zero_coin_booking_prices( $cart ) {
			if ( ( is_admin() && ! wp_doing_ajax() ) || ! ( $cart instanceof WC_Cart ) ) {
				return;
			}

			foreach ( $cart->get_cart() as $cart_item ) {
				if ( self::is_coin_booking_cart_item( $cart_item ) ) {
					$cart_item['data']->set_price( 0 );
				}
			}
		}

		/**
		 * Validate coin balance on cart page.
		 */
		public static function validate_cart_coin_balance() {
			self::validate_coin_balance();
		}

		/**
		 * Validate coin balance at checkout.
		 *
		 * @param array    $data   Checkout data.
		 * @param WP_Error $errors Validation errors.
		 */
		public static function validate_checkout_coin_balance( $data, $errors ) {
			self::validate_coin_balance( $errors );
		}

		/**
		 * Shared balance validation.
		 *
		 * @param WP_Error|null $errors Optional checkout errors.
		 */
		private static function validate_coin_balance( $errors = null ) {
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}

			$required = self::get_cart_coin_total();
			if ( $required <= 0 ) {
				return;
			}

			if ( ! is_user_logged_in() ) {
				self::add_validation_error( __( 'Please log in to book with coins.', 'coin-booking-bridge' ), $errors );
				return;
			}

			$balance = self::get_wallet_balance( get_current_user_id() );
			if ( $balance < $required ) {
				self::add_validation_error(
					sprintf(
						/* translators: 1: required coins, 2: available coins */
						__( 'You need %1$s coins for these bookings. Your current balance is %2$s coins.', 'coin-booking-bridge' ),
						wc_format_decimal( $required ),
						wc_format_decimal( $balance )
					),
					$errors
				);
			}
		}

		/**
		 * Add error to checkout or notices.
		 *
		 * @param string        $message Error message.
		 * @param WP_Error|null $errors  Checkout errors.
		 */
		private static function add_validation_error( $message, $errors = null ) {
			if ( $errors instanceof WP_Error ) {
				$errors->add( 'coin_booking_balance', $message );
			} else {
				wc_add_notice( $message, 'error' );
			}
		}

		/**
		 * Store coin cost on order item.
		 *
		 * @param WC_Order_Item_Product $item          Order item.
		 * @param string                $cart_item_key Cart item key.
		 * @param array                 $values        Cart item values.
		 * @param WC_Order              $order         Order.
		 */
		public static function store_order_item_coin_cost( $item, $cart_item_key, $values, $order ) {
			if ( ! self::is_coin_booking_cart_item( $values ) ) {
				return;
			}

			$coin_cost = self::get_cart_item_coin_cost( $values );
			if ( $coin_cost > 0 ) {
				$item->add_meta_data( self::META_COIN_ITEM_COST, $coin_cost, true );
				$item->add_meta_data( __( 'Coin cost', 'coin-booking-bridge' ), wc_format_decimal( $coin_cost ), true );
			}
		}

		/**
		 * Debit booking coins after checkout order creation.
		 *
		 * @param int      $order_id    Order ID.
		 * @param array    $posted_data Posted checkout data.
		 * @param WC_Order $order       Order.
		 */
		public static function debit_order_booking_coins( $order_id, $posted_data = array(), $order = null ) {
			if ( $order_id instanceof WC_Order ) {
				$order = $order_id;
			} else {
				$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			}

			if ( ! $order || $order->get_meta( self::META_DEBIT_TXN, true ) ) {
				return;
			}

			$user_id = (int) $order->get_customer_id();
			$coins   = self::get_order_coin_total( $order );

			if ( $user_id <= 0 || $coins <= 0 ) {
				return;
			}

			$transaction_id = woo_wallet()->wallet->debit(
				$user_id,
				$coins,
				sprintf( __( 'Coins used for booking order #%s', 'coin-booking-bridge' ), $order->get_order_number() ),
				array(
					'for'      => 'booking_payment',
					'currency' => $order->get_currency( 'edit' ),
				)
			);

			if ( ! $transaction_id ) {
				$order->add_order_note( __( 'Coin debit failed. Please review the wallet balance and booking order manually.', 'coin-booking-bridge' ) );
				return;
			}

			$order->update_meta_data( self::META_DEBIT_TXN, $transaction_id );
			$order->update_meta_data( self::META_COIN_TOTAL, $coins );
			$order->add_order_note(
				sprintf(
					/* translators: 1: coin amount, 2: transaction id */
					__( 'Debited %1$s coins for booking. Wallet transaction: %2$s.', 'coin-booking-bridge' ),
					wc_format_decimal( $coins ),
					$transaction_id
				)
			);
			$order->save();
		}

		/**
		 * Refund coins for a cancelled/refunded order.
		 *
		 * @param int $order_id Order ID.
		 */
		public static function refund_order_booking_coins( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order || ! $order->get_meta( self::META_DEBIT_TXN, true ) ) {
				return;
			}

			$refundable       = self::get_order_refundable_coin_total( $order );
			$already_refunded = (float) $order->get_meta( self::META_REFUNDED_TOTAL, true );
			$coins            = max( 0, $refundable - $already_refunded );

			if ( $coins <= 0 ) {
				return;
			}

			self::credit_coin_refund( $order, $coins, sprintf( __( 'Coin refund for booking order #%s', 'coin-booking-bridge' ), $order->get_order_number() ) );
		}

		/**
		 * Refund coins when Bookings cancellation hook fires.
		 *
		 * @param int $booking_id Booking ID.
		 */
		public static function refund_booking_coins( $booking_id ) {
			if ( ! function_exists( 'get_wc_booking' ) ) {
				return;
			}

			$booking = get_wc_booking( $booking_id );
			if ( ! $booking || ! method_exists( $booking, 'get_order_id' ) || $booking->get_meta( self::META_REFUND_TXN, true ) ) {
				return;
			}

			$order_id = $booking->get_order_id();
			$item_id  = method_exists( $booking, 'get_order_item_id' ) ? $booking->get_order_item_id() : 0;
			$order    = $order_id ? wc_get_order( $order_id ) : null;

			if ( ! $order || ! $order->get_meta( self::META_DEBIT_TXN, true ) || ! $item_id ) {
				return;
			}

			$item = $order->get_item( $item_id );
			if ( ! $item ) {
				return;
			}

			$product_id = self::get_item_product_or_parent_id( $item );
			$policy     = $product_id ? get_post_meta( $product_id, self::META_REFUND_POLICY, true ) : 'full';

			if ( 'none' === $policy ) {
				return;
			}

			$item_coin_cost = (float) $item->get_meta( self::META_COIN_ITEM_COST, true );
			if ( $item_coin_cost <= 0 ) {
				return;
			}

			$refundable       = self::get_order_refundable_coin_total( $order );
			$already_refunded = (float) $order->get_meta( self::META_REFUNDED_TOTAL, true );
			$coins            = min( $item_coin_cost, max( 0, $refundable - $already_refunded ) );

			if ( $coins <= 0 ) {
				return;
			}

			$transaction_id = self::credit_coin_refund( $order, $coins, sprintf( __( 'Coin refund for booking #%s', 'coin-booking-bridge' ), $booking_id ) );

			if ( $transaction_id ) {
				$booking->update_meta_data( self::META_REFUND_TXN, $transaction_id );
				$booking->save();
			}
		}

		/**
		 * Credit a coin refund and update order-level refund tracking.
		 *
		 * @param WC_Order $order   Order.
		 * @param float    $coins   Coins to refund.
		 * @param string   $details Wallet transaction details.
		 * @return int|false
		 */
		private static function credit_coin_refund( $order, $coins, $details ) {
			$transaction_id = woo_wallet()->wallet->credit(
				$order->get_customer_id(),
				$coins,
				$details,
				array(
					'for'      => 'booking_refund',
					'currency' => $order->get_currency( 'edit' ),
				)
			);

			if ( $transaction_id ) {
				$already_refunded = (float) $order->get_meta( self::META_REFUNDED_TOTAL, true );

				$order->update_meta_data( self::META_REFUND_TXN, $transaction_id );
				$order->update_meta_data( self::META_REFUNDED_TOTAL, $already_refunded + $coins );
				$order->add_order_note(
					sprintf(
						/* translators: 1: coin amount, 2: transaction id */
						__( 'Refunded %1$s booking coins. Wallet transaction: %2$s.', 'coin-booking-bridge' ),
						wc_format_decimal( $coins ),
						$transaction_id
					)
				);
				$order->save();
			}

			return $transaction_id;
		}

		/**
		 * Display coin cost in cart.
		 *
		 * @param array $item_data Cart display data.
		 * @param array $cart_item Cart item.
		 * @return array
		 */
		public static function display_cart_coin_cost( $item_data, $cart_item ) {
			if ( self::is_coin_booking_cart_item( $cart_item ) ) {
				$item_data[] = array(
					'name'  => __( 'Coin cost', 'coin-booking-bridge' ),
					'value' => wc_format_decimal( self::get_cart_item_coin_cost( $cart_item ) ),
				);
			}

			return $item_data;
		}

		/**
		 * Get cart coin total.
		 *
		 * @return float
		 */
		private static function get_cart_coin_total() {
			$total = 0.0;

			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( self::is_coin_booking_cart_item( $cart_item ) ) {
					$total += self::get_cart_item_coin_cost( $cart_item );
				}
			}

			return (float) apply_filters( 'cbb_cart_coin_total', $total, WC()->cart );
		}

		/**
		 * Get coin cost for cart item.
		 *
		 * @param array $cart_item Cart item.
		 * @return float
		 */
		private static function get_cart_item_coin_cost( $cart_item ) {
			$product_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$cost       = $product_id ? (float) get_post_meta( $product_id, self::META_BOOKING_COST, true ) : 0.0;
			$quantity   = isset( $cart_item['quantity'] ) ? max( 1, (float) $cart_item['quantity'] ) : 1;

			return (float) apply_filters( 'cbb_cart_item_coin_cost', $cost * $quantity, $cart_item );
		}

		/**
		 * Check whether cart item is coin-paid booking.
		 *
		 * @param array $cart_item Cart item.
		 * @return bool
		 */
		private static function is_coin_booking_cart_item( $cart_item ) {
			return ! empty( $cart_item['booking'] ) && self::get_cart_item_coin_cost( $cart_item ) > 0;
		}

		/**
		 * Get wallet numeric balance.
		 *
		 * @param int $user_id User ID.
		 * @return float
		 */
		private static function get_wallet_balance( $user_id ) {
			return (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
		}

		/**
		 * Sum coin costs stored on order items.
		 *
		 * @param WC_Order $order Order.
		 * @return float
		 */
		private static function get_order_coin_total( $order ) {
			$total = 0.0;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$item_coin_cost = (float) $item->get_meta( self::META_COIN_ITEM_COST, true );

				if ( $item_coin_cost <= 0 ) {
					$product_id     = self::get_item_product_or_parent_id( $item );
					$product_cost   = $product_id ? (float) get_post_meta( $product_id, self::META_BOOKING_COST, true ) : 0.0;
					$item_coin_cost = $product_cost * max( 1, (float) $item->get_quantity() );

					if ( $item_coin_cost > 0 ) {
						$item->add_meta_data( self::META_COIN_ITEM_COST, $item_coin_cost, true );
						$item->save();
					}
				}

				$total += $item_coin_cost;
			}

			return (float) apply_filters( 'cbb_order_coin_total', $total, $order );
		}

		/**
		 * Sum refundable coin total based on product policies.
		 *
		 * @param WC_Order $order Order.
		 * @return float
		 */
		private static function get_order_refundable_coin_total( $order ) {
			$total = 0.0;

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product_id = self::get_item_product_or_parent_id( $item );
				$policy     = $product_id ? get_post_meta( $product_id, self::META_REFUND_POLICY, true ) : 'full';

				if ( 'none' === $policy ) {
					continue;
				}

				$total += (float) $item->get_meta( self::META_COIN_ITEM_COST, true );
			}

			return (float) apply_filters( 'cbb_order_refundable_coin_total', $total, $order );
		}

		/**
		 * Prefer variation ID when it has meta, otherwise parent product ID.
		 *
		 * @param WC_Order_Item_Product $item Order item.
		 * @return int
		 */
		private static function get_item_product_or_parent_id( $item ) {
			$product_id   = (int) $item->get_product_id();
			$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;

			if ( $variation_id && get_post_meta( $variation_id, self::META_GRANT_AMOUNT, true ) !== '' ) {
				return $variation_id;
			}

			return $product_id;
		}
	}

	CBB_Coin_Booking_Bridge::init();
}
