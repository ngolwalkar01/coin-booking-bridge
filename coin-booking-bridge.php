<?php
/**
 * Plugin Name: Coin Booking Bridge
 * Description: MVP bridge for WooCommerce Memberships, Subscriptions, Bookings, and Tera Wallet coin-based bookings.
 * Version: 0.2.0
 * Author: Custom
 * Text Domain: coin-booking-bridge
 *
 * @package CoinBookingBridge
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'CBB_Coin_Booking_Bridge' ) ) {
	final class CBB_Coin_Booking_Bridge {

		const VERSION           = '0.2.0';
		const DB_VERSION        = '2026050801';
		const OPTION_DB_VERSION = 'cbb_db_version';
		const OPTION_SETTINGS   = 'cbb_zencoin_settings';

		const TABLE_BUCKETS = 'cbb_zencoin_buckets';
		const TABLE_LEDGER  = 'cbb_zencoin_ledger';

		const META_GRANT_AMOUNT     = '_cbb_coin_grant_amount';
		const META_BOOKING_COST     = '_cbb_booking_coin_cost';
		const META_REFUND_POLICY    = '_cbb_coin_refund_policy';
		const META_PRODUCT_TYPE     = '_cbb_zencoin_product_type';
		const META_ZC_GRANT_AMOUNT  = '_cbb_zencoin_grant_amount';
		const META_VALIDITY_DAYS    = '_cbb_zencoin_validity_days';
		const META_SOURCE_LABEL     = '_cbb_zencoin_source_label';
		const META_ONE_TIME_PERSON  = '_cbb_zencoin_one_time_per_person';
		const META_PACKAGE_SIZE     = '_cbb_zencoin_package_size';
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
		 * Plugin activation callback.
		 */
		public static function activate() {
			self::install_schema();
		}

		/**
		 * Register hooks once dependencies are available.
		 */
		public static function register_hooks() {
			if ( is_admin() ) {
				self::maybe_upgrade_schema();
			}

			if ( is_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'maybe_dependency_notice' ) );
				add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
				add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
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
				'free_dropin_validity_days'            => '30',
				'dropin_validity_days'                 => '90',
				'package_small_validity_days'          => '90',
				'package_medium_validity_days'         => '90',
				'package_large_validity_days'          => '180',
				'gift_card_validity_days'              => '1095',
				'newsletter_discount_validity_days'    => '30',
				'on_time_cancel_cutoff_hours'          => '12',
				'wallet_freeze_on_subscription_on_hold' => 'yes',
				'tera_wallet_mirror_enabled'           => 'yes',
			);
		}

		/**
		 * Get merged settings.
		 *
		 * @return array
		 */
		private static function get_settings() {
			$settings = get_option( self::OPTION_SETTINGS, array() );

			return wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_default_settings() );
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
				'free_dropin_validity_days',
				'dropin_validity_days',
				'package_small_validity_days',
				'package_medium_validity_days',
				'package_large_validity_days',
				'gift_card_validity_days',
				'newsletter_discount_validity_days',
				'on_time_cancel_cutoff_hours',
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
				<p><?php esc_html_e( 'Central Zencoin rules used by future package, drop-in, membership, gift-card, and booking flows. These settings are saved now but not yet applied to the current MVP debit/credit behavior.', 'coin-booking-bridge' ); ?></p>

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
						<?php self::render_number_setting_row( 'free_dropin_validity_days', __( 'Free drop-in validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'dropin_validity_days', __( 'Drop-in validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'package_small_validity_days', __( 'Small package validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'package_medium_validity_days', __( 'Medium package validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'package_large_validity_days', __( 'Large package validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'gift_card_validity_days', __( 'Gift card validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_number_setting_row( 'newsletter_discount_validity_days', __( 'Newsletter discount validity (days)', 'coin-booking-bridge' ), $settings, '1' ); ?>
					</table>

					<h2><?php esc_html_e( 'Rules', 'coin-booking-bridge' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php self::render_number_setting_row( 'on_time_cancel_cutoff_hours', __( 'On-time cancellation cutoff (hours)', 'coin-booking-bridge' ), $settings, '1' ); ?>
						<?php self::render_checkbox_setting_row( 'wallet_freeze_on_subscription_on_hold', __( 'Freeze wallet when membership subscription is on-hold', 'coin-booking-bridge' ), $settings ); ?>
						<?php self::render_checkbox_setting_row( 'tera_wallet_mirror_enabled', __( 'Mirror CBB balance changes to Tera Wallet', 'coin-booking-bridge' ), $settings ); ?>
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
			?>
			<h2><?php esc_html_e( 'System Status', 'coin-booking-bridge' ); ?></h2>
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
					'description' => __( 'Classifies non-booking products that will grant Zencoins in the bucket system. Saving only for now; grant behavior is wired in a later milestone.', 'coin-booking-bridge' ),
					'desc_tip'    => true,
					'options'     => self::get_zencoin_product_type_options(),
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'                => self::META_ZC_GRANT_AMOUNT,
					'label'             => __( 'Zencoins granted on paid order', 'coin-booking-bridge' ),
					'description'       => __( 'Used for packages, drop-ins, free trials, gift cards, and top-ups once bucket grants are enabled.', 'coin-booking-bridge' ),
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
					'id'                => self::META_VALIDITY_DAYS,
					'label'             => __( 'Zencoin validity days', 'coin-booking-bridge' ),
					'description'       => __( 'Leave empty to use the central default for the selected product type.', 'coin-booking-bridge' ),
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
			$validity     = isset( $_POST[ self::META_VALIDITY_DAYS ] ) && '' !== $_POST[ self::META_VALIDITY_DAYS ] ? absint( wp_unslash( $_POST[ self::META_VALIDITY_DAYS ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$label        = isset( $_POST[ self::META_SOURCE_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_SOURCE_LABEL ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$package_size = isset( $_POST[ self::META_PACKAGE_SIZE ] ) ? sanitize_key( wp_unslash( $_POST[ self::META_PACKAGE_SIZE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$one_time     = isset( $_POST[ self::META_ONE_TIME_PERSON ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			update_post_meta( $post_id, self::META_GRANT_AMOUNT, $grant_amount );
			update_post_meta( $post_id, self::META_BOOKING_COST, $booking_cost );
			update_post_meta( $post_id, self::META_REFUND_POLICY, in_array( $policy, array( 'full', 'none' ), true ) ? $policy : 'full' );
			update_post_meta( $post_id, self::META_PRODUCT_TYPE, array_key_exists( $product_type, self::get_zencoin_product_type_options() ) ? $product_type : 'none' );
			update_post_meta( $post_id, self::META_ZC_GRANT_AMOUNT, $zc_amount );
			update_post_meta( $post_id, self::META_VALIDITY_DAYS, $validity );
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

	register_activation_hook( __FILE__, array( 'CBB_Coin_Booking_Bridge', 'activate' ) );
	CBB_Coin_Booking_Bridge::init();
}
