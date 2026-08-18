<?php
/**
 * Plugin Name: Coin Booking Bridge
 * Description: MVP bridge for WooCommerce Memberships, Subscriptions, Bookings, and Tera Wallet coin-based bookings.
 * Version: 0.2.27
 * Author: Custom
 * Text Domain: coin-booking-bridge
 *
 * @package CoinBookingBridge
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'CBB_Coin_Booking_Bridge' ) ) {
	final class CBB_Coin_Booking_Bridge {

		const VERSION           = '0.2.27';
		const DB_VERSION        = '2026050801';
		const OPTION_DB_VERSION = 'cbb_db_version';
		const OPTION_SETTINGS   = 'cbb_zencoin_settings';
		const CRON_EXPIRE_BUCKETS = 'cbb_zencoin_expire_buckets';

		const TABLE_BUCKETS = 'cbb_zencoin_buckets';
		const TABLE_LEDGER  = 'cbb_zencoin_ledger';

		const META_GRANT_AMOUNT     = '_cbb_coin_grant_amount';
		const META_BOOKING_COST     = '_cbb_booking_coin_cost';
		const META_REFUND_POLICY    = '_cbb_coin_refund_policy';
		const META_PRODUCT_TYPE     = '_cbb_zencoin_product_type';
		const META_ZC_GRANT_AMOUNT  = '_cbb_zencoin_grant_amount';
		const META_VALIDITY_DAYS    = '_cbb_zencoin_validity_days';
		const META_VALIDITY_MONTHS  = '_cbb_zencoin_validity_months';
		const META_SOURCE_LABEL     = '_cbb_zencoin_source_label';
		const META_ONE_TIME_PERSON  = '_cbb_zencoin_one_time_per_person';
		const META_PACKAGE_SIZE     = '_cbb_zencoin_package_size';
		const META_CREDIT_TXN       = '_cbb_coins_credited_transaction_id';
		const META_PRODUCT_GRANTS   = '_cbb_zencoin_product_grants';
		const META_MEMBERSHIP_GRANT = '_cbb_zencoin_membership_grant';
		const META_TRIAL_IDENTITY   = '_cbb_free_trial_identity';
		const META_TRIAL_EMAIL_HASH = '_cbb_free_trial_email_hash';
		const META_TRIAL_PHONE_HASH = '_cbb_free_trial_phone_hash';
		const META_TRIAL_CARD_HASH   = '_cbb_free_trial_card_fingerprint_hash';
		const META_TRIAL_CARD_STATUS = '_cbb_free_trial_card_validation';
		const META_DEBIT_TXN        = '_cbb_coins_debited_transaction_id';
		const META_REFUND_TXN       = '_cbb_coins_refunded_transaction_id';
		const META_COIN_TOTAL       = '_cbb_coin_total';
		const META_COIN_ITEM_COST   = '_cbb_coin_item_cost';
		const META_COIN_CONSUMPTION = '_cbb_coin_consumption';
		const META_REFUNDED_TOTAL   = '_cbb_coins_refunded_total';
		const META_LATE_CANCEL_TOTAL = '_cbb_late_cancel_no_refund_total';
		const META_CHECKOUT_MODE    = '_cbb_checkout_mode';
		const META_RECOVERY_INTENT  = '_cbb_mixed_recovery_intent';
		const META_RECOVERY_STATUS  = '_cbb_mixed_recovery_status';
		const META_RECOVERY_CONTEXT = '_cbb_mixed_recovery_context';

		/**
		 * Boot plugin hooks.
		 */
		public static function init() {
			add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 20 );
		}

		/**
		 * Plugin activation callback.
		 */
		public static function activate() {
			self::install_schema();
			self::schedule_expiry_cron();
		}

		/**
		 * Plugin deactivation callback.
		 */
		public static function deactivate() {
			self::clear_expiry_cron();
		}

		/**
		 * Register hooks once dependencies are available.
		 */
		public static function register_hooks() {
			if ( is_admin() ) {
				self::maybe_upgrade_schema();
			}

			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_global_coin_assets' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_wallet_endpoint_assets' ) );
			add_shortcode( 'zencoin_coin', array( __CLASS__, 'render_global_coin_shortcode' ) );

			if ( is_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'maybe_dependency_notice' ) );
				add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
				add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
				add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_settings_assets' ) );
				add_action( 'admin_post_cbb_run_zencoin_expiry', array( __CLASS__, 'handle_manual_expiry_run' ) );
				add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_fields' ) );
				add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );
				add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_fields' ), 20, 3 );
				add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 20, 2 );
			}

			self::schedule_expiry_cron();
			add_action( self::CRON_EXPIRE_BUCKETS, array( __CLASS__, 'expire_zencoin_buckets' ) );

			if ( ! self::dependencies_loaded() ) {
				return;
			}

			if ( class_exists( 'Woo_Wallet_Frontend' ) ) {
				remove_action( 'woocommerce_account_woo-wallet_endpoint', array( Woo_Wallet_Frontend::instance(), 'woo_wallet_endpoint_content' ) );
			}

			add_action( 'woocommerce_account_woo-wallet_endpoint', array( __CLASS__, 'render_zencoin_wallet_endpoint' ) );

			add_action( 'woocommerce_subscription_payment_complete', array( __CLASS__, 'credit_subscription_coins' ), 20, 1 );
			add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'credit_subscription_renewal_coins' ), 20, 2 );
			add_action( 'woocommerce_payment_complete', array( __CLASS__, 'grant_order_product_zencoins' ), 20, 1 );
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'grant_order_product_zencoins' ), 20, 1 );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'grant_order_product_zencoins' ), 20, 1 );
			add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_free_dropin_trial_checkout' ), 20 );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'prepare_free_dropin_classic_wcpay_payment' ), 5, 3 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_free_dropin_trial_store_api_checkout' ), 20, 2 );
			add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( __CLASS__, 'prepare_free_dropin_wcpay_payment' ), 20, 2 );
			add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'force_free_dropin_cart_payment' ), 20, 2 );
			add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'force_free_dropin_order_payment' ), 20, 2 );
			add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'limit_free_dropin_to_woopayments' ), 100 );

			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_single_booking_add_to_cart' ), 1, 6 );
			add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'zero_coin_booking_cart_item_price' ), 999, 1 );
			add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'zero_coin_booking_session_item_price' ), 999, 3 );
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'zero_coin_booking_prices' ), 999 );
			add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_coin_balance' ), 20 );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout_coin_balance' ), 20, 2 );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'store_checkout_context_on_processed_order' ), 20, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'store_checkout_context_on_processed_order' ), 20, 1 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'store_order_item_coin_cost' ), 20, 4 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'store_order_item_dynamic_coin_grant' ), 25, 4 );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'debit_order_booking_coins' ), 30, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'debit_order_booking_coins' ), 30, 1 );
			add_action( 'woocommerce_payment_complete', array( __CLASS__, 'debit_order_booking_coins' ), 30, 1 );
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'debit_order_booking_coins' ), 30, 1 );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'debit_order_booking_coins' ), 30, 1 );
			add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'mark_mixed_recovery_payment_failed' ), 20, 1 );

			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'refund_order_booking_coins' ), 20 );
			add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'refund_order_booking_coins' ), 20 );
			add_action( 'woocommerce_booking_cancelled', array( __CLASS__, 'refund_booking_coins' ), 20, 1 );
			add_action( 'woocommerce_bookings_cancelled_booking', array( __CLASS__, 'refund_booking_coins' ), 20 );

			add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_coin_cost' ), 20, 2 );
		}

		/**
		 * Install or update custom Zencoin tables.
		 */
		private static function install_schema() {
			global $wpdb;

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$charset_collate = $wpdb->get_charset_collate();
			$buckets_table   = self::get_buckets_table_name();
			$ledger_table    = self::get_ledger_table_name();

			$buckets_sql = "CREATE TABLE {$buckets_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				source_type varchar(40) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				original_amount decimal(20,6) NOT NULL DEFAULT 0.000000,
				remaining_amount decimal(20,6) NOT NULL DEFAULT 0.000000,
				expires_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				related_order_id bigint(20) unsigned DEFAULT NULL,
				related_order_item_id bigint(20) unsigned DEFAULT NULL,
				related_product_id bigint(20) unsigned DEFAULT NULL,
				related_subscription_id bigint(20) unsigned DEFAULT NULL,
				related_booking_id bigint(20) unsigned DEFAULT NULL,
				related_coupon_id bigint(20) unsigned DEFAULT NULL,
				source_label varchar(120) DEFAULT NULL,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				KEY user_status_expiry (user_id,status,expires_at),
				KEY source_type (source_type),
				KEY related_order_id (related_order_id),
				KEY related_subscription_id (related_subscription_id),
				KEY related_booking_id (related_booking_id)
			) {$charset_collate};";

			$ledger_sql = "CREATE TABLE {$ledger_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				bucket_id bigint(20) unsigned DEFAULT NULL,
				entry_type varchar(40) NOT NULL,
				direction varchar(10) NOT NULL,
				amount decimal(20,6) NOT NULL DEFAULT 0.000000,
				balance_after decimal(20,6) DEFAULT NULL,
				label varchar(160) NOT NULL,
				created_at datetime NOT NULL,
				related_order_id bigint(20) unsigned DEFAULT NULL,
				related_order_item_id bigint(20) unsigned DEFAULT NULL,
				related_product_id bigint(20) unsigned DEFAULT NULL,
				related_subscription_id bigint(20) unsigned DEFAULT NULL,
				related_booking_id bigint(20) unsigned DEFAULT NULL,
				related_coupon_id bigint(20) unsigned DEFAULT NULL,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				KEY user_created (user_id,created_at),
				KEY bucket_id (bucket_id),
				KEY entry_type (entry_type),
				KEY related_order_id (related_order_id),
				KEY related_booking_id (related_booking_id)
			) {$charset_collate};";

			dbDelta( $buckets_sql );
			dbDelta( $ledger_sql );

			update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
		}

		/**
		 * Update schema if plugin files are newer than installed DB version.
		 */
		private static function maybe_upgrade_schema() {
			if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
				return;
			}

			self::install_schema();
		}

		/**
		 * Schedule daily bucket expiry maintenance.
		 */
		private static function schedule_expiry_cron() {
			if ( ! wp_next_scheduled( self::CRON_EXPIRE_BUCKETS ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_EXPIRE_BUCKETS );
			}
		}

		/**
		 * Clear bucket expiry maintenance.
		 */
		private static function clear_expiry_cron() {
			$timestamp = wp_next_scheduled( self::CRON_EXPIRE_BUCKETS );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_EXPIRE_BUCKETS );
			}
		}

		/**
		 * Get buckets table name.
		 *
		 * @return string
		 */
		private static function get_buckets_table_name() {
			global $wpdb;

			return $wpdb->prefix . self::TABLE_BUCKETS;
		}

		/**
		 * Get ledger table name.
		 *
		 * @return string
		 */
		private static function get_ledger_table_name() {
			global $wpdb;

			return $wpdb->prefix . self::TABLE_LEDGER;
		}

		/**
		 * Register settings page.
		 */
		public static function register_admin_menu() {
			add_submenu_page(
				'woocommerce',
				__( 'Zencoin Settings', 'coin-booking-bridge' ),
				__( 'Zencoin Settings', 'coin-booking-bridge' ),
				'manage_woocommerce',
				'cbb-zencoin-settings',
				array( __CLASS__, 'render_settings_page' )
			);
		}

		/**
		 * Register settings.
		 */
		public static function register_settings() {
			register_setting(
				'cbb_zencoin_settings',
				self::OPTION_SETTINGS,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
					'default'           => self::get_default_settings(),
				)
			);
		}

		/**
		 * Get default Zencoin settings.
		 *
		 * @return array
		 */
		private static function get_default_settings() {
			return array(
				'coin_value_eur'                       => '5',
				'standard_session_cost'                => '5',
				'workshop_tier_a_cost'                 => '6',
				'workshop_tier_b_cost'                 => '8',
				'workshop_tier_c_cost'                 => '10',
				'auto_calculate_booking_cost'          => 'yes',
				'free_dropin_validity_months'          => '1',
				'dropin_validity_months'               => '3',
				'package_small_validity_months'        => '3',
				'package_medium_validity_months'       => '3',
				'package_large_validity_months'        => '6',
				'gift_card_validity_days'              => '1095',
				'newsletter_discount_validity_days'    => '30',
				'on_time_cancel_cutoff_hours'          => '12',
				'wallet_freeze_on_subscription_on_hold' => 'yes',
				'tera_wallet_mirror_enabled'           => 'yes',
				'member_recovery_product_id'           => 0,
				'zencoin_global_coin_enabled'          => 'yes',
				'zencoin_tooltip'                      => __( 'Zencoins are credits used to book eligible Zenctuary experiences.', 'coin-booking-bridge' ),
			);
		}

		/**
		 * Get merged settings.
		 *
		 * @return array
		 */
		private static function get_settings() {
			$settings = get_option( self::OPTION_SETTINGS, array() );
			$settings = is_array( $settings ) ? $settings : array();
			$settings = self::migrate_legacy_validity_day_settings( $settings );

			return wp_parse_args( $settings, self::get_default_settings() );
		}

		/**
		 * Convert legacy day-based validity settings to month settings when needed.
		 *
		 * @param array $settings Raw saved settings.
		 * @return array
		 */
		private static function migrate_legacy_validity_day_settings( array $settings ) {
			$legacy_map = array(
				'free_dropin_validity_months'    => 'free_dropin_validity_days',
				'dropin_validity_months'         => 'dropin_validity_days',
				'package_small_validity_months'  => 'package_small_validity_days',
				'package_medium_validity_months' => 'package_medium_validity_days',
				'package_large_validity_months'  => 'package_large_validity_days',
			);

			foreach ( $legacy_map as $month_key => $day_key ) {
				if ( array_key_exists( $month_key, $settings ) || ! array_key_exists( $day_key, $settings ) ) {
					continue;
				}

				$settings[ $month_key ] = self::convert_legacy_validity_days_to_months( $settings[ $day_key ] );
			}

			return $settings;
		}

		/**
		 * Convert old fixed-day validity values to calendar-month settings.
		 *
		 * @param mixed $days Legacy day count.
		 * @return int
		 */
		private static function convert_legacy_validity_days_to_months( $days ) {
			$days = absint( $days );

			if ( $days <= 0 ) {
				return 0;
			}

			return max( 1, (int) round( $days / 30 ) );
		}

		/**
		 * Sanitize settings.
		 *
		 * @param array $settings Raw settings.
		 * @return array
		 */
		public static function sanitize_settings( $settings ) {
			$settings = is_array( $settings ) ? $settings : array();
			$defaults = self::get_default_settings();
			$clean    = array();

			$decimal_fields = array(
				'coin_value_eur',
				'standard_session_cost',
				'workshop_tier_a_cost',
				'workshop_tier_b_cost',
				'workshop_tier_c_cost',
			);

			$integer_fields = array(
				'free_dropin_validity_months',
				'dropin_validity_months',
				'package_small_validity_months',
				'package_medium_validity_months',
				'package_large_validity_months',
				'gift_card_validity_days',
				'newsletter_discount_validity_days',
				'on_time_cancel_cutoff_hours',
				'member_recovery_product_id',
			);

			foreach ( $decimal_fields as $field ) {
				$value           = isset( $settings[ $field ] ) ? self::sanitize_decimal_setting( wp_unslash( $settings[ $field ] ) ) : $defaults[ $field ];
				$clean[ $field ] = max( 0, (float) $value );
			}

			foreach ( $integer_fields as $field ) {
				$value           = isset( $settings[ $field ] ) ? absint( wp_unslash( $settings[ $field ] ) ) : (int) $defaults[ $field ];
				$clean[ $field ] = max( 0, $value );
			}

			$clean['auto_calculate_booking_cost']           = ! empty( $settings['auto_calculate_booking_cost'] ) ? 'yes' : 'no';
			$clean['wallet_freeze_on_subscription_on_hold'] = ! empty( $settings['wallet_freeze_on_subscription_on_hold'] ) ? 'yes' : 'no';
			$clean['tera_wallet_mirror_enabled']            = ! empty( $settings['tera_wallet_mirror_enabled'] ) ? 'yes' : 'no';
			$clean['zencoin_global_coin_enabled']           = ! empty( $settings['zencoin_global_coin_enabled'] ) ? 'yes' : 'no';
			$clean['zencoin_tooltip']                       = isset( $settings['zencoin_tooltip'] ) ? sanitize_textarea_field( wp_unslash( $settings['zencoin_tooltip'] ) ) : $defaults['zencoin_tooltip'];

			return wp_parse_args( $clean, $defaults );
		}

		/**
		 * Sanitize a decimal setting without requiring WooCommerce helpers.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		private static function sanitize_decimal_setting( $value ) {
			if ( function_exists( 'wc_format_decimal' ) ) {
				return wc_format_decimal( $value );
			}

			return preg_replace( '/[^0-9\.\-]/', '', (string) $value );
		}

		/**
		 * Enqueue admin assets for the Zencoin settings page.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public static function enqueue_admin_settings_assets( $hook ) {
			if ( 'woocommerce_page_cbb-zencoin-settings' !== $hook ) {
				return;
			}

			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		/**
		 * Get configured member recovery product ID.
		 *
		 * @return int
		 */
		public static function get_member_recovery_product_id() {
			$settings   = self::get_settings();
			$product_id = isset( $settings['member_recovery_product_id'] ) ? absint( $settings['member_recovery_product_id'] ) : 0;

			return absint( apply_filters( 'cbb_member_recovery_product_id', $product_id ) );
		}

		/**
		 * Render settings page.
		 */
		public static function render_settings_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$settings = self::get_settings();
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Zencoin Settings', 'coin-booking-bridge' ); ?></h1>
				<p><?php esc_html_e( 'Central Zencoin rules for package, drop-in, membership, gift-card, and booking flows. Package and drop-in validity settings use calendar months when expiring granted Zencoin buckets.', 'coin-booking-bridge' ); ?></p>

				<?php self::render_system_status_panel(); ?>

				<form method="post" action="options.php">
					<?php settings_fields( 'cbb_zencoin_settings' ); ?>

					<h2><?php esc_html_e( 'Pricing', 'coin-booking-bridge' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php self::render_number_setting_row( 'coin_value_eur', __( 'EUR value per Zencoin', 'coin-booking-bridge' ), $settings, '0.01' ); ?>
						<?php self::render_number_setting_row( 'standard_session_cost', __( 'Standard session cost (ZC)', 'coin-booking-bridge' ), $settings, '0.01' ); ?>
						<?php self::render_number_setting_row( 'workshop_tier_a_cost', __( 'Workshop tier A cost (ZC)', 'coin-booking-bridge' ), $settings, '0.01' ); ?>
						<?php self::render_number_setting_row( 'workshop_tier_b_cost', __( 'Workshop tier B cost (ZC)', 'coin-booking-bridge' ), $settings, '0.01' ); ?>
						<?php self::render_number_setting_row( 'workshop_tier_c_cost', __( 'Workshop tier C cost (ZC)', 'coin-booking-bridge' ), $settings, '0.01' ); ?>
						<?php self::render_checkbox_setting_row( 'auto_calculate_booking_cost', __( 'Auto-calculate booking ZC cost from product price', 'coin-booking-bridge' ), $settings ); ?>
					</table>

					<h2><?php esc_html_e( 'Validity', 'coin-booking-bridge' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php self::render_number_setting_row( 'free_dropin_validity_months', __( 'Free drop-in validity (months)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'dropin_validity_months', __( 'Drop-in validity (months)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'package_small_validity_months', __( 'Small package validity (months)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'package_medium_validity_months', __( 'Medium package validity (months)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'package_large_validity_months', __( 'Large package validity (months)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'gift_card_validity_days', __( 'Gift card validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'newsletter_discount_validity_days', __( 'Newsletter discount validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
					</table>

					<h2><?php esc_html_e( 'Rules', 'coin-booking-bridge' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php self::render_number_setting_row( 'on_time_cancel_cutoff_hours', __( 'On-time cancellation cutoff (hours)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_checkbox_setting_row( 'wallet_freeze_on_subscription_on_hold', __( 'Freeze wallet when membership subscription is on-hold', 'coin-booking-bridge' ), $settings ); ?>
						<?php self::render_checkbox_setting_row( 'tera_wallet_mirror_enabled', __( 'Mirror CBB balance changes to Tera Wallet', 'coin-booking-bridge' ), $settings ); ?>
					</table>

					<h2><?php esc_html_e( 'Recovery', 'coin-booking-bridge' ); ?></h2>
					<p><?php esc_html_e( 'Products used by checkout recovery flows when a member needs an exact Zencoin top-up.', 'coin-booking-bridge' ); ?></p>
					<table class="form-table" role="presentation">
						<?php self::render_product_setting_row( 'member_recovery_product_id', __( 'Member recovery product', 'coin-booking-bridge' ), $settings, __( 'Select the hidden virtual product used for dynamic member Zencoin top-ups.', 'coin-booking-bridge' ) ); ?>
					</table>

					<h2><?php esc_html_e( 'Global Zencoin Display', 'coin-booking-bridge' ); ?></h2>
					<p><?php esc_html_e( 'Shared coin badge markup, styling, and tooltip used across theme blocks and custom plugins.', 'coin-booking-bridge' ); ?></p>
					<table class="form-table" role="presentation">
						<?php self::render_checkbox_setting_row( 'zencoin_global_coin_enabled', __( 'Enable global Zencoin coin styling and replacement', 'coin-booking-bridge' ), $settings ); ?>
						<?php self::render_textarea_setting_row( 'zencoin_tooltip', __( 'Zencoin tooltip', 'coin-booking-bridge' ), $settings ); ?>
					</table>

					<?php submit_button(); ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Render system status panel.
		 */
		private static function render_system_status_panel() {
			$db_version     = get_option( self::OPTION_DB_VERSION, '' );
			$buckets_table  = self::get_buckets_table_name();
			$ledger_table   = self::get_ledger_table_name();
			$buckets_exists = self::table_exists( $buckets_table );
			$ledger_exists  = self::table_exists( $ledger_table );
			$expired_count  = isset( $_GET['cbb_expired_buckets'] ) ? absint( wp_unslash( $_GET['cbb_expired_buckets'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<h2><?php esc_html_e( 'System Status', 'coin-booking-bridge' ); ?></h2>
			<?php if ( null !== $expired_count ) : ?>
				<div class="notice notice-success inline">
					<p>
						<?php
						printf(
							/* translators: %s: expired bucket count */
							esc_html__( 'Zencoin expiry run complete. Expired buckets: %s.', 'coin-booking-bridge' ),
							esc_html( number_format_i18n( $expired_count ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<table class="widefat striped" style="max-width: 900px; margin-bottom: 24px;">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugin version', 'coin-booking-bridge' ); ?></th>
						<td><?php echo esc_html( self::VERSION ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Installed DB version', 'coin-booking-bridge' ); ?></th>
						<td>
							<?php echo esc_html( $db_version ? $db_version : __( 'Not installed', 'coin-booking-bridge' ) ); ?>
							<?php if ( $db_version !== self::DB_VERSION ) : ?>
								<span style="color: #b32d2e;"><?php esc_html_e( 'Update needed', 'coin-booking-bridge' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html( $buckets_table ); ?></th>
						<td><?php echo $buckets_exists ? esc_html__( 'Ready', 'coin-booking-bridge' ) : esc_html__( 'Missing', 'coin-booking-bridge' ); ?></td>
					</tr>
					<?php if ( $buckets_exists ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Bucket records', 'coin-booking-bridge' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( self::get_table_row_count( $buckets_table ) ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Expired active buckets ready for cleanup', 'coin-booking-bridge' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( self::get_expirable_bucket_count() ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html( $ledger_table ); ?></th>
						<td><?php echo $ledger_exists ? esc_html__( 'Ready', 'coin-booking-bridge' ) : esc_html__( 'Missing', 'coin-booking-bridge' ); ?></td>
					</tr>
					<?php if ( $ledger_exists ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ledger entries', 'coin-booking-bridge' ); ?></th>
							<td><?php echo esc_html( number_format_i18n( self::get_table_row_count( $ledger_table ) ) ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Runtime mode', 'coin-booking-bridge' ); ?></th>
						<td><?php esc_html_e( 'Current MVP credit/debit behavior is still active. Bucket and ledger tables are prepared but not yet used for live transactions.', 'coin-booking-bridge' ); ?></td>
					</tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: -12px 0 24px;">
				<input type="hidden" name="action" value="cbb_run_zencoin_expiry" />
				<?php wp_nonce_field( 'cbb_run_zencoin_expiry' ); ?>
				<?php submit_button( __( 'Run Zencoin expiry now', 'coin-booking-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
		}

		/**
		 * Check whether a DB table exists.
		 *
		 * @param string $table_name Table name.
		 * @return bool
		 */
		private static function table_exists( $table_name ) {
			global $wpdb;

			return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
		}

		/**
		 * Get table row count for diagnostics.
		 *
		 * @param string $table_name Table name.
		 * @return int
		 */
		private static function get_table_row_count( $table_name ) {
			global $wpdb;

			if ( ! self::table_exists( $table_name ) ) {
				return 0;
			}

			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		/**
		 * Get count of active buckets whose expiry time has passed.
		 *
		 * @return int
		 */
		private static function get_expirable_bucket_count() {
			global $wpdb;

			$table = self::get_buckets_table_name();

			if ( ! self::table_exists( $table ) ) {
				return 0;
			}

			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE status = %s AND remaining_amount > 0 AND expires_at IS NOT NULL AND expires_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'active',
					current_time( 'mysql' )
				)
			);
		}

		/**
		 * Create a Zencoin bucket.
		 *
		 * This helper is prepared for upcoming grant flows. Current MVP transactions do not call it yet.
		 *
		 * @param int   $user_id User ID.
		 * @param float $amount  ZC amount.
		 * @param array $args    Bucket args.
		 * @return int|false Bucket ID on success, false on failure.
		 */
		public static function create_zencoin_bucket( $user_id, $amount, $args = array() ) {
			global $wpdb;

			$user_id = absint( $user_id );
			$amount  = (float) $amount;

			if ( $user_id <= 0 || $amount <= 0 ) {
				return false;
			}

			$defaults = array(
				'source_type'             => 'manual',
				'status'                  => 'active',
				'expires_at'              => null,
				'related_order_id'        => null,
				'related_order_item_id'   => null,
				'related_product_id'      => null,
				'related_subscription_id' => null,
				'related_booking_id'      => null,
				'related_coupon_id'       => null,
				'source_label'            => '',
				'metadata'                => array(),
			);
			$args     = wp_parse_args( $args, $defaults );
			$now      = current_time( 'mysql' );

			$inserted = $wpdb->insert(
				self::get_buckets_table_name(),
				array(
					'user_id'                 => $user_id,
					'source_type'             => sanitize_key( $args['source_type'] ),
					'status'                  => sanitize_key( $args['status'] ),
					'original_amount'         => self::format_zencoin_amount( $amount ),
					'remaining_amount'        => self::format_zencoin_amount( $amount ),
					'expires_at'              => self::sanitize_datetime_or_null( $args['expires_at'] ),
					'created_at'              => $now,
					'updated_at'              => $now,
					'related_order_id'        => self::nullable_absint( $args['related_order_id'] ),
					'related_order_item_id'   => self::nullable_absint( $args['related_order_item_id'] ),
					'related_product_id'      => self::nullable_absint( $args['related_product_id'] ),
					'related_subscription_id' => self::nullable_absint( $args['related_subscription_id'] ),
					'related_booking_id'      => self::nullable_absint( $args['related_booking_id'] ),
					'related_coupon_id'       => self::nullable_absint( $args['related_coupon_id'] ),
					'source_label'            => sanitize_text_field( $args['source_label'] ),
					'metadata'                => self::encode_metadata( $args['metadata'] ),
				),
				array( '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
			);

			return $inserted ? (int) $wpdb->insert_id : false;
		}

		/**
		 * Add an append-only Zencoin ledger entry.
		 *
		 * This helper is prepared for upcoming grant/debit/refund flows. Current MVP transactions do not call it yet.
		 *
		 * @param int   $user_id User ID.
		 * @param float $amount  ZC amount.
		 * @param array $args    Ledger args.
		 * @return int|false Ledger entry ID on success, false on failure.
		 */
		public static function add_zencoin_ledger_entry( $user_id, $amount, $args = array() ) {
			global $wpdb;

			$user_id = absint( $user_id );
			$amount  = (float) $amount;

			if ( $user_id <= 0 || $amount <= 0 ) {
				return false;
			}

			$defaults = array(
				'bucket_id'               => null,
				'entry_type'              => 'manual_adjustment',
				'direction'               => 'credit',
				'balance_after'           => null,
				'label'                   => __( 'Manual adjustment', 'coin-booking-bridge' ),
				'related_order_id'        => null,
				'related_order_item_id'   => null,
				'related_product_id'      => null,
				'related_subscription_id' => null,
				'related_booking_id'      => null,
				'related_coupon_id'       => null,
				'metadata'                => array(),
			);
			$args     = wp_parse_args( $args, $defaults );
			$inserted = $wpdb->insert(
				self::get_ledger_table_name(),
				array(
					'user_id'                 => $user_id,
					'bucket_id'               => self::nullable_absint( $args['bucket_id'] ),
					'entry_type'              => sanitize_key( $args['entry_type'] ),
					'direction'               => self::sanitize_ledger_direction( $args['direction'] ),
					'amount'                  => self::format_zencoin_amount( $amount ),
					'balance_after'           => null === $args['balance_after'] ? null : self::format_zencoin_amount( $args['balance_after'] ),
					'label'                   => sanitize_text_field( $args['label'] ),
					'created_at'              => current_time( 'mysql' ),
					'related_order_id'        => self::nullable_absint( $args['related_order_id'] ),
					'related_order_item_id'   => self::nullable_absint( $args['related_order_item_id'] ),
					'related_product_id'      => self::nullable_absint( $args['related_product_id'] ),
					'related_subscription_id' => self::nullable_absint( $args['related_subscription_id'] ),
					'related_booking_id'      => self::nullable_absint( $args['related_booking_id'] ),
					'related_coupon_id'       => self::nullable_absint( $args['related_coupon_id'] ),
					'metadata'                => self::encode_metadata( $args['metadata'] ),
				),
				array( '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
			);

			return $inserted ? (int) $wpdb->insert_id : false;
		}

		/**
		 * Expire active buckets whose expiry datetime has passed.
		 *
		 * @return int Number of expired buckets.
		 */
		public static function expire_zencoin_buckets() {
			global $wpdb;

			$table = self::get_buckets_table_name();

			if ( ! self::table_exists( $table ) ) {
				return 0;
			}

			$now     = current_time( 'mysql' );
			$buckets = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s AND remaining_amount > 0 AND expires_at IS NOT NULL AND expires_at <= %s ORDER BY expires_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'active',
					$now
				)
			);

			$expired = 0;

			foreach ( $buckets as $bucket ) {
				$remaining = (float) $bucket->remaining_amount;

				if ( $remaining <= 0 ) {
					continue;
				}

				$updated = $wpdb->update(
					$table,
					array(
						'remaining_amount' => self::format_zencoin_amount( 0 ),
						'status'           => 'expired',
						'updated_at'       => $now,
					),
					array( 'id' => (int) $bucket->id ),
					array( '%f', '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					continue;
				}

				self::add_zencoin_ledger_entry(
					(int) $bucket->user_id,
					$remaining,
					array(
						'bucket_id'               => (int) $bucket->id,
						'entry_type'              => 'expired',
						'direction'               => 'debit',
						'balance_after'           => self::get_zencoin_bucket_balance( (int) $bucket->user_id ),
						'label'                   => __( 'Zencoins expired', 'coin-booking-bridge' ),
						'related_order_id'        => $bucket->related_order_id,
						'related_order_item_id'   => $bucket->related_order_item_id,
						'related_product_id'      => $bucket->related_product_id,
						'related_subscription_id' => $bucket->related_subscription_id,
						'related_booking_id'      => $bucket->related_booking_id,
						'metadata'                => array(
							'expired_at'  => $now,
							'expires_at'  => $bucket->expires_at,
							'source_type' => $bucket->source_type,
						),
					)
				);

				$expired++;
			}

			return $expired;
		}

		/**
		 * Handle manual expiry run from the admin status panel.
		 */
		public static function handle_manual_expiry_run() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have permission to run Zencoin expiry.', 'coin-booking-bridge' ) );
			}

			check_admin_referer( 'cbb_run_zencoin_expiry' );

			$expired = self::expire_zencoin_buckets();
			$url     = add_query_arg(
				array(
					'page'                => 'cbb-zencoin-settings',
					'cbb_expired_buckets' => $expired,
				),
				admin_url( 'admin.php' )
			);

			wp_safe_redirect( $url );
			exit;
		}

		/**
		 * Get active bucket balance for a user.
		 *
		 * @param int $user_id User ID.
		 * @return float
		 */
		public static function get_zencoin_bucket_balance( $user_id ) {
			global $wpdb;

			$user_id = absint( $user_id );
			if ( $user_id <= 0 ) {
				return 0.0;
			}

			$table = self::get_buckets_table_name();

			if ( ! self::table_exists( $table ) ) {
				return 0.0;
			}

			$now = current_time( 'mysql' );

			return (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(remaining_amount), 0) FROM {$table} WHERE user_id = %d AND status = %s AND remaining_amount > 0 AND (expires_at IS NULL OR expires_at > %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					'active',
					$now
				)
			);
		}

		/**
		 * Debit Zencoins from active buckets.
		 *
		 * Consumption order is membership buckets first, then purchased credits by earliest expiry.
		 *
		 * @param int   $user_id User ID.
		 * @param float $amount  ZC amount.
		 * @param array $args    Debit args.
		 * @return array|false Consumption rows or false.
		 */
		public static function debit_zencoin_buckets( $user_id, $amount, $args = array() ) {
			global $wpdb;

			$user_id = absint( $user_id );
			$amount  = (float) $amount;

			if ( $user_id <= 0 || $amount <= 0 ) {
				return false;
			}

			if ( self::get_zencoin_bucket_balance( $user_id ) + 0.000001 < $amount ) {
				return false;
			}

			$defaults = array(
				'entry_type'            => 'booking_charge',
				'label'                 => __( 'Booking charge', 'coin-booking-bridge' ),
				'related_order_id'      => null,
				'related_order_item_id' => null,
				'related_product_id'    => null,
				'related_booking_id'    => null,
				'metadata'              => array(),
			);
			$args     = wp_parse_args( $args, $defaults );
			$remaining = $amount;
			$now       = current_time( 'mysql' );
			$table     = self::get_buckets_table_name();

			$buckets = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d AND status = %s AND remaining_amount > 0 AND (expires_at IS NULL OR expires_at > %s) ORDER BY CASE WHEN source_type = 'membership' THEN 0 ELSE 1 END ASC, expires_at IS NULL ASC, expires_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					'active',
					$now
				)
			);

			$consumption = array();

			foreach ( $buckets as $bucket ) {
				if ( $remaining <= 0 ) {
					break;
				}

				$bucket_remaining = (float) $bucket->remaining_amount;
				$debit_amount     = min( $bucket_remaining, $remaining );
				$new_remaining    = max( 0, $bucket_remaining - $debit_amount );
				$new_status       = $new_remaining > 0 ? 'active' : 'consumed';

				$updated = $wpdb->update(
					$table,
					array(
						'remaining_amount' => self::format_zencoin_amount( $new_remaining ),
						'status'           => $new_status,
						'updated_at'       => $now,
					),
					array( 'id' => (int) $bucket->id ),
					array( '%f', '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					return false;
				}

				$ledger_id = self::add_zencoin_ledger_entry(
					$user_id,
					$debit_amount,
					array(
						'bucket_id'             => (int) $bucket->id,
						'entry_type'            => $args['entry_type'],
						'direction'             => 'debit',
						'balance_after'         => self::get_zencoin_bucket_balance( $user_id ),
						'label'                 => $args['label'],
						'related_order_id'      => $args['related_order_id'],
						'related_order_item_id' => $args['related_order_item_id'],
						'related_product_id'    => $args['related_product_id'],
						'related_booking_id'    => $args['related_booking_id'],
						'metadata'              => $args['metadata'],
					)
				);

				$consumption[] = array(
					'bucket_id'    => (int) $bucket->id,
					'ledger_id'    => $ledger_id,
					'amount'       => self::format_zencoin_amount( $debit_amount ),
					'source_type'  => $bucket->source_type,
					'expires_at'   => $bucket->expires_at,
				);

				$remaining -= $debit_amount;
			}

			if ( $remaining > 0.000001 ) {
				return false;
			}

			return $consumption;
		}

		/**
		 * Sanitize nullable integer.
		 *
		 * @param mixed $value Value.
		 * @return int|null
		 */
		private static function nullable_absint( $value ) {
			if ( null === $value || '' === $value ) {
				return null;
			}

			$value = absint( $value );

			return $value > 0 ? $value : null;
		}

		/**
		 * Format a Zencoin amount for DB storage.
		 *
		 * @param mixed $amount Amount.
		 * @return string
		 */
		private static function format_zencoin_amount( $amount ) {
			return number_format( (float) $amount, 6, '.', '' );
		}

		/**
		 * Sanitize datetime value or return null.
		 *
		 * @param mixed $value Datetime value.
		 * @return string|null
		 */
		private static function sanitize_datetime_or_null( $value ) {
			if ( empty( $value ) ) {
				return null;
			}

			$timestamp = strtotime( (string) $value );

			return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
		}

		/**
		 * Encode metadata for storage.
		 *
		 * @param mixed $metadata Metadata.
		 * @return string|null
		 */
		private static function encode_metadata( $metadata ) {
			if ( empty( $metadata ) ) {
				return null;
			}

			return wp_json_encode( $metadata );
		}

		/**
		 * Sanitize ledger direction.
		 *
		 * @param string $direction Direction.
		 * @return string
		 */
		private static function sanitize_ledger_direction( $direction ) {
			return in_array( $direction, array( 'credit', 'debit' ), true ) ? $direction : 'credit';
		}

		/**
		 * Render product selector setting row.
		 *
		 * @param string $key         Setting key.
		 * @param string $label       Field label.
		 * @param array  $settings    Settings.
		 * @param string $description Field description.
		 */
		private static function render_product_setting_row( $key, $label, $settings, $description = '' ) {
			$product_id = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;
			$product    = $product_id ? wc_get_product( $product_id ) : null;
			$selected   = $product ? wp_strip_all_tags( $product->get_formatted_name() ) : '';
			?>
			<tr>
				<th scope="row"><label for="cbb_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<select
						id="cbb_<?php echo esc_attr( $key ); ?>"
						name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[<?php echo esc_attr( $key ); ?>]"
						class="wc-product-search"
						style="width: 350px;"
						data-placeholder="<?php esc_attr_e( 'Search for a product...', 'coin-booking-bridge' ); ?>"
						data-action="woocommerce_json_search_products_and_variations"
						data-allow_clear="true"
					>
						<?php if ( $product_id && $selected ) : ?>
							<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected"><?php echo esc_html( $selected ); ?></option>
						<?php endif; ?>
					</select>
					<?php if ( $description ) : ?>
						<p class="description"><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render number setting row.
		 *
		 * @param string $key      Setting key.
		 * @param string $label    Field label.
		 * @param array  $settings Settings.
		 * @param string $step     Input step.
		 */
		private static function render_number_setting_row( $key, $label, $settings, $step ) {
			?>
			<tr>
				<th scope="row"><label for="cbb_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<input
						type="number"
						id="cbb_<?php echo esc_attr( $key ); ?>"
						name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[<?php echo esc_attr( $key ); ?>]"
						value="<?php echo esc_attr( $settings[ $key ] ); ?>"
						min="0"
						step="<?php echo esc_attr( $step ); ?>"
						class="regular-text"
					/>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render checkbox setting row.
		 *
		 * @param string $key      Setting key.
		 * @param string $label    Field label.
		 * @param array  $settings Settings.
		 */
		private static function render_checkbox_setting_row( $key, $label, $settings ) {
			?>
			<tr>
				<th scope="row"><?php echo esc_html( $label ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[<?php echo esc_attr( $key ); ?>]"
							value="1"
							<?php checked( 'yes', $settings[ $key ] ); ?>
						/>
						<?php esc_html_e( 'Enabled', 'coin-booking-bridge' ); ?>
					</label>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render textarea setting row.
		 *
		 * @param string $key      Setting key.
		 * @param string $label    Field label.
		 * @param array  $settings Settings.
		 */
		private static function render_textarea_setting_row( $key, $label, $settings ) {
			?>
			<tr>
				<th scope="row"><label for="cbb_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
				<td>
					<textarea
						id="cbb_<?php echo esc_attr( $key ); ?>"
						name="<?php echo esc_attr( self::OPTION_SETTINGS ); ?>[<?php echo esc_attr( $key ); ?>]"
						rows="3"
						class="large-text"
					><?php echo esc_textarea( $settings[ $key ] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown on hover/focus for every global Zencoin badge.', 'coin-booking-bridge' ); ?></p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Enqueue global Zencoin frontend assets.
		 */
		public static function enqueue_global_coin_assets() {
			$settings = self::get_settings();

			if ( 'yes' !== $settings['zencoin_global_coin_enabled'] ) {
				return;
			}

			$asset_url  = plugin_dir_url( __FILE__ ) . 'assets/';
			$asset_path = plugin_dir_path( __FILE__ ) . 'assets/';

			wp_enqueue_style(
				'cbb-zencoin-global',
				$asset_url . 'css/cbb-zencoin-global.css',
				array(),
				self::get_asset_version( $asset_path . 'css/cbb-zencoin-global.css' )
			);

			wp_enqueue_script(
				'cbb-zencoin-global',
				$asset_url . 'js/cbb-zencoin-global.js',
				array(),
				self::get_asset_version( $asset_path . 'js/cbb-zencoin-global.js' ),
				true
			);

			wp_localize_script(
				'cbb-zencoin-global',
				'cbbZencoinGlobal',
				array(
					'tooltip'  => $settings['zencoin_tooltip'],
					'selectors' => array(
						'[data-cbb-zencoin-value]',
						'[data-zencoin-value]',
						'.zpb-membership-card__coin',
						'.zpb-package-card__coin',
						'.zpb-dropin-card__coin',
						'.zen-what-zencoins-coin',
						'.zen-zencoins-badge',
						'.pfc__zencoin-badge',
						'.zen-coin-icon',
						'.zbp-coins',
						'.zbp-join-zencoins',
					),
					'rangeSelectors' => array(
						'[data-cbb-zencoin-range]',
						'[class*="coin-range"]',
						'[class*="range-coin"]',
					),
				)
			);
		}

		/**
		 * Enqueue Zencoin wallet endpoint styles only on the wallet account route.
		 */
		public static function enqueue_wallet_endpoint_assets() {
			if ( ! self::is_zencoin_wallet_endpoint() ) {
				return;
			}

			$asset_url  = plugin_dir_url( __FILE__ ) . 'assets/';
			$asset_path = plugin_dir_path( __FILE__ ) . 'assets/';

			wp_enqueue_style(
				'cbb-zencoin-global',
				$asset_url . 'css/cbb-zencoin-global.css',
				array(),
				self::get_asset_version( $asset_path . 'css/cbb-zencoin-global.css' )
			);

			wp_enqueue_style(
				'cbb-zencoin-wallet',
				$asset_url . 'css/cbb-zencoin-wallet.css',
				array( 'cbb-zencoin-global' ),
				self::get_asset_version( $asset_path . 'css/cbb-zencoin-wallet.css' )
			);
		}

		/**
		 * Get a cache-busting asset version from the file modification time.
		 *
		 * @param string $path Absolute asset path.
		 * @return string
		 */
		private static function get_asset_version( $path ) {
			return file_exists( $path ) ? (string) filemtime( $path ) : self::VERSION;
		}

		/**
		 * Check if the current request is the Woo Wallet account endpoint.
		 *
		 * @return bool
		 */
		private static function is_zencoin_wallet_endpoint() {
			return function_exists( 'is_account_page' )
				&& is_account_page()
				&& function_exists( 'is_wc_endpoint_url' )
				&& is_wc_endpoint_url( 'woo-wallet' );
		}

		/**
		 * Render the custom Zencoin wallet account endpoint.
		 */
		public static function render_zencoin_wallet_endpoint() {
			if ( ! is_user_logged_in() ) {
				return;
			}

			if ( function_exists( 'is_wallet_account_locked' ) && is_wallet_account_locked() && function_exists( 'woo_wallet' ) ) {
				woo_wallet()->get_template( 'no-access.php' );
				return;
			}

			$user_id      = get_current_user_id();
			$user         = wp_get_current_user();
			$balance      = self::get_available_coin_balance( $user_id );
			$transactions = self::get_zencoin_wallet_activity( $user_id );
			$has_activity = ! empty( $transactions );
			$topup_url    = apply_filters( 'cbb_zencoin_wallet_topup_url', home_url( '/prices/' ) );
			$redeem_url   = apply_filters( 'cbb_zencoin_wallet_redeem_url', '#' );

			?>
			<div class="cbb-zencoin-wallet" data-cbb-zencoin-wallet>
				<section class="cbb-zencoin-wallet__profile">
					<?php echo get_avatar( $user_id, 64, '', '', array( 'class' => 'cbb-zencoin-wallet__avatar' ) ); ?>
					<div>
						<strong><?php echo esc_html( $user->display_name ? $user->display_name : $user->user_login ); ?></strong>
						<span><?php echo esc_html( $user->user_email ); ?></span>
					</div>
				</section>

				<section class="cbb-zencoin-wallet__balance" aria-label="<?php echo esc_attr__( 'Current Zencoin balance', 'coin-booking-bridge' ); ?>">
					<div class="cbb-zencoin-wallet__balance-label">
						<span><?php esc_html_e( 'Current Balance:', 'coin-booking-bridge' ); ?></span>
						<strong><?php esc_html_e( 'ZENCOINS:', 'coin-booking-bridge' ); ?></strong>
					</div>
					<?php echo self::render_global_zencoin_coin( self::format_zencoin_display_amount( $balance ), array( 'class' => 'cbb-zencoin-wallet__coin' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>

				<a class="cbb-zencoin-wallet__topup" href="<?php echo esc_url( $topup_url ); ?>" data-zcf-open-checkout="choose_plan">
					<?php esc_html_e( 'Top-Up Zencoins', 'coin-booking-bridge' ); ?>
				</a>
				<a class="cbb-zencoin-wallet__redeem" href="<?php echo esc_url( $redeem_url ); ?>">
					<?php esc_html_e( 'Redeem voucher code', 'coin-booking-bridge' ); ?>
				</a>

				<?php if ( ! $has_activity ) : ?>
					<p class="cbb-zencoin-wallet__empty"><?php esc_html_e( 'No activity yet', 'coin-booking-bridge' ); ?></p>
				<?php else : ?>
					<section class="cbb-zencoin-wallet__activity">
						<h2><?php esc_html_e( 'Recent Activity:', 'coin-booking-bridge' ); ?></h2>
						<input class="cbb-zencoin-wallet__filter" type="radio" name="cbb_zencoin_wallet_filter" id="cbb-zencoin-filter-all" checked />
						<input class="cbb-zencoin-wallet__filter" type="radio" name="cbb_zencoin_wallet_filter" id="cbb-zencoin-filter-added" />
						<input class="cbb-zencoin-wallet__filter" type="radio" name="cbb_zencoin_wallet_filter" id="cbb-zencoin-filter-used" />

						<div class="cbb-zencoin-wallet__tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Transaction filters', 'coin-booking-bridge' ); ?>">
							<label for="cbb-zencoin-filter-all"><?php esc_html_e( 'All', 'coin-booking-bridge' ); ?></label>
							<label for="cbb-zencoin-filter-added"><?php esc_html_e( 'Added', 'coin-booking-bridge' ); ?></label>
							<label for="cbb-zencoin-filter-used"><?php esc_html_e( 'Used', 'coin-booking-bridge' ); ?></label>
						</div>

						<div class="cbb-zencoin-wallet__list">
							<?php foreach ( $transactions as $transaction ) : ?>
								<?php self::render_zencoin_wallet_activity_item( $transaction ); ?>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render one Zencoin activity item.
		 *
		 * @param array $transaction Transaction row.
		 */
		private static function render_zencoin_wallet_activity_item( $transaction ) {
			$direction = 'debit' === $transaction['direction'] ? 'used' : 'added';
			$amount    = self::format_zencoin_display_amount( $transaction['amount'] );
			$date      = $transaction['created_at'] ? mysql2date( 'd M Y', $transaction['created_at'] ) : '';
			$expires   = ! empty( $transaction['expires_at'] ) ? mysql2date( 'd M Y', $transaction['expires_at'] ) : '';
			?>
			<article class="cbb-zencoin-wallet__item cbb-zencoin-wallet__item--<?php echo esc_attr( $direction ); ?>" data-cbb-zencoin-activity="<?php echo esc_attr( $direction ); ?>">
				<div class="cbb-zencoin-wallet__item-head">
					<strong><?php echo esc_html( ( 'used' === $direction ? '- ' : '+ ' ) . $amount . ' ZC' ); ?></strong>
					<span><?php echo esc_html( $date ); ?></span>
				</div>
				<div class="cbb-zencoin-wallet__item-label"><?php echo esc_html( $transaction['label'] ); ?></div>
				<?php if ( $expires && 'used' !== $direction ) : ?>
					<div class="cbb-zencoin-wallet__item-expiry">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: expiry date */
								__( 'Expires %s', 'coin-booking-bridge' ),
								$expires
							)
						);
						?>
					</div>
				<?php endif; ?>
			</article>
			<?php
		}

		/**
		 * Get Zencoin wallet activity from CBB ledger, with Tera Wallet fallback.
		 *
		 * @param int $user_id User ID.
		 * @return array
		 */
		private static function get_zencoin_wallet_activity( $user_id ) {
			$ledger_activity = self::get_zencoin_ledger_activity( $user_id );

			if ( ! empty( $ledger_activity ) ) {
				return $ledger_activity;
			}

			return self::get_tera_wallet_activity_fallback( $user_id );
		}

		/**
		 * Get customer activity from the CBB ledger.
		 *
		 * @param int $user_id User ID.
		 * @return array
		 */
		private static function get_zencoin_ledger_activity( $user_id ) {
			global $wpdb;

			$ledger_table  = self::get_ledger_table_name();
			$buckets_table = self::get_buckets_table_name();
			$has_buckets   = self::table_exists( $buckets_table );

			if ( ! self::table_exists( $ledger_table ) ) {
				return array();
			}

			$select_expiry = $has_buckets ? 'buckets.expires_at' : 'NULL AS expires_at';
			$join_buckets  = $has_buckets ? "LEFT JOIN {$buckets_table} AS buckets ON ledger.bucket_id = buckets.id" : '';

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ledger.id, ledger.direction, ledger.amount, ledger.label, ledger.created_at, {$select_expiry}
					FROM {$ledger_table} AS ledger
					{$join_buckets}
					WHERE ledger.user_id = %d
					ORDER BY ledger.created_at DESC, ledger.id DESC
					LIMIT 100",
					$user_id
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				return array();
			}

			return array_map(
				static function ( $row ) {
					return array(
						'id'         => absint( $row['id'] ),
						'direction'  => sanitize_key( $row['direction'] ),
						'amount'     => (float) $row['amount'],
						'label'      => wp_strip_all_tags( (string) $row['label'] ),
						'created_at' => sanitize_text_field( (string) $row['created_at'] ),
						'expires_at' => ! empty( $row['expires_at'] ) ? sanitize_text_field( (string) $row['expires_at'] ) : '',
					);
				},
				$rows
			);
		}

		/**
		 * Get Tera Wallet transactions if the CBB ledger has no activity yet.
		 *
		 * @param int $user_id User ID.
		 * @return array
		 */
		private static function get_tera_wallet_activity_fallback( $user_id ) {
			if ( ! function_exists( 'get_wallet_transactions' ) ) {
				return array();
			}

			$transactions = get_wallet_transactions(
				array(
					'user_id'  => $user_id,
					'limit'    => 100,
					'order_by' => 'transaction_id',
					'order'    => 'DESC',
				)
			);

			if ( empty( $transactions ) ) {
				return array();
			}

			return array_map(
				static function ( $transaction ) {
					return array(
						'id'         => absint( $transaction->transaction_id ),
						'direction'  => 'debit' === $transaction->type ? 'debit' : 'credit',
						'amount'     => (float) $transaction->amount,
						'label'      => wp_strip_all_tags( (string) $transaction->details ),
						'created_at' => sanitize_text_field( (string) $transaction->date ),
						'expires_at' => '',
					);
				},
				$transactions
			);
		}

		/**
		 * Format a Zencoin amount for display.
		 *
		 * @param float $amount Amount.
		 * @return string
		 */
		private static function format_zencoin_display_amount( $amount ) {
			$amount = (float) $amount;

			if ( abs( $amount - round( $amount ) ) < 0.000001 ) {
				return (string) absint( round( $amount ) );
			}

			return wc_format_decimal( $amount, 2 );
		}

		/**
		 * Render a reusable global Zencoin coin badge.
		 *
		 * @param mixed $value Coin value.
		 * @param array $args  Optional render args.
		 * @return string
		 */
		public static function render_global_zencoin_coin( $value = '', $args = array() ) {
			$settings = self::get_settings();
			$args     = wp_parse_args(
				is_array( $args ) ? $args : array(),
				array(
					'class'   => '',
					'tooltip' => $settings['zencoin_tooltip'],
				)
			);
			$value    = trim( wp_strip_all_tags( (string) $value ) );
			$extra_classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $args['class'] ) ) );
			$classes  = trim( 'zen-coin-global ' . implode( ' ', $extra_classes ) );
			$tooltip  = trim( wp_strip_all_tags( (string) $args['tooltip'] ) );
			$label    = '' !== $tooltip
				? sprintf( __( '%1$s Zencoins. %2$s', 'coin-booking-bridge' ), $value, $tooltip )
				: sprintf( __( '%s Zencoins', 'coin-booking-bridge' ), $value );

			ob_start();
			?>
			<span
				class="<?php echo esc_attr( $classes ); ?>"
				data-cbb-zencoin="1"
				data-zencoin-value="<?php echo esc_attr( $value ); ?>"
				<?php if ( '' !== $tooltip ) : ?>
					data-zencoin-tooltip="<?php echo esc_attr( $tooltip ); ?>"
				<?php endif; ?>
				aria-label="<?php echo esc_attr( $label ); ?>"
				tabindex="0"
			>
				<span class="zen-coin-global__ring"></span>
				<span class="zen-coin-global__value"><?php echo esc_html( $value ); ?></span>
			</span>
			<?php

			return trim( ob_get_clean() );
		}

		/**
		 * Render shortcode: [zencoin_coin value="5"].
		 *
		 * @param array $atts Shortcode attributes.
		 * @return string
		 */
		public static function render_global_coin_shortcode( $atts ) {
			$atts = shortcode_atts(
				array(
					'value'   => '',
					'class'   => '',
					'tooltip' => null,
				),
				(array) $atts,
				'zencoin_coin'
			);

			$args = array(
				'class' => $atts['class'],
			);

			if ( null !== $atts['tooltip'] ) {
				$args['tooltip'] = $atts['tooltip'];
			}

			return self::render_global_zencoin_coin( $atts['value'], $args );
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
			echo '<div class="options_group">';

			woocommerce_wp_select(
				array(
					'id'          => self::META_PRODUCT_TYPE,
					'label'       => __( 'Zencoin product type', 'coin-booking-bridge' ),
					'description' => __( 'Classifies non-booking products that grant Zencoins when a paid order is processed.', 'coin-booking-bridge' ),
					'desc_tip'    => true,
					'options'     => self::get_zencoin_product_type_options(),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => self::META_ZC_GRANT_AMOUNT,
					'label'             => __( 'Zencoins granted on paid order', 'coin-booking-bridge' ),
					'description'       => __( 'For packages, drop-ins, and free drop-in trials, this amount is granted into an expiring Zencoin bucket after payment.', 'coin-booking-bridge' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'custom_attributes' => array(
						'step' => '0.01',
						'min'  => '0',
					),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => self::META_VALIDITY_MONTHS,
					'value'             => self::get_product_validity_months_field_value( get_the_ID() ),
					'label'             => __( 'Zencoin validity months', 'coin-booking-bridge' ),
					'description'       => __( 'Limits how many calendar months granted Zencoins remain available after purchase. Leave empty to use the central default for the selected product type.', 'coin-booking-bridge' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'custom_attributes' => array(
						'step' => '1',
						'min'  => '0',
					),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'          => self::META_SOURCE_LABEL,
					'label'       => __( 'Wallet source label', 'coin-booking-bridge' ),
					'description' => __( 'Optional label used later for wallet activity and reporting.', 'coin-booking-bridge' ),
					'desc_tip'    => true,
					'type'        => 'text',
				)
			);

			woocommerce_wp_select(
				array(
					'id'          => self::META_PACKAGE_SIZE,
					'label'       => __( 'Package size', 'coin-booking-bridge' ),
					'description' => __( 'Optional reporting hint for package products.', 'coin-booking-bridge' ),
					'desc_tip'    => true,
					'options'     => array(
						''       => __( 'Not a package', 'coin-booking-bridge' ),
						'small'  => __( 'Small', 'coin-booking-bridge' ),
						'medium' => __( 'Medium', 'coin-booking-bridge' ),
						'large'  => __( 'Large', 'coin-booking-bridge' ),
					),
				)
			);

			woocommerce_wp_checkbox(
				array(
					'id'          => self::META_ONE_TIME_PERSON,
					'label'       => __( 'One-time per person', 'coin-booking-bridge' ),
					'description' => __( 'For free trial style products. Eligibility will use normalized email and phone once enforcement is enabled.', 'coin-booking-bridge' ),
				)
			);

			echo '</div>';
		}

		/**
		 * Get the month value shown in the product validity field.
		 *
		 * @param int $product_id Product ID.
		 * @return int|string
		 */
		private static function get_product_validity_months_field_value( $product_id ) {
			$months = get_post_meta( $product_id, self::META_VALIDITY_MONTHS, true );

			if ( '' !== $months ) {
				return absint( $months );
			}

			$legacy_days = get_post_meta( $product_id, self::META_VALIDITY_DAYS, true );

			if ( '' !== $legacy_days ) {
				return self::convert_legacy_validity_days_to_months( $legacy_days );
			}

			return '';
		}

		/**
		 * Get Zencoin product type options.
		 *
		 * @return array
		 */
		private static function get_zencoin_product_type_options() {
			return array(
				'none'         => __( 'None', 'coin-booking-bridge' ),
				'package'      => __( 'Package', 'coin-booking-bridge' ),
				'drop_in'      => __( 'Drop-in', 'coin-booking-bridge' ),
				'free_drop_in' => __( 'Free drop-in trial', 'coin-booking-bridge' ),
				'gift_card'    => __( 'Gift card', 'coin-booking-bridge' ),
				'auto_top_up'  => __( 'Auto top-up', 'coin-booking-bridge' ),
			);
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
			$product_type = isset( $_POST[ self::META_PRODUCT_TYPE ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_PRODUCT_TYPE ] ) ) : 'none'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$zc_amount    = isset( $_POST[ self::META_ZC_GRANT_AMOUNT ] ) ? self::sanitize_decimal_setting( wp_unslash( $_POST[ self::META_ZC_GRANT_AMOUNT ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$validity     = isset( $_POST[ self::META_VALIDITY_MONTHS ] ) && '' !== $_POST[ self::META_VALIDITY_MONTHS ] ? absint( wp_unslash( $_POST[ self::META_VALIDITY_MONTHS ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$label        = isset( $_POST[ self::META_SOURCE_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_SOURCE_LABEL ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$package_size = isset( $_POST[ self::META_PACKAGE_SIZE ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_PACKAGE_SIZE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$one_time     = isset( $_POST[ self::META_ONE_TIME_PERSON ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			update_post_meta( $post_id, self::META_GRANT_AMOUNT, $grant_amount );
			update_post_meta( $post_id, self::META_BOOKING_COST, $booking_cost );
			update_post_meta( $post_id, self::META_REFUND_POLICY, in_array( $policy, array( 'full', 'none' ), true ) ? $policy : 'full' );
			update_post_meta( $post_id, self::META_PRODUCT_TYPE, array_key_exists( $product_type, self::get_zencoin_product_type_options() ) ? $product_type : 'none' );
			update_post_meta( $post_id, self::META_ZC_GRANT_AMOUNT, $zc_amount );
			update_post_meta( $post_id, self::META_VALIDITY_MONTHS, $validity );
			update_post_meta( $post_id, self::META_SOURCE_LABEL, $label );
			update_post_meta( $post_id, self::META_PACKAGE_SIZE, in_array( $package_size, array( '', 'small', 'medium', 'large' ), true ) ? $package_size : '' );
			update_post_meta( $post_id, self::META_ONE_TIME_PERSON, $one_time );
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

			if ( $order && function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
				return;
			}

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
		 * Grant Zencoins for paid package/drop-in products.
		 *
		 * @param int|WC_Order $order_id Order ID or object.
		 */
		public static function grant_order_product_zencoins( $order_id ) {
			$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );

			if ( ! $order || $order->get_meta( self::META_PRODUCT_GRANTS, true ) ) {
				return;
			}

			$user_id = (int) $order->get_customer_id();

			if ( $user_id <= 0 ) {
				return;
			}

			if ( self::order_contains_free_dropin_trial( $order ) && ! self::validate_free_trial_card_for_order( $order ) ) {
				return;
			}

			$grants = array();

			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				$grant = self::get_product_grant_for_order_item( $item );

				if ( ! $grant ) {
					continue;
				}

				if ( 'free_drop_in' === $grant['product_type'] && self::order_customer_has_used_free_trial( $order, $grant['product_id'] ) ) {
					$order->add_order_note( __( 'Free drop-in Zencoin grant skipped because this email/phone identity has already used a free trial.', 'coin-booking-bridge' ) );
					continue;
				}

				$amount = $grant['amount'] * max( 1, (float) $item->get_quantity() );

				if ( $amount <= 0 ) {
					continue;
				}

				$bucket_id = self::create_zencoin_bucket(
					$user_id,
					$amount,
					array(
						'source_type'           => $grant['product_type'],
						'expires_at'            => self::calculate_expiry_datetime( $grant['validity_months'], $order->get_date_paid() ),
						'related_order_id'      => $order->get_id(),
						'related_order_item_id' => $item_id,
						'related_product_id'    => $grant['product_id'],
						'source_label'          => $grant['source_label'],
						'metadata'              => array(
							'order_number' => $order->get_order_number(),
							'product_name'  => $item->get_name(),
							'package_size'  => $grant['package_size'],
						),
					)
				);

				if ( ! $bucket_id ) {
					$order->add_order_note( __( 'Zencoin bucket grant failed for a package/drop-in line item.', 'coin-booking-bridge' ) );
					continue;
				}

				$ledger_id = self::add_zencoin_ledger_entry(
					$user_id,
					$amount,
					array(
						'bucket_id'             => $bucket_id,
						'entry_type'            => self::get_grant_ledger_type( $grant['product_type'] ),
						'direction'             => 'credit',
						'balance_after'         => self::get_zencoin_bucket_balance( $user_id ),
						'label'                 => $grant['source_label'],
						'related_order_id'      => $order->get_id(),
						'related_order_item_id' => $item_id,
						'related_product_id'    => $grant['product_id'],
					)
				);

				$wallet_transaction_id = self::mirror_wallet_credit(
					$user_id,
					$amount,
					sprintf(
						/* translators: 1: source label, 2: order number */
						__( '%1$s from order #%2$s', 'coin-booking-bridge' ),
						$grant['source_label'],
						$order->get_order_number()
					),
					$order->get_currency( 'edit' )
				);

				$grants[] = array(
					'item_id'               => $item_id,
					'product_id'            => $grant['product_id'],
					'product_type'          => $grant['product_type'],
					'amount'                => self::format_zencoin_amount( $amount ),
					'bucket_id'             => $bucket_id,
					'ledger_id'             => $ledger_id,
					'wallet_transaction_id' => $wallet_transaction_id,
				);

				if ( 'free_drop_in' === $grant['product_type'] ) {
					self::mark_free_trial_identity_used( $order, $grant['product_id'] );
				}
			}

			if ( empty( $grants ) ) {
				return;
			}

			$order->update_meta_data( self::META_PRODUCT_GRANTS, $grants );
			$order->add_order_note(
				sprintf(
					/* translators: %s: coin amount */
					__( 'Created Zencoin bucket grants for %s ZC.', 'coin-booking-bridge' ),
					wc_format_decimal( array_sum( wp_list_pluck( $grants, 'amount' ) ) )
				)
			);
			$order->save();
			self::attempt_mixed_recovery_booking_finalization( $order, 'product_grant' );
		}

		/**
		 * Force a payment step for a zero-price free drop-in cart.
		 *
		 * @param bool    $needs_payment Whether the cart needs payment.
		 * @param WC_Cart $cart          Cart object.
		 * @return bool
		 */
		public static function force_free_dropin_cart_payment( $needs_payment, $cart = null ) {
			return self::cart_contains_free_dropin_trial( $cart ) ? true : $needs_payment;
		}

		/**
		 * Force unpaid free drop-in orders through the payment gateway.
		 *
		 * @param bool     $needs_payment Whether the order needs payment.
		 * @param WC_Order $order         Order object.
		 * @return bool
		 */
		public static function force_free_dropin_order_payment( $needs_payment, $order ) {
			if ( $order instanceof WC_Order && ! $order->is_paid() && self::order_contains_free_dropin_trial( $order ) ) {
				return true;
			}

			return $needs_payment;
		}

		/**
		 * Only WooPayments may verify and save the card for a free drop-in.
		 *
		 * @param array $gateways Available gateways.
		 * @return array
		 */
		public static function limit_free_dropin_to_woopayments( $gateways ) {
			if ( ! self::cart_contains_free_dropin_trial() ) {
				return $gateways;
			}

			return isset( $gateways['woocommerce_payments'] )
				? array( 'woocommerce_payments' => $gateways['woocommerce_payments'] )
				: array();
		}

		/**
		 * Force card saving before classic checkout invokes WooPayments.
		 *
		 * @param int      $order_id   Order ID.
		 * @param array    $posted_data Checkout data.
		 * @param WC_Order $order       Order object.
		 * @throws Exception When the card is not eligible.
		 */
		public static function prepare_free_dropin_classic_wcpay_payment( $order_id, $posted_data, $order ) {
			if ( ! $order instanceof WC_Order || ! self::order_contains_free_dropin_trial( $order ) ) {
				return;
			}

			$_POST['wc-woocommerce_payments-new-payment-method'] = 'true'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$payment_method_id = isset( $_POST['wcpay-payment-method'] ) ? sanitize_text_field( wp_unslash( $_POST['wcpay-payment-method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( preg_match( '/^pm_[A-Za-z0-9_]+$/', $payment_method_id ) ) {
				$card_hash = self::validate_free_trial_card_payment_method( $payment_method_id, $order->get_id() );
				$order->update_meta_data( self::META_TRIAL_CARD_HASH, $card_hash );
				$order->update_meta_data( self::META_TRIAL_CARD_STATUS, 'passed' );
				$order->save_meta_data();
			}
		}

		/**
		 * Force WooPayments to use a SetupIntent and save the card for the free trial.
		 *
		 * @param object $context Store API payment context.
		 * @param object $result  Store API payment result.
		 * @throws Exception When the payment method or card is not eligible.
		 */
		public static function prepare_free_dropin_wcpay_payment( $context, $result ) {
			$order = isset( $context->order ) ? $context->order : null;

			if ( ! $order instanceof WC_Order || ! self::order_contains_free_dropin_trial( $order ) ) {
				return;
			}

			if ( (int) $order->get_customer_id() <= 0 ) {
				throw new Exception( esc_html__( 'Please sign in before claiming the free drop-in trial so the verified card can be saved to your account.', 'coin-booking-bridge' ) );
			}

			if ( 'woocommerce_payments' !== (string) $context->payment_method ) {
				throw new Exception( esc_html__( 'Please use WooPayments card entry to claim the free drop-in trial.', 'coin-booking-bridge' ) );
			}

			$payment_data = is_array( $context->payment_data ) ? $context->payment_data : array();
			$payment_data['wc-woocommerce_payments-new-payment-method'] = 'true';
			$context->set_payment_data( $payment_data );

			$payment_method_id = isset( $payment_data['wcpay-payment-method'] ) ? sanitize_text_field( $payment_data['wcpay-payment-method'] ) : '';

			if ( preg_match( '/^pm_[A-Za-z0-9_]+$/', $payment_method_id ) ) {
				$card_hash = self::validate_free_trial_card_payment_method( $payment_method_id, $order->get_id() );
				$order->update_meta_data( self::META_TRIAL_CARD_HASH, $card_hash );
				$order->update_meta_data( self::META_TRIAL_CARD_STATUS, 'passed' );
				$order->save_meta_data();
			}
		}

		/**
		 * Validate free drop-in trial eligibility during classic checkout.
		 */
		public static function validate_free_dropin_trial_checkout() {
			if ( ! WC()->cart || WC()->cart->is_empty() || ! self::cart_contains_free_dropin_trial() ) {
				return;
			}

			if ( ! is_user_logged_in() ) {
				wc_add_notice( __( 'Please sign in before claiming the free drop-in trial so the verified card can be saved to your account.', 'coin-booking-bridge' ), 'error' );
				return;
			}

			$email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( ! self::has_free_trial_identity( $email, $phone ) ) {
				wc_add_notice( __( 'Please provide both email and phone number to claim the free drop-in trial.', 'coin-booking-bridge' ), 'error' );
				return;
			}

			if ( self::free_trial_identity_has_been_used( $email, $phone ) ) {
				wc_add_notice( __( 'This free drop-in trial has already been used for this email or phone number.', 'coin-booking-bridge' ), 'error' );
				return;
			}

			$gateway = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( 'woocommerce_payments' !== $gateway ) {
				wc_add_notice( __( 'Please use WooPayments card entry to claim the free drop-in trial.', 'coin-booking-bridge' ), 'error' );
				return;
			}

			$payment_method_id = isset( $_POST['wcpay-payment-method'] ) ? sanitize_text_field( wp_unslash( $_POST['wcpay-payment-method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( preg_match( '/^pm_[A-Za-z0-9_]+$/', $payment_method_id ) ) {
				try {
					self::validate_free_trial_card_payment_method( $payment_method_id );
				} catch ( Exception $exception ) {
					wc_add_notice( $exception->getMessage(), 'error' );
				}
			}
		}

		/**
		 * Validate free drop-in trial eligibility during Store API checkout.
		 *
		 * @param WC_Order   $order   Draft order.
		 * @param WP_REST_Request $request Store API request.
		 */
		public static function validate_free_dropin_trial_store_api_checkout( $order, $request ) {
			if ( ! WC()->cart || WC()->cart->is_empty() || ! self::cart_contains_free_dropin_trial() ) {
				return;
			}

			if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() <= 0 ) {
				throw new Exception( esc_html__( 'Please sign in before claiming the free drop-in trial so the verified card can be saved to your account.', 'coin-booking-bridge' ) );
			}

			$email = $order->get_billing_email();
			$phone = $order->get_billing_phone();

			if ( ! self::has_free_trial_identity( $email, $phone ) ) {
				throw new Exception( esc_html__( 'Please provide both email and phone number to claim the free drop-in trial.', 'coin-booking-bridge' ) );
			}

			if ( self::free_trial_identity_has_been_used( $email, $phone ) ) {
				throw new Exception( esc_html__( 'This free drop-in trial has already been used for this email or phone number.', 'coin-booking-bridge' ) );
			}
		}

		/**
		 * Check whether cart contains a free drop-in trial product.
		 *
		 * @param WC_Cart|null $cart Optional cart object.
		 * @return bool
		 */
		private static function cart_contains_free_dropin_trial( $cart = null ) {
			$cart = $cart instanceof WC_Cart ? $cart : ( WC()->cart ? WC()->cart : null );

			if ( ! $cart ) {
				return false;
			}

			foreach ( $cart->get_cart() as $cart_item ) {
				$product_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

				if ( $product_id && 'free_drop_in' === get_post_meta( $product_id, self::META_PRODUCT_TYPE, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check whether an order contains a free drop-in trial product.
		 *
		 * @param WC_Order $order Order object.
		 * @return bool
		 */
		private static function order_contains_free_dropin_trial( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return false;
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product_id = self::get_item_product_or_parent_id( $item );

				if ( $product_id && 'free_drop_in' === get_post_meta( $product_id, self::META_PRODUCT_TYPE, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Validate a WooPayments card and return its private fingerprint hash.
		 *
		 * @param string $payment_method_id WooPayments payment method ID.
		 * @param int    $exclude_order_id  Optional current order ID.
		 * @return string
		 * @throws Exception When the card cannot be verified or was already used.
		 */
		private static function validate_free_trial_card_payment_method( $payment_method_id, $exclude_order_id = 0 ) {
			if ( ! class_exists( 'WC_Payments' ) || ! is_callable( array( 'WC_Payments', 'get_payments_api_client' ) ) ) {
				throw new Exception( esc_html__( 'WooPayments card verification is unavailable. Please try again later.', 'coin-booking-bridge' ) );
			}

			try {
				$payment_method = WC_Payments::get_payments_api_client()->get_payment_method( $payment_method_id );
			} catch ( Exception $exception ) {
				throw new Exception( esc_html__( 'We could not verify this card. Please check the card details and try again.', 'coin-booking-bridge' ) );
			}

			$is_direct_card = is_array( $payment_method )
				&& isset( $payment_method['type'], $payment_method['card']['fingerprint'] )
				&& 'card' === $payment_method['type']
				&& empty( $payment_method['card']['wallet'] );
			$fingerprint    = $is_direct_card ? sanitize_text_field( $payment_method['card']['fingerprint'] ) : '';

			if ( '' === $fingerprint ) {
				throw new Exception( esc_html__( 'A verified card is required to claim the free drop-in trial.', 'coin-booking-bridge' ) );
			}

			$card_hash = wp_hash( 'wcpay-card:' . $fingerprint );

			if ( self::free_trial_card_has_been_used( $card_hash, $exclude_order_id ) ) {
				throw new Exception( esc_html__( 'This card has already been used for a free drop-in trial.', 'coin-booking-bridge' ) );
			}

			return $card_hash;
		}

		/**
		 * Check whether a hashed card fingerprint was used by a successful trial order.
		 *
		 * @param string $card_hash        Hashed card fingerprint.
		 * @param int    $exclude_order_id Optional current order ID.
		 * @return bool
		 */
		private static function free_trial_card_has_been_used( $card_hash, $exclude_order_id = 0 ) {
			if ( '' === $card_hash ) {
				return false;
			}

			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'status'     => array( 'wc-processing', 'wc-completed' ),
					'meta_key'   => self::META_TRIAL_CARD_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $card_hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'exclude'    => $exclude_order_id ? array( absint( $exclude_order_id ) ) : array(),
				)
			);

			return ! empty( $orders );
		}

		/**
		 * Verify the order card before any free Zencoins are granted.
		 *
		 * @param WC_Order $order Order object.
		 * @return bool
		 */
		private static function validate_free_trial_card_for_order( $order ) {
			if ( ! $order instanceof WC_Order || ! self::order_contains_free_dropin_trial( $order ) ) {
				return true;
			}

			$card_hash = (string) $order->get_meta( self::META_TRIAL_CARD_HASH, true );

			if ( $card_hash && 'passed' === $order->get_meta( self::META_TRIAL_CARD_STATUS, true ) ) {
				if ( ! self::free_trial_card_has_been_used( $card_hash, $order->get_id() ) ) {
					return true;
				}

				return self::fail_free_trial_card_validation( $order, __( 'This card has already been used for a free drop-in trial.', 'coin-booking-bridge' ), 'duplicate' );
			}

			$payment_method_id = sanitize_text_field( (string) $order->get_meta( '_payment_method_id', true ) );

			try {
				$card_hash = self::validate_free_trial_card_payment_method( $payment_method_id, $order->get_id() );
			} catch ( Exception $exception ) {
				return self::fail_free_trial_card_validation( $order, $exception->getMessage(), 'failed' );
			}

			$order->update_meta_data( self::META_TRIAL_CARD_HASH, $card_hash );
			$order->update_meta_data( self::META_TRIAL_CARD_STATUS, 'passed' );
			$order->save_meta_data();

			return true;
		}

		/**
		 * Fail a free trial order when its card cannot be approved.
		 *
		 * @param WC_Order $order   Order object.
		 * @param string   $message Failure message.
		 * @param string   $status  Internal validation status.
		 * @return false
		 */
		private static function fail_free_trial_card_validation( $order, $message, $status ) {
			$order->update_meta_data( self::META_TRIAL_CARD_STATUS, sanitize_key( $status ) );
			$order->add_order_note( sprintf( __( 'Free drop-in card validation failed: %s', 'coin-booking-bridge' ), wp_strip_all_tags( $message ) ) );
			$order->save();

			if ( ! $order->has_status( 'failed' ) ) {
				$order->update_status( 'failed' );
			}

			return false;
		}

		/**
		 * Check whether order customer identity has already used free trial.
		 *
		 * @param WC_Order $order      Order.
		 * @param int      $product_id Product ID.
		 * @return bool
		 */
		private static function order_customer_has_used_free_trial( $order, $product_id ) {
			if ( ! $order instanceof WC_Order ) {
				return false;
			}

			return self::free_trial_identity_has_been_used(
				$order->get_billing_email(),
				$order->get_billing_phone(),
				$order->get_id()
			);
		}

		/**
		 * Mark free trial identity as used on an order.
		 *
		 * @param WC_Order $order      Order.
		 * @param int      $product_id Product ID.
		 */
		private static function mark_free_trial_identity_used( $order, $product_id ) {
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$email_hash = self::hash_free_trial_email( $order->get_billing_email() );
			$phone_hash = self::hash_free_trial_phone( $order->get_billing_phone() );
			$card_hash  = (string) $order->get_meta( self::META_TRIAL_CARD_HASH, true );

			$order->update_meta_data(
				self::META_TRIAL_IDENTITY,
				array(
					'email_hash' => $email_hash,
					'phone_hash' => $phone_hash,
					'card_hash'  => $card_hash,
					'product_id' => absint( $product_id ),
				)
			);
			$order->update_meta_data( self::META_TRIAL_EMAIL_HASH, $email_hash );
			$order->update_meta_data( self::META_TRIAL_PHONE_HASH, $phone_hash );
		}

		/**
		 * Check whether a free drop-in trial is available for an identity.
		 *
		 * Incomplete identities remain visible and are validated during checkout.
		 *
		 * @param string $email Billing email.
		 * @param string $phone Billing phone.
		 * @return bool
		 */
		public static function is_free_dropin_trial_available( $email, $phone ) {
			if ( ! self::has_free_trial_identity( $email, $phone ) ) {
				return true;
			}

			return ! self::free_trial_identity_has_been_used( $email, $phone );
		}

		/**
		 * Check whether a free trial identity has already been used.
		 *
		 * @param string $email            Email.
		 * @param string $phone            Phone.
		 * @param int    $exclude_order_id Optional order ID to exclude.
		 * @return bool
		 */
		private static function free_trial_identity_has_been_used( $email, $phone, $exclude_order_id = 0 ) {
			if ( ! self::has_free_trial_identity( $email, $phone ) ) {
				return false;
			}

			$meta_query = array( 'relation' => 'OR' );
			$email_hash = self::hash_free_trial_email( $email );
			$phone_hash = self::hash_free_trial_phone( $phone );

			if ( $email_hash ) {
				$meta_query[] = array(
					'key'   => self::META_TRIAL_EMAIL_HASH,
					'value' => $email_hash,
				);
			}

			if ( $phone_hash ) {
				$meta_query[] = array(
					'key'   => self::META_TRIAL_PHONE_HASH,
					'value' => $phone_hash,
				);
			}

			$query_args = array(
				'limit'        => 1,
				'return'       => 'ids',
				'status'       => array( 'wc-processing', 'wc-completed' ),
				'meta_query'   => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'exclude'      => $exclude_order_id ? array( absint( $exclude_order_id ) ) : array(),
			);

			$orders = wc_get_orders( $query_args );

			return ! empty( $orders );
		}

		/**
		 * Whether identity has both required values.
		 *
		 * @param string $email Email.
		 * @param string $phone Phone.
		 * @return bool
		 */
		private static function has_free_trial_identity( $email, $phone ) {
			return '' !== self::normalize_free_trial_email( $email ) && '' !== self::normalize_free_trial_phone( $phone );
		}

		/**
		 * Hash normalized email.
		 *
		 * @param string $email Email.
		 * @return string
		 */
		private static function hash_free_trial_email( $email ) {
			$email = self::normalize_free_trial_email( $email );

			return $email ? wp_hash( $email ) : '';
		}

		/**
		 * Hash normalized phone.
		 *
		 * @param string $phone Phone.
		 * @return string
		 */
		private static function hash_free_trial_phone( $phone ) {
			$phone = self::normalize_free_trial_phone( $phone );

			return $phone ? wp_hash( $phone ) : '';
		}

		/**
		 * Normalize email for free trial identity.
		 *
		 * @param string $email Email.
		 * @return string
		 */
		private static function normalize_free_trial_email( $email ) {
			return strtolower( sanitize_email( $email ) );
		}

		/**
		 * Normalize phone for free trial identity.
		 *
		 * @param string $phone Phone.
		 * @return string
		 */
		private static function normalize_free_trial_phone( $phone ) {
			return preg_replace( '/[^0-9+]/', '', (string) $phone );
		}

		/**
		 * Get product grant config for a paid order item.
		 *
		 * @param WC_Order_Item_Product $item Order item.
		 * @return array|null
		 */
		private static function get_product_grant_for_order_item( $item ) {
			$product_id = self::get_item_product_or_parent_id( $item );

			if ( ! $product_id ) {
				return null;
			}

			$item_product_type = is_callable( array( $item, 'get_meta' ) ) ? sanitize_key( (string) $item->get_meta( self::META_PRODUCT_TYPE, true ) ) : '';
			$item_amount       = is_callable( array( $item, 'get_meta' ) ) ? (float) $item->get_meta( self::META_ZC_GRANT_AMOUNT, true ) : 0.0;

			if ( $item_amount > 0 && in_array( $item_product_type, array( 'package', 'drop_in', 'free_drop_in' ), true ) ) {
				$source_label = is_callable( array( $item, 'get_meta' ) ) ? (string) $item->get_meta( self::META_SOURCE_LABEL, true ) : '';

				if ( '' === $source_label ) {
					$source_label = __( 'Member Zencoin Top-up', 'coin-booking-bridge' );
				}

				return array(
					'product_id'       => $product_id,
					'product_type'     => $item_product_type,
					'amount'           => $item_amount,
					'validity_months'  => self::get_product_grant_validity_months( $product_id, $item_product_type ),
					'source_label'     => $source_label,
					'package_size'     => get_post_meta( $product_id, self::META_PACKAGE_SIZE, true ),
				);
			}

			$product_type = get_post_meta( $product_id, self::META_PRODUCT_TYPE, true );

			if ( ! in_array( $product_type, array( 'package', 'drop_in', 'free_drop_in' ), true ) ) {
				return null;
			}

			$amount = (float) get_post_meta( $product_id, self::META_ZC_GRANT_AMOUNT, true );

			if ( $amount <= 0 ) {
				return null;
			}

			$source_label = get_post_meta( $product_id, self::META_SOURCE_LABEL, true );

			if ( '' === $source_label ) {
				if ( 'free_drop_in' === $product_type ) {
					$source_label = __( 'Free Drop-In Trial', 'coin-booking-bridge' );
				} else {
					$source_label = 'package' === $product_type ? __( 'Zencoin Package', 'coin-booking-bridge' ) : __( 'Drop-In', 'coin-booking-bridge' );
				}
			}

			return array(
				'product_id'       => $product_id,
				'product_type'     => $product_type,
				'amount'           => $amount,
				'validity_months'  => self::get_product_grant_validity_months( $product_id, $product_type ),
				'source_label'     => $source_label,
				'package_size'     => get_post_meta( $product_id, self::META_PACKAGE_SIZE, true ),
			);
		}

		/**
		 * Get validity months for a package/drop-in product grant.
		 *
		 * @param int    $product_id   Product ID.
		 * @param string $product_type Product type.
		 * @return int
		 */
		private static function get_product_grant_validity_months( $product_id, $product_type ) {
			$override = get_post_meta( $product_id, self::META_VALIDITY_MONTHS, true );

			if ( '' !== $override ) {
				return absint( $override );
			}

			$legacy_days = get_post_meta( $product_id, self::META_VALIDITY_DAYS, true );

			if ( '' !== $legacy_days ) {
				return self::convert_legacy_validity_days_to_months( $legacy_days );
			}

			$settings = self::get_settings();

			if ( 'drop_in' === $product_type ) {
				return absint( $settings['dropin_validity_months'] );
			}

			if ( 'free_drop_in' === $product_type ) {
				return absint( $settings['free_dropin_validity_months'] );
			}

			$package_size = get_post_meta( $product_id, self::META_PACKAGE_SIZE, true );

			if ( 'large' === $package_size ) {
				return absint( $settings['package_large_validity_months'] );
			}

			if ( 'medium' === $package_size ) {
				return absint( $settings['package_medium_validity_months'] );
			}

			return absint( $settings['package_small_validity_months'] );
		}

		/**
		 * Calculate expiry datetime from validity months.
		 *
		 * @param int                    $validity_months Validity months.
		 * @param WC_DateTime|null|mixed $base_date     Base date.
		 * @return string|null
		 */
		private static function calculate_expiry_datetime( $validity_months, $base_date = null ) {
			$validity_months = absint( $validity_months );

			if ( $validity_months <= 0 ) {
				return null;
			}

			$timestamp = $base_date instanceof WC_DateTime ? $base_date->getTimestamp() : current_time( 'timestamp' );
			$date      = new DateTimeImmutable( '@' . $timestamp );
			$date      = $date->modify( '+' . $validity_months . ' months' );

			return $date->format( 'Y-m-d H:i:s' );
		}

		/**
		 * Get ledger type for grant source.
		 *
		 * @param string $product_type Product type.
		 * @return string
		 */
		private static function get_grant_ledger_type( $product_type ) {
			if ( 'drop_in' === $product_type ) {
				return 'drop_in_purchase';
			}

			if ( 'free_drop_in' === $product_type ) {
				return 'free_drop_in_trial';
			}

			return 'package_purchase';
		}

		/**
		 * Mirror a credit to Tera Wallet when enabled.
		 *
		 * @param int    $user_id  User ID.
		 * @param float  $amount   ZC amount.
		 * @param string $details  Wallet details.
		 * @param string $currency Currency code.
		 * @return int|false|null
		 */
		private static function mirror_wallet_credit( $user_id, $amount, $details, $currency ) {
			$settings = self::get_settings();

			if ( 'yes' !== $settings['tera_wallet_mirror_enabled'] || ! function_exists( 'woo_wallet' ) ) {
				return null;
			}

			return woo_wallet()->wallet->credit(
				$user_id,
				$amount,
				$details,
				array(
					'for'      => 'zencoin_product_grant',
					'currency' => $currency,
				)
			);
		}

		/**
		 * Mirror a debit to Tera Wallet when enabled.
		 *
		 * @param int    $user_id  User ID.
		 * @param float  $amount   ZC amount.
		 * @param string $details  Wallet details.
		 * @param string $currency Currency code.
		 * @return int|string|false
		 */
		private static function mirror_wallet_debit( $user_id, $amount, $details, $currency ) {
			$settings = self::get_settings();

			if ( 'yes' !== $settings['tera_wallet_mirror_enabled'] ) {
				return 'tera_wallet_mirror_disabled';
			}

			if ( ! function_exists( 'woo_wallet' ) ) {
				return false;
			}

			return woo_wallet()->wallet->debit(
				$user_id,
				$amount,
				$details,
				array(
					'for'      => 'booking_payment',
					'currency' => $currency,
				)
			);
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
				$reset_count = self::reset_subscription_membership_buckets( $user_id, $subscription, $order );
				$bucket_id = self::create_zencoin_bucket(
					$user_id,
					$coins,
					array(
						'source_type'             => 'membership',
						'expires_at'              => self::get_subscription_bucket_expiry( $subscription ),
						'related_order_id'        => $order->get_id(),
						'related_subscription_id' => $subscription->get_id(),
						'source_label'            => __( 'Membership Credits', 'coin-booking-bridge' ),
						'metadata'                => array(
							'order_number'        => $order->get_order_number(),
							'subscription_number' => $subscription->get_order_number(),
						),
					)
				);

				if ( $reset_count > 0 ) {
					$order->add_order_note(
						sprintf(
							/* translators: %s: bucket count */
							__( 'Reset %s previous membership Zencoin bucket(s) before granting the new cycle.', 'coin-booking-bridge' ),
							number_format_i18n( $reset_count )
						)
					);
				}

				$ledger_id = false;

				if ( $bucket_id ) {
					$ledger_id = self::add_zencoin_ledger_entry(
						$user_id,
						$coins,
						array(
							'bucket_id'               => $bucket_id,
							'entry_type'              => 'membership_grant',
							'direction'               => 'credit',
							'balance_after'           => self::get_zencoin_bucket_balance( $user_id ),
							'label'                   => __( 'Membership Credits', 'coin-booking-bridge' ),
							'related_order_id'        => $order->get_id(),
							'related_subscription_id' => $subscription->get_id(),
						)
					);
				} else {
					$order->add_order_note( __( 'Membership Zencoin bucket creation failed. Tera Wallet was credited by the MVP flow.', 'coin-booking-bridge' ) );
				}

				$order->update_meta_data( self::META_CREDIT_TXN, $transaction_id );
				$order->update_meta_data( self::META_COIN_TOTAL, $coins );
				$order->update_meta_data(
					self::META_MEMBERSHIP_GRANT,
					array(
						array(
							'product_type'          => 'membership',
							'amount'                => self::format_zencoin_amount( $coins ),
							'bucket_id'             => $bucket_id,
							'ledger_id'             => $ledger_id,
							'wallet_transaction_id' => $transaction_id,
							'subscription_id'       => $subscription->get_id(),
						),
					)
				);
				$order->save();
				self::attempt_mixed_recovery_booking_finalization( $order, 'membership_grant' );

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
		 * Get expiry datetime for a membership credit bucket.
		 *
		 * Uses next payment date when available. Falls back to one month from now for paid subscriptions
		 * without a scheduled next payment date.
		 *
		 * @param WC_Subscription $subscription Subscription object.
		 * @return string|null
		 */
		private static function get_subscription_bucket_expiry( $subscription ) {
			if ( ! $subscription || ! is_object( $subscription ) || ! method_exists( $subscription, 'get_date' ) ) {
				return null;
			}

			$next_payment = $subscription->get_date( 'next_payment', 'site' );

			if ( $next_payment ) {
				return self::sanitize_datetime_or_null( $next_payment );
			}

			return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + MONTH_IN_SECONDS );
		}

		/**
		 * Reset active membership buckets for the subscription before granting the next cycle.
		 *
		 * @param int             $user_id      User ID.
		 * @param WC_Subscription $subscription Subscription object.
		 * @param WC_Order|null   $order        Renewal/order object.
		 * @return int Number of reset buckets.
		 */
		private static function reset_subscription_membership_buckets( $user_id, $subscription, $order = null ) {
			global $wpdb;

			$user_id         = absint( $user_id );
			$subscription_id = is_object( $subscription ) && method_exists( $subscription, 'get_id' ) ? absint( $subscription->get_id() ) : 0;
			$table           = self::get_buckets_table_name();

			if ( $user_id <= 0 || $subscription_id <= 0 || ! self::table_exists( $table ) ) {
				return 0;
			}

			$buckets = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d AND related_subscription_id = %d AND source_type = %s AND status = %s AND remaining_amount > 0 ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user_id,
					$subscription_id,
					'membership',
					'active'
				)
			);

			if ( empty( $buckets ) ) {
				return 0;
			}

			$now         = current_time( 'mysql' );
			$reset_count = 0;

			foreach ( $buckets as $bucket ) {
				$remaining = (float) $bucket->remaining_amount;

				if ( $remaining <= 0 ) {
					continue;
				}

				$updated = $wpdb->update(
					$table,
					array(
						'remaining_amount' => self::format_zencoin_amount( 0 ),
						'status'           => 'expired',
						'updated_at'       => $now,
					),
					array( 'id' => (int) $bucket->id ),
					array( '%f', '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					continue;
				}

				$wallet_transaction_id = self::mirror_wallet_debit(
					$user_id,
					$remaining,
					sprintf(
						/* translators: %s: subscription number */
						__( 'Membership credits reset for subscription #%s', 'coin-booking-bridge' ),
						is_object( $subscription ) && method_exists( $subscription, 'get_order_number' ) ? $subscription->get_order_number() : $subscription_id
					),
					$order instanceof WC_Order ? $order->get_currency( 'edit' ) : get_woocommerce_currency()
				);

				self::add_zencoin_ledger_entry(
					$user_id,
					$remaining,
					array(
						'bucket_id'               => (int) $bucket->id,
						'entry_type'              => 'membership_reset',
						'direction'               => 'debit',
						'balance_after'           => self::get_zencoin_bucket_balance( $user_id ),
						'label'                   => __( 'Membership credits reset', 'coin-booking-bridge' ),
						'related_order_id'        => $order instanceof WC_Order ? $order->get_id() : $bucket->related_order_id,
						'related_order_item_id'   => $bucket->related_order_item_id,
						'related_product_id'      => $bucket->related_product_id,
						'related_subscription_id' => $subscription_id,
						'related_booking_id'      => $bucket->related_booking_id,
						'metadata'                => array(
							'reset_at'                   => $now,
							'original_bucket_expires_at' => $bucket->expires_at,
							'subscription_number'        => is_object( $subscription ) && method_exists( $subscription, 'get_order_number' ) ? $subscription->get_order_number() : '',
							'wallet_transaction_id'      => $wallet_transaction_id,
						),
					)
				);

				$reset_count++;
			}

			return $reset_count;
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
			$order_item_amount = is_callable( array( $item, 'get_meta' ) ) ? (float) $item->get_meta( self::META_ZC_GRANT_AMOUNT, true ) : 0.0;

			if ( $order_item_amount <= 0 && is_callable( array( $item, 'get_meta' ) ) ) {
				$order_item_amount = (float) $item->get_meta( self::META_GRANT_AMOUNT, true );
			}

			if ( $order_item_amount > 0 ) {
				return $order_item_amount;
			}

			$variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;

			if ( $variation_id ) {
				return (float) get_post_meta( $variation_id, self::META_GRANT_AMOUNT, true );
			}

			$product_id = (int) $item->get_product_id();

			return $product_id ? (float) get_post_meta( $product_id, self::META_GRANT_AMOUNT, true ) : 0.0;
		}

		/**
		 * Prevent more than one coin-paid booking from being added to the cart.
		 *
		 * @param bool $passed       Whether add-to-cart should proceed.
		 * @param int  $product_id   Product ID.
		 * @param int  $quantity     Quantity being added.
		 * @param int  $variation_id Variation ID.
		 * @return bool
		 */
		public static function validate_single_booking_add_to_cart( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
			if ( ! self::is_coin_booking_product( $product_id, $variation_id ) ) {
				return $passed;
			}

			if ( max( 1, absint( $quantity ) ) > 1 || self::get_cart_coin_booking_count() > 0 ) {
				wc_add_notice( __( 'Please book one class, workshop, event, or Fire & Ice session at a time.', 'coin-booking-bridge' ), 'error' );
				return false;
			}

			return $passed;
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

			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				$cart->cart_contents[ $cart_item_key ] = self::zero_coin_booking_cart_item_price( $cart_item );
			}
		}

		/**
		 * Keep coin-paid bookings at zero money price when cart items are created.
		 *
		 * WooCommerce Bookings applies its calculated booking cost to the cart item
		 * when items are added/restored, so this runs after that booking filter.
		 *
		 * @param array $cart_item Cart item.
		 * @return array
		 */
		public static function zero_coin_booking_cart_item_price( $cart_item ) {
			if ( self::is_coin_booking_cart_item( $cart_item ) && ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
				$cart_item['data']->set_price( 0 );
			}

			return $cart_item;
		}

		/**
		 * Keep restored session bookings at zero money price.
		 *
		 * @param array  $cart_item     Cart item.
		 * @param array  $values        Session values.
		 * @param string $cart_item_key Cart item key.
		 * @return array
		 */
		public static function zero_coin_booking_session_item_price( $cart_item, $values, $cart_item_key ) {
			return self::zero_coin_booking_cart_item_price( $cart_item );
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

			$booking_count = self::get_cart_coin_booking_count();
			if ( $booking_count > 1 ) {
				self::add_validation_error( __( 'Please book one class, workshop, event, or Fire & Ice session at a time.', 'coin-booking-bridge' ), $errors );
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

			$user_id = get_current_user_id();

			if ( self::is_wallet_frozen_for_user( $user_id ) ) {
				self::add_validation_error( __( 'Your Zencoin wallet is temporarily paused because a membership subscription is on hold. Please update your membership before booking with coins.', 'coin-booking-bridge' ), $errors );
				return;
			}

			$context = self::get_checkout_context( $user_id );

			if ( 'mixed_recovery' === ( isset( $context['mode'] ) ? $context['mode'] : '' ) && (float) $context['projected_missing_zencoins'] <= 0 ) {
				return;
			}

			$balance = self::get_available_coin_balance( $user_id );
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
		 * Persist checkout context snapshot on newly created orders.
		 *
		 * This is the first mixed-recovery orchestration step. The snapshot is
		 * read-only for now and is not yet used to alter payment/booking flow.
		 *
		 * @param WC_Order $order Order object.
		 * @param array    $data  Posted checkout data.
		 * @return void
		 */
		public static function store_checkout_context_on_processed_order( $order_id, $data = array(), $order = null ) {
			if ( $order_id instanceof WC_Order ) {
				$order = $order_id;
			} else {
				$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			}

			if ( ! $order || $order->get_meta( self::META_CHECKOUT_MODE, true ) ) {
				return;
			}

			$context = self::get_checkout_context( (int) $order->get_customer_id() );
			$mode    = isset( $context['mode'] ) ? (string) $context['mode'] : 'money_purchase';

			$order->update_meta_data( self::META_CHECKOUT_MODE, $mode );

			if ( 'mixed_recovery' === $mode ) {
				$intent = array(
					'mode'                     => $mode,
					'required_zencoins'        => isset( $context['required_zencoins'] ) ? self::normalize_zencoin_amount( $context['required_zencoins'] ) : 0.0,
					'available_zencoins'       => isset( $context['available_zencoins'] ) ? self::normalize_zencoin_amount( $context['available_zencoins'] ) : 0.0,
					'missing_zencoins'         => isset( $context['missing_zencoins'] ) ? self::normalize_zencoin_amount( $context['missing_zencoins'] ) : 0.0,
					'wallet_is_frozen'         => ! empty( $context['wallet_is_frozen'] ),
					'blocking_reason'          => isset( $context['blocking_reason'] ) ? sanitize_key( $context['blocking_reason'] ) : '',
					'booking_items'            => ! empty( $context['booking_items'] ) ? array_values( $context['booking_items'] ) : array(),
					'recovery_credit_products' => ! empty( $context['recovery_credit_products'] ) ? array_values( $context['recovery_credit_products'] ) : array(),
					'captured_at_gmt'          => gmdate( 'Y-m-d H:i:s' ),
				);

				$order->update_meta_data( self::META_RECOVERY_INTENT, $intent );
				$order->add_order_note(
					sprintf(
						/* translators: %s: missing zencoin amount */
						__( 'Mixed recovery checkout intent captured. Missing ZC at checkout: %s.', 'coin-booking-bridge' ),
						wc_format_decimal( $intent['missing_zencoins'] )
					)
				);
			}

			$order->save();
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
		 * Store dynamic Zencoin grant data from checkout recovery cart items.
		 *
		 * @param WC_Order_Item_Product $item          Order item.
		 * @param string                $cart_item_key Cart item key.
		 * @param array                 $values        Cart item values.
		 * @param WC_Order              $order         Order.
		 */
		public static function store_order_item_dynamic_coin_grant( $item, $cart_item_key, $values, $order ) {
			$amount = isset( $values['cbb_dynamic_zencoin_grant_amount'] ) ? (float) $values['cbb_dynamic_zencoin_grant_amount'] : 0.0;

			if ( $amount <= 0 ) {
				return;
			}

			$product_type = isset( $values['cbb_dynamic_zencoin_product_type'] ) ? sanitize_key( $values['cbb_dynamic_zencoin_product_type'] ) : 'package';

			if ( ! in_array( $product_type, array( 'package', 'drop_in', 'free_drop_in' ), true ) ) {
				$product_type = 'package';
			}

			$source_label = ! empty( $values['cbb_dynamic_zencoin_source_label'] ) ? sanitize_text_field( $values['cbb_dynamic_zencoin_source_label'] ) : __( 'Member Zencoin Top-up', 'coin-booking-bridge' );
			$amount       = self::normalize_zencoin_amount( $amount );

			$item->add_meta_data( self::META_ZC_GRANT_AMOUNT, $amount, true );
			$item->add_meta_data( self::META_PRODUCT_TYPE, $product_type, true );
			$item->add_meta_data( self::META_SOURCE_LABEL, $source_label, true );
			$item->add_meta_data( __( 'Zencoins granted', 'coin-booking-bridge' ), wc_format_decimal( $amount ), true );
		}

		/**
		 * Get the booking product label to show in wallet activity.
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		private static function get_order_booking_product_label( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return '';
			}

			$labels = array();

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$item_coin_cost = (float) $item->get_meta( self::META_COIN_ITEM_COST, true );

				if ( $item_coin_cost <= 0 ) {
					$product_id     = self::get_item_product_or_parent_id( $item );
					$product_cost   = $product_id ? (float) get_post_meta( $product_id, self::META_BOOKING_COST, true ) : 0.0;
					$item_coin_cost = $product_cost * max( 1, (float) $item->get_quantity() );
				}

				if ( $item_coin_cost <= 0 ) {
					continue;
				}

				$label = $item->get_name();

				if ( '' === $label && method_exists( $item, 'get_product' ) ) {
					$product = $item->get_product();
					$label   = $product ? $product->get_name() : '';
				}

				$label = html_entity_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES, get_bloginfo( 'charset' ) );

				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}

			$labels = array_values( array_unique( $labels ) );

			return (string) apply_filters( 'cbb_order_booking_product_label', implode( ', ', $labels ), $order, $labels );
		}

		/**
		 * Get a clear wallet label for refunded booking coins.
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		private static function get_booking_refund_wallet_label( $order ) {
			$booking_label = self::get_order_booking_product_label( $order );

			if ( '' !== $booking_label ) {
				return sprintf(
					/* translators: %s: booking product name */
					__( 'Coin refund cancellation of booking for "%s"', 'coin-booking-bridge' ),
					$booking_label
				);
			}

			return sprintf(
				/* translators: %s: order number */
				__( 'Coin refund cancellation of booking for order #%s', 'coin-booking-bridge' ),
				$order instanceof WC_Order ? $order->get_order_number() : ''
			);
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

			if ( self::should_delay_mixed_recovery_debit( $order, $user_id, $coins ) ) {
				return;
			}

			if ( self::is_mixed_recovery_debit_blocked_by_status( $order ) ) {
				return;
			}

			if ( self::is_wallet_frozen_for_user( $user_id ) ) {
				$order->update_status( 'on-hold', __( 'Zencoin debit blocked because the customer has an on-hold membership subscription.', 'coin-booking-bridge' ) );
				return;
			}

			$booking_label = self::get_order_booking_product_label( $order );
			$details       = '' !== $booking_label ? $booking_label : sprintf( __( 'Booking order #%s', 'coin-booking-bridge' ), $order->get_order_number() );
			$consumption   = false;

			if ( self::get_zencoin_bucket_balance( $user_id ) + 0.000001 >= $coins ) {
				$consumption = self::debit_zencoin_buckets(
					$user_id,
					$coins,
					array(
						'entry_type'       => 'booking_charge',
						'label'            => $details,
						'related_order_id' => $order->get_id(),
						'metadata'         => array(
							'order_number' => $order->get_order_number(),
							'product_name'  => $booking_label,
						),
					)
				);

				if ( ! $consumption ) {
					$order->add_order_note( __( 'Bucket debit failed. Please review the Zencoin buckets and booking order manually.', 'coin-booking-bridge' ) );
					return;
				}
			}

			$transaction_id = self::mirror_wallet_debit(
				$user_id,
				$coins,
				$details,
				$order->get_currency( 'edit' )
			);

			if ( ! $transaction_id ) {
				$order->add_order_note( __( 'Coin debit failed. Please review the wallet balance and booking order manually.', 'coin-booking-bridge' ) );
				return;
			}

			$order->update_meta_data( self::META_DEBIT_TXN, $transaction_id );
			$order->update_meta_data( self::META_COIN_TOTAL, $coins );
			if ( $consumption ) {
				$order->update_meta_data( self::META_COIN_CONSUMPTION, $consumption );
			}
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
		 * Delay mixed-recovery booking debit until the purchased credits exist.
		 *
		 * This avoids noisy failure notes from the legacy debit hooks while we
		 * are still building the dedicated mixed-recovery orchestration path.
		 *
		 * @param WC_Order $order   Order object.
		 * @param int      $user_id Customer ID.
		 * @param float    $coins   Required booking coins.
		 * @return bool
		 */
		private static function should_delay_mixed_recovery_debit( $order, $user_id, $coins ) {
			if ( ! $order || 'mixed_recovery' !== $order->get_meta( self::META_CHECKOUT_MODE, true ) ) {
				return false;
			}

			$available = max(
				self::get_wallet_balance( $user_id ),
				self::get_zencoin_bucket_balance( $user_id )
			);

			return $available + 0.000001 < $coins;
		}

		/**
		 * Check whether a mixed-recovery status should prevent later debit hooks.
		 *
		 * @param WC_Order $order Order object.
		 * @return bool
		 */
		private static function is_mixed_recovery_debit_blocked_by_status( $order ) {
			if ( ! $order || 'mixed_recovery' !== $order->get_meta( self::META_CHECKOUT_MODE, true ) ) {
				return false;
			}

			return in_array(
				(string) $order->get_meta( self::META_RECOVERY_STATUS, true ),
				array(
					'payment_failed',
					'booking_full',
					'booking_failed',
					'blocked_wallet_frozen',
					'blocked_insufficient_after_grant',
				),
				true
			);
		}

		/**
		 * Attempt mixed-recovery booking finalization after credits are granted.
		 *
		 * @param int|WC_Order $order_id Order ID or object.
		 * @param string       $source   Trigger source for diagnostics.
		 * @return void
		 */
		private static function attempt_mixed_recovery_booking_finalization( $order_id, $source = '' ) {
			$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );

			if ( ! $order || 'mixed_recovery' !== $order->get_meta( self::META_CHECKOUT_MODE, true ) || $order->get_meta( self::META_DEBIT_TXN, true ) ) {
				return;
			}

			$user_id = (int) $order->get_customer_id();
			$coins   = self::get_order_coin_total( $order );

			if ( $user_id <= 0 || $coins <= 0 ) {
				return;
			}

			if ( self::is_wallet_frozen_for_user( $user_id ) ) {
				self::pause_mixed_recovery_booking_fulfillment( $order );
				self::set_mixed_recovery_status(
					$order,
					'blocked_wallet_frozen',
					__( 'Mixed recovery payment succeeded, but booking finalization is paused because the customer wallet is frozen by an on-hold membership.', 'coin-booking-bridge' ),
					array(
						'user_message' => __( 'Your payment was completed, but your Zencoin wallet is currently paused because a membership is on hold. Your Zencoins remain in your wallet.', 'coin-booking-bridge' ),
						'action'       => 'profile',
					)
				);
				return;
			}

			$availability = self::check_mixed_recovery_booking_availability( $order );

			if ( is_wp_error( $availability ) ) {
				self::pause_mixed_recovery_booking_fulfillment( $order );
				self::set_mixed_recovery_status(
					$order,
					'booking_full',
					sprintf(
						/* translators: %s: availability error message */
						__( 'Mixed recovery payment succeeded, but booking finalization was stopped because the selected booking is no longer available. Purchased credits remain in the wallet. Availability message: %s', 'coin-booking-bridge' ),
						$availability->get_error_message()
					),
					array(
						'user_message' => __( 'Your payment was completed, but this class filled up at the last moment. Your Zencoins remain in your wallet so you can schedule another class.', 'coin-booking-bridge' ),
						'action'       => 'schedule',
						'error_code'   => $availability->get_error_code(),
					)
				);
				return;
			}

			$available = max(
				self::get_wallet_balance( $user_id ),
				self::get_zencoin_bucket_balance( $user_id )
			);

			if ( $available + 0.000001 < $coins ) {
				self::pause_mixed_recovery_booking_fulfillment( $order );
				self::set_mixed_recovery_status(
					$order,
					'blocked_insufficient_after_grant',
					sprintf(
						/* translators: 1: required zencoins, 2: available zencoins, 3: source */
						__( 'Mixed recovery could not finalize booking automatically after %3$s. Purchased credits remain in the wallet. Required ZC: %1$s. Available ZC: %2$s.', 'coin-booking-bridge' ),
						wc_format_decimal( $coins ),
						wc_format_decimal( $available ),
						$source ? $source : __( 'credit grant', 'coin-booking-bridge' )
					),
					array(
						'user_message' => __( 'Your payment was completed, but the booking could not be finalized because there are still not enough Zencoins available. Purchased Zencoins remain in your wallet.', 'coin-booking-bridge' ),
						'action'       => 'profile',
					)
				);
				return;
			}

			if ( $order->get_meta( self::META_RECOVERY_STATUS, true ) ) {
				$order->delete_meta_data( self::META_RECOVERY_STATUS );
				$order->save();
			}

			self::debit_order_booking_coins( $order );

			if ( $order->get_meta( self::META_DEBIT_TXN, true ) ) {
				self::set_mixed_recovery_status(
					$order,
					'completed',
					'',
					array(
						'user_message' => __( 'Booking completed.', 'coin-booking-bridge' ),
						'action'       => 'profile',
					)
				);
				$order->save();
				return;
			}

			self::pause_mixed_recovery_booking_fulfillment( $order );
			self::set_mixed_recovery_status(
				$order,
				'booking_failed',
				__( 'Mixed recovery payment succeeded and the selected booking was still available, but Zencoin debit/final booking completion failed. Purchased credits remain in the wallet.', 'coin-booking-bridge' ),
				array(
					'user_message' => __( 'A technical issue prevented booking completion. Your Zencoins were credited to your account and you can try booking again at any time.', 'coin-booking-bridge' ),
					'action'       => 'schedule',
				)
			);
		}

		/**
		 * Update mixed-recovery status once without repeating the same note.
		 *
		 * @param WC_Order $order   Order object.
		 * @param string   $status  Status key.
		 * @param string   $note    Optional order note.
		 * @param array    $context Optional UI/API context.
		 * @return void
		 */
		private static function set_mixed_recovery_status( $order, $status, $note = '', $context = array() ) {
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$current = (string) $order->get_meta( self::META_RECOVERY_STATUS, true );

			if ( $current === $status && empty( $context ) ) {
				return;
			}

			$order->update_meta_data( self::META_RECOVERY_STATUS, $status );
			$order->update_meta_data(
				self::META_RECOVERY_CONTEXT,
				array_merge(
					array(
						'status'         => $status,
						'user_message'   => '',
						'action'         => '',
						'updated_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
					),
					array_filter(
						(array) $context,
						function ( $value ) {
							return null !== $value && '' !== $value;
						}
					)
				)
			);

			if ( '' !== $note && $current !== $status ) {
				$order->add_order_note( $note );
			}

			$order->save();
		}

		/**
		 * Mark mixed-recovery orders whose money payment failed.
		 *
		 * @param int $order_id Order ID.
		 * @return void
		 */
		public static function mark_mixed_recovery_payment_failed( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order || 'mixed_recovery' !== $order->get_meta( self::META_CHECKOUT_MODE, true ) || $order->get_meta( self::META_DEBIT_TXN, true ) ) {
				return;
			}

			self::set_mixed_recovery_status(
				$order,
				'payment_failed',
				__( 'Mixed recovery payment failed before Zencoin booking finalization. No booking Zencoins were debited.', 'coin-booking-bridge' ),
				array(
					'user_message' => __( 'Something went wrong while processing payment. Please try again or use a different payment method.', 'coin-booking-bridge' ),
					'action'       => 'try_again',
				)
			);
		}

		/**
		 * Re-check mixed-recovery booking availability before spending Zencoins.
		 *
		 * @param WC_Order $order Order object.
		 * @return true|WP_Error
		 */
		private static function check_mixed_recovery_booking_availability( $order ) {
			if ( ! $order instanceof WC_Order || ! function_exists( 'get_wc_booking' ) || ! class_exists( 'WC_Booking_Data_Store' ) ) {
				return true;
			}

			$booking_ids = WC_Booking_Data_Store::get_booking_ids_from_order_id( $order->get_id() );

			if ( empty( $booking_ids ) ) {
				return true;
			}

			$result = self::with_order_bookings_excluded_from_availability(
				$booking_ids,
				function () use ( $booking_ids ) {
					foreach ( $booking_ids as $booking_id ) {
						$booking = get_wc_booking( $booking_id );

						if ( ! $booking || $booking->has_status( array( 'cancelled', 'trash' ) ) ) {
							return new WP_Error( 'booking_unavailable', __( 'The selected booking is no longer available.', 'coin-booking-bridge' ) );
						}

						$product = method_exists( $booking, 'get_product' ) ? $booking->get_product() : false;

						if ( ! $product || ! method_exists( $product, 'is_bookable' ) ) {
							return new WP_Error( 'booking_product_unavailable', __( 'The selected booking product is no longer available.', 'coin-booking-bridge' ) );
						}

						$data     = self::build_booking_validation_data_from_booking( $booking, $product );
						$validate = $product->is_bookable( $data );

						if ( is_wp_error( $validate ) ) {
							return $validate;
						}
					}

					return true;
				}
			);

			/**
			 * Allow site-specific availability integrations to refine the final
			 * paid-before-debit mixed-recovery decision.
			 *
			 * @param true|WP_Error $result      Availability result.
			 * @param WC_Order      $order       Order object.
			 * @param array         $booking_ids Booking IDs attached to the order.
			 */
			return apply_filters( 'cbb_mixed_recovery_booking_availability_result', $result, $order, $booking_ids );
		}

		/**
		 * Build Woo Bookings validation data from an already-created booking.
		 *
		 * @param WC_Booking         $booking Booking object.
		 * @param WC_Product_Booking $product Booking product.
		 * @return array
		 */
		private static function build_booking_validation_data_from_booking( $booking, $product ) {
			$start     = method_exists( $booking, 'get_start' ) ? (int) $booking->get_start( 'edit' ) : 0;
			$end       = method_exists( $booking, 'get_end' ) ? (int) $booking->get_end( 'edit' ) : 0;
			$persons   = method_exists( $booking, 'get_persons' ) ? (array) $booking->get_persons() : array();
			$duration  = 1;
			$unit      = method_exists( $product, 'get_duration_unit' ) ? $product->get_duration_unit() : '';
			$base      = method_exists( $product, 'get_duration' ) ? max( 1, (int) $product->get_duration() ) : 1;
			$qty       = 1;

			if ( method_exists( $product, 'is_duration_type' ) && $product->is_duration_type( 'customer' ) && $start > 0 && $end > $start ) {
				$seconds_per_block = 'hour' === $unit ? $base * HOUR_IN_SECONDS : ( 'day' === $unit ? $base * DAY_IN_SECONDS : $base * MINUTE_IN_SECONDS );
				$duration          = max( 1, (int) ceil( ( $end - $start ) / max( 1, $seconds_per_block ) ) );
			}

			if ( method_exists( $product, 'get_has_person_qty_multiplier' ) && $product->get_has_person_qty_multiplier() && ! empty( $persons ) ) {
				$qty = max( 1, array_sum( array_map( 'absint', $persons ) ) );
			}

			return array(
				'_start_date'    => $start,
				'_end_date'      => $end,
				'_date'          => $start ? date( 'Y-m-d', $start ) : '',
				'date'           => $start ? date_i18n( wc_bookings_date_format(), $start ) : '',
				'_time'          => $start ? date( 'G:i', $start ) : '',
				'time'           => $start ? date_i18n( wc_bookings_time_format(), $start ) : '',
				'_duration'      => $duration,
				'_duration_unit' => $unit,
				'_resource_id'   => method_exists( $booking, 'get_resource_id' ) ? (int) $booking->get_resource_id( 'edit' ) : 0,
				'_qty'           => $qty,
				'_persons'       => $persons,
			);
		}

		/**
		 * Run an availability callback while excluding this order's own bookings.
		 *
		 * @param array    $booking_ids Booking IDs to exclude.
		 * @param callable $callback    Availability callback.
		 * @return mixed
		 */
		private static function with_order_bookings_excluded_from_availability( $booking_ids, $callback ) {
			global $wpdb;

			$booking_ids = wp_parse_id_list( $booking_ids );
			$statuses    = array();

			foreach ( $booking_ids as $booking_id ) {
				$statuses[ $booking_id ] = get_post_status( $booking_id );
				$wpdb->update(
					$wpdb->posts,
					array( 'post_status' => 'was-in-cart' ),
					array( 'ID' => $booking_id ),
					array( '%s' ),
					array( '%d' )
				);
				clean_post_cache( $booking_id );
			}

			self::clear_bookings_availability_cache();

			try {
				$result = is_callable( $callback ) ? call_user_func( $callback ) : true;
			} finally {
				foreach ( $statuses as $booking_id => $status ) {
					if ( $status ) {
						$wpdb->update(
							$wpdb->posts,
							array( 'post_status' => $status ),
							array( 'ID' => $booking_id ),
							array( '%s' ),
							array( '%d' )
						);
						clean_post_cache( $booking_id );
					}
				}

				self::clear_bookings_availability_cache();
			}

			return $result;
		}

		/**
		 * Clear Woo Bookings availability caches after temporary status changes.
		 *
		 * @return void
		 */
		private static function clear_bookings_availability_cache() {
			if ( class_exists( 'WC_Bookings_Cache' ) && method_exists( 'WC_Bookings_Cache', 'delete_booking_slots_transient' ) ) {
				WC_Bookings_Cache::delete_booking_slots_transient();
			}

			if ( class_exists( 'WC_Cache_Helper' ) ) {
				WC_Cache_Helper::get_transient_version( 'bookings', true );
			}
		}

		/**
		 * Revert booking fulfillment for mixed-recovery failures.
		 *
		 * The payment may already be successful, but the booking should not stay
		 * in a paid/fulfilled state until the Zencoin debit actually succeeds.
		 *
		 * @param WC_Order $order Order object.
		 * @return void
		 */
		private static function pause_mixed_recovery_booking_fulfillment( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			if ( function_exists( 'get_wc_booking' ) && class_exists( 'WC_Booking_Data_Store' ) ) {
				$booking_ids = WC_Booking_Data_Store::get_booking_ids_from_order_id( $order->get_id() );

				foreach ( $booking_ids as $booking_id ) {
					$booking = get_wc_booking( $booking_id );

					if ( ! $booking || $booking->has_status( array( 'unpaid', 'cancelled', 'was-in-cart' ) ) ) {
						continue;
					}

					$booking->set_status( 'unpaid' );
					$booking->save();
				}
			}

			if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
				$order->update_status(
					'on-hold',
					__( 'Mixed recovery booking fulfillment paused until Zencoin finalization succeeds.', 'coin-booking-bridge' )
				);
			}
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

			if ( $order->get_meta( self::META_COIN_CONSUMPTION, true ) && self::refund_order_bucket_consumption( $order, self::get_booking_refund_wallet_label( $order ), 'refund_on_time_cancel' ) ) {
				return;
			}

			$refundable        = self::get_order_refundable_coin_total( $order );
			$already_refunded  = (float) $order->get_meta( self::META_REFUNDED_TOTAL, true );
			$late_no_refund    = (float) $order->get_meta( self::META_LATE_CANCEL_TOTAL, true );
			$coins             = max( 0, $refundable - $already_refunded - $late_no_refund );

			if ( $coins <= 0 ) {
				return;
			}

			self::credit_coin_refund( $order, $coins, self::get_booking_refund_wallet_label( $order ) );
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

			if ( self::is_late_booking_cancellation( $booking ) ) {
				self::record_late_booking_cancellation( $booking, $order, $item_coin_cost );
				return;
			}

			if ( $order->get_meta( self::META_COIN_CONSUMPTION, true ) && self::refund_order_bucket_consumption( $order, self::get_booking_refund_wallet_label( $order ), 'refund_on_time_cancel' ) ) {
				$booking->update_meta_data( self::META_REFUND_TXN, 'bucket_refund' );
				$booking->save();
				return;
			}

			$refundable       = self::get_order_refundable_coin_total( $order );
			$already_refunded = (float) $order->get_meta( self::META_REFUNDED_TOTAL, true );
			$late_no_refund   = (float) $order->get_meta( self::META_LATE_CANCEL_TOTAL, true );
			$coins            = min( $item_coin_cost, max( 0, $refundable - $already_refunded - $late_no_refund ) );

			if ( $coins <= 0 ) {
				return;
			}

			$transaction_id = self::credit_coin_refund( $order, $coins, self::get_booking_refund_wallet_label( $order ) );

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
		 * Check whether a booking cancellation is inside the no-refund cutoff.
		 *
		 * @param WC_Booking $booking Booking object.
		 * @return bool
		 */
		private static function is_late_booking_cancellation( $booking ) {
			if ( ! $booking || ! is_object( $booking ) || ! method_exists( $booking, 'get_start' ) ) {
				return false;
			}

			$settings     = self::get_settings();
			$cutoff_hours = isset( $settings['on_time_cancel_cutoff_hours'] ) ? absint( $settings['on_time_cancel_cutoff_hours'] ) : 12;
			$start_time   = (int) $booking->get_start( 'edit' );

			if ( $start_time <= 0 || $cutoff_hours <= 0 ) {
				return false;
			}

			return ( current_time( 'timestamp' ) + ( $cutoff_hours * HOUR_IN_SECONDS ) ) > $start_time;
		}

		/**
		 * Record a late cancellation without refunding consumed Zencoins.
		 *
		 * @param WC_Booking $booking Booking object.
		 * @param WC_Order   $order   Order object.
		 * @param float      $coins   Coin amount not refunded.
		 */
		private static function record_late_booking_cancellation( $booking, $order, $coins ) {
			if ( ! $booking || ! ( $order instanceof WC_Order ) || $booking->get_meta( self::META_REFUND_TXN, true ) ) {
				return;
			}

			$user_id = (int) $order->get_customer_id();
			$coins   = (float) $coins;

			if ( $user_id <= 0 || $coins <= 0 ) {
				return;
			}

			$already_late = (float) $order->get_meta( self::META_LATE_CANCEL_TOTAL, true );
			$settings     = self::get_settings();
			$ledger_id    = self::add_zencoin_ledger_entry(
				$user_id,
				$coins,
				array(
					'entry_type'         => 'late_cancel',
					'direction'          => 'debit',
					'balance_after'      => self::get_zencoin_bucket_balance( $user_id ),
					'label'              => __( 'Late cancellation - no Zencoin refund', 'coin-booking-bridge' ),
					'related_order_id'   => $order->get_id(),
					'related_booking_id' => method_exists( $booking, 'get_id' ) ? $booking->get_id() : null,
					'metadata'           => array(
						'order_number'      => $order->get_order_number(),
						'booking_start'     => method_exists( $booking, 'get_start' ) ? $booking->get_start( 'edit' ) : '',
						'cutoff_hours'      => isset( $settings['on_time_cancel_cutoff_hours'] ) ? absint( $settings['on_time_cancel_cutoff_hours'] ) : 12,
						'no_balance_change' => true,
					),
				)
			);

			$order->update_meta_data( self::META_LATE_CANCEL_TOTAL, $already_late + $coins );
			$order->add_order_note(
				sprintf(
					/* translators: 1: coin amount, 2: ledger id */
					__( 'Late booking cancellation recorded. %1$s ZC were not refunded. Ledger entry: %2$s.', 'coin-booking-bridge' ),
					wc_format_decimal( $coins ),
					$ledger_id ? $ledger_id : __( 'not created', 'coin-booking-bridge' )
				)
			);
			$order->save();

			$booking->update_meta_data( self::META_REFUND_TXN, 'late_cancel_no_refund' );
			$booking->save();
		}

		/**
		 * Refund a bucket-aware booking order back to the original consumed buckets.
		 *
		 * If the original bucket has already expired, the refund becomes a new
		 * purchased-credit bucket valid for three months from the refund date.
		 *
		 * @param WC_Order $order      Order object.
		 * @param string   $label      Ledger label.
		 * @param string   $entry_type Ledger entry type.
		 * @return bool
		 */
		private static function refund_order_bucket_consumption( $order, $label, $entry_type ) {
			global $wpdb;

			if ( ! $order instanceof WC_Order || $order->get_meta( self::META_REFUND_TXN, true ) ) {
				return false;
			}

			$consumption = $order->get_meta( self::META_COIN_CONSUMPTION, true );

			if ( ! is_array( $consumption ) || empty( $consumption ) ) {
				return false;
			}

			$refundable       = self::get_order_refundable_coin_total( $order );
			$already_refunded = (float) $order->get_meta( self::META_REFUNDED_TOTAL, true );
			$late_no_refund   = (float) $order->get_meta( self::META_LATE_CANCEL_TOTAL, true );
			$remaining_refund = max( 0, $refundable - $already_refunded - $late_no_refund );

			if ( $remaining_refund <= 0 ) {
				return false;
			}

			$table          = self::get_buckets_table_name();
			$user_id        = (int) $order->get_customer_id();
			$now            = current_time( 'mysql' );
			$total_refunded = 0.0;
			$refunds        = array();

			foreach ( $consumption as $used ) {
				if ( $remaining_refund <= 0 ) {
					break;
				}

				$bucket_id = ! empty( $used['bucket_id'] ) ? absint( $used['bucket_id'] ) : 0;
				$amount    = isset( $used['amount'] ) ? min( (float) $used['amount'], $remaining_refund ) : 0.0;

				if ( $bucket_id <= 0 || $amount <= 0 ) {
					continue;
				}

				$bucket = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$bucket_id,
						$user_id
					)
				);

				if ( ! $bucket ) {
					continue;
				}

				$related_booking_id = ! empty( $used['related_booking_id'] ) ? absint( $used['related_booking_id'] ) : null;
				$is_expired         = $bucket->expires_at && $bucket->expires_at <= $now;

				if ( $is_expired ) {
					$refund_expires_at = self::calculate_expiry_datetime( 3 );
					$refund_bucket_id = self::create_zencoin_bucket(
						$user_id,
						$amount,
						array(
							'source_type'           => 'purchased_credit',
							'expires_at'            => $refund_expires_at,
							'related_order_id'      => $order->get_id(),
							'related_product_id'    => $bucket->related_product_id,
							'related_booking_id'    => $related_booking_id,
							'source_label'          => __( 'Purchased Credit Refund', 'coin-booking-bridge' ),
							'metadata'              => array(
								'original_bucket_id'         => $bucket_id,
								'original_source_type'       => $bucket->source_type,
								'original_bucket_expires_at' => $bucket->expires_at,
								'refunded_at'                => $now,
								'refund_reason'              => 'original_bucket_expired',
							),
						)
					);

					if ( ! $refund_bucket_id ) {
						continue;
					}

					$ledger_id = self::add_zencoin_ledger_entry(
						$user_id,
						$amount,
						array(
							'bucket_id'          => $refund_bucket_id,
							'entry_type'         => $entry_type,
							'direction'          => 'credit',
							'balance_after'      => self::get_zencoin_bucket_balance( $user_id ),
							'label'              => $label,
							'related_order_id'   => $order->get_id(),
							'related_booking_id' => $related_booking_id,
							'metadata'           => array(
								'order_number'               => $order->get_order_number(),
								'refunded_at'                => $now,
								'expires_at'                 => $refund_expires_at,
								'source_type'                => 'purchased_credit',
								'original_bucket_id'         => $bucket_id,
								'original_source_type'       => $bucket->source_type,
								'original_bucket_expires_at' => $bucket->expires_at,
							),
						)
					);

					$refunds[] = array(
						'bucket_id'          => $refund_bucket_id,
						'original_bucket_id' => $bucket_id,
						'ledger_id'          => $ledger_id,
						'amount'             => self::format_zencoin_amount( $amount ),
						'expires_at'         => $refund_expires_at,
						'refund_bucket_type' => 'purchased_credit',
					);

					$total_refunded   += $amount;
					$remaining_refund -= $amount;
					continue;
				}

				$new_remaining = min( (float) $bucket->original_amount, (float) $bucket->remaining_amount + $amount );
				$actual_refund = max( 0, $new_remaining - (float) $bucket->remaining_amount );

				if ( $actual_refund <= 0 ) {
					continue;
				}

				$updated = $wpdb->update(
					$table,
					array(
						'remaining_amount' => self::format_zencoin_amount( $new_remaining ),
						'status'           => 'active',
						'updated_at'       => $now,
					),
					array( 'id' => $bucket_id ),
					array( '%f', '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					continue;
				}

				$ledger_id = self::add_zencoin_ledger_entry(
					$user_id,
					$actual_refund,
					array(
						'bucket_id'          => $bucket_id,
						'entry_type'         => $entry_type,
						'direction'          => 'credit',
						'balance_after'      => self::get_zencoin_bucket_balance( $user_id ),
						'label'              => $label,
						'related_order_id'   => $order->get_id(),
						'related_booking_id' => $related_booking_id,
						'metadata'           => array(
							'order_number' => $order->get_order_number(),
							'refunded_at'  => $now,
							'expires_at'   => $bucket->expires_at,
							'source_type'  => $bucket->source_type,
						),
					)
				);

				$refunds[] = array(
					'bucket_id' => $bucket_id,
					'ledger_id' => $ledger_id,
					'amount'    => self::format_zencoin_amount( $actual_refund ),
				);

				$total_refunded   += $actual_refund;
				$remaining_refund -= $actual_refund;
			}

			if ( $total_refunded <= 0 ) {
				return false;
			}

			$transaction_id = self::mirror_wallet_credit(
				$user_id,
				$total_refunded,
				self::get_booking_refund_wallet_label( $order ),
				$order->get_currency( 'edit' )
			);

			$order->update_meta_data( self::META_REFUND_TXN, $transaction_id ? $transaction_id : 'bucket_refund_only' );
			$order->update_meta_data( self::META_REFUNDED_TOTAL, $already_refunded + $total_refunded );
			$order->update_meta_data( '_cbb_coin_refund_consumption', $refunds );
			$order->add_order_note(
				sprintf(
					/* translators: 1: coin amount, 2: transaction id */
					__( 'Refunded %1$s booking coins to Zencoin buckets. Expired original buckets were converted to purchased-credit refund buckets. Wallet transaction: %2$s.', 'coin-booking-bridge' ),
					wc_format_decimal( $total_refunded ),
					$transaction_id ? $transaction_id : __( 'not mirrored', 'coin-booking-bridge' )
				)
			);
			$order->save();

			return true;
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
		 * Get normalized checkout context for the current cart.
		 *
		 * This is a read-only API for external UI consumers such as custom
		 * checkout flows. It does not alter runtime checkout behavior.
		 *
		 * @param int   $user_id Optional user ID override.
		 * @param array $args    Optional context arguments.
		 * @return array
		 */
		public static function get_checkout_context( $user_id = 0, $args = array() ) {
			$cart = isset( $args['cart'] ) && $args['cart'] instanceof WC_Cart ? $args['cart'] : ( function_exists( 'WC' ) ? WC()->cart : null );

			$user_id = $user_id > 0 ? (int) $user_id : get_current_user_id();
			$context = array(
				'mode'                         => 'money_purchase',
				'has_booking_items'            => false,
				'has_credit_products'          => false,
				'has_recovery_products'        => false,
				'required_zencoins'            => 0.0,
				'available_zencoins'           => $user_id > 0 ? self::get_available_coin_balance( $user_id ) : 0.0,
				'recovery_zencoins'            => 0.0,
				'projected_available_zencoins' => 0.0,
				'missing_zencoins'             => 0.0,
				'projected_missing_zencoins'   => 0.0,
				'booking_items'                => array(),
				'credit_products'              => array(),
				'recovery_credit_products'     => array(),
				'non_recovery_credit_products' => array(),
				'allowed_recovery_product_types' => array( 'membership', 'package', 'drop_in', 'free_drop_in' ),
				'wallet_is_frozen'             => $user_id > 0 ? self::is_wallet_frozen_for_user( $user_id ) : false,
				'blocking_reason'              => '',
			);

			if ( ! $cart instanceof WC_Cart || $cart->is_empty() ) {
				return (array) apply_filters( 'cbb_checkout_context', $context, $user_id, $args, $cart );
			}

			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( self::is_coin_booking_cart_item( $cart_item ) ) {
					$context['has_booking_items'] = true;
					$item_cost                    = self::get_cart_item_coin_cost( $cart_item );
					$context['required_zencoins'] += $item_cost;
					$context['booking_items'][]   = self::build_checkout_context_booking_item( $cart_item_key, $cart_item, $item_cost );
					continue;
				}

				if ( self::is_credit_purchase_cart_item( $cart_item ) ) {
					$credit_item                      = self::build_checkout_context_credit_item( $cart_item_key, $cart_item, $context['allowed_recovery_product_types'] );
					$context['has_credit_products']   = true;
					$context['credit_products'][]     = $credit_item;

					if ( ! empty( $credit_item['is_recovery_eligible'] ) ) {
						$context['has_recovery_products']    = true;
						$context['recovery_credit_products'][] = $credit_item;
						$context['recovery_zencoins']        += isset( $credit_item['granted_zencoins'] ) ? (float) $credit_item['granted_zencoins'] : 0.0;
					} else {
						$context['non_recovery_credit_products'][] = $credit_item;
					}
				}
			}

			$context['required_zencoins'] = self::normalize_zencoin_amount( $context['required_zencoins'] );
			$context['recovery_zencoins'] = self::normalize_zencoin_amount( $context['recovery_zencoins'] );
			$context['projected_available_zencoins'] = self::normalize_zencoin_amount( $context['available_zencoins'] + $context['recovery_zencoins'] );
			$context['missing_zencoins']  = self::normalize_zencoin_amount( max( 0, $context['required_zencoins'] - $context['available_zencoins'] ) );
			$context['projected_missing_zencoins'] = self::normalize_zencoin_amount( max( 0, $context['required_zencoins'] - $context['projected_available_zencoins'] ) );
			$context['mode']              = self::classify_cart_mode( $user_id, $context );
			$context['blocking_reason']   = self::get_checkout_context_blocking_reason( $context );

			return (array) apply_filters( 'cbb_checkout_context', $context, $user_id, $args, $cart );
		}

		/**
		 * Classify the current cart into a checkout mode.
		 *
		 * @param int   $user_id Optional user ID override.
		 * @param array $context Optional pre-built context.
		 * @return string
		 */
		public static function classify_cart_mode( $user_id = 0, $context = array() ) {
			if ( empty( $context ) ) {
				$context = self::get_checkout_context( $user_id );
			}

			if ( ! empty( $context['has_booking_items'] ) ) {
				if ( ! empty( $context['wallet_is_frozen'] ) ) {
					return 'insufficient_prompt';
				}

				if ( (float) $context['missing_zencoins'] <= 0 ) {
					return 'zencoin_booking';
				}

				if ( ! empty( $context['has_recovery_products'] ) && (float) $context['projected_missing_zencoins'] <= 0 ) {
					return 'mixed_recovery';
				}

				return 'insufficient_prompt';
			}

			return 'money_purchase';
		}

		/**
		 * Count coin-paid booking units in the cart.
		 *
		 * @return int
		 */
		private static function get_cart_coin_booking_count() {
			if ( ! WC()->cart ) {
				return 0;
			}

			$count = 0;

			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( self::is_coin_booking_cart_item( $cart_item ) ) {
					$count += isset( $cart_item['quantity'] ) ? max( 1, absint( $cart_item['quantity'] ) ) : 1;
				}
			}

			return (int) apply_filters( 'cbb_cart_coin_booking_count', $count, WC()->cart );
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
			$product_id = self::get_cart_item_product_id( $cart_item );

			$cost       = $product_id ? (float) get_post_meta( $product_id, self::META_BOOKING_COST, true ) : 0.0;
			$quantity   = isset( $cart_item['quantity'] ) ? max( 1, (float) $cart_item['quantity'] ) : 1;

			return (float) apply_filters( 'cbb_cart_item_coin_cost', $cost * $quantity, $cart_item );
		}

		/**
		 * Check whether a product is a coin-paid booking product.
		 *
		 * @param int $product_id   Product ID.
		 * @param int $variation_id Variation ID.
		 * @return bool
		 */
		private static function is_coin_booking_product( $product_id, $variation_id = 0 ) {
			$product_id   = absint( $product_id );
			$variation_id = absint( $variation_id );
			$lookup_id    = $variation_id > 0 ? $variation_id : $product_id;

			if ( $lookup_id <= 0 ) {
				return false;
			}

			$cost = (float) get_post_meta( $lookup_id, self::META_BOOKING_COST, true );
			if ( $cost <= 0 && $variation_id > 0 ) {
				$cost = (float) get_post_meta( $product_id, self::META_BOOKING_COST, true );
			}

			if ( $cost <= 0 ) {
				return false;
			}

			$products = array( wc_get_product( $lookup_id ) );
			if ( $variation_id > 0 ) {
				$products[] = wc_get_product( $product_id );
			}
			$products = array_filter( $products );

			foreach ( $products as $product ) {
				if ( function_exists( 'is_wc_booking_product' ) && is_wc_booking_product( $product ) ) {
					return true;
				}

				if ( method_exists( $product, 'is_type' ) && $product->is_type( 'booking' ) ) {
					return true;
				}

				if ( is_a( $product, 'WC_Product_Booking' ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check whether cart item is coin-paid booking.
		 *
		 * @param array $cart_item Cart item.
		 * @return bool
		 */
		private static function is_coin_booking_cart_item( $cart_item ) {
			if ( self::get_cart_item_coin_cost( $cart_item ) <= 0 ) {
				return false;
			}

			if ( ! empty( $cart_item['booking'] ) ) {
				return true;
			}

			$product = ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null;

			if ( ! $product ) {
				return false;
			}

			if ( function_exists( 'is_wc_booking_product' ) && is_wc_booking_product( $product ) ) {
				return true;
			}

			if ( method_exists( $product, 'is_type' ) && $product->is_type( 'booking' ) ) {
				return true;
			}

			return is_a( $product, 'WC_Product_Booking' );
		}

		/**
		 * Check whether a cart item is a Zencoin credit purchase item.
		 *
		 * @param array $cart_item Cart item.
		 * @return bool
		 */
		private static function is_credit_purchase_cart_item( $cart_item ) {
			$product_id = self::get_cart_item_product_id( $cart_item );

			if ( $product_id <= 0 ) {
				return false;
			}

			$product_type = (string) get_post_meta( $product_id, self::META_PRODUCT_TYPE, true );
			if ( in_array( $product_type, array( 'package', 'drop_in', 'free_drop_in', 'gift_card', 'auto_top_up' ), true ) ) {
				return true;
			}

			return self::get_cart_item_coin_grant_amount( $cart_item ) > 0;
		}

		/**
		 * Check whether a credit product type is eligible to recover a booking shortage.
		 *
		 * @param string $product_type Product type.
		 * @param array  $allowed_types Allowed recovery product types.
		 * @return bool
		 */
		private static function is_recovery_eligible_product_type( $product_type, $allowed_types ) {
			return in_array( $product_type, $allowed_types, true );
		}

		/**
		 * Get product/variation ID for a cart item.
		 *
		 * @param array $cart_item Cart item.
		 * @return int
		 */
		private static function get_cart_item_product_id( $cart_item ) {
			$variation_id = ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			$product_id   = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

			if ( $variation_id > 0 ) {
				return $variation_id;
			}

			if ( $product_id > 0 ) {
				return $product_id;
			}

			if ( ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_id' ) ) {
				return (int) $cart_item['data']->get_id();
			}

			return 0;
		}

		/**
		 * Get Zencoin grant amount represented by a cart item.
		 *
		 * @param array $cart_item Cart item.
		 * @return float
		 */
		private static function get_cart_item_coin_grant_amount( $cart_item ) {
			if ( ! empty( $cart_item['cbb_dynamic_zencoin_grant_amount'] ) ) {
				$quantity = isset( $cart_item['quantity'] ) ? max( 1, (float) $cart_item['quantity'] ) : 1;

				return self::normalize_zencoin_amount( (float) $cart_item['cbb_dynamic_zencoin_grant_amount'] * $quantity );
			}

			$product_id = self::get_cart_item_product_id( $cart_item );

			if ( $product_id <= 0 ) {
				return 0.0;
			}

			$grant_amount = (float) get_post_meta( $product_id, self::META_ZC_GRANT_AMOUNT, true );

			if ( $grant_amount <= 0 ) {
				$grant_amount = (float) get_post_meta( $product_id, self::META_GRANT_AMOUNT, true );
			}

			if ( $grant_amount <= 0 ) {
				$parent_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

				if ( $parent_id > 0 && $parent_id !== $product_id ) {
					$grant_amount = (float) get_post_meta( $parent_id, self::META_ZC_GRANT_AMOUNT, true );

					if ( $grant_amount <= 0 ) {
						$grant_amount = (float) get_post_meta( $parent_id, self::META_GRANT_AMOUNT, true );
					}
				}
			}

			$quantity = isset( $cart_item['quantity'] ) ? max( 1, (float) $cart_item['quantity'] ) : 1;

			return self::normalize_zencoin_amount( $grant_amount * $quantity );
		}

		/**
		 * Build checkout-context data for a booking cart item.
		 *
		 * @param string $cart_item_key Cart item key.
		 * @param array  $cart_item     Cart item.
		 * @param float  $item_cost     Required Zencoins.
		 * @return array
		 */
		private static function build_checkout_context_booking_item( $cart_item_key, $cart_item, $item_cost ) {
			$product    = ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null;
			$product_id = self::get_cart_item_product_id( $cart_item );

			return array(
				'cart_item_key'    => (string) $cart_item_key,
				'product_id'       => $product_id,
				'product_name'     => $product && method_exists( $product, 'get_name' ) ? $product->get_name() : '',
				'quantity'         => isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1,
				'required_zencoins' => self::normalize_zencoin_amount( $item_cost ),
			);
		}

		/**
		 * Build checkout-context data for a Zencoin credit cart item.
		 *
		 * @param string $cart_item_key Cart item key.
		 * @param array  $cart_item     Cart item.
		 * @return array
		 */
		private static function build_checkout_context_credit_item( $cart_item_key, $cart_item, $allowed_types = array() ) {
			$product    = ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null;
			$product_id = self::get_cart_item_product_id( $cart_item );
			$type       = ! empty( $cart_item['cbb_dynamic_zencoin_product_type'] ) ? sanitize_key( $cart_item['cbb_dynamic_zencoin_product_type'] ) : self::get_checkout_context_credit_product_type( $product_id, $product );

			return array(
				'cart_item_key'         => (string) $cart_item_key,
				'product_id'            => $product_id,
				'product_name'          => $product && method_exists( $product, 'get_name' ) ? $product->get_name() : '',
				'quantity'              => isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1,
				'product_type'          => $type ? $type : 'none',
				'granted_zencoins'      => self::get_cart_item_coin_grant_amount( $cart_item ),
				'is_recovery_eligible'  => self::is_recovery_eligible_product_type( $type, $allowed_types ),
			);
		}

		/**
		 * Resolve normalized checkout-context credit product type.
		 *
		 * @param int             $product_id Product ID.
		 * @param WC_Product|null $product    Product object.
		 * @return string
		 */
		private static function get_checkout_context_credit_product_type( $product_id, $product = null ) {
			$type = $product_id > 0 ? (string) get_post_meta( $product_id, self::META_PRODUCT_TYPE, true ) : 'none';

			if ( '' === $type ) {
				$type = 'none';
			}

			if ( 'none' === $type && $product && class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
				$type = 'membership';
			}

			if ( 'none' === $type && $product && method_exists( $product, 'is_type' ) && ( $product->is_type( 'subscription' ) || $product->is_type( 'variable-subscription' ) || $product->is_type( 'subscription_variation' ) ) ) {
				$type = 'membership';
			}

			if ( 'none' === $type && $product_id > 0 && (float) get_post_meta( $product_id, self::META_GRANT_AMOUNT, true ) > 0 ) {
				$type = 'membership';
			}

			return $type ? $type : 'none';
		}

		/**
		 * Get a human/machine readable blocking reason for checkout context.
		 *
		 * @param array $context Checkout context.
		 * @return string
		 */
		private static function get_checkout_context_blocking_reason( $context ) {
			if ( ! empty( $context['wallet_is_frozen'] ) ) {
				return 'wallet_frozen';
			}

			if ( ! empty( $context['has_booking_items'] ) && (float) $context['projected_missing_zencoins'] > 0 ) {
				if ( ! empty( $context['has_recovery_products'] ) ) {
					return 'insufficient_recovery_zencoins';
				}

				return 'insufficient_zencoins';
			}

			return '';
		}

		/**
		 * Normalize Zencoin amount shape for public context output.
		 *
		 * @param float $amount Amount.
		 * @return float
		 */
		private static function normalize_zencoin_amount( $amount ) {
			return (float) wc_format_decimal( (float) $amount, 6 );
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
		 * Get available coin balance for booking validation.
		 *
		 * During migration, use the larger of bucket balance and Tera Wallet balance so legacy wallet credits
		 * still work until all balances are represented by buckets.
		 *
		 * @param int $user_id User ID.
		 * @return float
		 */
		private static function get_available_coin_balance( $user_id ) {
			$bucket_balance = self::get_zencoin_bucket_balance( $user_id );

			return max( $bucket_balance, self::get_wallet_balance( $user_id ) );
		}

		/**
		 * Check whether the customer's Zencoin wallet should be frozen.
		 *
		 * @param int $user_id User ID.
		 * @return bool
		 */
		private static function is_wallet_frozen_for_user( $user_id ) {
			$settings = self::get_settings();

			if ( 'yes' !== $settings['wallet_freeze_on_subscription_on_hold'] || $user_id <= 0 || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
				return false;
			}

			foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
				if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'has_status' ) || ! $subscription->has_status( 'on-hold' ) ) {
					continue;
				}

				if ( self::subscription_contains_zencoin_grant( $subscription ) ) {
					return (bool) apply_filters( 'cbb_wallet_frozen_for_user', true, $user_id, $subscription );
				}
			}

			return (bool) apply_filters( 'cbb_wallet_frozen_for_user', false, $user_id, null );
		}

		/**
		 * Check whether a subscription includes a Zencoin-granting product.
		 *
		 * @param WC_Subscription $subscription Subscription object.
		 * @return bool
		 */
		private static function subscription_contains_zencoin_grant( $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_items' ) ) {
				return false;
			}

			foreach ( $subscription->get_items( 'line_item' ) as $item ) {
				if ( self::get_item_coin_grant_amount( $item ) > 0 ) {
					return true;
				}
			}

			return false;
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

	register_activation_hook( __FILE__, array( 'CBB_Coin_Booking_Bridge', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'CBB_Coin_Booking_Bridge', 'deactivate' ) );
	CBB_Coin_Booking_Bridge::init();
}

if ( ! function_exists( 'cbb_get_member_recovery_product_id' ) ) {
	/**
	 * Public helper for the configured member recovery product.
	 *
	 * @return int
	 */
	function cbb_get_member_recovery_product_id() {
		return class_exists( 'CBB_Coin_Booking_Bridge' ) ? CBB_Coin_Booking_Bridge::get_member_recovery_product_id() : 0;
	}
}
if ( ! function_exists( 'cbb_get_checkout_context' ) ) {
	/**
	 * Public helper for external UI consumers.
	 *
	 * @param int   $user_id Optional user ID override.
	 * @param array $args    Optional context arguments.
	 * @return array
	 */
	function cbb_get_checkout_context( $user_id = 0, $args = array() ) {
		return class_exists( 'CBB_Coin_Booking_Bridge' ) ? CBB_Coin_Booking_Bridge::get_checkout_context( $user_id, $args ) : array();
	}
}

if ( ! function_exists( 'cbb_render_zencoin_coin' ) ) {
	/**
	 * Public helper for rendering the shared Zencoin coin badge.
	 *
	 * @param mixed $value Coin value.
	 * @param array $args  Optional render args.
	 * @return string
	 */
	function cbb_render_zencoin_coin( $value = '', $args = array() ) {
		return class_exists( 'CBB_Coin_Booking_Bridge' ) ? CBB_Coin_Booking_Bridge::render_global_zencoin_coin( $value, $args ) : '';
	}
}

if ( ! function_exists( 'cbb_is_free_dropin_trial_available' ) ) {
	/**
	 * Check whether the supplied identity may claim the free drop-in trial.
	 *
	 * @param string $email Billing email.
	 * @param string $phone Billing phone.
	 * @return bool
	 */
	function cbb_is_free_dropin_trial_available( $email, $phone ) {
		return class_exists( 'CBB_Coin_Booking_Bridge' ) ? CBB_Coin_Booking_Bridge::is_free_dropin_trial_available( $email, $phone ) : true;
	}
}

if ( ! function_exists( 'cbb_classify_cart_mode' ) ) {
	/**
	 * Public helper for external UI consumers.
	 *
	 * @param int   $user_id Optional user ID override.
	 * @param array $context Optional pre-built checkout context.
	 * @return string
	 */
	function cbb_classify_cart_mode( $user_id = 0, $context = array() ) {
		return class_exists( 'CBB_Coin_Booking_Bridge' ) ? CBB_Coin_Booking_Bridge::classify_cart_mode( $user_id, $context ) : 'money_purchase';
	}
}