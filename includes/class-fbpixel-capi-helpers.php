<?php
/**
 * W3 Pixel CAPI Helpers
 * 
 * Utility functions and helpers for high Event Match Quality (EMQ),
 * cookie persistence, and robust deduplication.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FBPixel_CAPI_Helpers {
    
    /**
     * Generate an RFC 4122 compliant UUIDv4 for event deduplication
     *
     * @return string
     */
    public static function guidv4() {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Get client IP address resolving reverse proxies and Cloudflare
     * 
     * @return string Client IP address
     */
    public static function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_TRUE_CLIENT_IP',       // Akamai / Cloudflare Enterprise
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer / proxy chain
            'HTTP_CLIENT_IP',            // Proxy
            'REMOTE_ADDR'                // Direct TCP peer
        );
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = sanitize_text_field(wp_unslash($_SERVER[$key]));
                
                // If comma-separated chain, take the first (originating client) IP
                $ip = trim(current(preg_split('/,/', $raw)));
                
                // Strip port numbers from IPv4 (1.2.3.4:56789) and bracketed IPv6 ([::1]:56789)
                $ip = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+):.*|\[([^\]]+)\].*/', '$1$2', $ip);
                
                // Validate IP address (IPv4 or IPv6)
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';
    }
    
    /**
     * Get user agent
     * 
     * @return string User agent
     */
    public static function get_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    }
    
    /**
     * Get current page URL
     * 
     * @return string Current URL
     */
    public static function get_current_url() {
        if (is_admin()) {
            return admin_url();
        }

        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : parse_url(home_url(), PHP_URL_HOST);
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        return esc_url_raw($scheme . '://' . $host . $request_uri);
    }

    /**
     * Get cookie domain so cookies span subdomains
     *
     * @return string
     */
    public static function get_cookie_domain() {
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
            return COOKIE_DOMAIN;
        }

        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : parse_url(home_url(), PHP_URL_HOST);
        if (empty($host)) {
            return '';
        }

        // Strip port if present
        $host = preg_replace('/:\d+$/', '', $host);

        // Strip www. prefix
        $host = preg_replace('/^www\./i', '', $host);

        // Don\'t set domain for localhost or raw IP
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        return '.' . $host;
    }
    
    /**
     * Sanitize and validate email
     * 
     * @param string $email Email address
     * @return string|false Sanitized email or false if invalid
     */
    public static function sanitize_email($email) {
        $email = sanitize_email($email);
        return is_email($email) ? $email : false;
    }
    
    /**
     * Sanitize phone number (digits only)
     * 
     * @param string $phone Phone number
     * @return string Sanitized phone number (digits only)
     */
    public static function sanitize_phone($phone) {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Check if a value looks like a SHA256 hash
     *
     * @param string $value
     * @return bool
     */
    public static function is_sha256($value) {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value);
    }
    
    /**
     * Hash data using SHA256
     * 
     * @param string $data Data to hash
     * @return string Hashed data
     */
    public static function hash_data($data) {
        return hash('sha256', strtolower(trim($data)));
    }

    /**
     * Normalize and hash string data if needed (avoids double hashing)
     *
     * @param string $data
     * @return string|null
     */
    public static function normalize_and_hash($data) {
        if ($data === null || $data === '') {
            return null;
        }
        $data = trim((string) $data);
        if ($data === '') {
            return null;
        }
        if (self::is_sha256($data)) {
            return strtolower($data);
        }

        if (function_exists('mb_strtolower')) {
            $data = mb_strtolower($data, 'UTF-8');
        } else {
            $data = strtolower($data);
        }

        return self::hash_data($data);
    }

    /**
     * Normalize and hash phone number if needed (avoids double hashing)
     *
     * @param string $phone
     * @return string|null
     */
    public static function normalize_and_hash_phone($phone) {
        if ($phone === null || $phone === '') {
            return null;
        }
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }
        if (self::is_sha256($phone)) {
            return strtolower($phone);
        }
        $digits = self::sanitize_phone($phone);
        if ($digits === '') {
            return null;
        }
        return hash('sha256', $digits);
    }
    
    /**
     * Validate Pixel ID format
     * 
     * @param string $pixel_id Pixel ID
     * @return bool Whether Pixel ID is valid
     */
    public static function validate_pixel_id($pixel_id) {
        return preg_match('/^[0-9]{15,16}$/', $pixel_id);
    }
    
    /**
     * Validate access token format
     * 
     * @param string $access_token Access token
     * @return bool Whether access token is valid
     */
    public static function validate_access_token($access_token) {
        return strlen($access_token) > 50 && preg_match('/^[a-zA-Z0-9_-]+$/', $access_token);
    }

    /**
     * Get or generate a persistent External ID for guests
     *
     * @return string SHA-256 hashed 16-byte random token
     */
    public static function get_pbid() {
        if (!empty($_COOKIE['w3_pbid'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['w3_pbid']));
        }

        $ip = self::get_client_ip();
        $transient_key = 'w3_pbid_' . md5($ip);
        $cached = get_transient($transient_key);
        if (!empty($cached)) {
            return $cached;
        }

        $random = bin2hex(random_bytes(16));
        $pbid = hash('sha256', $random);

        self::set_cookie('w3_pbid', $pbid, 365);
        set_transient($transient_key, $pbid, 600);

        return $pbid;
    }

    /**
     * Ensure persistent external ID exists
     *
     * @return string
     */
    public static function ensure_pbid() {
        return self::get_pbid();
    }

    /**
     * Extract user data persisted from form interactions
     *
     * @param string $em Existing email
     * @param string $fn Existing first name
     * @param string $ln Existing last name
     * @param string $ph Existing phone
     * @return array
     */
    public static function get_persistence_user_data($em = '', $fn = '', $ln = '', $ph = '') {
        if (!empty($_COOKIE['w3_advanced_form_data'])) {
            $data = json_decode(stripslashes($_COOKIE['w3_advanced_form_data']), true);
            if (is_array($data)) {
                if (empty($em) && !empty($data['email'])) {
                    $em = sanitize_email($data['email']);
                }
                if (empty($fn) && !empty($data['first_name'])) {
                    $fn = sanitize_text_field($data['first_name']);
                }
                if (empty($ln) && !empty($data['last_name'])) {
                    $ln = sanitize_text_field($data['last_name']);
                }
                if (empty($ph) && !empty($data['phone'])) {
                    $ph = sanitize_text_field($data['phone']);
                }
            }
        }

        return array(
            'em' => $em,
            'fn' => $fn,
            'ln' => $ln,
            'ph' => $ph
        );
    }
    
    /**
     * Get WordPress user data
     * 
     * @param int $user_id User ID (optional, defaults to current user)
     * @return array User data
     */
    public static function get_wp_user_data($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return array();
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            return array();
        }
        
        $user_data = array();
        
        if (!empty($user->user_email)) {
            $hashed = self::normalize_and_hash($user->user_email);
            if ($hashed) {
                $user_data['em'] = $hashed;
            }
        }
        
        if (!empty($user->first_name)) {
            $hashed = self::normalize_and_hash($user->first_name);
            if ($hashed) {
                $user_data['fn'] = $hashed;
            }
        }
        
        if (!empty($user->last_name)) {
            $hashed = self::normalize_and_hash($user->last_name);
            if ($hashed) {
                $user_data['ln'] = $hashed;
            }
        }
        
        return $user_data;
    }
    
    /**
     * Get WooCommerce customer data
     * 
     * @param WC_Customer $customer Customer object
     * @return array Customer data
     */
    public static function get_wc_customer_data($customer) {
        if (!$customer || !class_exists('WooCommerce')) {
            return array();
        }
        
        $customer_data = array();
        
        $email = $customer->get_email();
        if (!empty($email)) {
            $hashed = self::normalize_and_hash($email);
            if ($hashed) {
                $customer_data['em'] = $hashed;
            }
        }
        
        $first_name = $customer->get_first_name();
        if (!empty($first_name)) {
            $hashed = self::normalize_and_hash($first_name);
            if ($hashed) {
                $customer_data['fn'] = $hashed;
            }
        }
        
        $last_name = $customer->get_last_name();
        if (!empty($last_name)) {
            $hashed = self::normalize_and_hash($last_name);
            if ($hashed) {
                $customer_data['ln'] = $hashed;
            }
        }
        
        $phone = $customer->get_billing_phone();
        if (!empty($phone)) {
            $hashed = self::normalize_and_hash_phone($phone);
            if ($hashed) {
                $customer_data['ph'] = $hashed;
            }
        }
        
        $city = $customer->get_billing_city();
        if (!empty($city)) {
            $hashed = self::normalize_and_hash($city);
            if ($hashed) {
                $customer_data['ct'] = $hashed;
            }
        }
        
        $state = $customer->get_billing_state();
        if (!empty($state)) {
            $hashed = self::normalize_and_hash($state);
            if ($hashed) {
                $customer_data['st'] = $hashed;
            }
        }
        
        $postcode = $customer->get_billing_postcode();
        if (!empty($postcode)) {
            $hashed = self::normalize_and_hash($postcode);
            if ($hashed) {
                $customer_data['zp'] = $hashed;
            }
        }
        
        $country = $customer->get_billing_country();
        if (!empty($country)) {
            $hashed = self::normalize_and_hash($country);
            if ($hashed) {
                $customer_data['country'] = $hashed;
            }
        }
        
        return $customer_data;
    }

    /**
     * Lock Facebook cookies into WooCommerce order meta during checkout
     * This ensures payment gateway webhooks (PayPal, Stripe) retain attribution
     *
     * @param int|WC_Order $order_param
     */
    public static function save_fb_cookies_to_order($order_param) {
        $order = ($order_param instanceof \WC_Order) ? $order_param : (function_exists('wc_get_order') ? wc_get_order($order_param) : null);
        if (!$order) {
            return;
        }

        $data = array(
            'fbp' => self::ensure_fbp(),
            'fbc' => self::ensure_fbc(),
        );

        $order->update_meta_data('_w3_fb_cookie', $data);
        $order->save();
    }

    /**
     * Retrieve Facebook cookies locked in WooCommerce order meta
     *
     * @param WC_Order $order
     * @return array Array with fbp and fbc keys
     */
    public static function get_fb_cookies_from_order($order) {
        $fbp = '';
        $fbc = '';

        if ($order && is_a($order, 'WC_Order')) {
            $saved = $order->get_meta('_w3_fb_cookie', true);
            if (!empty($saved) && is_array($saved)) {
                if (!empty($saved['fbp'])) {
                    $fbp = $saved['fbp'];
                }
                if (!empty($saved['fbc'])) {
                    $fbc = $saved['fbc'];
                }
            }
        }

        if (empty($fbp)) {
            $fbp = self::get_facebook_browser_id();
        }
        if (empty($fbc)) {
            $fbc = self::get_facebook_click_id();
        }

        return array(
            'fbp' => $fbp,
            'fbc' => $fbc
        );
    }
    
    /**
     * Check if WooCommerce is active
     * 
     * @return bool Whether WooCommerce is active
     */
    public static function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }
    
    /**
     * Get currency code
     * 
     * @return string Currency code
     */
    public static function get_currency() {
        if (self::is_woocommerce_active()) {
            return get_woocommerce_currency();
        }
        
        return 'USD';
    }
    
    /**
     * Format price for Facebook
     * 
     * @param float $price Price
     * @return float Formatted price
     */
    public static function format_price($price) {
        return round((float) $price, 2);
    }
    
    /**
     * Get page type
     * 
     * @return string Page type
     */
    public static function get_page_type() {
        if (is_front_page()) {
            return 'home';
        } elseif (is_shop() || is_product_category() || is_product_tag()) {
            return 'category';
        } elseif (is_product()) {
            return 'product';
        } elseif (is_cart()) {
            return 'cart';
        } elseif (is_checkout()) {
            return 'checkout';
        } elseif (is_account_page()) {
            return 'account';
        } elseif (is_search()) {
            return 'search';
        } elseif (is_404()) {
            return '404';
        } else {
            return 'other';
        }
    }
    
    /**
     * Log message to WordPress debug log
     * 
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     */
    public static function log($message, $level = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[Facebook Pixel CAPI] [%s] %s', strtoupper($level), $message));
        }
    }
    
    /**
     * Check if current request is from a bot/crawler
     * 
     * @return bool Whether request is from a bot
     */
    public static function is_bot_request() {
        $user_agent = self::get_user_agent();
        
        $bot_patterns = array(
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
            'googlebot', 'bingbot', 'slurp', 'duckduckbot',
            'baiduspider', 'yandexbot', 'facebookexternalhit'
        );
        
        foreach ($bot_patterns as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get Facebook click ID from URL or cookie
     * 
     * @return string|null Facebook click ID
     */
    public static function get_facebook_click_id() {
        if (!empty($_GET['fbclid'])) {
            return sanitize_text_field(wp_unslash($_GET['fbclid']));
        }
        
        if (!empty($_COOKIE['_fbc'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
        }
        
        return null;
    }
    
    /**
     * Get Facebook browser ID from cookie
     * 
     * @return string|null Facebook browser ID
     */
    public static function get_facebook_browser_id() {
        if (!empty($_COOKIE['_fbp'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
        }
        
        return null;
    }

    /**
     * Ensure _fbp cookie exists and return its value
     *
     * @return string|null
     */
    public static function ensure_fbp() {
        if (!empty($_COOKIE['_fbp'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
        }

        $fbp = 'fb.1.' . time() . '.' . wp_rand(1000000000, 9999999999);
        self::set_cookie('_fbp', $fbp, 730); // 2 years
        return $fbp;
    }

    /**
     * Ensure _fbc cookie exists from fbclid and return its value
     *
     * @return string|null
     */
    public static function ensure_fbc() {
        if (!empty($_COOKIE['_fbc'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
        }

        if (!empty($_GET['fbclid'])) {
            $fbclid = sanitize_text_field(wp_unslash($_GET['fbclid']));
            $fbc = 'fb.1.' . time() . '.' . $fbclid;
            self::set_cookie('_fbc', $fbc, 90); // 90 days
            return $fbc;
        }

        return null;
    }

    /**
     * Set a cookie with root domain and secure attributes
     *
     * @param string $name
     * @param string $value
     * @param int $days
     */
    public static function set_cookie($name, $value, $days = 90) {
        if (headers_sent()) {
            return;
        }

        $expire = time() + (int) $days * DAY_IN_SECONDS;
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = self::get_cookie_domain();
        $secure = is_ssl();
        $httponly = false;

        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, array(
                'expires' => $expire,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax'
            ));
        } else {
            setcookie($name, $value, $expire, $path . '; samesite=Lax', $domain, $secure, $httponly);
        }

        $_COOKIE[$name] = $value;
    }
}
