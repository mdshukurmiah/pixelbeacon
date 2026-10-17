<?php
/**
 * Plugin Name: PixelBeacon for WooCommerce
 * Plugin URI: https://github.com/mdshukurmiah/pixelbeacon
 * Description: Professional Meta Pixel & Conversions API (CAPI) tracking for WooCommerce with real-time deduplication, Event Match Quality (EMQ), and server-side signals.
 * Version: 1.0.5
 * Author: Md Shukur Miah
 * Author URI: https://www.shukurs.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pixelbeacon
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.9.4
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PIXELBEACON_VERSION', '1.0.5');
define('PIXELBEACON_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIXELBEACON_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('PIXELBEACON_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Legacy compatibility constants
if (!defined('FBPIXEL_CAPI_VERSION')) {
    define('FBPIXEL_CAPI_VERSION', PIXELBEACON_VERSION);
    define('FBPIXEL_CAPI_PLUGIN_URL', PIXELBEACON_PLUGIN_URL);
    define('FBPIXEL_CAPI_PLUGIN_PATH', PIXELBEACON_PLUGIN_PATH);
    define('FBPIXEL_CAPI_PLUGIN_BASENAME', PIXELBEACON_PLUGIN_BASENAME);
}

/**
 * Main plugin class
 */
class PixelBeacon {
    
    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * @var PixelBeacon_API_Client
     */
    private $api_client;

    /**
     * @var PixelBeacon_Event_Tracker
     */
    private $event_tracker;

    /**
     * @var PixelBeacon_REST_API
     */
    private $rest_api;

    /**
     * @var PixelBeacon_Admin
     */
    private $admin;
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Load plugin text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Include required files
        $this->include_files();
        
        // Initialize components
        add_action('init', array($this, 'init_components'));

        // Ensure Facebook cookies are set early
        add_action('init', array($this, 'ensure_facebook_cookies'), 1);

        // Queue processing cron & daily log cleanup cron
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('pixelbeacon_process_queue', array($this, 'process_queue'));
        add_action('fbpixel_capi_process_queue', array($this, 'process_queue'));
        if (!wp_next_scheduled('pixelbeacon_process_queue')) {
            wp_schedule_event(time() + 300, 'pixelbeacon_five_minutes', 'pixelbeacon_process_queue');
        }

        add_action('pixelbeacon_cleanup_logs', array($this, 'cleanup_logs'));
        add_action('fbpixel_capi_cleanup_logs', array($this, 'cleanup_logs'));
        if (!wp_next_scheduled('pixelbeacon_cleanup_logs')) {
            wp_schedule_event(time() + 86400, 'daily', 'pixelbeacon_cleanup_logs');
        }
        
        // Initialize WooCommerce hooks after plugins are loaded
        add_action('plugins_loaded', array($this, 'init_woocommerce_hooks'));
        
        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'admin_init'));
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        }
        
        // Frontend scripts for non-blocking asynchronous tracking & form persistence
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));

        // AJAX handlers for CAPI event intake (primary + legacy fallback)
        add_action('wp_ajax_pixelbeacon_event', array($this, 'ajax_event_handler'));
        add_action('wp_ajax_nopriv_pixelbeacon_event', array($this, 'ajax_event_handler'));
        add_action('wp_ajax_w3_pixel_capi_event', array($this, 'ajax_event_handler'));
        add_action('wp_ajax_nopriv_w3_pixel_capi_event', array($this, 'ajax_event_handler'));

        // Frontend hooks for event tracking
        add_action('wp_head', array($this, 'track_page_view'), 2);
        add_action('wp_footer', array($this, 'track_deferred_events'), 25);

        // Browser-side (Pixel) tracking
        add_action('wp_head', array($this, 'output_pixel_base_code'), 1);
        add_action('wp_footer', array($this, 'output_browser_events'), 20);
    }
    
    /**
     * Include required files
     */
    private function include_files() {
        require_once PIXELBEACON_PLUGIN_PATH . 'includes/class-pixelbeacon-api-client.php';
        require_once PIXELBEACON_PLUGIN_PATH . 'includes/class-pixelbeacon-event-tracker.php';
        require_once PIXELBEACON_PLUGIN_PATH . 'includes/class-pixelbeacon-helpers.php';
        require_once PIXELBEACON_PLUGIN_PATH . 'includes/class-pixelbeacon-rest-api.php';
        require_once PIXELBEACON_PLUGIN_PATH . 'admin/class-pixelbeacon-admin.php';
    }
    
    /**
     * Initialize components
     */
    public function init_components() {
        // Initialize API client
        $this->api_client = new PixelBeacon_API_Client();
        
        // Initialize event tracker
        $this->event_tracker = new PixelBeacon_Event_Tracker($this->api_client);

        // Initialize REST API for non-blocking CAPI intake and cached pages
        $this->rest_api = new PixelBeacon_REST_API($this->api_client);
        
        // Initialize admin (if in admin area)
        if (is_admin()) {
            $this->admin = new PixelBeacon_Admin();
        }

        // Global hooks for non-WooCommerce events
        add_action('user_register', array($this, 'track_complete_registration'));
        add_action('wpcf7_mail_sent', array($this, 'track_lead_cf7'));
        add_action('wpforms_process_complete', array($this, 'track_lead_wpforms'), 10, 4);

        // Auto-migrate database tables and indexes if version updated
        $this->maybe_update_db();
    }
    
    /**
     * Initialize WooCommerce hooks
     */
    public function init_woocommerce_hooks() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Lock Facebook cookies (_fbp, _fbc) into order meta during checkout
        // Critical for payment gateway webhooks (PayPal, Stripe) to retain attribution
        add_action('woocommerce_checkout_update_order_meta', 'PixelBeacon_Helpers::save_fb_cookies_to_order', 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_meta', 'PixelBeacon_Helpers::save_fb_cookies_to_order', 10, 1);

        // Standard WooCommerce events
        add_action('template_redirect', array($this, 'maybe_track_initiate_checkout'));
        add_action('woocommerce_add_to_cart', array($this, 'track_add_to_cart'), 10, 6);
        add_action('woocommerce_cart_item_removed', array($this, 'track_remove_from_cart'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'track_add_payment_info'), 10, 3);
        add_action('woocommerce_thankyou', array($this, 'track_purchase'), 10, 1);
        add_action('woocommerce_before_checkout_form', array($this, 'track_initiate_checkout'));
        add_action('wp_head', array($this, 'track_view_content'), 5);
        add_action('wp_head', array($this, 'track_view_category'), 6);
        add_action('wp_head', array($this, 'track_search'), 7);

        // Wishlist integrations (YITH)
        add_action('yith_wcwl_added_to_wishlist', array($this, 'track_add_to_wishlist'), 10, 1);

        // Track completed/processing purchases triggered by external gateways/webhooks
        add_action('woocommerce_order_status_completed', array($this, 'track_purchase_status_change'), 10, 2);
        add_action('woocommerce_order_status_processing', array($this, 'track_purchase_status_change'), 10, 2);
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('pixelbeacon', false, dirname(PIXELBEACON_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Enqueue frontend tracking scripts
     */
    public function enqueue_frontend_scripts() {
        if (is_admin()) {
            return;
        }

        $settings = PixelBeacon_Helpers::get_settings();
        if (empty($settings['pixel_id'])) {
            return;
        }

        $version = $this->get_asset_version(PIXELBEACON_PLUGIN_PATH . 'assets/js/public.js');

        wp_enqueue_script(
            'pixelbeacon-public',
            PIXELBEACON_PLUGIN_URL . 'assets/js/public.js',
            array('jquery'),
            $version,
            true
        );

        $client_options = array(
            'pixelId'             => esc_js($settings['pixel_id']),
            'testEventCode'       => !empty($settings['test_event_code']) ? esc_js($settings['test_event_code']) : '',
            'restUrl'             => esc_url_raw(rest_url('pixelbeacon/v1/event')),
            'ajaxUrl'             => esc_url_raw(admin_url('admin-ajax.php')),
            'dynamicOptionsUrl'   => esc_url_raw(rest_url('pixelbeacon/v1/dynamic-options')),
            'fetchDynamicOptions' => true,
            'cookieDomain'        => PixelBeacon_Helpers::get_cookie_domain(),
            'currency'            => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
            'debug'               => !empty($settings['debug_mode']),
        );

        // Localize modern object pixelBeaconOptions, and provide fallback alias w3PixelOptions
        wp_localize_script('pixelbeacon-public', 'pixelBeaconOptions', $client_options);
        wp_localize_script('pixelbeacon-public', 'w3PixelOptions', $client_options);
    }

    /**
     * Get asset version for cache busting
     *
     * In development (WP_DEBUG, SCRIPT_DEBUG, or Debug Mode enabled),
     * returns current timestamp time() for instant cache-busting on every refresh.
     * In live / production, returns PIXELBEACON_VERSION.
     *
     * @param string $file_path Optional file path
     * @return string
     */
    public function get_asset_version($file_path = '') {
        $settings = PixelBeacon_Helpers::get_settings();
        $is_dev = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)
            || (defined('WP_DEBUG') && WP_DEBUG)
            || !empty($settings['debug_mode']);

        $version = $is_dev ? (string) time() : PIXELBEACON_VERSION;

        return apply_filters('pixelbeacon_asset_version', $version, $file_path, $is_dev);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        $default_options = array(
            'pixel_id'        => '',
            'access_token'    => '',
            'test_event_code' => '',
            'enabled_events'  => array(
                'PageView'             => true,
                'AddToCart'            => true,
                'InitiateCheckout'     => true,
                'Purchase'             => true,
                'ViewContent'          => true,
                'ViewCategory'         => true,
                'RemoveFromCart'       => true,
                'AddPaymentInfo'       => true,
                'AddToWishlist'        => true,
                'Search'               => true,
                'Lead'                 => true,
                'Contact'              => true,
                'CompleteRegistration' => true,
                'CustomizeProduct'     => true,
                'FirstOrder'           => true,
                'ReturningCustomer'    => true,
                'VipClient'            => true
            ),
            'debug_mode'      => false,
            'enable_ldu'      => false
        );
        
        $existing = get_option('pixelbeacon_settings');
        if (empty($existing)) {
            $legacy = get_option('fbpixel_capi_settings');
            if (!empty($legacy) && is_array($legacy)) {
                update_option('pixelbeacon_settings', $legacy);
            } else {
                add_option('pixelbeacon_settings', $default_options);
            }
        }
        
        $this->create_log_table();
        $this->create_queue_table();
        update_option('pixelbeacon_db_version', PIXELBEACON_VERSION);

        if (!wp_next_scheduled('pixelbeacon_process_queue')) {
            wp_schedule_event(time() + 300, 'pixelbeacon_five_minutes', 'pixelbeacon_process_queue');
        }

        if (!wp_next_scheduled('pixelbeacon_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'pixelbeacon_cleanup_logs');
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('pixelbeacon_cleanup_logs');
        wp_clear_scheduled_hook('pixelbeacon_process_queue');
        wp_clear_scheduled_hook('fbpixel_capi_cleanup_logs');
        wp_clear_scheduled_hook('fbpixel_capi_process_queue');
    }
    
    /**
     * Create log table for debugging
     */
    private function create_log_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pixelbeacon_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_name varchar(100) NOT NULL,
            event_data longtext NOT NULL,
            response_data longtext,
            status varchar(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_idx (status),
            KEY created_at_idx (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create queue table for retrying failed events
     */
    private function create_queue_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'pixelbeacon_queue';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_data longtext NOT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            last_error text,
            next_attempt_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY next_attempt_idx (next_attempt_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Run table upgrades if version changed
     */
    public function maybe_update_db() {
        $installed_ver = get_option('pixelbeacon_db_version', get_option('fbpixel_capi_db_version', '0'));
        if (version_compare($installed_ver, PIXELBEACON_VERSION, '<')) {
            $this->create_log_table();
            $this->create_queue_table();
            update_option('pixelbeacon_db_version', PIXELBEACON_VERSION);
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('PixelBeacon Settings', 'pixelbeacon'),
            __('PixelBeacon', 'pixelbeacon'),
            'manage_options',
            'pixelbeacon-settings',
            array($this, 'admin_page_callback')
        );
    }
    
    /**
     * Admin page callback
     */
    public function admin_page_callback() {
        if (isset($this->admin)) {
            $this->admin->render_settings_page();
        }
    }
    
    /**
     * Admin init
     */
    public function admin_init() {
        if (isset($this->admin)) {
            $this->admin->init_settings();
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if ('settings_page_pixelbeacon-settings' !== $hook && 'settings_page_fbpixel-capi-settings' !== $hook) {
            return;
        }
        
        $css_ver = $this->get_asset_version(PIXELBEACON_PLUGIN_PATH . 'assets/css/admin.css');
        $js_ver  = $this->get_asset_version(PIXELBEACON_PLUGIN_PATH . 'assets/js/admin.js');

        wp_enqueue_style(
            'pixelbeacon-admin',
            PIXELBEACON_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $css_ver
        );
        
        wp_enqueue_script(
            'pixelbeacon-admin',
            PIXELBEACON_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $js_ver,
            true
        );
    }
    
    /**
     * Track page view event
     */
    public function track_page_view() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_page_view();
        }
    }

    /**
     * Ensure Facebook cookies exist
     */
    public function ensure_facebook_cookies() {
        PixelBeacon_Helpers::ensure_fbp();
        PixelBeacon_Helpers::ensure_fbc();
        PixelBeacon_Helpers::ensure_pbid();
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules
     * @return array
     */
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['pixelbeacon_five_minutes'])) {
            $schedules['pixelbeacon_five_minutes'] = array(
                'interval' => 300,
                'display'  => __('Every 5 Minutes', 'pixelbeacon')
            );
        }
        if (!isset($schedules['fbpixel_capi_five_minutes'])) {
            $schedules['fbpixel_capi_five_minutes'] = $schedules['pixelbeacon_five_minutes'];
        }
        return $schedules;
    }

    /**
     * Process queued events
     */
    public function process_queue() {
        if (isset($this->api_client)) {
            $this->api_client->process_queue();
        }
    }

    /**
     * Clean up logs older than 7 days
     */
    public function cleanup_logs() {
        if (isset($this->api_client)) {
            $this->api_client->cleanup_old_logs(7);
        }
    }

    /**
     * AJAX fallback handler for client-side events if REST API is firewalled
     */
    public function ajax_event_handler() {
        if (PixelBeacon_Helpers::is_bot_request()) {
            wp_send_json_success(array('skipped' => 'bot'));
        }

        $event_name = isset($_POST['event_name']) ? sanitize_text_field(wp_unslash($_POST['event_name'])) : '';
        if (empty($event_name)) {
            wp_send_json_error(array('message' => 'Missing event name'), 400);
        }

        $settings = PixelBeacon_Helpers::get_settings();
        $allowed_events = array(
            'PageView', 'AddToCart', 'InitiateCheckout', 'Purchase', 'ViewContent',
            'ViewCategory', 'RemoveFromCart', 'AddPaymentInfo', 'AddToWishlist',
            'Search', 'Lead', 'Contact', 'CompleteRegistration', 'CustomizeProduct',
            'FirstOrder', 'ReturningCustomer', 'VipClient'
        );
        if (!in_array($event_name, $allowed_events, true)) {
            wp_send_json_error(array('message' => 'Invalid event'), 400);
        }

        if (isset($settings['enabled_events']) && array_key_exists($event_name, $settings['enabled_events']) && empty($settings['enabled_events'][$event_name])) {
            wp_send_json_success(array('skipped' => 'event_disabled'));
        }

        $event_id = isset($_POST['event_id']) ? sanitize_text_field(wp_unslash($_POST['event_id'])) : PixelBeacon_Helpers::guidv4();
        
        $raw_custom = isset($_POST['custom_data']) ? wp_unslash($_POST['custom_data']) : array();
        $custom_data = is_array($raw_custom) ? $raw_custom : json_decode($raw_custom, true);
        if (!is_array($custom_data)) {
            $custom_data = array();
        }

        $event_source_url = isset($_POST['event_source_url']) ? esc_url_raw(wp_unslash($_POST['event_source_url'])) : PixelBeacon_Helpers::get_current_url();
        $referrer_url = isset($_POST['referrer_url']) ? esc_url_raw(wp_unslash($_POST['referrer_url'])) : '';

        // Build enriched user data using event tracker
        $user_data = isset($this->event_tracker) ? $this->event_tracker->get_user_data() : array(
            'client_ip_address' => PixelBeacon_Helpers::get_client_ip(),
            'client_user_agent' => PixelBeacon_Helpers::get_user_agent(),
        );

        $event_data = array(
            'event_name'       => $event_name,
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => $event_source_url,
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
            'event_id'         => $event_id,
        );

        if (!empty($referrer_url)) {
            $event_data['referrer_url'] = $referrer_url;
        }

        if (isset($this->api_client)) {
            $this->api_client->send_event($event_data);
        }

        wp_send_json_success(array('event_id' => $event_id));
    }
    
    /**
     * Track deferred events
     */
    public function track_deferred_events() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->process_deferred_events();
        }
    }
    
    /**
     * Track add to cart event (WooCommerce)
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_add_to_cart($product_id, $quantity, $variation_id);
        }
    }
    
    /**
     * Track purchase event on order received page
     */
    public function track_purchase($order_id) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_purchase($order_id);
        }
    }

    /**
     * Track purchase event on order status change (for gateway webhooks)
     */
    public function track_purchase_status_change($order_id, $order = null) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_purchase_on_status_change($order_id, $order);
        }
    }
    
    /**
     * Track initiate checkout event conditionally on template_redirect
     * Supports both WooCommerce Block Checkout (wp:woocommerce/checkout) and Classic Checkout
     */
    public function maybe_track_initiate_checkout() {
        if (is_admin() || !class_exists('WooCommerce')) {
            return;
        }

        $is_checkout = false;
        if (function_exists('is_checkout') && is_checkout() && !is_order_received_page() && !is_wc_endpoint_url('order-pay')) {
            $is_checkout = true;
        } elseif (function_exists('has_block') && (has_block('woocommerce/checkout') || has_block('woocommerce/checkout-express-payment-block'))) {
            $is_checkout = true;
        }

        if ($is_checkout && isset($this->event_tracker)) {
            $this->event_tracker->track_initiate_checkout();
        }
    }

    /**
     * Track initiate checkout event (WooCommerce)
     */
    public function track_initiate_checkout() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_initiate_checkout();
        }
    }
    
    /**
     * Track view content event (WooCommerce product pages)
     */
    public function track_view_content() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_view_content();
        }
    }

    /**
     * Track view category event
     */
    public function track_view_category() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_view_category();
        }
    }

    /**
     * Track search event
     */
    public function track_search() {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_search();
        }
    }

    /**
     * Track remove from cart event
     */
    public function track_remove_from_cart($cart_item_key, $cart = null) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_remove_from_cart($cart_item_key, $cart);
        }
    }

    /**
     * Track add payment info event
     */
    public function track_add_payment_info($order_id, $posted_data = array(), $order = null) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_add_payment_info($order_id, $posted_data, $order);
        }
    }

    /**
     * Track add to wishlist event
     */
    public function track_add_to_wishlist($product_id) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_add_to_wishlist($product_id);
        }
    }

    /**
     * Track complete registration event
     */
    public function track_complete_registration($user_id) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_complete_registration($user_id);
        }
    }

    /**
     * Track Contact Form 7 lead
     */
    public function track_lead_cf7($contact_form) {
        if (isset($this->event_tracker) && is_object($contact_form) && method_exists($contact_form, 'title')) {
            $this->event_tracker->track_lead(array(
                'content_name' => $contact_form->title(),
                'category'     => 'Contact Form 7'
            ));
        }
    }

    /**
     * Track WPForms lead
     */
    public function track_lead_wpforms($fields, $entry, $form_data, $entry_id) {
        if (isset($this->event_tracker)) {
            $title = !empty($form_data['settings']['form_title']) ? $form_data['settings']['form_title'] : 'WPForms';
            $this->event_tracker->track_lead(array(
                'content_name' => $title,
                'category'     => 'WPForms'
            ));
        }
    }

    /**
     * Track contact event
     */
    public function track_contact($data = array()) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_contact($data);
        }
    }

    /**
     * Track customize product event
     */
    public function track_customize_product($product_id, $variation_id = 0) {
        if (isset($this->event_tracker)) {
            $this->event_tracker->track_customize_product($product_id, $variation_id);
        }
    }

    /**
     * Output Facebook Pixel base code with Advanced Matching for browser tracking
     */
    public function output_pixel_base_code() {
        if (is_admin() || PixelBeacon_Helpers::is_bot_request()) {
            return;
        }

        $settings = PixelBeacon_Helpers::get_settings();
        if (empty($settings['pixel_id'])) {
            return;
        }

        $pixel_id = esc_js($settings['pixel_id']);
        $pageview_enabled = !empty($settings['enabled_events']['PageView']);

        // Gather advanced matching data for browser init
        $advanced_matching = array();
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (!empty($user->user_email)) {
                $advanced_matching['em'] = PixelBeacon_Helpers::normalize_and_hash($user->user_email);
            }
            if (!empty($user->first_name)) {
                $advanced_matching['fn'] = PixelBeacon_Helpers::normalize_and_hash($user->first_name);
            }
            if (!empty($user->last_name)) {
                $advanced_matching['ln'] = PixelBeacon_Helpers::normalize_and_hash($user->last_name);
            }
            $advanced_matching['external_id'] = PixelBeacon_Helpers::normalize_and_hash((string) $user->ID);
        } else {
            $persisted = PixelBeacon_Helpers::get_persistence_user_data();
            if (!empty($persisted['em'])) {
                $advanced_matching['em'] = PixelBeacon_Helpers::normalize_and_hash($persisted['em']);
            }
            if (!empty($persisted['fn'])) {
                $advanced_matching['fn'] = PixelBeacon_Helpers::normalize_and_hash($persisted['fn']);
            }
            if (!empty($persisted['ln'])) {
                $advanced_matching['ln'] = PixelBeacon_Helpers::normalize_and_hash($persisted['ln']);
            }
            if (!empty($persisted['ph'])) {
                $advanced_matching['ph'] = PixelBeacon_Helpers::normalize_and_hash_phone($persisted['ph']);
            }
            $advanced_matching['external_id'] = PixelBeacon_Helpers::get_pbid();
        }
        $advanced_matching = array_filter($advanced_matching);
        ?>
        <!-- Facebook Pixel Base Code (PixelBeacon for WooCommerce) -->
        <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        <?php if (!empty($advanced_matching)): ?>
        fbq('init', '<?php echo esc_js($pixel_id); ?>', <?php echo wp_json_encode($advanced_matching); ?>);
        <?php else: ?>
        fbq('init', '<?php echo esc_js($pixel_id); ?>');
        <?php endif; ?>
        </script>
        <?php if ($pageview_enabled): ?>
        <noscript>
            <img height="1" width="1" style="display:none"
                 src="https://www.facebook.com/tr?id=<?php echo esc_attr($settings['pixel_id']); ?>&ev=PageView&noscript=1" />
        </noscript>
        <?php endif; ?>
        <!-- End Facebook Pixel Base Code -->
        <?php
    }

    /**
     * Output browser-side events with matching parameters and event IDs
     */
    public function output_browser_events() {
        if (is_admin() || PixelBeacon_Helpers::is_bot_request() || !isset($this->event_tracker)) {
            return;
        }

        $settings = PixelBeacon_Helpers::get_settings();
        if (empty($settings['pixel_id'])) {
            return;
        }

        $events = $this->event_tracker->get_browser_events();
        if (empty($events)) {
            return;
        }

        $test_event_code = !empty($settings['test_event_code']) ? esc_js($settings['test_event_code']) : '';
        ?>
        <!-- Facebook Pixel Events (PixelBeacon for WooCommerce) -->
        <script>
        (function () {
            var rawEvents = <?php echo wp_json_encode($events); ?> || [];
            if (!Array.isArray(rawEvents) || !rawEvents.length) return;

            var testCode = '<?php echo esc_js($test_event_code); ?>';

            for (var i = 0; i < rawEvents.length; i++) {
                var evt = rawEvents[i] || {};
                if (!evt.event_name) continue;

                var params = (evt.custom_data && typeof evt.custom_data === 'object' && !Array.isArray(evt.custom_data)) ? evt.custom_data : {};
                var trackOpts = {};
                if (evt.event_id) {
                    trackOpts.eventID = evt.event_id;
                }
                if (testCode) {
                    trackOpts.test_event_code = testCode;
                }

                if (typeof window.fbq === 'function') {
                    if (Object.keys(trackOpts).length > 0) {
                        window.fbq('track', evt.event_name, params, trackOpts);
                    } else {
                        window.fbq('track', evt.event_name, params);
                    }
                    evt.browser_tracked = true;
                }
            }

            window.pixelBeaconEvents = (window.pixelBeaconEvents || []).concat(rawEvents);
            window.w3PixelEvents = (window.w3PixelEvents || []).concat(rawEvents);
        })();
        </script>
        <!-- End Facebook Pixel Events -->
        <?php
    }
}

// Function and class prefix: pixelBeacon
function pixelBeacon() {
    return PixelBeacon::get_instance();
}

// Backwards compatibility alias for class
if (!class_exists('FacebookPixelCAPI')) {
    class_alias('PixelBeacon', 'FacebookPixelCAPI');
}

// Initialize the plugin
pixelBeacon();
