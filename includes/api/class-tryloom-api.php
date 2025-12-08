<?php
/**
 * WooCommerce Try On API.
 *
 * @package WooCommerce_Try_On
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Tryloom_API Class.
 */
class Tryloom_API
{

	/**
	 * API Endpoint URL.
	 *
	 * @var string
	 */
	private $api_endpoint;

	/**
	 * Platform Key.
	 *
	 * @var string
	 */
	private $platform_key;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// External Service: TryLoom Cloud API (Google Cloud Functions)
		// Used for: AI Image Generation and License Validation
		// Terms: https://tryloom.toolteek.com/terms-and-conditions/
		$this->api_endpoint = 'https://us-central1-try-on-proxy-by-toolteek.cloudfunctions.net/fashionTryOn';

		// Get platform key - prefer paid key, fallback to free key
		$paid_key = get_option('tryloom_platform_key', '');
		if (!empty($paid_key)) {
			$this->platform_key = $paid_key;
		} else {
			$this->platform_key = get_option('tryloom_free_platform_key', '');
		}
	}

	/**
	 * Ensure a URL is absolute using the site's base if needed.
	 *
	 * @param string $url URL that may be relative.
	 * @return string Absolute URL.
	 */
	private function make_absolute_url($url)
	{
		$url = trim((string) $url);
		if ('' === $url) {
			return '';
		}
		$parts = wp_parse_url($url);
		if ($parts && isset($parts['scheme']) && isset($parts['host'])) {
			return $url; // already absolute
		}
		if (0 === strpos($url, '//')) {
			// Protocol-relative, prefix with site scheme
			$scheme = is_ssl() ? 'https:' : 'http:';
			return $scheme . $url;
		}
		if (0 === strpos($url, '/')) {
			return home_url($url);
		}
		// Relative path without leading slash
		return trailingslashit(site_url('/')) . ltrim($url, '/');
	}

	/**
	 * Attempt to resolve a local filesystem path from a given URL.
	 * Supports protected try-on URLs, attachment URLs, and same-host URLs.
	 *
	 * @param string $url
	 * @return string Empty when not resolvable, or absolute file path when found
	 */
	private function resolve_local_file_from_url($url)
	{
		if (empty($url)) {
			return '';
		}

		$url = $this->make_absolute_url($url);

		// 1) Protected try-on URL
		if (strpos($url, '?tryloom_image=') !== false) {
			$parsed_url = wp_parse_url($url);
			if (isset($parsed_url['query'])) {
				parse_str($parsed_url['query'], $query_params);
				if (isset($query_params['tryloom_image'])) {
					$image_name = sanitize_file_name($query_params['tryloom_image']);
					$upload_dir = wp_upload_dir();
					$protected_image_path = $upload_dir['basedir'] . '/tryloom/' . $image_name;
					if (file_exists($protected_image_path) && is_readable($protected_image_path)) {
						return $protected_image_path;
					}
				}
			}
		}

		// 2) Attachment URL -> attached file path (works even if CDN rewrites the URL)
		$attachment_id = attachment_url_to_postid($url);
		if ($attachment_id) {
			$file_path = get_attached_file($attachment_id);
			if ($file_path && file_exists($file_path) && is_readable($file_path)) {
				return $file_path;
			}
		}

		// 3) Same-host direct mapping - try uploads directory first
		$site_host = wp_parse_url(site_url(), PHP_URL_HOST);
		$url_host = wp_parse_url($url, PHP_URL_HOST);
		if ($site_host && $url_host && strtolower($site_host) === strtolower($url_host)) {
			// Try to extract path from uploads directory URL
			$upload_dir = wp_upload_dir();
			$upload_base_url = $upload_dir['baseurl'];
			$upload_base_dir = $upload_dir['basedir'];

			// Check if URL is within uploads directory
			if (strpos($url, $upload_base_url) === 0) {
				$relative_path = str_replace($upload_base_url, '', $url);
				// Remove query string if present
				$relative_path = strtok($relative_path, '?');
				$file_path = $upload_base_dir . $relative_path;
				if (file_exists($file_path) && is_readable($file_path)) {
					return $file_path;
				}
			}

			// Fallback: try content directory mapping (for non-upload files)
			// Use wp_upload_dir() to ensure compatibility with all server setups
			$content_url = content_url();
			if (strpos($url, $content_url) === 0) {
				$relative_path = str_replace($content_url, '', $url);
				$relative_path = strtok($relative_path, '?');
				// Use wp_upload_dir() to get the base directory structure
				$upload_dir = wp_upload_dir();
				// For content directory files, we need to construct path relative to uploads base
				// If the file is in uploads, use upload_dir, otherwise skip this fallback
				// as we cannot reliably determine WP_CONTENT_DIR on all server setups
				if (strpos($relative_path, '/uploads/') === 0) {
					$file_path = $upload_dir['basedir'] . str_replace('/uploads', '', $relative_path);
					if (file_exists($file_path) && is_readable($file_path)) {
						return $file_path;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Get base64 encoded image from URL.
	 *
	 * @param string $url Image URL.
	 * @return string|WP_Error Base64 encoded string or WP_Error on failure.
	 */
	private function get_base64_from_url($url)
	{
		$url = $this->make_absolute_url($url);

		// Prefer local filesystem when possible
		$local_path = $this->resolve_local_file_from_url($url);
		if ($local_path) {
			// Validate that $local_path is actually a local file path (not a URL)
			// Check if it's a valid file path and exists
			if (file_exists($local_path) && is_file($local_path) && !filter_var($local_path, FILTER_VALIDATE_URL)) {
				// FIX: Use WP_Filesystem instead of file_get_contents
				global $wp_filesystem;
				if (empty($wp_filesystem)) {
					require_once ABSPATH . '/wp-admin/includes/file.php';
					WP_Filesystem();
				}

				// Check if filesystem is ready
				if ($wp_filesystem) {
					$contents = $wp_filesystem->get_contents($local_path);
					if (false !== $contents) {
						return base64_encode($contents);
					}
				}
			}
		}

		// Fallback to HTTP API
		$response = wp_remote_get($url, array('timeout' => 30));

		if (is_wp_error($response)) {
			if ('yes' === get_option('tryloom_enable_logging', 'no')) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('[WooCommerce Try On] Image Fetch WP_Error: ' . $response->get_error_message() . ' for URL: ' . $url);
			}
			return new WP_Error('image_fetch_error', __('Could not fetch image from URL.', 'tryloom'));
		}

		$response_code = wp_remote_retrieve_response_code($response);
		if (200 !== $response_code) {
			return new WP_Error('image_fetch_error', __('Could not fetch image from URL (HTTP Error).', 'tryloom'));
		}

		$image_data = wp_remote_retrieve_body($response);
		if (empty($image_data)) {
			return new WP_Error('image_fetch_error', __('Image data is empty.', 'tryloom'));
		}

		return base64_encode($image_data);
	}

	/**
	 * Process the try-on request.
	 *
	 * @param array $data Data for the API request.
	 * @return array|WP_Error
	 */
	public function process_try_on($data)
	{
		// Resolve platform key at request time to ensure free key is used when paid key isn't set
		$paid_key = get_option('tryloom_platform_key', '');
		$platform_key = !empty($paid_key) ? $paid_key : get_option('tryloom_free_platform_key', '');
		if (empty($platform_key)) {
			// Log a notice if still missing
			if ('yes' === get_option('tryloom_enable_logging', 'no')) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('[WooCommerce Try On] API Notice: Platform key is not set (paid or free).');
			}
		}

		// Get base64 encoded images.
		$user_photo_base64 = $this->get_base64_from_url($data['user_photo_url']);
		if (is_wp_error($user_photo_base64)) {
			return $user_photo_base64;
		}

		$product_image_base64 = $this->get_base64_from_url($data['product_image_url']);
		if (is_wp_error($product_image_base64)) {
			return $product_image_base64;
		}

		// Determine license type
		$license_type = !empty($paid_key) ? 'paid' : 'free';

		$body = array(
			'platform_key' => $platform_key,
			'user_photo' => $user_photo_base64,
			'product_image' => $product_image_base64,
			'product_id' => $data['product_id'],
			'store_domain' => site_url(),
			'plugin_version' => defined('TRYLOOM_VERSION') ? TRYLOOM_VERSION : '1.0.4',
			'license_type' => $license_type,
		);

		$args = array(
			'body' => wp_json_encode($body),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'timeout' => 120, // Increased timeout to 120 seconds.
			'data_format' => 'body',
		);

		// Send the request.
		$response = wp_remote_post($this->api_endpoint, $args);

		// Check for WordPress-level errors.
		if (is_wp_error($response)) {
			if ('yes' === get_option('tryloom_enable_logging', 'no')) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('[WooCommerce Try On] API Request Error: ' . $response->get_error_message());
			}
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		// Check for error responses first
		if (isset($response_data['error'])) {
			$error_message = $response_data['error'];

			// Handle "Free Trial Ended" error
			if ('Free Trial Ended' === $error_message) {
				// Disable try-on features
				update_option('tryloom_enabled', 'no');
				update_option('tryloom_free_trial_ended', 'yes');

				if ('yes' === get_option('tryloom_enable_logging', 'no')) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log('[WooCommerce Try On] Free Trial Ended');
				}

				return new WP_Error('free_trial_ended', __('Free Trial Ended', 'tryloom'));
			}

			// Handle "Free Trial activation failed" error
			if ('Free Trial activation failed.' === $error_message) {
				$stored_free_key = get_option('tryloom_free_platform_key', '');
				$stored_paid_key = get_option('tryloom_platform_key', '');
				if (empty($stored_free_key) && empty($stored_paid_key)) {
					update_option('tryloom_free_trial_error', $error_message);

					if ('yes' === get_option('tryloom_enable_logging', 'no')) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log('[WooCommerce Try On] Free Trial activation failed.');
					}

					return new WP_Error('free_trial_activation_failed', __('Free Trial activation failed.', 'tryloom'));
				}

				// When a free platform key already exists, surface a generic error and avoid persistent notices.
				if ('yes' === get_option('tryloom_enable_logging', 'no')) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log('[WooCommerce Try On] Free Trial activation failed notice suppressed because a free platform key is present.');
				}

				$error_message = __('Service temporarily unavailable. Please try again.', 'tryloom');
			}

			// Generic error handling
			if ('yes' === get_option('tryloom_enable_logging', 'no')) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('[WooCommerce Try On] API Response Error: ' . $error_message);
			}

			return new WP_Error('try_on_api_error', $error_message, array('body' => $response_body));
		}

		// Check for a non-200 response code or missing image data.
		if ($response_code !== 200 || !isset($response_data['image']) || empty($response_data['image'])) {
			if ('yes' === get_option('tryloom_enable_logging', 'no')) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log('[WooCommerce Try On] API Response Error: (' . $response_code . ') Body: ' . (is_string($response_body) ? substr($response_body, 0, 200) : ''));
			}
			return new WP_Error('try_on_api_error', __('Failed to get try-on image from API.', 'tryloom'), array('body' => $response_body));
		}

		// Update usage counter if provided
		if (isset($response_data['used']) && isset($response_data['limit'])) {
			update_option('tryloom_usage_used', absint($response_data['used']));
			update_option('tryloom_usage_limit', absint($response_data['limit']));
		}

		// Return the base64 encoded image string.
		return array(
			'success' => true,
			'data' => array(
				'image_base64' => $response_data['image'],
				'used' => isset($response_data['used']) ? absint($response_data['used']) : null,
				'limit' => isset($response_data['limit']) ? absint($response_data['limit']) : null,
			),
		);
	}

	/**
	 * Mock API response for testing.
	 *
	 * @param string $user_photo_url URL of the user's photo.
	 * @param string $product_image_url URL of the product image.
	 * @return array
	 */
	public function mock_api_response($user_photo_url, $product_image_url)
	{
		// Simulate a network delay.
		sleep(2);

		// In this mock response, we simply return the user's photo as the result.
		return array(
			'success' => true,
			'data' => array(
				'image_url' => $user_photo_url,
			),
		);
	}

	/**
	 * Send request to API.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $data     Request data.
	 * @return array|WP_Error
	 */
	public function send_request($endpoint, $data)
	{
		// For the 'generate' endpoint, we process the try-on request.
		if ('generate' === $endpoint) {
			// Get product image URL.
			$product_id = isset($data['product_id']) ? absint($data['product_id']) : 0;
			$variation_id = isset($data['variation_id']) ? absint($data['variation_id']) : 0;

			// Get the product or variation image.
			$product_image_url = '';
			if ($variation_id > 0) {
				$variation = wc_get_product($variation_id);
				if ($variation) {
					$image_id = $variation->get_image_id();
					if ($image_id) {
						$product_image_url = wp_get_attachment_url($image_id);
					}
				}
			} elseif ($product_id > 0) {
				$product = wc_get_product($product_id);
				if ($product) {
					$image_id = $product->get_image_id();
					if ($image_id) {
						$product_image_url = wp_get_attachment_url($image_id);
					}
				}
			}
			$product_image_url = $this->make_absolute_url($product_image_url);

			// Build product meta to send along
			$product_title = '';
			$product_description = '';
			$product_variation = '';
			if ($variation) {
				$product_title = $variation->get_name();
				$product_variation = wc_get_formatted_variation($variation, true, true, false);
				$parent = wc_get_product($variation->get_parent_id());
				if ($parent) {
					$product_description = wp_strip_all_tags($parent->get_short_description() ? $parent->get_short_description() : $parent->get_description());
				}
			} else if (isset($product) && $product) {
				$product_title = $product->get_name();
				$product_description = wp_strip_all_tags($product->get_short_description() ? $product->get_short_description() : $product->get_description());
			}

			// Prepare data for process_try_on method.
			$process_data = array(
				'user_photo_url' => isset($data['user_photo']) ? $data['user_photo'] : '',
				'product_image_url' => $product_image_url,
				'product_id' => $product_id,
				'product_description' => $product_description,
				'product_title' => $product_title,
				'product_variation' => $product_variation,
			);

			// Process the try-on request.
			return $this->process_try_on($process_data);
		}

		// For other endpoints, return an error.
		return new WP_Error('invalid_endpoint', __('Invalid API endpoint.', 'tryloom'));
	}
}
