<?php
/**
 * W3 Pixel CAPI Event Tracker
 * 
 * Handles event tracking, deduplication, and rich customer data collection.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FBPixel_CAPI_Event_Tracker {
    
    /**
     * API client instance
     */
    private $api_client;
    
    /**
     * Plugin settings
     */
    private $settings;
    
    /**
     * Deferred events queue
     */
    private $deferred_events = array();

    /**
     * Browser-side events queue (for Pixel)
     */
    private $browser_events = array();

    /**
     * Stashed AJAX event data for cart fragments
     */
    private $last_ajax_event = null;
    
    /**
     * Constructor
     * 
     * @param FBPixel_CAPI_API_Client $api_client API client instance
     */
    public function __construct($api_client) {
        $this->api_client = $api_client;
        $this->settings = get_option('fbpixel_capi_settings', array());
    }
    
    /**
     * Track PageView event
     * Queues browser event and dispatches to CAPI non-blockingly via public.js
     */
    public function track_page_view() {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('PageView')) {
            return;
        }
        
        $event_id = $this->generate_event_id('PageView');
        $event_data = array(
            'event_name'       => 'PageView',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(),
            'event_id'         => $event_id
        );
        
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $event_data['referrer_url'] = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
        }

        // Queue browser event with matching event_id and non-blocking CAPI relay
        $this->queue_browser_event($event_data, false, true);
    }
    
    /**
     * Track ViewContent event (WooCommerce product pages)
     */
    public function track_view_content() {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('ViewContent') || !class_exists('WooCommerce') || !is_product()) {
            return;
        }
        
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        $product_id = $product->get_id();
        $product_price = $product->get_price();
        
        if ($product->is_type('variable')) {
            $variation_prices = $product->get_variation_prices();
            if (!empty($variation_prices['price'])) {
                $product_price = min($variation_prices['price']);
            }
        }
        
        $event_id = $this->generate_event_id('ViewContent', (string) $product_id);
        $event_data = array(
            'event_name'       => 'ViewContent',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_ids'      => array((string) $product_id),
                'content_type'     => 'product',
                'content_name'     => $product->get_name(),
                'content_category' => $this->get_product_categories($product_id),
                'value'            => (float) $product_price,
                'currency'         => get_woocommerce_currency(),
                'contents'         => array(
                    array(
                        'id'         => (string) $product_id,
                        'quantity'   => 1,
                        'item_price' => (float) $product_price
                    )
                )
            ),
            'event_id'         => $event_id
        );
        
        if ($product->get_sku()) {
            $event_data['custom_data']['content_sku'] = $product->get_sku();
        }
        
        $brand = $this->get_product_brand($product);
        if ($brand) {
            $event_data['custom_data']['brand'] = $brand;
        }

        // Queue browser event with matching event_id and non-blocking CAPI relay
        $this->queue_browser_event($event_data, false, true);
    }
    
    /**
     * Track AddToCart event (WooCommerce standard form submission)
     * 
     * @param int $product_id Product ID
     * @param int $quantity Quantity
     * @param int $variation_id Variation ID (optional)
     */
    public function track_add_to_cart($product_id, $quantity = 1, $variation_id = 0) {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('AddToCart') || !class_exists('WooCommerce')) {
            return;
        }
        
        $target_id = $variation_id ? $variation_id : $product_id;
        $product = wc_get_product($target_id);
        if (!$product) {
            return;
        }
        
        $event_id = $this->generate_event_id('AddToCart', $target_id . '_' . $quantity);
        $event_data = array(
            'event_name'       => 'AddToCart',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_ids'      => array((string) $target_id),
                'content_type'     => 'product',
                'value'            => (float) $product->get_price() * (int) $quantity,
                'currency'         => get_woocommerce_currency(),
                'content_name'     => $product->get_name(),
                'content_category' => $this->get_product_categories($product_id),
                'num_items'        => (int) $quantity,
                'contents'         => array(
                    array(
                        'id'         => (string) $target_id,
                        'quantity'   => (int) $quantity,
                        'item_price' => (float) $product->get_price()
                    )
                )
            ),
            'event_id'         => $event_id
        );

        $is_ajax = (function_exists('wp_doing_ajax') && wp_doing_ajax()) || isset($_REQUEST['wc-ajax']);

        if ($is_ajax) {
            // For AJAX AddToCart: Send CAPI directly now and relay matching event_id to browser via cart fragments
            $this->send_event($event_data);
            $this->last_ajax_event = $event_data;
            add_filter('woocommerce_add_to_cart_fragments', array($this, 'add_cart_fragment_event'), 20);
        } else {
            // Store in session for browser pickup on redirect/reload
            $this->queue_browser_event($event_data, true, false);
            $this->send_event($event_data);
        }
    }

    /**
     * Pass synchronized Facebook Pixel event into WooCommerce AJAX fragments
     *
     * @param array $fragments
     * @return array
     */
    public function add_cart_fragment_event($fragments) {
        if (!empty($this->last_ajax_event)) {
            $fragments['w3_fbpixel_event'] = array(
                'event_name'  => $this->last_ajax_event['event_name'],
                'event_id'    => $this->last_ajax_event['event_id'],
                'custom_data' => $this->last_ajax_event['custom_data']
            );
        }
        return $fragments;
    }
    
    /**
     * Get or create synchronized purchase event ID stored in order meta
     *
     * @param WC_Order $order
     * @return string UUIDv4
     */
    public function get_or_create_purchase_event_id($order) {
        $meta_key = '_w3_purchase_event_id';
        $existing = $order->get_meta($meta_key, true);
        if (!empty($existing)) {
            return $existing;
        }

        $event_id = FBPixel_CAPI_Helpers::guidv4();
        $order->update_meta_data($meta_key, $event_id);
        $order->save();

        return $event_id;
    }

    /**
     * Track Purchase event on Order Received (Thank You) page
     * 
     * @param int $order_id Order ID
     */
    public function track_purchase($order_id) {
        if (!$this->is_event_enabled('Purchase') || !class_exists('WooCommerce')) {
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Deduplication Gate 1: Check if browser or server already fired purchase
        $already_fired = $order->get_meta('_w3_purchase_event_fired', true);
        
        // Synchronized event ID from order meta
        $event_id = $this->get_or_create_purchase_event_id($order);

        $event_data = $this->build_purchase_event_data($order, $event_id);

        // Only fire if not already tracked (prevents duplicates on thank you page reloads)
        if (!$already_fired) {
            $order->update_meta_data('_w3_purchase_event_fired', '1');
            $order->save();

            // Queue browser event with the exact same event_id
            $this->queue_browser_event($event_data, false, false);
            $this->send_event($event_data);
        }
    }

    /**
     * Track Purchase event on Order Status Change (Processing / Completed)
     * Catches orders completed via payment gateway webhooks (Stripe, PayPal, COD)
     * even if customer never visits the Thank You page.
     *
     * @param int $order_id
     * @param WC_Order|null $order
     */
    public function track_purchase_on_status_change($order_id, $order = null) {
        if (!$this->is_event_enabled('Purchase') || !class_exists('WooCommerce')) {
            return;
        }

        if (!$order && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        // Deduplication Gate: If already fired (e.g. by thankyou page), skip!
        if ($order->get_meta('_w3_purchase_event_fired', true)) {
            return;
        }

        // Block frontend AJAX requests to avoid race conditions during checkout
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || isset($_REQUEST['wc-ajax'])) {
            return;
        }

        $event_id = $this->get_or_create_purchase_event_id($order);
        $order->update_meta_data('_w3_purchase_event_fired', '1');
        $order->save();

        $event_data = $this->build_purchase_event_data($order, $event_id);
        $this->send_event($event_data);
    }

    /**
     * Build rich purchase event data with customer totals and LTV metrics
     *
     * @param WC_Order $order
     * @param string $event_id
     * @return array
     */
    private function build_purchase_event_data($order, $event_id) {
        $content_ids = array();
        $contents = array();
        $total_quantity = 0;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            $price = $quantity > 0 ? $item->get_total() / $quantity : 0;
            
            $content_ids[] = (string) $product_id;
            $contents[] = array(
                'id'         => (string) $product_id,
                'quantity'   => (int) $quantity,
                'item_price' => (float) $price
            );
            $total_quantity += $quantity;
        }

        $custom_data = array(
            'content_ids'  => $content_ids,
            'content_type' => 'product',
            'contents'     => $contents,
            'value'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
            'num_items'    => $total_quantity,
            'order_id'     => (string) $order->get_id()
        );

        // Add Customer Totals and Lifetime Value (LTV) metrics
        $customer_id = $order->get_customer_id();
        if ($customer_id && function_exists('wc_get_orders')) {
            $customer_orders = wc_get_orders(array(
                'customer_id' => $customer_id,
                'limit'       => -1,
                'status'      => array('wc-completed', 'wc-processing')
            ));
            $orders_count = count($customer_orders);
            $ltv = 0;
            foreach ($customer_orders as $past_order) {
                $ltv += (float) $past_order->get_total();
            }
            $custom_data['predicted_ltv'] = (float) round($ltv, 2);
            $custom_data['transactions_count'] = (int) $orders_count;
            $custom_data['average_order'] = $orders_count > 0 ? (float) round($ltv / $orders_count, 2) : (float) $order->get_total();
        }

        // Coupon details
        $coupons = $order->get_coupon_codes();
        if (!empty($coupons)) {
            $custom_data['coupon_used'] = 'yes';
            $custom_data['coupon_name'] = implode(', ', $coupons);
        }

        // Shipping method
        $shipping_items = $order->get_items('shipping');
        if (!empty($shipping_items)) {
            $shipping_names = array();
            foreach ($shipping_items as $s) {
                $shipping_names[] = $s->get_name();
            }
            $custom_data['shipping'] = implode(', ', $shipping_names);
        }
        
        return array(
            'event_name'       => 'Purchase',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => $order->get_checkout_order_received_url(),
            'user_data'        => $this->get_user_data_from_order($order),
            'custom_data'      => $custom_data,
            'event_id'         => $event_id
        );
    }
    
    /**
     * Flag to prevent duplicate InitiateCheckout event in the same request
     */
    private $initiate_checkout_fired = false;

    /**
     * Track InitiateCheckout event (WooCommerce)
     */
    public function track_initiate_checkout() {
        if ($this->initiate_checkout_fired) {
            return;
        }

        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('InitiateCheckout') || !class_exists('WooCommerce')) {
            return;
        }
        
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return;
        }

        $this->initiate_checkout_fired = true;
        
        $content_ids = array();
        $contents = array();
        $total_quantity = 0;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $price = $quantity > 0 ? $cart_item['line_total'] / $quantity : 0;
            
            $content_ids[] = (string) $product_id;
            $contents[] = array(
                'id'         => (string) $product_id,
                'quantity'   => (int) $quantity,
                'item_price' => (float) $price
            );
            $total_quantity += $quantity;
        }
        
        $event_id = $this->generate_event_id('InitiateCheckout', md5(serialize($content_ids)));
        $event_data = array(
            'event_name'       => 'InitiateCheckout',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_ids'  => $content_ids,
                'content_type' => 'product',
                'contents'     => $contents,
                'value'        => (float) $cart->get_total('edit'),
                'currency'     => get_woocommerce_currency(),
                'num_items'    => $total_quantity
            ),
            'event_id'         => $event_id
        );
        
        // Queue browser event to fire in footer
        $this->queue_browser_event($event_data, false, true);
    }
    
    /**
     * Process deferred events
     */
    public function process_deferred_events() {
        if (empty($this->deferred_events)) {
            return;
        }
        
        foreach ($this->deferred_events as $event_data) {
            $this->send_event($event_data);
        }
        
        $this->deferred_events = array();
    }

    /**
     * Get queued browser-side events (Pixel)
     *
     * @return array
     */
    public function get_browser_events() {
        $events = $this->browser_events;

        // Merge and clear any WooCommerce-session queued events
        if (class_exists('WooCommerce') && function_exists('WC')) {
            $session = WC()->session;
            if ($session) {
                $session_events = $session->get('fbpixel_capi_browser_events', array());
                if (!empty($session_events) && is_array($session_events)) {
                    $events = array_merge($events, $session_events);
                    $session->__unset('fbpixel_capi_browser_events');
                }
            }
        }

        return $events;
    }
    
    /**
     * Send event to Facebook Conversions API
     * 
     * @param array $event_data Event data
     * @return array|WP_Error Response or error
     */
    public function send_event($event_data) {
        $result = $this->api_client->send_event($event_data);
        
        if (!empty($this->settings['debug_mode'])) {
            if (is_wp_error($result)) {
                error_log('Facebook Pixel CAPI Error: ' . $result->get_error_message());
            } else {
                error_log('Facebook Pixel CAPI Success: Event ' . $event_data['event_name'] . ' sent');
            }
        }
        
        return $result;
    }

    /**
     * Queue browser-side event for Pixel tracking
     *
     * @param array $event_data
     * @param bool $store_in_session
     * @param bool $send_to_capi
     */
    private function queue_browser_event($event_data, $store_in_session = false, $send_to_capi = false) {
        if (empty($event_data['event_name'])) {
            return;
        }

        $browser_event = array(
            'event_name'   => $event_data['event_name'],
            'event_id'     => isset($event_data['event_id']) ? $event_data['event_id'] : '',
            'custom_data'  => isset($event_data['custom_data']) ? $event_data['custom_data'] : array(),
            'send_to_capi' => $send_to_capi
        );

        if ($store_in_session && class_exists('WooCommerce') && function_exists('WC')) {
            $session = WC()->session;
            if ($session) {
                $existing = $session->get('fbpixel_capi_browser_events', array());
                if (!is_array($existing)) {
                    $existing = array();
                }
                $existing[] = $browser_event;
                $session->set('fbpixel_capi_browser_events', $existing);
                return;
            }
        }

        $this->browser_events[] = $browser_event;
    }
    
    /**
     * Get rich user data for event (High EMQ)
     * 
     * @return array User data
     */
    public function get_user_data() {
        $user_data = array(
            'client_ip_address' => FBPixel_CAPI_Helpers::get_client_ip(),
            'client_user_agent' => FBPixel_CAPI_Helpers::get_user_agent()
        );
        
        // Ensure cookies and add Facebook click/browser IDs
        $fbc = FBPixel_CAPI_Helpers::ensure_fbc();
        if (!empty($fbc)) {
            $user_data['fbc'] = $fbc;
        }

        $fbp = FBPixel_CAPI_Helpers::ensure_fbp();
        if (!empty($fbp)) {
            $user_data['fbp'] = $fbp;
        }
        
        // Add user data if logged in
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
            // Check form data persistence
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
            // Persistent external ID for guests
            $user_data['external_id'] = FBPixel_CAPI_Helpers::get_pbid();
        }

        // Add WooCommerce customer data when available
        if (class_exists('WooCommerce') && function_exists('WC') && WC()->customer) {
            $customer_data = FBPixel_CAPI_Helpers::get_wc_customer_data(WC()->customer);
            $user_data = array_merge($user_data, $customer_data);
        }

        $user_data = array_filter($user_data, function ($value) {
            return $value !== null && $value !== '';
        });
        
        return $user_data;
    }
    
    /**
     * Get user data from WooCommerce order (retrieves locked fbp/fbc from order meta)
     * 
     * @param WC_Order $order Order object
     * @return array User data
     */
    private function get_user_data_from_order($order) {
        // Retrieve locked cookies & customer tech info from order meta
        $fb_cookies = FBPixel_CAPI_Helpers::get_fb_cookies_from_order($order);

        $client_user_agent = !empty($fb_cookies['user_agent']) ? $fb_cookies['user_agent'] : FBPixel_CAPI_Helpers::get_user_agent();
        $client_ip = $order->get_customer_ip_address();
        if (empty($client_ip) && !empty($fb_cookies['ip'])) {
            $client_ip = $fb_cookies['ip'];
        }
        if (empty($client_ip)) {
            $client_ip = FBPixel_CAPI_Helpers::get_client_ip();
        }

        $user_data = array(
            'client_ip_address' => $client_ip,
            'client_user_agent' => $client_user_agent
        );
        
        if (!empty($fb_cookies['fbc'])) {
            $user_data['fbc'] = $fb_cookies['fbc'];
        }
        if (!empty($fb_cookies['fbp'])) {
            $user_data['fbp'] = $fb_cookies['fbp'];
        }
        
        // Add customer data from order
        $billing_email = $order->get_billing_email();
        if (!empty($billing_email)) {
            $user_data['em'] = FBPixel_CAPI_Helpers::normalize_and_hash($billing_email);
        }
        
        $billing_country = $order->get_billing_country();
        if (!empty($billing_country)) {
            $user_data['country'] = FBPixel_CAPI_Helpers::normalize_country($billing_country);
        }

        $billing_first_name = $order->get_billing_first_name();
        if (!empty($billing_first_name)) {
            $user_data['fn'] = FBPixel_CAPI_Helpers::normalize_name($billing_first_name);
        }
        
        $billing_last_name = $order->get_billing_last_name();
        if (!empty($billing_last_name)) {
            $user_data['ln'] = FBPixel_CAPI_Helpers::normalize_name($billing_last_name);
        }

        $billing_phone = $order->get_billing_phone();
        if (!empty($billing_phone)) {
            $user_data['ph'] = FBPixel_CAPI_Helpers::normalize_and_hash_phone($billing_phone);
        }
        
        $billing_city = $order->get_billing_city();
        if (!empty($billing_city)) {
            $user_data['ct'] = FBPixel_CAPI_Helpers::normalize_city($billing_city);
        }
        
        $billing_state = $order->get_billing_state();
        if (!empty($billing_state)) {
            $user_data['st'] = FBPixel_CAPI_Helpers::normalize_state($billing_state);
        }
        
        $billing_postcode = $order->get_billing_postcode();
        if (!empty($billing_postcode)) {
            $user_data['zp'] = FBPixel_CAPI_Helpers::normalize_zip($billing_postcode, $billing_country);
        }

        $customer_id = $order->get_customer_id();
        if (!empty($customer_id)) {
            $user_data['external_id'] = FBPixel_CAPI_Helpers::normalize_and_hash((string) $customer_id);
        } else {
            $user_data['external_id'] = FBPixel_CAPI_Helpers::get_pbid();
        }

        $user_data = array_filter($user_data, function ($value) {
            return $value !== null && $value !== '';
        });
        
        return $user_data;
    }
    
    /**
     * Get product brand
     * 
     * @param WC_Product $product Product object
     * @return string Product brand
     */
    private function get_product_brand($product) {
        $brand = '';
        $attributes = $product->get_attributes();
        $brand_attributes = array('brand', 'pa_brand', 'product_brand', 'pa_product_brand');
        
        foreach ($brand_attributes as $brand_attr) {
            if (isset($attributes[$brand_attr])) {
                $attribute = $attributes[$brand_attr];
                if ($attribute->is_taxonomy()) {
                    $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                    if (!empty($terms) && !is_wp_error($terms)) {
                        $brand = $terms[0]->name;
                        break;
                    }
                } else {
                    $brand = $attribute->get_options()[0] ?? '';
                    if ($brand) {
                        break;
                    }
                }
            }
        }
        
        if (empty($brand)) {
            $custom_brand_fields = array('_brand', '_product_brand', 'brand', 'product_brand');
            foreach ($custom_brand_fields as $field) {
                $brand = get_post_meta($product->get_id(), $field, true);
                if (!empty($brand)) {
                    break;
                }
            }
        }
        
        return sanitize_text_field($brand);
    }
    
    /**
     * Get product categories
     * 
     * @param int $product_id Product ID
     * @return string Product categories
     */
    private function get_product_categories($product_id) {
        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }
        
        $categories = array();
        foreach ($terms as $term) {
            $categories[] = $term->name;
        }
        
        return implode(', ', $categories);
    }
    
    /**
     * Generate unique RFC 4122 UUIDv4 event ID for deduplication
     * 
     * @param string $event_name Event name
     * @param string $additional_data Additional data for uniqueness
     * @return string Event ID
     */
    private function generate_event_id($event_name, $additional_data = '') {
        $event_id = FBPixel_CAPI_Helpers::guidv4();
        return apply_filters('fbpixel_capi_event_id', $event_id, $event_name, $additional_data);
    }
    
    /**
     * Check if event is enabled
     * 
     * @param string $event_name Event name
     * @return bool Whether event is enabled
     */
    private function is_event_enabled($event_name) {
        if (empty($this->settings['enabled_events'])) {
            return false;
        }
        
        return !empty($this->settings['enabled_events'][$event_name]);
    }
}
