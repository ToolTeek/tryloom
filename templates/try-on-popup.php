<?php
/**
 * Try On Popup Template.
 *
 * @package WooCommerce_Try_On
 */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// Template variables and WooCommerce hooks are acceptable in template files.

// Get settings.
$settings = get_option('tryloom_settings', array());
$theme = get_option('tryloom_theme_color', 'light');
$primary_color = get_option('tryloom_primary_color', '#552FBC'); // Force direct use, not from settings array
$watermark = isset($settings['watermark']) ? wp_get_attachment_url($settings['watermark']) : '';
$save_photos = isset($settings['save_photos']) ? $settings['save_photos'] : 'yes';
$retry_button = get_option('tryloom_retry_button', 'yes');

// Get current user.
$current_user = wp_get_current_user();
$user_id = $current_user->ID;
$default_photo = false;

// Check if user has default photo.
if ($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tryloom_user_photos';

    // First try to get permanent default (manually_set_default = 1).
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
    $default_photo = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . esc_sql($table_name) . ' WHERE user_id = %d AND is_default = 1 AND manually_set_default = 1 LIMIT 1',
        $user_id
    ));

    // If no permanent default, get temp default (manually_set_default = 0).
    if (!$default_photo) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
        $default_photo = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . esc_sql($table_name) . ' WHERE user_id = %d AND is_default = 1 AND manually_set_default = 0 LIMIT 1',
            $user_id
        ));
    }
}

// Get default photo URL.
$default_photo_url = $default_photo ? $default_photo->image_url : '';

// Refresh nonce for protected URLs (nonces expire, so we need fresh ones)
if ($default_photo_url && strpos($default_photo_url, '?tryloom_image=') !== false) {
    // Extract the image name and create a fresh nonce
    $parsed = wp_parse_url($default_photo_url);
    if (isset($parsed['query'])) {
        parse_str($parsed['query'], $query_params);
        if (isset($query_params['tryloom_image'])) {
            $image_name = $query_params['tryloom_image'];
            $fresh_nonce = wp_create_nonce('tryloom_image_access');
            $default_photo_url = home_url('?tryloom_image=' . urlencode($image_name) . '&_wpnonce=' . urlencode($fresh_nonce));
        }
    }
}
?>
<div id="tryloom-popup" class="tryloom-popup tryloom-theme-<?php echo esc_attr($theme); ?>"
    style="display: none; --tryloom-primary-color: <?php echo esc_attr($primary_color); ?>;">
    <div class="tryloom-popup-content">
        <div class="tryloom-popup-header">
            <h3><?php esc_html_e('Virtual Try On', 'tryloom'); ?></h3>
            <button class="tryloom-popup-close">&times;</button>
        </div>

        <div class="tryloom-popup-body">
            <!-- Step 1: Upload and Select Variation -->
            <div class="tryloom-step tryloom-step-1">
                <div class="tryloom-upload">
                    <h4><?php esc_html_e('Your Photo', 'tryloom'); ?></h4>
                    <div class="tryloom-upload-area">
                        <div class="tryloom-upload-preview">
                            <?php if ($default_photo_url): ?>
                                <div class="tryloom-preview">
                                    <img src="<?php echo esc_url($default_photo_url); ?>"
                                        alt="<?php esc_attr_e('Your Photo', 'tryloom'); ?>" />
                                </div>
                            <?php else: ?>
                                <div class="tryloom-upload-placeholder">
                                    <img src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/img/tryloom_upload_placeholder.png'); ?>"
                                        alt="<?php esc_attr_e('Upload', 'tryloom'); ?>" width="80" height="80" />
                                    <p class="tryloom-upload-title"><?php esc_html_e('Upload your photo', 'tryloom'); ?></p>
                                    <p class="tryloom-upload-subtitle">
                                        <?php esc_html_e('or drag and drop here.', 'tryloom'); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <input type="file" id="tryloom-file" accept="image/*" style="display: none;">

                <div class="tryloom-variations">
                    <h4><?php esc_html_e('Select Product Variation', 'tryloom'); ?></h4>
                    <div class="tryloom-variations-container">
                        <p class="tryloom-loading"><?php esc_html_e('Loading variations...', 'tryloom'); ?></p>
                    </div>
                </div>

                <div class="tryloom-actions">
                    <?php
                    // Get Add to Cart button classes.
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                    $button_classes = apply_filters('tryloom_product_single_add_to_cart_button_classes', 'button alt');
                    ?>
                    <button type="button" class="<?php echo esc_attr($button_classes); ?> tryloom-generate"
                        style="background-color: <?php echo esc_attr($primary_color); ?>; color: #fff;">
                        <i class="fas fa-magic"></i>
                        <?php esc_html_e('See My Look', 'tryloom'); ?>
                    </button>
                </div>
            </div><!-- End tryloom-step-1 -->

            <!-- Step 2: Result -->
            <div class="tryloom-step tryloom-step-2" style="display: none;">
                <div class="tryloom-result">
                    <div class="tryloom-result-image">
                        <div class="tryloom-result-loading" aria-hidden="true">
                            <div class="tryloom-spinner"></div>
                        </div>
                        <img src="" alt="<?php esc_attr_e('Try On Result', 'tryloom'); ?>">
                        <?php if ($watermark): ?>
                            <div class="tryloom-watermark">
                                <img src="<?php echo esc_url($watermark); ?>"
                                    alt="<?php esc_attr_e('Watermark', 'tryloom'); ?>">
                            </div>
                        <?php endif; ?>

                        <!-- Image action icons (using inline SVG for reliability) -->
                        <div class="tryloom-image-actions">
                            <a href="#" class="tryloom-icon-button tryloom-download-icon" download
                                title="<?php esc_attr_e('Download', 'tryloom'); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                    <polyline points="7 10 12 15 17 10"></polyline>
                                    <line x1="12" y1="15" x2="12" y2="3"></line>
                                </svg>
                            </a>
                            <?php if ('yes' === $retry_button): ?>
                                <button type="button" class="tryloom-icon-button tryloom-retry-icon"
                                    title="<?php esc_attr_e('Try Again', 'tryloom'); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <polyline points="23 4 23 10 17 10"></polyline>
                                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                                    </svg>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tryloom-result-actions">
                        <?php
                        // Get Add to Cart button classes.
                        $button_classes = apply_filters('tryloom_product_single_add_to_cart_button_classes', 'button alt');
                        ?>
                        <button type="button" class="<?php echo esc_attr($button_classes); ?> tryloom-add-to-cart"
                            style="background-color: <?php echo esc_attr($primary_color); ?>; color: #fff;">
                            <i class="fas fa-shopping-cart"></i>
                            <?php esc_html_e('Looks Good', 'tryloom'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <!-- Loading Overlay INSIDE popup content -->
            <div class="tryloom-loading-overlay" style="display: none;">
                <div class="tryloom-progress-ring-container">
                    <svg class="tryloom-progress-ring" width="80" height="80">
                        <circle class="tryloom-progress-ring-bg" cx="40" cy="40" r="34" fill="none" stroke-width="6">
                        </circle>
                        <circle class="tryloom-progress-ring-fill" cx="40" cy="40" r="34" fill="none" stroke-width="6"
                            stroke-dasharray="214" stroke-dashoffset="214" stroke-linecap="round"></circle>
                    </svg>
                </div>
                <p class="tryloom-loading-status"><?php esc_html_e('Processing...', 'tryloom'); ?></p>
            </div>
        </div>
    </div>