<?php
/**
 * W3 Pixel CAPI REST API
 * 
 * Provides high-performance, non-blocking asynchronous event intake
 * and dynamic options for cached pages (WP Rocket, LiteSpeed, Cloudflare).
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FBPixel_CAPI_REST_API {

    /**
     * REST namespace
     */
    const REST_NAMESPACE = 'w3-pixel-capi/v1';

    /**
     * @var FBPixel_CAPI_API_Client
     */
    private $api_client;

    /**
     * Constructor
     *
     * @param FBPixel_CAPI_API_Client $api_client
     */
    public function __construct($api_client) {
        $this->api_client = $api_client;
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Asynchronous non-blocking event endpoint
        register_rest_route(
            self::REST_NAMESPACE,
            '/event',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle_event'),
                'permission_callback' => '__return_true', // Public tracking endpoint
                'args'                => array(
                    'event_name' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'event_id' => array(
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'event_source_url' => array(
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'esc_url_raw',
                    ),
                    'referrer_url' => array(
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'esc_url_raw',
                    ),
                    'custom_data' => array(
                        'required' => false,
                    ),
                )
            )
        );

        // Dynamic options endpoint for cached pages
        register_rest_route(
            self::REST_NAMESPACE,
            '/dynamic-options',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'handle_dynamic_options'),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle incoming client-side event asynchronously
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_event($request) {
        // Drop crawler and bot requests immediately
        if (FBPixel_CAPI_Helpers::is_bot_request()) {
            return new WP_REST_Response(array('success' => true, 'skipped' => 'bot_request'), 200);
        }

        $event_name = $request->get_param('event_name');
        if (empty($event_name)) {
            return new WP_Error('missing_event_name', __('Event name is required', 'w3-pixel-capi'), array('status' => 400));
        }

        // Verify event is supported and enabled in plugin settings
        $allowed_events = array('PageView', 'AddToCart', 'InitiateCheckout', 'Purchase', 'ViewContent', 'Search', 'Lead', 'CompleteRegistration');
        if (!in_array($event_name, $allowed_events, true)) {
            return new WP_Error('invalid_event_name', __('Event is not supported', 'w3-pixel-capi'), array('status' => 400));
        }

        $settings = get_option('fbpixel_capi_settings', array());
        if (empty($settings['enabled_events'][$event_name])) {
            return new WP_REST_Response(array('success' => true, 'skipped' => 'event_disabled'), 200);
        }

        // Capture or generate event ID
        $event_id = $request->get_param('event_id');
        if (empty($event_id)) {
            $event_id = FBPixel_CAPI_Helpers::guidv4();
        }

        // Capture custom data
        $raw_custom_data = $request->get_param('custom_data');
        $custom_data = array();
        if (is_array($raw_custom_data)) {
            $custom_data = $raw_custom_data;
        } elseif (is_string($raw_custom_data)) {
            $decoded = json_decode($raw_custom_data, true);
            if (is_array($decoded)) {
                $custom_data = $decoded;
            }
        }

        // Capture event source URL
        $event_source_url = $request->get_param('event_source_url');
        if (empty($event_source_url)) {
            $referer = $request->get_header('referer');
            $event_source_url = !empty($referer) ? esc_url_raw($referer) : FBPixel_CAPI_Helpers::get_current_url();
        }

        // Build enriched user data
        $user_data = array(
            'client_ip_address' => FBPixel_CAPI_Helpers::get_client_ip(),
            'client_user_agent' => FBPixel_CAPI_Helpers::get_user_agent(),
        );

        // First-party Facebook cookies
        $fbp = FBPixel_CAPI_Helpers::ensure_fbp();
        if (!empty($fbp)) {
            $user_data['fbp'] = $fbp;
        }
        $fbc = FBPixel_CAPI_Helpers::ensure_fbc();
        if (!empty($fbc)) {
            $user_data['fbc'] = $fbc;
        }

        // Check logged-in user
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            if (!empty($current_user->user_email)) {
                $user_data['em'] = FBPixel_CAPI_Helpers::normalize_and_hash($current_user->user_email);
            }
            if (!empty($current_user->first_name)) {
                $user_data['fn'] = FBPixel_CAPI_Helpers::normalize_and_hash($current_user->first_name);
            }
            if (!empty($current_user->last_name)) {
                $user_data['ln'] = FBPixel_CAPI_Helpers::normalize_and_hash($current_user->last_name);
            }
            $user_data['external_id'] = FBPixel_CAPI_Helpers::normalize_and_hash((string) $current_user->ID);
        } else {
            // Check persistent form data
            $persisted = FBPixel_CAPI_Helpers::get_persistence_user_data();
            if (!empty($persisted['em'])) {
                $user_data['em'] = FBPixel_CAPI_Helpers::normalize_and_hash($persisted['em']);
            }
            if (!empty($persisted['fn'])) {
                $user_data['fn'] = FBPixel_CAPI_Helpers::normalize_and_hash($persisted['fn']);
            }
            if (!empty($persisted['ln'])) {
                $user_data['ln'] = FBPixel_CAPI_Helpers::normalize_and_hash($persisted['ln']);
            }
            if (!empty($persisted['ph'])) {
                $user_data['ph'] = FBPixel_CAPI_Helpers::normalize_and_hash_phone($persisted['ph']);
            }
            // Persistent guest ID
            $user_data['external_id'] = FBPixel_CAPI_Helpers::get_pbid();
        }

        // Check WooCommerce customer if available
        if (class_exists('WooCommerce') && function_exists('WC') && WC()->customer) {
            $wc_data = FBPixel_CAPI_Helpers::get_wc_customer_data(WC()->customer);
            $user_data = array_merge($user_data, $wc_data);
        }

        // Clean null/empty keys
        $user_data = array_filter($user_data, function($val) {
            return $val !== null && $val !== '';
        });

        $event_data = array(
            'event_name'       => $event_name,
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => $event_source_url,
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
            'event_id'         => $event_id,
        );

        $referrer_url = $request->get_param('referrer_url');
        if (!empty($referrer_url)) {
            $event_data['referrer_url'] = esc_url_raw($referrer_url);
        }

        // Dispatch to Facebook Graph API
        $response = $this->api_client->send_event($event_data);

        return new WP_REST_Response(array(
            'success'  => !is_wp_error($response),
            'event_id' => $event_id
        ), 200);
    }

    /**
     * Handle dynamic options for cached pages
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_dynamic_options($request) {
        nocache_headers();

        // Prevent cron loops when using ALTERNATE_WP_CRON
        if (defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON) {
            remove_action('init', 'wp_cron');
        }

        // Restore logged in user manually if page is served from static cache without nonce
        if (!is_user_logged_in() && function_exists('wp_validate_auth_cookie')) {
            $cookie_user_id = wp_validate_auth_cookie('', 'logged_in');
            if ($cookie_user_id) {
                wp_set_current_user($cookie_user_id);
            }
        }

        $advanced_matching = array();
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (!empty($user->user_email)) {
                $advanced_matching['em'] = FBPixel_CAPI_Helpers::normalize_and_hash($user->user_email);
            }
            if (!empty($user->first_name)) {
                $advanced_matching['fn'] = FBPixel_CAPI_Helpers::normalize_name($user->first_name);
            }
            if (!empty($user->last_name)) {
                $advanced_matching['ln'] = FBPixel_CAPI_Helpers::normalize_name($user->last_name);
            }
            $advanced_matching['external_id'] = FBPixel_CAPI_Helpers::normalize_and_hash((string) $user->ID);
        } else {
            $persisted = FBPixel_CAPI_Helpers::get_persistence_user_data();
            if (!empty($persisted['em'])) {
                $advanced_matching['em'] = FBPixel_CAPI_Helpers::normalize_and_hash($persisted['em']);
            }
            if (!empty($persisted['fn'])) {
                $advanced_matching['fn'] = FBPixel_CAPI_Helpers::normalize_name($persisted['fn']);
            }
            if (!empty($persisted['ln'])) {
                $advanced_matching['ln'] = FBPixel_CAPI_Helpers::normalize_name($persisted['ln']);
            }
            if (!empty($persisted['ph'])) {
                $advanced_matching['ph'] = FBPixel_CAPI_Helpers::normalize_and_hash_phone($persisted['ph']);
            }
            $advanced_matching['external_id'] = FBPixel_CAPI_Helpers::get_pbid();
        }

        $response_data = array(
            'advanced_matching' => $advanced_matching,
            'fbp'               => FBPixel_CAPI_Helpers::ensure_fbp(),
            'fbc'               => FBPixel_CAPI_Helpers::ensure_fbc(),
            'pbid'              => FBPixel_CAPI_Helpers::get_pbid(),
        );

        return new WP_REST_Response($response_data, 200);
    }
}
