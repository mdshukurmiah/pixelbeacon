<?php
/**
 * PixelBeacon for WooCommerce Admin
 * 
 * Handles admin interface, tabbed navigation, and settings for Facebook & TikTok.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PixelBeacon_Admin {
    
    /**
     * Settings option name
     */
    const OPTION_NAME = 'pixelbeacon_settings';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Admin hooks are handled in main plugin file
    }
    
    /**
     * Initialize settings
     */
    public function init_settings() {
        register_setting(
            'pixelbeacon_settings_group',
            self::OPTION_NAME,
            array($this, 'sanitize_settings')
        );
        
        // ==========================================
        // FACEBOOK TAB SETTINGS
        // ==========================================
        add_settings_section(
            'pixelbeacon_general_section',
            __('Facebook General Settings', 'pixelbeacon'),
            array($this, 'general_section_callback'),
            'pixelbeacon-settings'
        );
        
        // Pixel ID field
        add_settings_field(
            'pixel_id',
            __('Facebook Pixel ID', 'pixelbeacon'),
            array($this, 'pixel_id_callback'),
            'pixelbeacon-settings',
            'pixelbeacon_general_section'
        );
        
        // Access Token field
        add_settings_field(
            'access_token',
            __('Conversions API Access Token', 'pixelbeacon'),
            array($this, 'access_token_callback'),
            'pixelbeacon-settings',
            'pixelbeacon_general_section'
        );
        
        // Test Event Code field
        add_settings_field(
            'test_event_code',
            __('Test Event Code', 'pixelbeacon'),
            array($this, 'test_event_code_callback'),
            'pixelbeacon-settings',
            'pixelbeacon_general_section'
        );
        
        // Events settings section
        add_settings_section(
            'pixelbeacon_events_section',
            __('Facebook Event Settings', 'pixelbeacon'),
            array($this, 'events_section_callback'),
            'pixelbeacon-settings'
        );
        
        // Enabled Events field
        add_settings_field(
            'enabled_events',
            __('Enabled Facebook Events', 'pixelbeacon'),
            array($this, 'enabled_events_callback'),
            'pixelbeacon-settings',
            'pixelbeacon_events_section'
        );
        
        // Debug settings section
        add_settings_section(
            'pixelbeacon_debug_section',
            __('Debug Settings', 'pixelbeacon'),
            array($this, 'debug_section_callback'),
            'pixelbeacon-settings'
        );
        
        // Debug Mode field
        add_settings_field(
            'debug_mode',
            __('Debug Mode', 'pixelbeacon'),
            array($this, 'debug_mode_callback'),
            'pixelbeacon-settings',
            'pixelbeacon_debug_section'
        );

        // Privacy Section
        add_settings_section(
            'pixelbeacon_privacy_section',
            __('Privacy & Compliance', 'pixelbeacon'),
            array($this, 'privacy_section_callback'),
            'pixelbeacon-settings'
        );

        // Limited Data Use field
        add_settings_field(
            'enable_ldu',
            __('Limited Data Use (LDU)', 'pixelbeacon'),
            array($this, 'enable_ldu_callback'),
            'pixelbeacon-settings',
            'pixelbeacon_privacy_section'
        );

        // ==========================================
        // TIKTOK TAB SETTINGS
        // ==========================================
        add_settings_section(
            'pixelbeacon_tiktok_section',
            __('TikTok General Settings', 'pixelbeacon'),
            array($this, 'tiktok_section_callback'),
            'pixelbeacon-tiktok-settings'
        );

        // TikTok Enabled Toggle
        add_settings_field(
            'tiktok_enabled',
            __('Enable TikTok Tracking', 'pixelbeacon'),
            array($this, 'tiktok_enabled_callback'),
            'pixelbeacon-tiktok-settings',
            'pixelbeacon_tiktok_section'
        );

        // TikTok Pixel ID
        add_settings_field(
            'tiktok_pixel_id',
            __('TikTok Pixel ID', 'pixelbeacon'),
            array($this, 'tiktok_pixel_id_callback'),
            'pixelbeacon-tiktok-settings',
            'pixelbeacon_tiktok_section'
        );

        // TikTok Events API Access Token
        add_settings_field(
            'tiktok_access_token',
            __('Events API Access Token', 'pixelbeacon'),
            array($this, 'tiktok_access_token_callback'),
            'pixelbeacon-tiktok-settings',
            'pixelbeacon_tiktok_section'
        );

        // TikTok Test Event Code
        add_settings_field(
            'tiktok_test_event_code',
            __('TikTok Test Event Code', 'pixelbeacon'),
            array($this, 'tiktok_test_event_code_callback'),
            'pixelbeacon-tiktok-settings',
            'pixelbeacon_tiktok_section'
        );

        // TikTok Events Section
        add_settings_section(
            'pixelbeacon_tiktok_events_section',
            __('TikTok Event Settings', 'pixelbeacon'),
            array($this, 'tiktok_events_section_callback'),
            'pixelbeacon-tiktok-settings'
        );

        // TikTok Enabled Events
        add_settings_field(
            'tiktok_enabled_events',
            __('Enabled TikTok Events', 'pixelbeacon'),
            array($this, 'tiktok_enabled_events_callback'),
            'pixelbeacon-tiktok-settings',
            'pixelbeacon_tiktok_events_section'
        );
    }
    
    /**
     * Render settings page with Facebook & TikTok Tabs
     */
    public function render_settings_page() {
        // Handle Facebook test connection
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified inside handle_test_connection().
        if (isset($_POST['test_connection'])) {
            $this->handle_test_connection();
        }

        // Handle TikTok test connection
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified inside handle_test_tiktok_connection().
        if (isset($_POST['test_tiktok_connection'])) {
            $this->handle_test_tiktok_connection();
        }

        // Handle clear logs
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified inside handle_clear_logs().
        if (isset($_POST['clear_logs'])) {
            $this->handle_clear_logs();
        }
        
        $settings = PixelBeacon_Helpers::get_settings();
        ?>
        <div class="wrap pixelbeacon-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors(); ?>

            <!-- Navigation Tabs -->
            <h2 class="nav-tab-wrapper wp-clearfix" style="margin-top: 15px; margin-bottom: 20px;">
                <a href="#tab-facebook" class="nav-tab nav-tab-active" data-tab="facebook">
                    <span class="dashicons dashicons-facebook" style="vertical-align: text-bottom; margin-right: 4px;"></span><?php esc_html_e('Facebook', 'pixelbeacon'); ?>
                </a>
                <a href="#tab-tiktok" class="nav-tab" data-tab="tiktok">
                    <span class="dashicons dashicons-video-alt3" style="vertical-align: text-bottom; margin-right: 4px;"></span><?php esc_html_e('TikTok', 'pixelbeacon'); ?>
                </a>
            </h2>
            
            <div class="pixelbeacon-admin-container">
                <div class="pixelbeacon-main-content">
                    <form method="post" action="options.php" id="pixelbeacon_settings_form">
                        <?php settings_fields('pixelbeacon_settings_group'); ?>
                        <input type="hidden" name="pixelbeacon_active_tab" id="pixelbeacon_active_tab" value="facebook">

                        <!-- Facebook Tab Content -->
                        <div id="pixelbeacon-tab-facebook" class="pixelbeacon-tab-panel active">
                            <?php
                            do_settings_sections('pixelbeacon-settings');
                            submit_button(__('Save Facebook Settings', 'pixelbeacon'));
                            ?>
                        </div>

                        <!-- TikTok Tab Content -->
                        <div id="pixelbeacon-tab-tiktok" class="pixelbeacon-tab-panel" style="display: none;">
                            <?php
                            do_settings_sections('pixelbeacon-tiktok-settings');
                            submit_button(__('Save TikTok Settings', 'pixelbeacon'));
                            ?>
                        </div>
                    </form>
                    
                    <!-- Facebook Extra Tools (Connection Test & Failure Logs) -->
                    <div id="pixelbeacon-tools-facebook" class="pixelbeacon-tab-tools active">
                        <hr>
                        
                        <h2><?php esc_html_e('Test Facebook Connection', 'pixelbeacon'); ?></h2>
                        <p><?php esc_html_e('Test your Meta Conversions API connection to ensure your Pixel ID and Access Token are working correctly.', 'pixelbeacon'); ?></p>
                        
                        <form method="post" id="pixelbeacon_test_connection_form" style="display:inline-block; margin-right: 10px;">
                            <?php wp_nonce_field('pixelbeacon_test_connection', 'pixelbeacon_test_nonce'); ?>
                            <input type="hidden" name="test_pixel_id" id="test_pixel_id" value="">
                            <input type="hidden" name="test_access_token" id="test_access_token" value="">
                            <input type="submit" name="test_connection" class="button button-secondary" value="<?php esc_html_e('Test Facebook Connection', 'pixelbeacon'); ?>">
                        </form>

                        <form method="post" style="display:inline-block;">
                            <?php wp_nonce_field('pixelbeacon_clear_logs', 'pixelbeacon_clear_nonce'); ?>
                            <input type="submit" name="clear_logs" class="button button-link-delete" value="<?php esc_html_e('Clear Logs', 'pixelbeacon'); ?>" onclick="return confirm('Are you sure you want to clear all logs?');">
                        </form>

                        <hr>

                        <h2><?php esc_html_e('Recent Event & Failure Logs', 'pixelbeacon'); ?></h2>
                        <p><?php esc_html_e('Displays recent API transmissions, failures, and retry statuses for both Meta CAPI and TikTok Events API.', 'pixelbeacon'); ?></p>
                        
                        <?php
                        $api_client = new PixelBeacon_API_Client();
                        $logs = $api_client->get_recent_logs(15);
                        if (!empty($logs)):
                        ?>
                        <table class="widefat fixed striped" style="margin-top: 10px;">
                            <thead>
                                <tr>
                                    <th style="width: 140px;"><?php esc_html_e('Date / Time', 'pixelbeacon'); ?></th>
                                    <th style="width: 140px;"><?php esc_html_e('Event / Platform', 'pixelbeacon'); ?></th>
                                    <th style="width: 90px;"><?php esc_html_e('Status', 'pixelbeacon'); ?></th>
                                    <th><?php esc_html_e('Details / Error Message', 'pixelbeacon'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): 
                                    $status = esc_attr($log['status']);
                                    $badge_style = 'padding: 3px 8px; border-radius: 3px; font-weight: 600; text-transform: uppercase; font-size: 11px;';
                                    if ($status === 'success') {
                                        $badge_style .= ' background: #d4edda; color: #155724;';
                                    } elseif ($status === 'error') {
                                        $badge_style .= ' background: #f8d7da; color: #721c24;';
                                    } else {
                                        $badge_style .= ' background: #fff3cd; color: #856404;';
                                    }
                                    $details = '';
                                    if (!empty($log['event_data'])) {
                                        $decoded_data = json_decode($log['event_data'], true);
                                        if (!empty($decoded_data['error_message'])) {
                                            $details = $decoded_data['error_message'];
                                        } elseif (!empty($decoded_data['endpoint'])) {
                                            $details = 'Endpoint: ' . $decoded_data['endpoint'];
                                        } elseif (!empty($decoded_data['status_code'])) {
                                            $details = 'HTTP Status: ' . $decoded_data['status_code'];
                                        }
                                    }
                                    if (empty($details) && !empty($log['response_data'])) {
                                        $details = substr($log['response_data'], 0, 150);
                                    }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($log['created_at']); ?></td>
                                    <td><strong><?php echo esc_html($log['event_name']); ?></strong></td>
                                    <td><span style="<?php echo esc_attr($badge_style); ?>"><?php echo esc_html($status); ?></span></td>
                                    <td><code style="word-break: break-all;"><?php echo esc_html($details ? $details : __('No extra details', 'pixelbeacon')); ?></code></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p><em><?php esc_html_e('No log entries recorded yet.', 'pixelbeacon'); ?></em></p>
                        <?php endif; ?>
                    </div>

                    <!-- TikTok Extra Tools (Connection Test) -->
                    <div id="pixelbeacon-tools-tiktok" class="pixelbeacon-tab-tools" style="display: none;">
                        <hr>
                        
                        <h2><?php esc_html_e('Test TikTok Connection', 'pixelbeacon'); ?></h2>
                        <p><?php esc_html_e('Test your TikTok Events API connection to ensure your Pixel ID and Access Token are valid and authorized by TikTok.', 'pixelbeacon'); ?></p>
                        
                        <form method="post" id="pixelbeacon_test_tiktok_connection_form" style="display:inline-block;">
                            <?php wp_nonce_field('pixelbeacon_test_tiktok_connection', 'pixelbeacon_test_tiktok_nonce'); ?>
                            <input type="hidden" name="test_tiktok_pixel_id" id="test_tiktok_pixel_id" value="">
                            <input type="hidden" name="test_tiktok_access_token" id="test_tiktok_access_token" value="">
                            <input type="submit" name="test_tiktok_connection" class="button button-secondary" value="<?php esc_html_e('Test TikTok Connection', 'pixelbeacon'); ?>">
                        </form>
                    </div>
                </div>
                
                <div class="pixelbeacon-sidebar">
                    <!-- Facebook Sidebar Info -->
                    <div id="pixelbeacon-sidebar-facebook" class="pixelbeacon-sidebar-panel active">
                        <div class="pixelbeacon-info-box">
                            <h3><?php esc_html_e('Getting Started (Facebook)', 'pixelbeacon'); ?></h3>
                            <ol>
                                <li><?php esc_html_e('Create a Facebook Business Manager account', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Set up a Facebook Pixel in Events Manager', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Generate a Conversions API access token', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Enter your Pixel ID and Access Token', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Configure which events to track', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Click Test Facebook Connection', 'pixelbeacon'); ?></li>
                            </ol>
                        </div>
                        
                        <div class="pixelbeacon-info-box">
                            <h3><?php esc_html_e('Facebook Documentation', 'pixelbeacon'); ?></h3>
                            <ul>
                                <li><a href="https://developers.facebook.com/docs/marketing-api/conversions-api/" target="_blank"><?php esc_html_e('Facebook Conversions API Documentation', 'pixelbeacon'); ?></a></li>
                                <li><a href="https://www.facebook.com/business/help/AboutConversionsAPI" target="_blank"><?php esc_html_e('About Conversions API', 'pixelbeacon'); ?></a></li>
                                <li><a href="https://developers.facebook.com/docs/marketing-api/conversions-api/get-started" target="_blank"><?php esc_html_e('Getting Started Guide', 'pixelbeacon'); ?></a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- TikTok Sidebar Info -->
                    <div id="pixelbeacon-sidebar-tiktok" class="pixelbeacon-sidebar-panel" style="display: none;">
                        <div class="pixelbeacon-info-box">
                            <h3><?php esc_html_e('Getting Started (TikTok)', 'pixelbeacon'); ?></h3>
                            <ol>
                                <li><?php esc_html_e('Log into TikTok Ads Manager -> Assets -> Events', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Under Web Events, select or create your TikTok Pixel', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Go to Settings -> Events API -> Generate Access Token', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Paste your Pixel ID and Access Token here and enable tracking', 'pixelbeacon'); ?></li>
                                <li><?php esc_html_e('Click Test TikTok Connection', 'pixelbeacon'); ?></li>
                            </ol>
                        </div>

                        <div class="pixelbeacon-info-box">
                            <h3><?php esc_html_e('TikTok Match Quality (EMQ)', 'pixelbeacon'); ?></h3>
                            <p style="font-size: 13px; line-height: 1.4; color: #555;">
                                <?php esc_html_e('PixelBeacon automatically generates and stores 1st-party cookies (_ttp) and captures TikTok Click IDs (ttclid), locking them into order meta for maximum Event Match Quality and server deduplication.', 'pixelbeacon'); ?>
                            </p>
                            <ul>
                                <li><a href="https://business-api.tiktok.com/portal/docs?id=1741601162187778" target="_blank"><?php esc_html_e('TikTok Events API Guide', 'pixelbeacon'); ?></a></li>
                                <li><a href="https://ads.tiktok.com/help/article/first-party-cookies" target="_blank"><?php esc_html_e('First-Party Cookies (_ttp)', 'pixelbeacon'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="pixelbeacon-info-box">
                        <h3><?php esc_html_e('System Information', 'pixelbeacon'); ?></h3>
                        <p><strong><?php esc_html_e('WooCommerce Status:', 'pixelbeacon'); ?></strong> 
                           <?php echo class_exists('WooCommerce') ? '<span style="color:#46b450; font-weight:600;">' . esc_html__('Active', 'pixelbeacon') . '</span>' : '<span style="color:#dc3232;">' . esc_html__('Not Active', 'pixelbeacon') . '</span>'; ?>
                        </p>
                        <p><strong><?php esc_html_e('Meta Pixel Status:', 'pixelbeacon'); ?></strong> 
                           <?php echo !empty($settings['pixel_id']) ? '<span style="color:#46b450; font-weight:600;">' . esc_html__('Configured', 'pixelbeacon') . '</span>' : '<span style="color:#999;">' . esc_html__('Not Set', 'pixelbeacon') . '</span>'; ?>
                        </p>
                        <p><strong><?php esc_html_e('TikTok Status:', 'pixelbeacon'); ?></strong> 
                           <?php echo (!empty($settings['tiktok_enabled']) && !empty($settings['tiktok_pixel_id'])) ? '<span style="color:#46b450; font-weight:600;">' . esc_html__('Active', 'pixelbeacon') . '</span>' : '<span style="color:#999;">' . esc_html__('Inactive', 'pixelbeacon') . '</span>'; ?>
                        </p>
                        <?php if (!empty($settings['debug_mode'])): ?>
                        <p style="color:#d63638; font-weight:600;">
                            <?php esc_html_e('Debug mode is ENABLED.', 'pixelbeacon'); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Facebook General section callback
     */
    public function general_section_callback() {
        echo '<p>' . esc_html__('Configure your Facebook Pixel and Meta Conversions API credentials.', 'pixelbeacon') . '</p>';
    }
    
    /**
     * Facebook Events section callback
     */
    public function events_section_callback() {
        echo '<p>' . esc_html__('Choose which events to track via server-side and browser tracking.', 'pixelbeacon') . '</p>';
    }
    
    /**
     * Debug section callback
     */
    public function debug_section_callback() {
       echo '<p>' . esc_html__('Debug settings for troubleshooting and development.', 'pixelbeacon') . '</p>';
    }
    
    /**
     * Facebook Pixel ID field callback
     */
    public function pixel_id_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = isset($settings['pixel_id']) ? $settings['pixel_id'] : '';
        ?>
        <input type="text" id="pixel_id" name="<?php echo esc_attr(self::OPTION_NAME); ?>[pixel_id]" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="123456789012345">
        <p class="description"><?php esc_html_e('Your Facebook Pixel ID (15-16 digits). You can find this in your Facebook Events Manager.', 'pixelbeacon'); ?></p>
        <?php
    }
    
    /**
     * Facebook Access Token field callback
     */
    public function access_token_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = isset($settings['access_token']) ? $settings['access_token'] : '';
        ?>
        <input type="password" id="access_token" name="<?php echo esc_attr(self::OPTION_NAME); ?>[access_token]" value="<?php echo esc_attr($value); ?>" class="large-text" placeholder="EAAxxxxxxxxxxxxx">
        <p class="description"><?php esc_html_e('Your Conversions API access token. Generate this in Facebook Events Manager under Conversions API settings.', 'pixelbeacon'); ?></p>
        <?php
    }
    
    /**
     * Facebook Test Event Code field callback
     */
    public function test_event_code_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = isset($settings['test_event_code']) ? $settings['test_event_code'] : '';
        ?>
        <input type="text" id="test_event_code" name="<?php echo esc_attr(self::OPTION_NAME); ?>[test_event_code]" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="TEST12345">
        <p class="description"><?php esc_html_e('Optional test event code for testing your integration. You can find this in Facebook Events Manager.', 'pixelbeacon'); ?></p>
        <?php
    }
    
    /**
     * Facebook Enabled Events field callback
     */
    public function enabled_events_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $enabled_events = isset($settings['enabled_events']) ? $settings['enabled_events'] : array();
        
        $available_events = array(
            'PageView'             => __('Page View', 'pixelbeacon'),
            'ViewContent'          => __('View Content (Product)', 'pixelbeacon'),
            'ViewCategory'         => __('View Category (Category/Tag Archive)', 'pixelbeacon'),
            'AddToCart'            => __('Add to Cart', 'pixelbeacon'),
            'RemoveFromCart'       => __('Remove from Cart', 'pixelbeacon'),
            'InitiateCheckout'     => __('Initiate Checkout', 'pixelbeacon'),
            'AddPaymentInfo'       => __('Add Payment Info', 'pixelbeacon'),
            'Purchase'             => __('Purchase', 'pixelbeacon'),
            'AddToWishlist'        => __('Add to Wishlist', 'pixelbeacon'),
            'Search'               => __('Search', 'pixelbeacon'),
            'Lead'                 => __('Lead (Form Submissions)', 'pixelbeacon'),
            'Contact'              => __('Contact', 'pixelbeacon'),
            'CompleteRegistration' => __('Complete Registration', 'pixelbeacon'),
            'CustomizeProduct'     => __('Customize Product (Variations)', 'pixelbeacon'),
            'FirstOrder'           => __('First Order (Audience Trigger)', 'pixelbeacon'),
            'ReturningCustomer'    => __('Returning Customer (Audience Trigger)', 'pixelbeacon'),
            'VipClient'            => __('VIP Client (High LTV Trigger)', 'pixelbeacon'),
        );

        $descriptions = array(
            'PageView'             => __('Track when users view any page on your website', 'pixelbeacon'),
            'ViewContent'          => __('Track when users view single product pages with catalog parameters', 'pixelbeacon'),
            'ViewCategory'         => __('Track when users view product category or tag archive pages', 'pixelbeacon'),
            'AddToCart'            => __('Track when users add products to cart (Standard and AJAX)', 'pixelbeacon'),
            'RemoveFromCart'       => __('Track when users remove products from cart', 'pixelbeacon'),
            'InitiateCheckout'     => __('Track when users start checkout (Classic and Block checkout)', 'pixelbeacon'),
            'AddPaymentInfo'       => __('Track when users select payment method during checkout', 'pixelbeacon'),
            'Purchase'             => __('Track completed orders with LTV, transaction count, shipping and tax', 'pixelbeacon'),
            'AddToWishlist'        => __('Track when users save products to wishlist (YITH & custom)', 'pixelbeacon'),
            'Search'               => __('Track search queries and result product IDs', 'pixelbeacon'),
            'Lead'                 => __('Track form inquiries (Contact Form 7, WPForms)', 'pixelbeacon'),
            'Contact'              => __('Track contact submissions and support touches', 'pixelbeacon'),
            'CompleteRegistration' => __('Track new user and customer registrations', 'pixelbeacon'),
            'CustomizeProduct'     => __('Track when users select variable product attributes', 'pixelbeacon'),
            'FirstOrder'           => __('Trigger custom audience event on first-time customer purchases', 'pixelbeacon'),
            'ReturningCustomer'    => __('Trigger custom audience event on repeat buyer purchases', 'pixelbeacon'),
            'VipClient'            => __('Trigger high-value audience event when customer LTV >= 500 or 5+ orders', 'pixelbeacon'),
        );
        
        $woo_events = array('ViewContent', 'ViewCategory', 'AddToCart', 'RemoveFromCart', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase', 'AddToWishlist', 'CustomizeProduct', 'FirstOrder', 'ReturningCustomer', 'VipClient');

        echo '<fieldset>';
        foreach ($available_events as $event_key => $event_label) {
            $checked = !empty($enabled_events[$event_key]) ? 'checked' : '';
            $disabled = '';
            $desc = isset($descriptions[$event_key]) ? $descriptions[$event_key] : '';

            if (in_array($event_key, $woo_events, true) && !class_exists('WooCommerce')) {
                $disabled = 'disabled';
                $desc .= ' (' . __('Requires WooCommerce', 'pixelbeacon') . ')';
            }
            
            printf(
                '<label><input type="checkbox" name="%s[enabled_events][%s]" value="1" %s %s> %s</label><br>',
                esc_attr(self::OPTION_NAME),
                esc_attr($event_key),
                $checked ? 'checked' : '',
                $disabled ? 'disabled' : '',
                esc_html($event_label)
            );
            
            if ($desc) {
               echo '<p class="description" style="margin-left: 25px; margin-top: 2px; margin-bottom: 8px;">' . esc_html($desc) . '</p>';
            }
        }
        echo '</fieldset>';
    }
    
    /**
     * Debug Mode field callback
     */
    public function debug_mode_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = !empty($settings['debug_mode']);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[debug_mode]" value="1" <?php checked($value); ?>>
            <?php esc_html_e('Enable debug mode', 'pixelbeacon'); ?>
        </label>
        <p class="description"><?php esc_html_e('When enabled, detailed logs will be written to the WordPress debug log and database for troubleshooting.', 'pixelbeacon'); ?></p>
        <?php
    }

    /**
     * Privacy section callback
     */
    public function privacy_section_callback() {
        echo '<p>' . esc_html__('Configure data processing and privacy compliance options.', 'pixelbeacon') . '</p>';
    }

    /**
     * Limited Data Use (LDU) field callback
     */
    public function enable_ldu_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = !empty($settings['enable_ldu']);
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[enable_ldu]" value="1" <?php checked($value); ?>>
            <?php esc_html_e('Enable Meta Limited Data Use (LDU)', 'pixelbeacon'); ?>
        </label>
        <p class="description"><?php esc_html_e('Enables Limited Data Use mode for California Consumer Privacy Act (CCPA) compliance across Meta Conversions API events.', 'pixelbeacon'); ?></p>
        <?php
    }

    // ==========================================
    // TIKTOK CALLBACKS
    // ==========================================

    /**
     * TikTok General section callback
     */
    public function tiktok_section_callback() {
        echo '<p>' . esc_html__('Configure your TikTok Pixel and Events API credentials for server-side & browser tracking.', 'pixelbeacon') . '</p>';
    }

    /**
     * TikTok Enabled Toggle callback
     */
    public function tiktok_enabled_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = !empty($settings['tiktok_enabled']);
        ?>
        <label>
            <input type="checkbox" id="tiktok_enabled" name="<?php echo esc_attr(self::OPTION_NAME); ?>[tiktok_enabled]" value="1" <?php checked($value); ?>>
            <strong><?php esc_html_e('Enable TikTok Pixel & Events API Tracking', 'pixelbeacon'); ?></strong>
        </label>
        <p class="description"><?php esc_html_e('Activates TikTok browser pixel and high-accuracy server-side Events API tracking.', 'pixelbeacon'); ?></p>
        <?php
    }

    /**
     * TikTok Pixel ID callback
     */
    public function tiktok_pixel_id_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = isset($settings['tiktok_pixel_id']) ? $settings['tiktok_pixel_id'] : '';
        ?>
        <input type="text" id="tiktok_pixel_id" name="<?php echo esc_attr(self::OPTION_NAME); ?>[tiktok_pixel_id]" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="C1234567890ABCDEFGH">
        <p class="description"><?php esc_html_e('Your TikTok Pixel ID (alphanumeric code found in TikTok Ads Manager -> Assets -> Events).', 'pixelbeacon'); ?></p>
        <?php
    }

    /**
     * TikTok Access Token callback
     */
    public function tiktok_access_token_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = isset($settings['tiktok_access_token']) ? $settings['tiktok_access_token'] : '';
        ?>
        <input type="password" id="tiktok_access_token" name="<?php echo esc_attr(self::OPTION_NAME); ?>[tiktok_access_token]" value="<?php echo esc_attr($value); ?>" class="large-text" placeholder="Your TikTok Events API Access Token">
        <p class="description"><?php esc_html_e('Your TikTok Events API access token. Generate this in TikTok Events Manager -> Pixel -> Settings -> Events API.', 'pixelbeacon'); ?></p>
        <?php
    }

    /**
     * TikTok Test Event Code callback
     */
    public function tiktok_test_event_code_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $value = isset($settings['tiktok_test_event_code']) ? $settings['tiktok_test_event_code'] : '';
        ?>
        <input type="text" id="tiktok_test_event_code" name="<?php echo esc_attr(self::OPTION_NAME); ?>[tiktok_test_event_code]" value="<?php echo esc_attr($value); ?>" class="regular-text" placeholder="TEST12345">
        <p class="description"><?php esc_html_e('Optional test event code from TikTok Events Manager (Test Events tab) to verify server events live.', 'pixelbeacon'); ?></p>
        <?php
    }

    /**
     * TikTok Events section callback
     */
    public function tiktok_events_section_callback() {
        echo '<p>' . esc_html__('Select which TikTok events to trigger across browser and server.', 'pixelbeacon') . '</p>';
    }

    /**
     * TikTok Enabled Events callback
     */
    public function tiktok_enabled_events_callback() {
        $settings = PixelBeacon_Helpers::get_settings();
        $enabled = isset($settings['tiktok_enabled_events']) ? $settings['tiktok_enabled_events'] : array();

        $available_events = array(
            'PageView'             => __('Page View', 'pixelbeacon'),
            'ViewContent'          => __('View Content (Single Product)', 'pixelbeacon'),
            'AddToCart'            => __('Add to Cart', 'pixelbeacon'),
            'InitiateCheckout'     => __('Initiate Checkout', 'pixelbeacon'),
            'AddPaymentInfo'       => __('Add Payment Info', 'pixelbeacon'),
            'CompletePayment'      => __('Complete Payment (Purchase)', 'pixelbeacon'),
            'Search'               => __('Search', 'pixelbeacon'),
            'AddToWishlist'        => __('Add to Wishlist', 'pixelbeacon'),
            'CompleteRegistration' => __('Complete Registration', 'pixelbeacon'),
            'Contact'              => __('Contact', 'pixelbeacon'),
        );

        $descriptions = array(
            'PageView'             => __('Track when users view any page on your website', 'pixelbeacon'),
            'ViewContent'          => __('Track when users view single product details pages', 'pixelbeacon'),
            'AddToCart'            => __('Track when users add items to the cart (Standard and AJAX)', 'pixelbeacon'),
            'InitiateCheckout'     => __('Track when users start checkout (Classic and WooCommerce Blocks)', 'pixelbeacon'),
            'AddPaymentInfo'       => __('Track when users select payment method on checkout', 'pixelbeacon'),
            'CompletePayment'      => __('Track completed orders with full catalog, currency, and value parameters', 'pixelbeacon'),
            'Search'               => __('Track keyword searches on product catalog', 'pixelbeacon'),
            'AddToWishlist'        => __('Track wishlist saves (YITH & custom buttons)', 'pixelbeacon'),
            'CompleteRegistration' => __('Track new user registrations', 'pixelbeacon'),
            'Contact'              => __('Track Contact Form 7 and WPForms submissions', 'pixelbeacon'),
        );

        $woo_events = array('ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'CompletePayment', 'AddToWishlist');

        echo '<fieldset>';
        foreach ($available_events as $event_key => $event_label) {
            $checked = !empty($enabled[$event_key]) ? 'checked' : '';
            $disabled = '';
            $desc = isset($descriptions[$event_key]) ? $descriptions[$event_key] : '';

            if (in_array($event_key, $woo_events, true) && !class_exists('WooCommerce')) {
                $disabled = 'disabled';
                $desc .= ' (' . __('Requires WooCommerce', 'pixelbeacon') . ')';
            }

            printf(
                '<label><input type="checkbox" name="%s[tiktok_enabled_events][%s]" value="1" %s %s> %s</label><br>',
                esc_attr(self::OPTION_NAME),
                esc_attr($event_key),
                $checked ? 'checked' : '',
                $disabled ? 'disabled' : '',
                esc_html($event_label)
            );

            if ($desc) {
                echo '<p class="description" style="margin-left: 25px; margin-top: 2px; margin-bottom: 8px;">' . esc_html($desc) . '</p>';
            }
        }
        echo '</fieldset>';
    }
    
    /**
     * Sanitize settings for both Facebook and TikTok
     * 
     * @param array $input Input settings
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $sanitized = PixelBeacon_Helpers::get_default_settings();
        $existing = PixelBeacon_Helpers::get_settings();
        
        // ==========================================
        // SANITIZE FACEBOOK SETTINGS
        // ==========================================
        if (!empty($input['pixel_id'])) {
            $pixel_id = sanitize_text_field($input['pixel_id']);
            if (PixelBeacon_Helpers::validate_pixel_id($pixel_id)) {
                $sanitized['pixel_id'] = $pixel_id;
            } else {
                $sanitized['pixel_id'] = !empty($existing['pixel_id']) ? $existing['pixel_id'] : '';
                add_settings_error(
                    self::OPTION_NAME,
                    'invalid_pixel_id',
                    __('Invalid Facebook Pixel ID format. Please enter a valid 15-16 digit Pixel ID.', 'pixelbeacon')
                );
            }
        } else {
            $sanitized['pixel_id'] = '';
        }
        
        if (!empty($input['access_token'])) {
            $access_token = sanitize_text_field($input['access_token']);
            if (PixelBeacon_Helpers::validate_access_token($access_token)) {
                $sanitized['access_token'] = $access_token;
            } else {
                $sanitized['access_token'] = !empty($existing['access_token']) ? $existing['access_token'] : '';
                add_settings_error(
                    self::OPTION_NAME,
                    'invalid_access_token',
                    __('Invalid Facebook Access Token format. Please enter a valid Conversions API access token.', 'pixelbeacon')
                );
            }
        } else {
            $sanitized['access_token'] = '';
        }
        
        $sanitized['test_event_code'] = !empty($input['test_event_code']) ? sanitize_text_field($input['test_event_code']) : '';
        
        if (isset($input['enabled_events']) && is_array($input['enabled_events'])) {
            $sanitized['enabled_events'] = array();
            $allowed_events = array(
                'PageView', 'AddToCart', 'InitiateCheckout', 'Purchase', 'ViewContent',
                'ViewCategory', 'RemoveFromCart', 'AddPaymentInfo', 'AddToWishlist',
                'Search', 'Lead', 'Contact', 'CompleteRegistration', 'CustomizeProduct',
                'FirstOrder', 'ReturningCustomer', 'VipClient'
            );
            
            foreach ($input['enabled_events'] as $event => $value) {
                if (in_array($event, $allowed_events, true) && !empty($value)) {
                    $sanitized['enabled_events'][$event] = true;
                }
            }
        } else {
            // Keep existing if form submitted without event fields
            $sanitized['enabled_events'] = !empty($existing['enabled_events']) ? $existing['enabled_events'] : array();
        }
        
        $sanitized['debug_mode'] = !empty($input['debug_mode']);
        $sanitized['enable_ldu'] = !empty($input['enable_ldu']);

        // ==========================================
        // SANITIZE TIKTOK SETTINGS
        // ==========================================
        $sanitized['tiktok_enabled'] = !empty($input['tiktok_enabled']);

        if (!empty($input['tiktok_pixel_id'])) {
            $tt_pixel_id = sanitize_text_field($input['tiktok_pixel_id']);
            if (PixelBeacon_Helpers::validate_tiktok_pixel_id($tt_pixel_id)) {
                $sanitized['tiktok_pixel_id'] = $tt_pixel_id;
            } else {
                $sanitized['tiktok_pixel_id'] = !empty($existing['tiktok_pixel_id']) ? $existing['tiktok_pixel_id'] : '';
                add_settings_error(
                    self::OPTION_NAME,
                    'invalid_tiktok_pixel_id',
                    __('Invalid TikTok Pixel ID format. Expected 16-20 alphanumeric characters.', 'pixelbeacon')
                );
            }
        } else {
            $sanitized['tiktok_pixel_id'] = '';
        }

        if (!empty($input['tiktok_access_token'])) {
            $tt_token = sanitize_text_field($input['tiktok_access_token']);
            if (PixelBeacon_Helpers::validate_tiktok_access_token($tt_token)) {
                $sanitized['tiktok_access_token'] = $tt_token;
            } else {
                $sanitized['tiktok_access_token'] = !empty($existing['tiktok_access_token']) ? $existing['tiktok_access_token'] : '';
                add_settings_error(
                    self::OPTION_NAME,
                    'invalid_tiktok_access_token',
                    __('Invalid TikTok Access Token format. Expected 40+ hexadecimal characters.', 'pixelbeacon')
                );
            }
        } else {
            $sanitized['tiktok_access_token'] = '';
        }

        $sanitized['tiktok_test_event_code'] = !empty($input['tiktok_test_event_code']) ? sanitize_text_field($input['tiktok_test_event_code']) : '';

        if (isset($input['tiktok_enabled_events']) && is_array($input['tiktok_enabled_events'])) {
            $sanitized['tiktok_enabled_events'] = array();
            $allowed_tt_events = array(
                'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout',
                'AddPaymentInfo', 'CompletePayment', 'Search', 'AddToWishlist',
                'CompleteRegistration', 'Contact'
            );

            foreach ($input['tiktok_enabled_events'] as $event => $value) {
                if (in_array($event, $allowed_tt_events, true) && !empty($value)) {
                    $sanitized['tiktok_enabled_events'][$event] = true;
                }
            }
        } else {
            $sanitized['tiktok_enabled_events'] = !empty($existing['tiktok_enabled_events']) ? $existing['tiktok_enabled_events'] : array();
        }
        
        return $sanitized;
    }
    
    /**
     * Handle Facebook test connection
     */
    private function handle_test_connection() {
        // Verify nonce
        $test_nonce = isset($_POST['pixelbeacon_test_nonce']) ? sanitize_text_field(wp_unslash($_POST['pixelbeacon_test_nonce'])) : '';
        if (empty($test_nonce) || !wp_verify_nonce($test_nonce, 'pixelbeacon_test_connection')) {
            add_settings_error(
                self::OPTION_NAME,
                'invalid_nonce',
                __('Security check failed. Please try again.', 'pixelbeacon'),
                'error'
            );
            return;
        }
        
        // Test API connection using form inputs if available, otherwise saved settings
        $test_pixel_id = !empty($_POST['test_pixel_id']) ? sanitize_text_field(wp_unslash($_POST['test_pixel_id'])) : '';
        $test_access_token = !empty($_POST['test_access_token']) ? sanitize_text_field(wp_unslash($_POST['test_access_token'])) : '';

        $api_client = new PixelBeacon_API_Client();
        $result = $api_client->test_connection($test_pixel_id, $test_access_token);
		
        if (is_wp_error($result)) {
            add_settings_error(
                self::OPTION_NAME,
                'connection_failed',
                sprintf(__('Facebook connection test failed: %s', 'pixelbeacon'), $result->get_error_message()),
                'error'
            );
        } else {
            add_settings_error(
                self::OPTION_NAME,
                'connection_success',
                __('Facebook connection test successful! Your Conversions API is working correctly.', 'pixelbeacon'),
                'success'
            );
        }
    }

    /**
     * Handle TikTok test connection
     */
    private function handle_test_tiktok_connection() {
        // Verify nonce
        $test_nonce = isset($_POST['pixelbeacon_test_tiktok_nonce']) ? sanitize_text_field(wp_unslash($_POST['pixelbeacon_test_tiktok_nonce'])) : '';
        if (empty($test_nonce) || !wp_verify_nonce($test_nonce, 'pixelbeacon_test_tiktok_connection')) {
            add_settings_error(
                self::OPTION_NAME,
                'invalid_nonce',
                __('Security check failed. Please try again.', 'pixelbeacon'),
                'error'
            );
            return;
        }

        $test_pixel_id = !empty($_POST['test_tiktok_pixel_id']) ? sanitize_text_field(wp_unslash($_POST['test_tiktok_pixel_id'])) : '';
        $test_access_token = !empty($_POST['test_tiktok_access_token']) ? sanitize_text_field(wp_unslash($_POST['test_tiktok_access_token'])) : '';

        $api_client = new PixelBeacon_API_Client();
        $result = $api_client->test_tiktok_connection($test_pixel_id, $test_access_token);

        if (is_wp_error($result)) {
            add_settings_error(
                self::OPTION_NAME,
                'tiktok_connection_failed',
                sprintf(__('TikTok connection test failed: %s', 'pixelbeacon'), $result->get_error_message()),
                'error'
            );
        } else {
            add_settings_error(
                self::OPTION_NAME,
                'tiktok_connection_success',
                __('TikTok connection test successful! Your TikTok Events API is working correctly.', 'pixelbeacon'),
                'success'
            );
        }
    }

    /**
     * Handle clear logs action
     */
    private function handle_clear_logs() {
        $clear_nonce = isset($_POST['pixelbeacon_clear_nonce']) ? sanitize_text_field(wp_unslash($_POST['pixelbeacon_clear_nonce'])) : '';
        if (empty($clear_nonce) || !wp_verify_nonce($clear_nonce, 'pixelbeacon_clear_logs')) {
            add_settings_error(
                self::OPTION_NAME,
                'invalid_nonce',
                __('Security check failed. Please try again.', 'pixelbeacon'),
                'error'
            );
            return;
        }

        $api_client = new PixelBeacon_API_Client();
        $api_client->clear_logs();

        add_settings_error(
            self::OPTION_NAME,
            'logs_cleared',
            __('All CAPI event and failure logs have been cleared.', 'pixelbeacon'),
            'success'
        );
    }
}

// Backwards compatibility alias
if (!class_exists('FBPixel_CAPI_Admin')) {
    class_alias('PixelBeacon_Admin', 'FBPixel_CAPI_Admin');
}
