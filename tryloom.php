<?php
/**
 * Plugin Name: TryLoom - Virtual Try On for WooCommerce
 * Plugin URI: https://tryloom.toolteek.com/
 * Description: TryLoom lets customers virtually try on clothing, shoes, hats, and eyewear in WooCommerce.
 * Version: 1.0.5
 * Stable tag: 1.0.5
 * Author: ToolTeek
 * Author URI: https://toolteek.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tryloom
 * Requires at least: 5.6
 * Tested up to: 6.8
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 10.3
 *
 * @package WooCommerce_Try_On
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants.
define('TRYLOOM_VERSION', '1.0.5');
define('TRYLOOM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TRYLOOM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TRYLOOM_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class.
 */
class Tryloom
{

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Admin class instance.
	 *
	 * @var Tryloom_Admin
	 */
	public $admin = null;

	/**
	 * Frontend class instance.
	 *
	 * @var Tryloom_Frontend
	 */
	public $frontend = null;

	/**
	 * API class instance.
	 *
	 * @var Tryloom_API
	 */
	public $api = null;

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Check if WooCommerce is active.
		add_action('plugins_loaded', array($this, 'check_woocommerce'));

		// Flush rewrite rules on plugin activation
		add_action('admin_init', array($this, 'maybe_flush_rewrite_rules'));
	}

	/**
	 * Check if WooCommerce is active and load plugin if it is.
	 */
	public function check_woocommerce()
	{
		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
			return;
		}

		$this->maybe_migrate_legacy_data();
		$this->includes();
		$this->init_hooks();
		$this->init_classes();
	}

	/**
	 * Show notice if WooCommerce is not active.
	 */
	public function woocommerce_missing_notice()
	{
		?>
		<div class="error">
			<p><?php esc_html_e('TryLoom - requires WooCommerce to be installed and active.', 'tryloom'); ?></p>
		</div>
		<?php
	}

	/**
	 * Include required files.
	 */
	public function includes()
	{
		// Include admin files.
		require_once TRYLOOM_PLUGIN_DIR . 'includes/admin/class-tryloom-admin.php';

		// Include frontend files.
		require_once TRYLOOM_PLUGIN_DIR . 'includes/frontend/class-tryloom-frontend.php';

		// Include API files.
		require_once TRYLOOM_PLUGIN_DIR . 'includes/api/class-tryloom-api.php';
	}

	/**
	 * Initialize hooks.
	 */
	public function init_hooks()
	{
		// Activation/deactivation hooks are registered at file scope to ensure they run even if WooCommerce isn't active at activation time.

		// Check version and run installer if needed.
		add_action('admin_init', array($this, 'check_version'));

		// Check if we need to flush rewrite rules
		add_action('admin_init', array($this, 'check_flush_rewrite_rules'));

		// Plugin text domain is automatically loaded by WordPress.org for hosted plugins.

		// Declare HPOS compatibility.
		add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

		// Handle scheduled deletion hook.
		add_action('tryloom_delete_generated_image', array($this, 'delete_generated_image'));

		// Record last login timestamps for users
		add_action('wp_login', array($this, 'record_user_last_login'), 10, 2);

		// Register cleanup cron handler
		add_action('tryloom_cleanup_inactive_users', array($this, 'cleanup_inactive_users'));

		// Cart conversion AJAX endpoint
		add_action('wp_ajax_tryloom_cart_conversion', array($this, 'ajax_cart_conversion'));

		// Register REST API endpoint for verification
		add_action('rest_api_init', array($this, 'register_rest_routes'));
	}

	/**
	 * Initialize plugin classes.
	 */
	public function init_classes()
	{
		// Initialize admin class.
		if (is_admin()) {
			$this->admin = new Tryloom_Admin();
		}

		// Initialize frontend class.
		$this->frontend = new Tryloom_Frontend();

		// Initialize API class.
		$this->api = new Tryloom_API();
	}

	/**
	 * Activate plugin.
	 */
	public function activate()
	{
		$this->install();
		flush_rewrite_rules();

		// Schedule daily cleanup if not already scheduled
		if (!wp_next_scheduled('tryloom_cleanup_inactive_users')) {
			wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'tryloom_cleanup_inactive_users');
		}
	}

	/**
	 * Deactivate plugin.
	 */
	public function deactivate()
	{
		// Clean up if needed.
		flush_rewrite_rules();

		// Clear scheduled cleanup
		wp_clear_scheduled_hook('tryloom_cleanup_inactive_users');
	}

	/**
	 * Install plugin. Creates tables, sets options, and handles upgrades.
	 */
	public function install()
	{
		// Create necessary database tables.
		$this->create_tables();

		// Set default options.
		$this->set_default_options();

		// Set option to flush rewrite rules on next admin page load.
		update_option('tryloom_flush_rewrite_rules', 'yes');

		// Update the version number in the database.
		update_option('tryloom_version', TRYLOOM_VERSION);
	}

	/**
	 * Maybe flush rewrite rules.
	 */
	public function maybe_flush_rewrite_rules()
	{
		// Check if we need to flush rewrite rules
		$flush_rewrite_rules = get_option('tryloom_flush_rewrite_rules', 'no');
		if ('yes' === $flush_rewrite_rules) {
			flush_rewrite_rules();
			update_option('tryloom_flush_rewrite_rules', 'no');
		}
	}

	/**
	 * Create necessary database tables.
	 */
	public function create_tables()
	{
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Table for try-on history.
		$table_name = $wpdb->prefix . 'tryloom_history';

		$sql = "CREATE TABLE `{$table_name}` (
			`id` mediumint(9) NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) NOT NULL,
			`product_id` bigint(20) NOT NULL,
			`variation_id` bigint(20) DEFAULT 0 NOT NULL,
			`user_image_url` varchar(255) NOT NULL,
			`generated_image_url` varchar(255) NOT NULL,
			`added_to_cart` tinyint(1) DEFAULT 0 NOT NULL,
			`created_at` datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		// Table for user photos.
		$table_name = $wpdb->prefix . 'tryloom_user_photos';

		$sql = "CREATE TABLE `{$table_name}` (
			`id` mediumint(9) NOT NULL AUTO_INCREMENT,
			`user_id` bigint(20) NOT NULL,
			`attachment_id` bigint(20) DEFAULT 0 NOT NULL,
			`image_url` varchar(255) NOT NULL,
			`is_default` tinyint(1) DEFAULT 0 NOT NULL,
			`manually_set_default` tinyint(1) DEFAULT 0 NOT NULL,
			`created_at` datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			`last_used` datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		dbDelta($sql);

		// Check if the columns exist, and add them if they don't
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name sanitized with esc_sql(), schema change operation
		$columns = $wpdb->get_results('SHOW COLUMNS FROM ' . esc_sql($table_name));
		$column_names = array();
		foreach ($columns as $column) {
			$column_names[] = $column->Field;
		}

		if (!in_array('attachment_id', $column_names)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name sanitized with esc_sql(), schema change operation
			$wpdb->query('ALTER TABLE ' . esc_sql($table_name) . ' ADD `attachment_id` bigint(20) DEFAULT 0 NOT NULL');
		}

		if (!in_array('manually_set_default', $column_names)) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name sanitized with esc_sql(), schema change operation
			$wpdb->query('ALTER TABLE ' . esc_sql($table_name) . ' ADD `manually_set_default` tinyint(1) DEFAULT 0 NOT NULL');
		}

		// Table for cart conversions after try-on.
		$table_name = $wpdb->prefix . 'tryloom_cart_conversions';
		$sql = "CREATE TABLE `{$table_name}` (
			`user_id` bigint(20) NOT NULL,
			`product_id` bigint(20) NOT NULL,
			`try_on_date` bigint(20) unsigned NOT NULL,
			`cart_add_date` bigint(20) unsigned NOT NULL,
			PRIMARY KEY (`user_id`,`product_id`,`try_on_date`)
		) $charset_collate;";
		dbDelta($sql);
	}

	/**
	 * Set default options.
	 */
	public function set_default_options()
	{
		$default_options = array(
			'enabled' => 'yes',
			'theme_color' => 'light',
			'primary_color' => '#552FBC',
			'save_photos' => 'yes',
			'platform_key' => '',
			'allowed_categories' => array(),
			'retry_button' => 'yes',
			'generation_limit' => 10,
			'time_period' => 'hour',
			'enable_logging' => 'yes',
			'brand_watermark' => '',
			'delete_photos_days' => 30,
			'allowed_user_roles' => array('administrator', 'customer', 'subscriber'),
			'admin_user_roles' => array('administrator', 'shop_manager'),
			'button_placement' => 'default',
			'custom_popup_css' => '',
			'custom_button_css' => '',
			'custom_account_css' => '',
			'enable_account_tab' => 'yes',
			'enable_history' => 'yes',
			'show_popup_errors' => 'no',
		);

		foreach ($default_options as $key => $value) {
			// Only add option if it doesn't exist
			if (false === get_option('tryloom_' . $key, false)) {
				add_option('tryloom_' . $key, $value);
			}
		}
	}


	/**
	 * Check plugin version and run installer if needed.
	 */
	public function check_version()
	{
		if (get_option('tryloom_version') !== TRYLOOM_VERSION) {
			$this->install();
		}
	}

	/**
	 * Check if we need to flush rewrite rules.
	 */
	public function check_flush_rewrite_rules()
	{
		// Check if we need to flush rewrite rules
		$flush_rewrite_rules = get_option('tryloom_flush_rewrite_rules', 'no');
		if ('yes' === $flush_rewrite_rules) {
			flush_rewrite_rules();
			update_option('tryloom_flush_rewrite_rules', 'no');
		}
	}

	/**
	 * Declare HPOS (High-Performance Order Storage) compatibility.
	 */
	public function declare_hpos_compatibility()
	{
		if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		}
	}

	/**
	 * Get file path from URL using WordPress upload directory.
	 *
	 * @param string $image_url The URL of the image.
	 * @return string|false File path on success, false on failure.
	 */
	private function get_file_path_from_url($image_url)
	{
		if (empty($image_url)) {
			return false;
		}

		// If the URL is a protected URL, extract the filename and get path from custom directory
		if (strpos($image_url, '?tryloom_image=') !== false) {
			$parsed_url = wp_parse_url($image_url);
			if (isset($parsed_url['query'])) {
				parse_str($parsed_url['query'], $query_params);
				if (isset($query_params['tryloom_image'])) {
					$image_name = sanitize_file_name($query_params['tryloom_image']);
					$upload_dir = wp_upload_dir();
					$protected_image_path = $upload_dir['basedir'] . '/tryloom/' . $image_name;
					if (file_exists($protected_image_path)) {
						return $protected_image_path;
					}
				}
			}
		}

		// Try to get attachment ID and file path
		$attachment_id = attachment_url_to_postid($image_url);
		if ($attachment_id) {
			$file_path = get_attached_file($attachment_id);
			if ($file_path && file_exists($file_path)) {
				return $file_path;
			}
		}

		// Try to extract path from uploads directory URL
		$upload_dir = wp_upload_dir();
		$upload_base_url = $upload_dir['baseurl'];
		$upload_base_dir = $upload_dir['basedir'];

		// Check if URL is within uploads directory
		if (strpos($image_url, $upload_base_url) === 0) {
			$relative_path = str_replace($upload_base_url, '', $image_url);
			$file_path = $upload_base_dir . $relative_path;
			if (file_exists($file_path)) {
				return $file_path;
			}
		}

		return false;
	}

	/**
	 * Delete generated image after scheduled time.
	 *
	 * @param string $image_url The URL of the image to delete.
	 */
	public function delete_generated_image($image_url)
	{
		if (empty($image_url)) {
			return;
		}

		// Get the file path from URL using WordPress upload directory.
		$file_path = $this->get_file_path_from_url($image_url);
		if ($file_path && file_exists($file_path)) {
			wp_delete_file($file_path);
		}

		// Delete the attachment from media library.
		$attachment_id = attachment_url_to_postid($image_url);
		if ($attachment_id) {
			wp_delete_attachment($attachment_id, true);
		}
	}

	/**
	 * Record user's last login timestamp.
	 *
	 * @param string   $user_login Username.
	 * @param WP_User  $user       WP_User object.
	 */
	public function record_user_last_login($user_login, $user)
	{
		if ($user && isset($user->ID)) {
			update_user_meta($user->ID, 'tryloom_last_login', current_time('timestamp'));
		}
	}

	/**
	 * Cleanup inactive users' try-on data based on configured days.
	 */
	public function cleanup_inactive_users()
	{
		$days = absint(get_option('tryloom_delete_photos_days', 30));
		if ($days <= 0) {
			return; // Disabled
		}

		$cutoff = current_time('timestamp') - ($days * DAY_IN_SECONDS);

		// Find users whose last login is older than cutoff
		global $wpdb;
		$usermeta_table = $wpdb->usermeta;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
		$user_ids = $wpdb->get_col($wpdb->prepare(
			'SELECT user_id FROM ' . esc_sql($usermeta_table) . ' WHERE meta_key = %s AND meta_value <> %s AND CAST(meta_value AS UNSIGNED) < %d',
			'tryloom_last_login',
			'',
			$cutoff
		));

		if (empty($user_ids)) {
			return;
		}

		$photos_table = $wpdb->prefix . 'tryloom_user_photos';
		$history_table = $wpdb->prefix . 'tryloom_history';

		foreach ($user_ids as $user_id) {
			$user_id = intval($user_id);

			// Delete generated images from media library and filesystem for this user's history
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
			$history_records = $wpdb->get_results($wpdb->prepare(
				'SELECT generated_image_url FROM ' . esc_sql($history_table) . ' WHERE user_id = %d',
				$user_id
			));
			if ($history_records) {
				foreach ($history_records as $record) {
					if (!empty($record->generated_image_url)) {
						$attachment_id = attachment_url_to_postid($record->generated_image_url);
						if ($attachment_id) {
							wp_delete_attachment($attachment_id, true);
						}

						// Delete from filesystem using WordPress upload directory
						$file_path = $this->get_file_path_from_url($record->generated_image_url);
						if ($file_path && file_exists($file_path)) {
							wp_delete_file($file_path);
						}
					}
				}
			}

			// Delete user's uploaded photos and attachments
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
			$user_photos = $wpdb->get_results($wpdb->prepare(
				'SELECT attachment_id, image_url FROM ' . esc_sql($photos_table) . ' WHERE user_id = %d',
				$user_id
			));
			if ($user_photos) {
				foreach ($user_photos as $photo) {
					if (!empty($photo->attachment_id)) {
						wp_delete_attachment((int) $photo->attachment_id, true);
					}
					if (!empty($photo->image_url)) {
						$file_path = $this->get_file_path_from_url($photo->image_url);
						if ($file_path && file_exists($file_path)) {
							wp_delete_file($file_path);
						}
					}
				}
			}

			// Delete DB rows for this user
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Using $wpdb->delete() with prepared format strings
			$wpdb->delete($history_table, array('user_id' => $user_id), array('%d'));
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Using $wpdb->delete() with prepared format strings
			$wpdb->delete($photos_table, array('user_id' => $user_id), array('%d'));
		}
	}

	/**
	 * AJAX handler to record a cart conversion after try-on.
	 */
	public function ajax_cart_conversion()
	{
		// Must be logged in
		if (!is_user_logged_in()) {
			wp_send_json_error(array('message' => __('You must be logged in.', 'tryloom')));
		}
		if (!check_ajax_referer('tryloom', 'nonce', false)) {
			wp_send_json_error(array('message' => __('Invalid nonce.', 'tryloom')));
		}
		$user_id = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
		$product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
		$try_on_date = isset($_POST['try_on_date']) ? intval($_POST['try_on_date']) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
		$cart_add_date = isset($_POST['cart_add_date']) ? intval($_POST['cart_add_date']) : time();
		if (!$product_id || !$try_on_date) {
			wp_send_json_error(array('message' => __('Missing product or try-on date.', 'tryloom')));
		}
		global $wpdb;
		$table = $wpdb->prefix . 'tryloom_cart_conversions';
		// Only insert if not present
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
		$existing = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . esc_sql($table) . ' WHERE user_id=%d AND product_id=%d AND try_on_date=%d', $user_id, $product_id, $try_on_date));
		if (!$existing) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Using $wpdb->insert() with prepared format strings
			$wpdb->insert($table, [
				'user_id' => $user_id,
				'product_id' => $product_id,
				'try_on_date' => $try_on_date,
				'cart_add_date' => $cart_add_date
			], ['%d', '%d', '%d', '%d']);
		}
		wp_send_json_success();
	}

	/**
	 * Generate unique verification token for free trial.
	 */
	public function generate_verification_token()
	{
		$existing_token = get_option('tryloom_verification_token', '');

		// Only generate if token doesn't exist
		if (empty($existing_token)) {
			$token = wp_generate_password(64, false);
			update_option('tryloom_verification_token', $token);
		}
	}

	/**
	 * Ensure free platform key exists by hitting verification endpoint when needed.
	 */
	public function maybe_fetch_free_key()
	{
		$paid_key = get_option('tryloom_platform_key', '');
		$free_key = get_option('tryloom_free_platform_key', '');
		if (empty($paid_key) && empty($free_key)) {
			$this->generate_verification_token();
			$this->connect_to_cloud_service();
		}
	}

	/**
	 * Register free trial with verification server.
	 */
	public function connect_to_cloud_service()
	{
		// In-process lock to prevent duplicate requests within the same PHP request
		static $already_attempted = false;
		if ($already_attempted) {
			return;
		}
		$already_attempted = true;

		// Cross-request transient lock to avoid rapid duplicate calls
		if (get_transient('tryloom_verification_lock')) {
			return;
		}
		set_transient('tryloom_verification_lock', 1, 60); // 60 seconds debounce

		$token = get_option('tryloom_verification_token', '');

		if (empty($token)) {
			return;
		}

		$site_url = site_url();

		$args = array(
			'body' => wp_json_encode(array(
				'token' => $token,
				'referrer' => $site_url,
			)),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'timeout' => 30,
			'data_format' => 'body',
		);
		
		// External Service: TryLoom Cloud API (ToolTeek)
		// Used for: Authenticating the API session (Service Check)
		// Privacy Policy: https://tryloom.toolteek.com/privacy-policy/
		$response = wp_remote_post('https://us-central1-try-on-proxy-by-toolteek.cloudfunctions.net/cloudConnection', $args);

		if (is_wp_error($response)) {
			// Store error for admin display
			update_option('tryloom_free_trial_error', 'Free Trial activation failed.');
			return;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		if (200 === $response_code && isset($response_data['status']) && 'success' === $response_data['status']) {
			// Save free platform key
			if (isset($response_data['free_platform_key'])) {
				update_option('tryloom_free_platform_key', sanitize_text_field($response_data['free_platform_key']));
				// Clear any previous errors
				delete_option('tryloom_free_trial_error');
			}
		} else {
			// Store error for admin display
			$error_message = isset($response_data['error']) ? $response_data['error'] : 'Free Trial activation failed.';
			update_option('tryloom_free_trial_error', sanitize_text_field($error_message));
		}
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes()
	{
		register_rest_route(
			'tryloom/v1',
			'/verify',
			array(
				'methods' => 'GET',
				'callback' => array($this, 'rest_get_verification_token'),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST API callback to return verification token.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function rest_get_verification_token($request)
	{
		$token = get_option('tryloom_verification_token', '');

		if (empty($token)) {
			// Generate token if it doesn't exist
			$this->generate_verification_token();
			$token = get_option('tryloom_verification_token', '');
		}

		return new WP_REST_Response(
			array(
				'token' => $token,
			),
			200
		);
	}

	/**
	 * Static wrappers for activation/deactivation to ensure hooks are registered even if WooCommerce is inactive.
	 */
	public static function activate_plugin()
	{
		self::get_instance()->activate();
	}

	public static function deactivate_plugin()
	{
		self::get_instance()->deactivate();
	}

	/**
	 * Migrate legacy wc_try_on data to the new tryloom naming scheme.
	 */
	private function maybe_migrate_legacy_data()
	{
		if ('yes' === get_option('tryloom_legacy_migrated', 'no')) {
			return;
		}

		$option_map = array(
			'wc_try_on_enabled' => 'tryloom_enabled',
			'wc_try_on_theme_color' => 'tryloom_theme_color',
			'wc_try_on_primary_color' => 'tryloom_primary_color',
			'wc_try_on_save_photos' => 'tryloom_save_photos',
			'wc_try_on_platform_key' => 'tryloom_platform_key',
			'wc_try_on_free_platform_key' => 'tryloom_free_platform_key',
			'wc_try_on_allowed_categories' => 'tryloom_allowed_categories',
			'wc_try_on_retry_button' => 'tryloom_retry_button',
			'wc_try_on_button_placement' => 'tryloom_button_placement',
			'wc_try_on_custom_popup_css' => 'tryloom_custom_popup_css',
			'wc_try_on_custom_button_css' => 'tryloom_custom_button_css',
			'wc_try_on_custom_account_css' => 'tryloom_custom_account_css',
			'wc_try_on_generation_limit' => 'tryloom_generation_limit',
			'wc_try_on_time_period' => 'tryloom_time_period',
			'wc_try_on_delete_photos_days' => 'tryloom_delete_photos_days',
			'wc_try_on_allowed_user_roles' => 'tryloom_allowed_user_roles',
			'wc_try_on_enable_history' => 'tryloom_enable_history',
			'wc_try_on_enable_account_tab' => 'tryloom_enable_account_tab',
			'wc_try_on_enable_logging' => 'tryloom_enable_logging',
			'wc_try_on_admin_user_roles' => 'tryloom_admin_user_roles',
			'wc_try_on_show_popup_errors' => 'tryloom_show_popup_errors',
			'wc_try_on_settings' => 'tryloom_settings',
			'wc_try_on_version' => 'tryloom_version',
			'wc_try_on_flush_rewrite_rules' => 'tryloom_flush_rewrite_rules',
			'wc_try_on_usage_used' => 'tryloom_usage_used',
			'wc_try_on_usage_limit' => 'tryloom_usage_limit',
			'wc_try_on_free_trial_error' => 'tryloom_free_trial_error',
			'wc_try_on_verification_token' => 'tryloom_verification_token',
			'wc_try_on_free_trial_ended' => 'tryloom_free_trial_ended',
		);

		foreach ($option_map as $legacy_key => $new_key) {
			$legacy_value = get_option($legacy_key, null);

			if (null !== $legacy_value && false === get_option($new_key, false)) {
				update_option($new_key, $legacy_value);
			}
		}

		global $wpdb;

		$table_map = array(
			$wpdb->prefix . 'wc_try_on_history' => $wpdb->prefix . 'tryloom_history',
			$wpdb->prefix . 'wc_try_on_user_photos' => $wpdb->prefix . 'tryloom_user_photos',
			$wpdb->prefix . 'wc_try_on_cart_conversions' => $wpdb->prefix . 'tryloom_cart_conversions',
		);

		foreach ($table_map as $legacy_table => $new_table) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration operation, table existence check
			$legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy_table)));
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration operation, table existence check
			$new_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new_table)));

			if ($legacy_exists && !$new_exists) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table names sanitized with esc_sql(), schema migration operation
				$wpdb->query('RENAME TABLE `' . esc_sql($legacy_table) . '` TO `' . esc_sql($new_table) . '`');
			}
		}

		// Migrate user meta key storing the last login timestamp.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . esc_sql($wpdb->usermeta) . ' SET meta_key = %s WHERE meta_key = %s',
				'tryloom_last_login',
				'wc_try_on_last_login'
			)
		);

		// Ensure legacy scheduled events are migrated.
		while ($timestamp = wp_next_scheduled('wc_try_on_cleanup_inactive_users')) {
			wp_unschedule_event($timestamp, 'wc_try_on_cleanup_inactive_users');
		}

		if (!wp_next_scheduled('tryloom_cleanup_inactive_users')) {
			wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'tryloom_cleanup_inactive_users');
		}

		// Clean up obsolete transients.
		delete_transient('wc_try_on_verification_lock');

		update_option('tryloom_legacy_migrated', 'yes');
	}
}

// Initialize the plugin.
function tryloom()
{
	return Tryloom::get_instance();
}

// Start the plugin.
tryloom();

// Register activation/deactivation hooks at file scope so they always trigger.
register_activation_hook(__FILE__, array('Tryloom', 'activate_plugin'));
register_deactivation_hook(__FILE__, array('Tryloom', 'deactivate_plugin'));