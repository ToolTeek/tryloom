<?php
/**
 * Try On Popup Template.
 *
 * @package WooCommerce_Try_On
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
// Template variables and WooCommerce hooks are acceptable in template files.

// Get settings.
$settings = get_option( 'tryloom_settings', array() );
$theme = get_option( 'tryloom_theme_color', 'light' );
$primary_color = get_option( 'tryloom_primary_color', '#552FBC' ); // Force direct use, not from settings array
$watermark = isset( $settings['watermark'] ) ? wp_get_attachment_url( $settings['watermark'] ) : '';
$save_photos = isset( $settings['save_photos'] ) ? $settings['save_photos'] : 'yes';
$retry_button = get_option( 'tryloom_retry_button', 'yes' );

// Get current user.
$current_user = wp_get_current_user();
$user_id = $current_user->ID;
$default_photo = false;

// Check if user has default photo.
if ( $user_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tryloom_user_photos';
    
    // First try to get permanent default (manually_set_default = 1).
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
    $default_photo = $wpdb->get_row( $wpdb->prepare(
        'SELECT * FROM ' . esc_sql( $table_name ) . ' WHERE user_id = %d AND is_default = 1 AND manually_set_default = 1 LIMIT 1',
        $user_id
    ) );
    
    // If no permanent default, get temp default (manually_set_default = 0).
    if ( ! $default_photo ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name sanitized with esc_sql()
        $default_photo = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . esc_sql( $table_name ) . ' WHERE user_id = %d AND is_default = 1 AND manually_set_default = 0 LIMIT 1',
            $user_id
        ) );
    }
}

// Get default photo URL.
$default_photo_url = $default_photo ? $default_photo->image_url : '';
?>
<div id="tryloom-popup" class="tryloom-popup tryloom-theme-<?php echo esc_attr( $theme ); ?>" style="display: none;">
    <div class="tryloom-popup-content">
        <div class="tryloom-popup-header">
            <h3><?php esc_html_e( 'Virtual Try On', 'tryloom' ); ?></h3>
            <button class="tryloom-popup-close">&times;</button>
        </div>
        
        <div class="tryloom-popup-body">
            <!-- Step 1: Upload and Select Variation -->
            <div class="tryloom-step tryloom-step-1">
                <div class="tryloom-upload">
                    <h4><?php esc_html_e( 'Your Photo', 'tryloom' ); ?></h4>
                    <div class="tryloom-upload-area">
                        <div class="tryloom-upload-preview">
                            <?php if ( $default_photo_url ) : ?>
                                <div class="tryloom-preview">
                                    <img src="<?php echo esc_url( $default_photo_url ); ?>" alt="<?php esc_attr_e( 'Your Photo', 'tryloom' ); ?>" />
                                </div>
                            <?php else : ?>
                                <div class="tryloom-upload-placeholder">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="64" height="64"><path fill="none" d="M0 0h24v24H0z"/><path d="M21 15v3h3v2h-3v3h-2v-3h-3v-2h3v-3h2zm.008-12c.548 0 .992.445.992.993v9.349A5.99 5.99 0 0 0 20 13V5H4v13.586l3.293-3.293a1 1 0 0 1 1.414 0L12 18.586l2.293-2.293a1 1 0 0 1 1.414 0l.293.293V13a5.99 5.99 0 0 0-2 .341V9a1 1 0 0 1 1-1h5zm-9.489 4.99a3.5 3.5 0 1 1-3.5 3.5 3.5 3.5 0 0 1 3.5-3.5zM4.003 3h16.995c.55 0 .997.446.997.996V13h-2V5H4v13.589l3.294-3.291a1 1 0 0 1 1.32-.084l.094.084 3.292 3.292 1.968-1.968a5.942 5.942 0 0 0 1.173 1.423l-3.141 3.141a1 1 0 0 1-1.32.084l-.094-.084L8 18.585l-5.293 5.294A1 1 0 0 1 1.999 24a.993.993 0 0 1-.996-.996V3.996A.996.996 0 0 1 1.997 3h.006z" fill="rgba(128,128,128,0.5)"/></svg>
                                    <p><?php esc_html_e( 'Click or drag to upload', 'tryloom' ); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <input type="file" id="tryloom-file" accept="image/*" style="display: none;">
                    
                    <?php if ( $save_photos === 'user' ) : ?>
                        <div class="tryloom-save-option">
                            <label>
                                <input type="checkbox" id="tryloom-save-photo">
                                <?php esc_html_e( 'Save photo for later use', 'tryloom' ); ?>
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="tryloom-variations">
                    <h4><?php esc_html_e( 'Select Product Variation', 'tryloom' ); ?></h4>
                    <div class="tryloom-variations-container">
                        <p class="tryloom-loading"><?php esc_html_e( 'Loading variations...', 'tryloom' ); ?></p>
                    </div>
                </div>
                
                <div class="tryloom-actions">
                    <?php
                    // Get Add to Cart button classes.
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                    $button_classes = apply_filters( 'tryloom_product_single_add_to_cart_button_classes', 'button alt' );
                    ?>
                    <button type="button" class="<?php echo esc_attr( $button_classes ); ?> tryloom-generate" style="background-color: <?php echo esc_attr( $primary_color ); ?>; color: #fff;">
                        <i class="fas fa-magic"></i>
                        <?php esc_html_e( 'Generate Try On', 'tryloom' ); ?>
                    </button>
                </div>
            </div>
            
            <!-- Step 2: Result -->
            <div class="tryloom-step tryloom-step-2" style="display: none;">
                <div class="tryloom-result">
                    <div class="tryloom-result-image">
                        <div class="tryloom-result-loading" aria-hidden="true">
                            <div class="tryloom-spinner"></div>
                        </div>
                        <img src="" alt="<?php esc_attr_e( 'Try On Result', 'tryloom' ); ?>">
                        <?php if ( $watermark ) : ?>
                            <div class="tryloom-watermark">
                                <img src="<?php echo esc_url( $watermark ); ?>" alt="<?php esc_attr_e( 'Watermark', 'tryloom' ); ?>">
                            </div>
                        <?php endif; ?>
                        
                        <!-- Image action icons -->
                        <div class="tryloom-image-actions">
                            <a href="#" class="tryloom-icon-button tryloom-download-icon" download title="<?php esc_attr_e( 'Download', 'tryloom' ); ?>">
                                <i class="fas fa-download"></i>
                            </a>
                            <?php if ( 'yes' === $retry_button ) : ?>
                            <button type="button" class="tryloom-icon-button tryloom-retry-icon" title="<?php esc_attr_e( 'Try Again', 'tryloom' ); ?>">
                                <i class="fas fa-redo"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="tryloom-result-actions">
                        <?php
                        // Get Add to Cart button classes.
                        $button_classes = apply_filters( 'tryloom_product_single_add_to_cart_button_classes', 'button alt' );
                        ?>
                        <button type="button" class="<?php echo esc_attr( $button_classes ); ?> tryloom-add-to-cart" style="background-color: <?php echo esc_attr( $primary_color ); ?>; color: #fff;">
                            <i class="fas fa-shopping-cart"></i>
                            <?php esc_html_e( 'Looks Good', 'tryloom' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Loading Overlay INSIDE popup content -->
        <div class="tryloom-loading-overlay" style="display: none;">
            <div class="tryloom-spinner"></div>
            <p><?php esc_html_e( 'Trying it on...', 'tryloom' ); ?></p>
        </div>
    </div>
</div>