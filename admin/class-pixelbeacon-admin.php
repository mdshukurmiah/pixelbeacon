<?php
/**
 * PixelBeacon for WooCommerce Admin
 * 
 * Handles admin interface and settings
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
        
        // General settings section
        add_settings_section(
            'pixelbeacon_general_section',
            __('General Settings', 'pixelbeacon'),
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
            __('Event Settings', 'pixelbeacon'),
            array($this, 'events_section_callback'),
            'pixelbeacon-settings'
        );
        
        // Enabled Events field
        add_settings_field(
            'enabled_events',
            __('Enabled Events', 'pixelbeacon'),
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
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Handle form submissions
        if (isset($_POST['test_connection'])) {
            $this->handle_test_connection();
        }
        if (isset($_POST['clear_logs'])) {
            $this->handle_clear_logs();
        }
        
        $settings = PixelBeacon_Helpers::get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php settings_errors(); ?>
            
            <div class="pixelbeacon-admin-container">
                <div class="pixelbeacon-main-content">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('pixelbeacon_settings_group');
                        do_settings_sections('pixelbeacon-settings');
                        submit_button();
                        ?>
                    </form>
                    
                    <hr>
                    
                    <h2><?php esc_html_e('Test Connection', 'pixelbeacon'); ?></h2>
                    <p><?php esc_html_e('Test your Conversions API connection to ensure everything is working correctly.', 'pixelbeacon'); ?></p>
                    
                    <form method="post" id="pixelbeacon_test_connection_form" style="display:inline-block; margin-right: 10px;">
                        <?php wp_nonce_field('pixelbeacon_test_connection', 'pixelbeacon_test_nonce'); ?>
                        <input type="hidden" name="test_pixel_id" id="test_pixel_id" value="">
                        <input type="hidden" name="test_access_token" id="test_access_token" value="">
                        <input type="submit" name="test_connection" class="button button-secondary" value="<?php esc_html_e('Test Connection', 'pixelbeacon'); ?>">
                    </form>

                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field('pixelbeacon_clear_logs', 'pixelbeacon_clear_nonce'); ?>
                        <input type="submit" name="clear_logs" class="button button-link-delete" value="<?php esc_html_e('Clear Logs', 'pixelbeacon'); ?>" onclick="return confirm('Are you sure you want to clear all logs?');">
                    </form>

                    <hr>

                    <h2><?php esc_html_e('Recent Event & Failure Logs', 'pixelbeacon'); ?></h2>
                    <p><?php esc_html_e('Displays recent API transmissions, failures, and retry statuses.', 'pixelbeacon'); ?></p>
                    
                    <?php
                    $api_client = new PixelBeacon_API_Client();
                    $logs = $api_client->get_recent_logs(15);
                    if (!empty($logs)):
                    ?>
                    <table class="widefat fixed striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th style="width: 140px;"><?php esc_html_e('Date / Time', 'pixelbeacon'); ?></th>
                                <th style="width: 120px;"><?php esc_html_e('Event / Type', 'pixelbeacon'); ?></th>
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
                
                <div class="pixelbeacon-sidebar">
                    <div class="pixelbeacon-info-box">
                        <h3><?php esc_html_e('Getting Started', 'pixelbeacon'); ?></h3>
                        <ol>
                            <li><?php esc_html_e('Create a Facebook Business Manager account', 'pixelbeacon'); ?></li>
                            <li><?php esc_html_e('Set up a Facebook Pixel in Events Manager', 'pixelbeacon'); ?></li>
                            <li><?php esc_html_e('Generate a Conversions API access token', 'pixelbeacon'); ?></li>
                            <li><?php esc_html_e('Enter your Pixel ID and Access Token above', 'pixelbeacon'); ?></li>
                            <li><?php esc_html_e('Configure which events to track', 'pixelbeacon'); ?></li>
                            <li><?php esc_html_e('Test your connection', 'pixelbeacon'); ?></li>
                        </ol>
                    </div>
                    
                    <div class="pixelbeacon-info-box">
                        <h3><?php esc_html_e('Documentation', 'pixelbeacon'); ?></h3>
                        <ul>
                            <li><a href="https://developers.facebook.com/docs/marketing-api/conversions-api/" target="_blank"><?php esc_html_e('Facebook Conversions API Documentation', 'pixelbeacon'); ?></a></li>
                            <li><a href="https://www.facebook.com/business/help/AboutConversionsAPI" target="_blank"><?php esc_html_e('About Conversions API', 'pixelbeacon'); ?></a></li>
                            <li><a href="https://developers.facebook.com/docs/marketing-api/conversions-api/get-started" target="_blank"><?php esc_html_e('Getting Started Guide', 'pixelbeacon'); ?></a></li>
                        </ul>
                    </div>
                    
                    <?php if (!empty($settings['debug_mode'])): ?>
                    <div class="pixelbeacon-info-box">
                        <h3><?php esc_html_e('Debug Information', 'pixelbeacon'); ?></h3>
                        <p><?php esc_html_e('Debug mode is enabled. Check your WordPress debug log for detailed information about API requests and responses.', 'pixelbeacon'); ?></p>
                        <p><strong><?php esc_html_e('WooCommerce Status:', 'pixelbeacon'); ?></strong> 
                           <?php echo class_exists('WooCommerce') ? esc_html__('Active', 'pixelbeacon') : esc_html__('Not Active', 'pixelbeacon'); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * General section callback
     */
    public function general_section_callback() {
        echo '<p>' . esc_html__('Configure your Facebook Pixel and Conversions API credentials.', 'pixelbeacon') . '</p>';
    }
    
    /**
     * Events section callback
     */
    public function events_section_callback() {
        echo '<p>' . esc_html__('Choose which events to track via server-side tracking.', 'pixelbeacon') . '</p>';
    }
    
    /**
     * Debug section callback
     */
    public function debug_section_callback() {
       echo '<p>' . esc_html__('Debug settings for troubleshooting and development.', 'pixelbeacon') . '</p>';
    }
    
    /**
     * Pixel ID field callback
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
     * Access Token field callback
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
     * Test Event Code field callback
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
     * Enabled Events field callback
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
    
    /**
     * Sanitize settings
     * 
     * @param array $input Input settings
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // Sanitize Pixel ID
        if (!empty($input['pixel_id'])) {
            $pixel_id = sanitize_text_field($input['pixel_id']);
            if (PixelBeacon_Helpers::validate_pixel_id($pixel_id)) {
                $sanitized['pixel_id'] = $pixel_id;
            } else {
                add_settings_error(
                    self::OPTION_NAME,
                    'invalid_pixel_id',
                    __('Invalid Pixel ID format. Please enter a valid 15-16 digit Pixel ID.', 'pixelbeacon')
                );
            }
        }
        
        // Sanitize Access Token
        if (!empty($input['access_token'])) {
            $access_token = sanitize_text_field($input['access_token']);
            if (PixelBeacon_Helpers::validate_access_token($access_token)) {
                $sanitized['access_token'] = $access_token;
            } else {
                add_settings_error(
                    self::OPTION_NAME,
                    'invalid_access_token',
                    __('Invalid Access Token format. Please enter a valid Conversions API access token.', 'pixelbeacon')
                );
            }
        }
        
        // Sanitize Test Event Code
        if (!empty($input['test_event_code'])) {
            $sanitized['test_event_code'] = sanitize_text_field($input['test_event_code']);
        }
        
        // Sanitize Enabled Events
        if (!empty($input['enabled_events']) && is_array($input['enabled_events'])) {
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
        }
        
        // Sanitize Debug Mode
        $sanitized['debug_mode'] = !empty($input['debug_mode']);

        // Sanitize Limited Data Use
        $sanitized['enable_ldu'] = !empty($input['enable_ldu']);
        
        return $sanitized;
    }
    
    /**
     * Handle test connection
     */
    private function handle_test_connection() {
        // Verify nonce
        if (!isset($_POST['pixelbeacon_test_nonce']) || !wp_verify_nonce($_POST['pixelbeacon_test_nonce'], 'pixelbeacon_test_connection')) {
            add_settings_error(
                self::OPTION_NAME,
                'invalid_nonce',
                __('Security check failed. Please try again.', 'pixelbeacon'),
                'error'
            );
            return;
        }
        
        // Test API connection using form inputs if available, otherwise saved settings
        $test_pixel_id = !empty($_POST['test_pixel_id']) ? sanitize_text_field($_POST['test_pixel_id']) : '';
        $test_access_token = !empty($_POST['test_access_token']) ? sanitize_text_field($_POST['test_access_token']) : '';

        $api_client = new PixelBeacon_API_Client();
        $result = $api_client->test_connection($test_pixel_id, $test_access_token);
		
        if (is_wp_error($result)) {
            add_settings_error(
                self::OPTION_NAME,
                'connection_failed',
				// translators: %s: error message from the connection test
                sprintf(__('Connection test failed: %s', 'pixelbeacon'), $result->get_error_message()),
                'error'
            );
        } else {
            add_settings_error(
                self::OPTION_NAME,
                'connection_success',
                __('Connection test successful! Your Conversions API is working correctly.', 'pixelbeacon'),
                'success'
            );
        }
    }

    /**
     * Handle clear logs action
     */
    private function handle_clear_logs() {
        if (!isset($_POST['pixelbeacon_clear_nonce']) || !wp_verify_nonce($_POST['pixelbeacon_clear_nonce'], 'pixelbeacon_clear_logs')) {
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
