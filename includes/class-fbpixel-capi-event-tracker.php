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

        $post_type = get_post_type();
        if (!$post_type) {
            if (is_front_page()) {
                $post_type = 'frontpage';
            } elseif (function_exists('is_shop') && is_shop()) {
                $post_type = 'shop';
            } else {
                $post_type = 'page';
            }
        }

        $user_role = 'guest';
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            if (!empty($current_user->roles)) {
                $user_role = implode(', ', $current_user->roles);
            }
        }
        
        $event_id = $this->generate_event_id('PageView');
        $event_data = array(
            'event_name'       => 'PageView',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'page_title' => wp_get_document_title(),
                'post_type'  => $post_type,
                'post_id'    => get_the_ID() ? (string) get_the_ID() : '',
                'plugin'     => 'w3-pixel-server-side-tracking',
                'user_role'  => $user_role,
                'event_url'  => FBPixel_CAPI_Helpers::get_current_url()
            ),
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

        $category = $this->get_product_categories($product_id);
        $tags = $this->get_product_tags($product_id);
        
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
                'content_category' => $category,
                'category_name'    => $category,
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

        if (!empty($tags)) {
            $event_data['custom_data']['tags'] = $tags;
        }

        // Queue browser event with matching event_id and non-blocking CAPI relay
        $this->queue_browser_event($event_data, false, true);
    }

    /**
     * Track ViewCategory event (WooCommerce category & tag archives)
     */
    public function track_view_category() {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('ViewCategory') || !class_exists('WooCommerce')) {
            return;
        }

        if (!is_product_category() && !is_product_tag()) {
            return;
        }

        $term = get_queried_object();
        if (!$term || empty($term->name)) {
            return;
        }

        // Extract top product IDs currently displayed in category query
        global $wp_query;
        $content_ids = array();
        if (!empty($wp_query->posts)) {
            foreach ($wp_query->posts as $p) {
                if (count($content_ids) >= 10) break;
                $content_ids[] = (string) $p->ID;
            }
        }

        $event_id = $this->generate_event_id('ViewCategory', (string) $term->term_id);
        $event_data = array(
            'event_name'       => 'ViewCategory',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_name'     => $term->name,
                'content_category' => $term->name,
                'category_name'    => $term->name,
                'content_ids'      => $content_ids,
                'content_type'     => 'product'
            ),
            'event_id'         => $event_id
        );

        $this->queue_browser_event($event_data, false, true);
    }

    /**
     * Track Search event (Search results page)
     */
    public function track_search() {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('Search')) {
            return;
        }

        if (!is_search()) {
            return;
        }

        $query = get_search_query();
        if (empty($query)) {
            return;
        }

        global $wp_query;
        $content_ids = array();
        if (!empty($wp_query->posts)) {
            foreach ($wp_query->posts as $p) {
                if (count($content_ids) >= 10) break;
                $content_ids[] = (string) $p->ID;
            }
        }

        $event_id = $this->generate_event_id('Search', md5($query));
        $event_data = array(
            'event_name'       => 'Search',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'search_string'    => $query,
                'content_ids'      => $content_ids,
                'content_type'     => 'product',
                'content_category' => 'Search Results'
            ),
            'event_id'         => $event_id
        );

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

        $category = $this->get_product_categories($product_id);
        $tags = $this->get_product_tags($product_id);
        $sku = $product->get_sku();
        $brand = $this->get_product_brand($product);
        
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
                'content_category' => $category,
                'category_name'    => $category,
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

        if (!empty($sku)) {
            $event_data['custom_data']['content_sku'] = $sku;
        }
        if (!empty($brand)) {
            $event_data['custom_data']['brand'] = $brand;
        }
        if (!empty($tags)) {
            $event_data['custom_data']['tags'] = $tags;
        }

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
     * Track RemoveFromCart event (WooCommerce)
     *
     * @param string $cart_item_key Cart item key
     * @param WC_Cart $cart Cart instance
     */
    public function track_remove_from_cart($cart_item_key, $cart = null) {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('RemoveFromCart') || !class_exists('WooCommerce')) {
            return;
        }

        if (!$cart) {
            $cart = WC()->cart;
        }
        if (!$cart) {
            return;
        }

        $removed_item = isset($cart->removed_cart_contents[$cart_item_key]) ? $cart->removed_cart_contents[$cart_item_key] : null;
        if (!$removed_item) {
            return;
        }

        $target_id = !empty($removed_item['variation_id']) ? $removed_item['variation_id'] : $removed_item['product_id'];
        $product = wc_get_product($target_id);
        if (!$product) {
            return;
        }

        $quantity = isset($removed_item['quantity']) ? (int) $removed_item['quantity'] : 1;
        $price = (float) $product->get_price();
        $category = $this->get_product_categories($removed_item['product_id']);

        $event_id = $this->generate_event_id('RemoveFromCart', $target_id . '_' . $quantity);
        $event_data = array(
            'event_name'       => 'RemoveFromCart',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_name'     => $product->get_name(),
                'content_ids'      => array((string) $target_id),
                'content_type'     => 'product',
                'value'            => $price * $quantity,
                'currency'         => get_woocommerce_currency(),
                'contents'         => array(
                    array(
                        'id'         => (string) $target_id,
                        'quantity'   => $quantity,
                        'item_price' => $price
                    )
                ),
                'content_category' => $category,
                'category_name'    => $category,
                'num_items'        => $quantity
            ),
            'event_id'         => $event_id
        );

        $this->send_event($event_data);
        $this->queue_browser_event($event_data, true, false);
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
        $orders_count = isset($event_data['_orders_count']) ? (int) $event_data['_orders_count'] : 1;
        $ltv          = isset($event_data['_ltv']) ? (float) $event_data['_ltv'] : (float) $order->get_total();
        unset($event_data['_orders_count'], $event_data['_ltv']);

        // Only fire if not already tracked (prevents duplicates on thank you page reloads)
        if (!$already_fired) {
            $order->update_meta_data('_w3_purchase_event_fired', '1');
            $order->save();

            // Queue browser event with the exact same event_id
            $this->queue_browser_event($event_data, false, false);
            $this->send_event($event_data);

            // Optional Segment Triggers: FirstOrder, ReturningCustomer, VipClient
            $this->maybe_track_customer_segments($order, $orders_count, $ltv);
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
        $orders_count = isset($event_data['_orders_count']) ? (int) $event_data['_orders_count'] : 1;
        $ltv          = isset($event_data['_ltv']) ? (float) $event_data['_ltv'] : (float) $order->get_total();
        unset($event_data['_orders_count'], $event_data['_ltv']);

        $this->send_event($event_data);

        // Optional Segment Triggers: FirstOrder, ReturningCustomer, VipClient
        $this->maybe_track_customer_segments($order, $orders_count, $ltv);
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
        $content_names = array();
        $all_categories = array();
        $total_quantity = 0;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            $price = $quantity > 0 ? $item->get_total() / $quantity : 0;
            
            $content_names[] = $item->get_name();
            $cat = $this->get_product_categories($product_id);
            if (!empty($cat) && !in_array($cat, $all_categories, true)) {
                $all_categories[] = $cat;
            }

            $content_ids[] = (string) $product_id;
            $contents[] = array(
                'id'         => (string) $product_id,
                'quantity'   => (int) $quantity,
                'item_price' => (float) $price
            );
            $total_quantity += $quantity;
        }

        $category_string = implode(', ', $all_categories);

        $custom_data = array(
            'content_name'     => implode(', ', $content_names),
            'content_category' => $category_string,
            'category_name'    => $category_string,
            'content_ids'      => $content_ids,
            'content_type'     => 'product',
            'contents'         => $contents,
            'value'            => (float) $order->get_total(),
            'currency'         => $order->get_currency(),
            'num_items'        => $total_quantity,
            'order_id'         => (string) $order->get_id(),
            'shipping_cost'    => (float) $order->get_shipping_total(),
            'tax'              => (float) $order->get_total_tax()
        );

        // Calculate Customer Lifetime Metrics (orders_count, ltv)
        $customer_stats = $this->calculate_customer_order_stats($order);
        $orders_count   = $customer_stats['orders_count'];
        $ltv            = $customer_stats['ltv'];

        $custom_data['predicted_ltv']      = (float) round($ltv, 2);
        $custom_data['transactions_count'] = (int) $orders_count;
        $custom_data['average_order']      = $orders_count > 0 ? (float) round($ltv / $orders_count, 2) : (float) $order->get_total();

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
            'event_id'         => $event_id,
            '_orders_count'    => $orders_count,
            '_ltv'             => $ltv
        );
    }

    /**
     * Calculate customer lifetime order count and total spend (LTV).
     * For registered customers ($customer_id > 0), matches by customer_id.
     * For guest orders:
     *   1. First tries to match past orders with billing_email.
     *   2. If guest order doesn't match with email, tries again to match with phone (billing_phone).
     *
     * @param WC_Order $order
     * @return array array('orders_count' => int, 'ltv' => float)
     */
    private function calculate_customer_order_stats($order) {
        $current_id    = (int) $order->get_id();
        $customer_id   = (int) $order->get_customer_id();
        $billing_email = sanitize_email($order->get_billing_email());
        $billing_phone = trim((string) $order->get_billing_phone());

        $matched_order_ids = array();
        $valid_statuses = array('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'completed', 'processing', 'on-hold', 'pending');

        if (!empty($customer_id)) {
            // 1. Logged in customer: match by customer_id
            $matched_order_ids = $this->find_orders_by_customer_id($customer_id, $valid_statuses);
        } else {
            // 2. Guest order:
            // Step 1: Try to match with email
            if (!empty($billing_email)) {
                $email_order_ids = $this->find_orders_by_email($billing_email, $valid_statuses);
                // Check if any past order (other than the current one) matched
                $past_orders_by_email = array_diff($email_order_ids, array($current_id));
                if (!empty($past_orders_by_email)) {
                    $matched_order_ids = $email_order_ids;
                }
            }

            // Step 2: If guest order doesn't match with email, try again to match with phone
            if (empty($matched_order_ids) && !empty($billing_phone)) {
                $phone_order_ids = $this->find_orders_by_phone($billing_phone, $valid_statuses);
                $past_orders_by_phone = array_diff($phone_order_ids, array($current_id));
                if (!empty($past_orders_by_phone)) {
                    $matched_order_ids = $phone_order_ids;
                }
            }
        }

        // Always include the current order
        if (!in_array($current_id, $matched_order_ids)) {
            $matched_order_ids[] = $current_id;
        }

        $orders_count = count($matched_order_ids);
        $ltv = 0.0;
        foreach ($matched_order_ids as $oid) {
            $o = ($oid == $current_id) ? $order : (function_exists('wc_get_order') ? wc_get_order($oid) : null);
            if ($o && is_a($o, 'WC_Order')) {
                $ltv += (float) $o->get_total();
            }
        }

        return array(
            'orders_count' => max(1, $orders_count),
            'ltv'          => (float) round($ltv, 2)
        );
    }

    /**
     * Find orders by customer ID
     *
     * @param int $customer_id
     * @param array $statuses
     * @return array Order IDs
     */
    private function find_orders_by_customer_id($customer_id, $statuses) {
        $order_ids = array();
        if (function_exists('wc_get_orders')) {
            $ids = wc_get_orders(array(
                'limit'       => -1,
                'customer_id' => (int) $customer_id,
                'status'      => $statuses,
                'return'      => 'ids',
            ));
            if (!empty($ids) && is_array($ids)) {
                $order_ids = array_map('intval', $ids);
            }
        }
        return $order_ids;
    }

    /**
     * Find orders by billing email (with WC query and direct DB fallbacks for HPOS/CPT)
     *
     * @param string $email
     * @param array $statuses
     * @return array Order IDs
     */
    private function find_orders_by_email($email, $statuses) {
        $order_ids = array();
        if (function_exists('wc_get_orders')) {
            $ids = wc_get_orders(array(
                'limit'         => -1,
                'billing_email' => $email,
                'status'        => $statuses,
                'return'        => 'ids',
            ));
            if (!empty($ids) && is_array($ids)) {
                $order_ids = array_map('intval', $ids);
            }
        }

        // Fallback to database queries
        global $wpdb;
        if (empty($order_ids) && !empty($wpdb)) {
            // Check HPOS table if exists
            $hpos_table = $wpdb->prefix . 'wc_orders';
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hpos_table));
            if ($table_exists === $hpos_table) {
                $status_clean = array_map('esc_sql', $statuses);
                $status_in    = "'" . implode("','", $status_clean) . "'";
                $hpos_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$hpos_table} WHERE billing_email = %s AND status IN ({$status_in})",
                    $email
                ));
                if (!empty($hpos_ids)) {
                    $order_ids = array_merge($order_ids, array_map('intval', $hpos_ids));
                }
            }

            // Check postmeta (legacy or HPOS synced)
            $postmeta_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_billing_email' AND pm.meta_value = %s
                 AND p.post_type = 'shop_order'
                 AND p.post_status NOT IN ('trash', 'auto-draft')",
                $email
            ));
            if (!empty($postmeta_ids)) {
                $order_ids = array_merge($order_ids, array_map('intval', $postmeta_ids));
            }
        }

        return array_values(array_unique(array_filter($order_ids)));
    }

    /**
     * Find orders by billing phone (supports variations: local 01..., intl +8801..., raw digits, etc.)
     *
     * @param string $phone
     * @param array $statuses
     * @return array Order IDs
     */
    private function find_orders_by_phone($phone, $statuses) {
        $order_ids = array();
        $raw_phone = trim((string) $phone);
        if (empty($raw_phone)) {
            return $order_ids;
        }

        $phone_variants = array($raw_phone);
        $digits = preg_replace('/[^0-9]/', '', $raw_phone);
        if (!empty($digits)) {
            $phone_variants[] = $digits;
            // Bangladesh format detection (01X...)
            if (strlen($digits) === 11 && strpos($digits, '01') === 0) {
                $phone_variants[] = '+88' . $digits;
                $phone_variants[] = '88' . $digits;
                $phone_variants[] = substr($digits, 1); // 17XXXXXXXX
            } elseif (strlen($digits) === 13 && strpos($digits, '8801') === 0) {
                $local = '0' . substr($digits, 2); // 01XXXXXXXX
                $phone_variants[] = $local;
                $phone_variants[] = '+' . $digits;
                $phone_variants[] = substr($digits, 3); // 17XXXXXXXX
            } elseif (strlen($digits) === 10 && strpos($digits, '1') === 0) {
                $phone_variants[] = '0' . $digits;
                $phone_variants[] = '+880' . $digits;
                $phone_variants[] = '880' . $digits;
            }
        }
        $phone_variants = array_values(array_unique(array_filter($phone_variants)));

        // 1. Try wc_get_orders with meta_query
        if (function_exists('wc_get_orders')) {
            $meta_queries = array('relation' => 'OR');
            foreach ($phone_variants as $v) {
                $meta_queries[] = array(
                    'key'     => '_billing_phone',
                    'value'   => $v,
                    'compare' => '='
                );
            }
            $ids = wc_get_orders(array(
                'limit'      => -1,
                'status'     => $statuses,
                'meta_query' => $meta_queries,
                'return'     => 'ids',
            ));
            if (!empty($ids) && is_array($ids)) {
                $order_ids = array_merge($order_ids, array_map('intval', $ids));
            }
        }

        // 2. Direct database query fallback
        global $wpdb;
        if (!empty($wpdb) && !empty($phone_variants)) {
            $placeholders = implode(',', array_fill(0, count($phone_variants), '%s'));

            // Check HPOS addresses table
            $addr_table = $wpdb->prefix . 'wc_order_addresses';
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $addr_table));
            if ($table_exists === $addr_table) {
                $hpos_addr_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT order_id FROM {$addr_table} WHERE address_type = 'billing' AND phone IN ({$placeholders})",
                    $phone_variants
                ));
                if (!empty($hpos_addr_ids)) {
                    $order_ids = array_merge($order_ids, array_map('intval', $hpos_addr_ids));
                }
            }

            // Check postmeta
            $postmeta_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_billing_phone' AND pm.meta_value IN ({$placeholders})
                 AND p.post_type = 'shop_order'
                 AND p.post_status NOT IN ('trash', 'auto-draft')",
                $phone_variants
            ));
            if (!empty($postmeta_ids)) {
                $order_ids = array_merge($order_ids, array_map('intval', $postmeta_ids));
            }
        }

        return array_values(array_unique(array_filter($order_ids)));
    }

    /**
     * Dispatch advanced audience segment events on purchase
     */
    private function maybe_track_customer_segments($order, $orders_count, $ltv) {
        if ($order->get_meta('_w3_segments_event_fired', true)) {
            return;
        }
        $order->update_meta_data('_w3_segments_event_fired', '1');
        $order->save();

        $user_data = $this->get_user_data_from_order($order);
        $order_url = $order->get_checkout_order_received_url();

        // 1. FirstOrder: ONLY fires on customer's first lifetime purchase (orders_count === 1)
        if ($orders_count === 1 && $this->is_event_enabled('FirstOrder')) {
            $first_order_data = array(
                'event_name'       => 'FirstOrder',
                'event_time'       => time(),
                'action_source'    => 'website',
                'event_source_url' => $order_url,
                'user_data'        => $user_data,
                'custom_data'      => array(
                    'content_name' => 'First Order',
                    'order_id'     => (string) $order->get_id(),
                    'value'        => (float) $order->get_total(),
                    'currency'     => $order->get_currency()
                ),
                'event_id'         => $this->generate_event_id('FirstOrder', (string) $order->get_id())
            );
            $this->send_event($first_order_data);
            $this->queue_browser_event($first_order_data, false, false);
        }

        // 2. ReturningCustomer: Fires on repeat purchases (2 or more orders)
        if ($orders_count > 1 && $this->is_event_enabled('ReturningCustomer')) {
            $returning_data = array(
                'event_name'       => 'ReturningCustomer',
                'event_time'       => time(),
                'action_source'    => 'website',
                'event_source_url' => $order_url,
                'user_data'        => $user_data,
                'custom_data'      => array(
                    'content_name'       => 'Returning Customer',
                    'transactions_count' => (int) $orders_count,
                    'average_order'      => $orders_count > 0 ? (float) round($ltv / $orders_count, 2) : (float) $order->get_total(),
                    'predicted_ltv'      => (float) round($ltv, 2),
                    'value'              => (float) $order->get_total()
                ),
                'event_id'         => $this->generate_event_id('ReturningCustomer', (string) $order->get_id())
            );
            $this->send_event($returning_data);
            $this->queue_browser_event($returning_data, false, false);
        }

        // 3. VipClient: High-value repeat customers only (must have > 1 order AND (orders >= 5 OR ltv >= 5000))
        if ($orders_count > 1 && ($orders_count >= 5 || $ltv >= 5000) && $this->is_event_enabled('VipClient')) {
            $vip_data = array(
                'event_name'       => 'VipClient',
                'event_time'       => time(),
                'action_source'    => 'website',
                'event_source_url' => $order_url,
                'user_data'        => $user_data,
                'custom_data'      => array(
                    'content_name'       => 'VIP Client',
                    'transactions_count' => (int) $orders_count,
                    'average_order'      => $orders_count > 0 ? (float) round($ltv / $orders_count, 2) : (float) $order->get_total(),
                    'predicted_ltv'      => (float) round($ltv, 2)
                ),
                'event_id'         => $this->generate_event_id('VipClient', (string) $order->get_id())
            );
            $this->send_event($vip_data);
            $this->queue_browser_event($vip_data, false, false);
        }
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
        $content_names = array();
        $all_categories = array();
        $all_tags = array();
        $total_quantity = 0;
        
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $quantity = $cart_item['quantity'];
            $price = $quantity > 0 ? $cart_item['line_total'] / $quantity : 0;
            
            $product = isset($cart_item['data']) ? $cart_item['data'] : wc_get_product($product_id);
            if ($product) {
                $content_names[] = $product->get_name();
                $cat = $this->get_product_categories($product_id);
                if (!empty($cat) && !in_array($cat, $all_categories, true)) {
                    $all_categories[] = $cat;
                }
                $t = $this->get_product_tags($product_id);
                if (!empty($t) && !in_array($t, $all_tags, true)) {
                    $all_tags[] = $t;
                }
            }
            
            $content_ids[] = (string) $product_id;
            $contents[] = array(
                'id'         => (string) $product_id,
                'quantity'   => (int) $quantity,
                'item_price' => (float) $price
            );
            $total_quantity += $quantity;
        }
        
        $cat_string = implode(', ', $all_categories);
        $custom_data = array(
            'content_name'     => implode(', ', $content_names),
            'content_category' => $cat_string,
            'category_name'    => $cat_string,
            'content_ids'      => $content_ids,
            'content_type'     => 'product',
            'contents'         => $contents,
            'value'            => (float) $cart->get_total('edit'),
            'subtotal'         => (float) $cart->get_subtotal(),
            'currency'         => get_woocommerce_currency(),
            'num_items'        => $total_quantity
        );

        if (!empty($all_tags)) {
            $custom_data['tags'] = implode(', ', $all_tags);
        }
        
        $event_id = $this->generate_event_id('InitiateCheckout', md5(serialize($content_ids)));
        $event_data = array(
            'event_name'       => 'InitiateCheckout',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => $custom_data,
            'event_id'         => $event_id
        );
        
        // Queue browser event to fire in footer
        $this->queue_browser_event($event_data, false, true);
    }

    /**
     * Track AddPaymentInfo event (WooCommerce checkout)
     *
     * @param int $order_id
     * @param array $posted_data
     * @param WC_Order|null $order
     */
    public function track_add_payment_info($order_id, $posted_data = array(), $order = null) {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('AddPaymentInfo') || !class_exists('WooCommerce')) {
            return;
        }

        if (!$order && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

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

        $payment_method = $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method();

        $event_id = $this->generate_event_id('AddPaymentInfo', (string) $order_id);
        $event_data = array(
            'event_name'       => 'AddPaymentInfo',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data_from_order($order),
            'custom_data'      => array(
                'content_ids'    => $content_ids,
                'content_type'   => 'product',
                'value'          => (float) $order->get_total(),
                'currency'       => $order->get_currency(),
                'contents'       => $contents,
                'num_items'      => $total_quantity,
                'payment_method' => $payment_method
            ),
            'event_id'         => $event_id
        );

        $this->send_event($event_data);
    }

    /**
     * Track AddToWishlist event
     *
     * @param int $product_id
     */
    public function track_add_to_wishlist($product_id) {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('AddToWishlist') || !class_exists('WooCommerce')) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $category = $this->get_product_categories($product_id);
        $price = (float) $product->get_price();

        $event_id = $this->generate_event_id('AddToWishlist', (string) $product_id);
        $event_data = array(
            'event_name'       => 'AddToWishlist',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_name'     => $product->get_name(),
                'content_ids'      => array((string) $product_id),
                'content_type'     => 'product',
                'value'            => $price,
                'currency'         => get_woocommerce_currency(),
                'contents'         => array(
                    array(
                        'id'         => (string) $product_id,
                        'quantity'   => 1,
                        'item_price' => $price
                    )
                ),
                'content_category' => $category,
                'category_name'    => $category
            ),
            'event_id'         => $event_id
        );

        $this->send_event($event_data);
        $this->queue_browser_event($event_data, true, false);
    }

    /**
     * Track CompleteRegistration event (New WordPress/Woo user registration)
     *
     * @param int $user_id
     */
    public function track_complete_registration($user_id) {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('CompleteRegistration')) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $user_data = $this->get_user_data();
        if (!empty($user->user_email)) {
            $user_data['em'] = FBPixel_CAPI_Helpers::normalize_and_hash($user->user_email);
        }
        if (!empty($user->first_name)) {
            $user_data['fn'] = FBPixel_CAPI_Helpers::normalize_and_hash($user->first_name);
        }
        if (!empty($user->last_name)) {
            $user_data['ln'] = FBPixel_CAPI_Helpers::normalize_and_hash($user->last_name);
        }
        $user_data['external_id'] = FBPixel_CAPI_Helpers::normalize_and_hash((string) $user_id);

        $event_id = $this->generate_event_id('CompleteRegistration', (string) $user_id);
        $event_data = array(
            'event_name'       => 'CompleteRegistration',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $user_data,
            'custom_data'      => array(
                'content_name' => $user->display_name ? $user->display_name : $user->user_login,
                'status'       => 'complete',
                'event_url'    => FBPixel_CAPI_Helpers::get_current_url()
            ),
            'event_id'         => $event_id
        );

        $this->send_event($event_data);
        $this->queue_browser_event($event_data, true, false);
    }

    /**
     * Track Lead event (Contact forms, subscription forms)
     *
     * @param array $data
     */
    public function track_lead($data = array()) {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('Lead')) {
            return;
        }

        $event_id = $this->generate_event_id('Lead', FBPixel_CAPI_Helpers::guidv4());
        $form_name = !empty($data['content_name']) ? sanitize_text_field($data['content_name']) : wp_get_document_title();
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

        $event_data = array(
            'event_name'       => 'Lead',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_name' => $form_name,
                'category'     => !empty($data['category']) ? sanitize_text_field($data['category']) : 'Lead Form',
                'value'        => !empty($data['value']) ? (float) $data['value'] : 0.0,
                'currency'     => $currency
            ),
            'event_id'         => $event_id
        );

        $this->send_event($event_data);
        $this->queue_browser_event($event_data, true, false);
    }

    /**
     * Track Contact event
     *
     * @param array $data
     */
    public function track_contact($data = array()) {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('Contact')) {
            return;
        }

        $event_id = $this->generate_event_id('Contact', FBPixel_CAPI_Helpers::guidv4());
        $page_title = !empty($data['content_name']) ? sanitize_text_field($data['content_name']) : wp_get_document_title();

        $event_data = array(
            'event_name'       => 'Contact',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_name' => $page_title,
                'event_url'    => FBPixel_CAPI_Helpers::get_current_url()
            ),
            'event_id'         => $event_id
        );

        $this->send_event($event_data);
        $this->queue_browser_event($event_data, true, false);
    }

    /**
     * Track CustomizeProduct event (Variable product configuration)
     *
     * @param int $product_id
     * @param int $variation_id
     */
    public function track_customize_product($product_id, $variation_id = 0) {
        if (FBPixel_CAPI_Helpers::is_bot_request() || !$this->is_event_enabled('CustomizeProduct') || !class_exists('WooCommerce')) {
            return;
        }

        $target_id = $variation_id ? $variation_id : $product_id;
        $product = wc_get_product($target_id);
        if (!$product) {
            return;
        }

        $price = (float) $product->get_price();
        $event_id = $this->generate_event_id('CustomizeProduct', (string) $target_id);
        $event_data = array(
            'event_name'       => 'CustomizeProduct',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => FBPixel_CAPI_Helpers::get_current_url(),
            'user_data'        => $this->get_user_data(),
            'custom_data'      => array(
                'content_name' => $product->get_name(),
                'content_ids'  => array((string) $target_id),
                'content_type' => 'product',
                'value'        => $price,
                'currency'     => get_woocommerce_currency()
            ),
            'event_id'         => $event_id
        );

        $this->send_event($event_data);
        $this->queue_browser_event($event_data, true, false);
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
     * Get product tags
     *
     * @param int $product_id Product ID
     * @return string Product tags
     */
    private function get_product_tags($product_id) {
        $terms = get_the_terms($product_id, 'product_tag');
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        $tags = array();
        foreach ($terms as $term) {
            $tags[] = $term->name;
        }

        return implode(', ', $tags);
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
        if (!isset($this->settings['enabled_events'])) {
            return true;
        }

        if (isset($this->settings['enabled_events'][$event_name])) {
            return !empty($this->settings['enabled_events'][$event_name]);
        }
        
        // Newly added events default to enabled
        return true;
    }
}
