<?php
/**
 * W3 Pixel CAPI API Client
 * 
 * Handles communication with Facebook Conversions API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class FBPixel_CAPI_API_Client {
    
    /**
     * Facebook Graph API base URL
     */
    const API_BASE_URL = 'https://graph.facebook.com';
    
    /**
     * API version
     */
    const API_VERSION = 'v18.0';
    
    /**
     * Plugin settings
     */
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('fbpixel_capi_settings', array());
    }
    
    /**
     * Send events to Facebook Conversions API
     * 
     * @param array $events Array of event data
     * @param bool $allow_queue Whether to enqueue on failure
     * @param bool $blocking Whether request should block
     * @return array|WP_Error Response data or error
     */
    public function send_events($events, $allow_queue = true, $blocking = true) {
        // Validate settings
        if (empty($this->settings['pixel_id']) || empty($this->settings['access_token'])) {
            return new WP_Error('missing_credentials', __('Pixel ID or Access Token is missing', 'w3-pixel-capi'));
        }
        
        // Prepare API endpoint
        $endpoint = sprintf(
            '%s/%s/%s/events',
            self::API_BASE_URL,
            self::API_VERSION,
            $this->settings['pixel_id']
        );
        
        // Prepare request body
        $body = array(
            'data'          => $events,
            'partner_agent' => 'w3pixelcapi'
        );
        
        // Limited Data Use (LDU) for California Consumer Privacy Act (CCPA) compliance
        if (!empty($this->settings['enable_ldu'])) {
            $body['data_processing_options'] = array('LDU');
            $body['data_processing_options_country'] = 0;
            $body['data_processing_options_state'] = 0;
        }

        // Add test event code if provided
        if (!empty($this->settings['test_event_code'])) {
            $body['test_event_code'] = $this->settings['test_event_code'];
        }
        
        // Prepare request headers
        $headers = array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->settings['access_token']
        );
        
        // Prepare request arguments
        $args = array(
            'method'     => 'POST',
            'headers'    => $headers,
            'body'       => wp_json_encode($body),
            'timeout'    => 30,
            'blocking'   => (bool) $blocking,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );
        
        // Log the request if debug mode is enabled
        if (!empty($this->settings['debug_mode'])) {
            $this->log_request($endpoint, $body, $args);
        }
        
        // Send the request
        $response = wp_remote_post($endpoint, $args);
        
        // Non-blocking requests return early
        if (!$blocking) {
            return array('status' => 'sent_async');
        }

        // Handle response
        if (is_wp_error($response)) {
            $this->log_error('API Request Failed', $response->get_error_message(), $events);
            if ($allow_queue) {
                foreach ($events as $event) {
                    $this->queue_event($event, $response->get_error_message());
                }
            }
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log the response if debug mode is enabled
        if (!empty($this->settings['debug_mode'])) {
            $this->log_response($response_code, $response_body, $events);
        }
        
        // Check for API errors
        if ($response_code >= 400) {
            $error_message = $this->parse_error_response($response_body);
            $this->log_error('API Error', $error_message, $events);
            // Only retry temporary server errors (5xx) or rate limit throttling (429)
            // Dropping 400-499 client errors (invalid token, bad parameters) avoids dead retry loops
            $is_retryable = ($response_code >= 500 || $response_code === 429);
            if ($allow_queue && $is_retryable) {
                foreach ($events as $event) {
                    $this->queue_event($event, $error_message);
                }
            }
            return new WP_Error('api_error', $error_message);
        }
        
        return array(
            'status_code' => $response_code,
            'body'        => json_decode($response_body, true)
        );
    }
    
    /**
     * Send a single event to Facebook Conversions API
     * 
     * @param array $event_data Single event data
     * @param bool $blocking Whether request should block
     * @return array|WP_Error Response data or error
     */
    public function send_event($event_data, $blocking = true) {
        return $this->send_events(array($event_data), true, $blocking);
    }

    /**
     * Queue failed events for retry
     *
     * @param array $event_data
     * @param string $error_message
     */
    public function queue_event($event_data, $error_message = '') {
        global $wpdb;

        $table_name = $this->get_queue_table_name();
        $wpdb->insert(
            $table_name,
            array(
                'event_data'      => wp_json_encode($event_data),
                'attempts'        => 0,
                'last_error'      => $error_message,
                'next_attempt_at' => current_time('mysql'),
                'created_at'      => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s')
        );

        if (!wp_next_scheduled('fbpixel_capi_process_queue')) {
            wp_schedule_single_event(time() + 300, 'fbpixel_capi_process_queue');
        }
    }

    /**
     * Process queued events
     *
     * @param int $limit
     */
    public function process_queue($limit = 10) {
        global $wpdb;

        $table_name = $this->get_queue_table_name();
        $now = current_time('mysql');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
                $now,
                $limit
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $event_data = json_decode($row['event_data'], true);
            if (empty($event_data) || !is_array($event_data)) {
                $wpdb->delete($table_name, array('id' => (int) $row['id']), array('%d'));
                continue;
            }

            $result = $this->send_events(array($event_data), false);
            if (!is_wp_error($result)) {
                $wpdb->delete($table_name, array('id' => (int) $row['id']), array('%d'));
                continue;
            }

            $attempts = (int) $row['attempts'] + 1;

            // Maximum 5 retry attempts (avoids queue bloat on permanent API issues)
            if ($attempts >= 5) {
                $this->log_error('Queue Retry Exhausted', 'Event dropped after 5 failed attempts: ' . $result->get_error_message(), array($event_data));
                $wpdb->delete($table_name, array('id' => (int) $row['id']), array('%d'));
                continue;
            }

            $backoff = min(3600, 300 * $attempts);
            $next_attempt = date('Y-m-d H:i:s', time() + $backoff);

            $wpdb->update(
                $table_name,
                array(
                    'attempts'        => $attempts,
                    'last_error'      => $result->get_error_message(),
                    'next_attempt_at' => $next_attempt
                ),
                array('id' => (int) $row['id']),
                array('%d', '%s', '%s'),
                array('%d')
            );
        }
    }
    
    /**
     * Parse error response from Facebook API
     * 
     * @param string $response_body Response body
     * @return string Error message
     */
    private function parse_error_response($response_body) {
        $decoded = json_decode($response_body, true);
        
        if (isset($decoded['error']['message'])) {
            return $decoded['error']['message'];
        }
        
        if (isset($decoded['error']['error_user_msg'])) {
            return $decoded['error']['error_user_msg'];
        }
        
        return __('Unknown API error occurred', 'w3-pixel-capi');
    }
    
    /**
     * Log API request for debugging
     * 
     * @param string $endpoint API endpoint
     * @param array $body Request body
     * @param array $args Request arguments
     */
    private function log_request($endpoint, $body, $args) {
        $log_data = array(
            'type'      => 'request',
            'endpoint'  => $endpoint,
            'body'      => $body,
            'timestamp' => current_time('mysql')
        );
        
        $this->save_log('API Request', $log_data, 'pending');
    }
    
    /**
     * Log API response for debugging
     * 
     * @param int $status_code HTTP status code
     * @param string $response_body Response body
     * @param array $events Events sent
     */
    private function log_response($status_code, $response_body, $events) {
        $log_data = array(
            'type'        => 'response',
            'status_code' => $status_code,
            'body'        => json_decode($response_body, true),
            'events'      => $events,
            'timestamp'   => current_time('mysql')
        );
        
        $status = ($status_code >= 200 && $status_code < 300) ? 'success' : 'error';
        $this->save_log('API Response', $log_data, $status);
    }
    
    /**
     * Log API error for debugging
     * 
     * @param string $error_type Error type
     * @param string $error_message Error message
     * @param array $events Events being processed
     */
    private function log_error($error_type, $error_message, $events) {
        $log_data = array(
            'type'          => 'error',
            'error_type'    => $error_type,
            'error_message' => $error_message,
            'events'        => $events,
            'timestamp'     => current_time('mysql')
        );
        
        $this->save_log('API Error', $log_data, 'error');
    }
    
    /**
     * Save log entry to database
     * 
     * @param string $event_name Event or log name
     * @param array $data Log data
     * @param string $status Status (success, error, pending)
     */
    private function save_log($event_name, $data, $status) {
        global $wpdb;
        
        $table_name = $this->get_log_table_name();
        
        $wpdb->insert(
            $table_name,
            array(
                'event_name'    => $event_name,
                'event_data'    => wp_json_encode($data),
                'response_data' => isset($data['body']) ? wp_json_encode($data['body']) : null,
                'status'        => $status,
                'created_at'    => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Get queue table name
     *
     * @return string
     */
    private function get_queue_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'fbpixel_capi_queue';
    }

    /**
     * Get log table name
     * 
     * @return string Log table name
     */
    private function get_log_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'fbpixel_capi_logs';
    }
    /**
     * Test API connection with a test event
     *
     * @return array|WP_Error
     */
    public function test_connection($pixel_id = '', $access_token = '') {
        $pixel_id = !empty($pixel_id) ? trim($pixel_id) : (!empty($this->settings['pixel_id']) ? $this->settings['pixel_id'] : '');
        $access_token = !empty($access_token) ? trim($access_token) : (!empty($this->settings['access_token']) ? $this->settings['access_token'] : '');

        if (empty($pixel_id) || empty($access_token)) {
            return new WP_Error('missing_credentials', __('Pixel ID or Access Token is missing', 'w3-pixel-capi'));
        }

        $orig_settings = $this->settings;
        $this->settings['pixel_id'] = $pixel_id;
        $this->settings['access_token'] = $access_token;

        $test_event = array(
            'event_name'       => 'PageView',
            'event_time'       => time(),
            'action_source'    => 'website',
            'event_source_url' => home_url('/'),
            'user_data'        => array(
                'client_ip_address' => FBPixel_CAPI_Helpers::get_client_ip(),
                'client_user_agent' => FBPixel_CAPI_Helpers::get_user_agent(),
            ),
            'custom_data'      => array(
                'test_connection' => true
            ),
            'event_id'         => FBPixel_CAPI_Helpers::guidv4()
        );

        $result = $this->send_events(array($test_event), false, true);
        $this->settings = $orig_settings;

        return $result;
    }

    /**
     * Get recent logs
     *
     * @param int $limit
     * @return array
     */
    public function get_recent_logs($limit = 20) {
        global $wpdb;
        $table_name = $this->get_log_table_name();
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
    }

    /**
     * Clear all logs
     *
     * @return bool
     */
    public function clear_logs() {
        global $wpdb;
        $table_name = $this->get_log_table_name();
        return (bool) $wpdb->query("TRUNCATE TABLE {$table_name}");
    }

    /**
     * Prune old logs to avoid database bloat
     *
     * @param int $days
     * @return int Number of deleted rows
     */
    public function cleanup_old_logs($days = 7) {
        global $wpdb;
        $table_name = $this->get_log_table_name();
        $days = max(1, (int) $days);
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }

}
