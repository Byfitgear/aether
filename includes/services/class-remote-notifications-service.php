<?php
/**
 * Remote Notifications Service
 *
 * Fetches and manages remote notifications from aether server
 *
 * @package aether
 */

defined('ABSPATH') || exit;

class Aether_Remote_Notifications_Service {

    /**
     * API base URLs by environment
     */
    const API_BASE_URLS = [
        'production' => 'https://www.aether.app/api',
        'development' => 'http://localhost:5173/api',
        'staging' => 'https://aether-staging.yansir.workers.dev/api',
    ];

    /**
     * Notifications endpoint path
     */
    const NOTIFICATIONS_PATH = '/wp-updates/notifications';

    /**
     * Cache transient key prefix
     */
    const CACHE_KEY_PREFIX = 'aether_remote_notifications';

    /**
     * Cache duration in seconds (6 hours)
     */
    const CACHE_DURATION = 6 * HOUR_IN_SECONDS;

    /**
     * Backoff transient key prefix (for failed requests)
     */
    const BACKOFF_KEY_PREFIX = 'aether_notifications_backoff';

    /**
     * Backoff duration in seconds (1 hour)
     */
    const BACKOFF_DURATION = HOUR_IN_SECONDS;

    /**
     * Dismissed notifications user meta key
     */
    const DISMISSED_META_KEY = 'aether_dismissed_notifications';

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Register AJAX handlers for dismissing notifications
        add_action('wp_ajax_aether_dismiss_notification', [$this, 'ajax_dismiss_notification']);
    }

    /**
     * Get notifications for current user
     *
     * @param bool $force_refresh Whether to bypass cache
     * @return array
     */
    public function get_notifications($force_refresh = false) {
        // Check if notifications are disabled
        if (defined('AETHER_DISABLE_NOTIFICATIONS') && AETHER_DISABLE_NOTIFICATIONS) {
            return [];
        }

        // Get environment-specific cache keys
        $cache_key = $this->get_cache_key();
        $backoff_key = $this->get_backoff_key();

        // Check backoff period
        if (!$force_refresh && get_transient($backoff_key)) {
            return [];
        }

        // Try to get cached notifications
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                return $this->filter_notifications($cached);
            }
        }

        // Fetch from remote
        $notifications = $this->fetch_remote_notifications();

        if ($notifications === false) {
            // Set backoff on failure
            set_transient($backoff_key, 1, self::BACKOFF_DURATION);
            return [];
        }

        // Cache successful response
        set_transient($cache_key, $notifications, self::CACHE_DURATION);

        return $this->filter_notifications($notifications);
    }

    /**
     * Fetch notifications from remote server
     *
     * @return array|false
     */
    private function fetch_remote_notifications() {
        $params = [
            'plugin_version' => AETHER_VERSION,
            'theme_version' => $this->get_aether_theme_version(),
            'wp_version' => get_bloginfo('version'),
        ];

        // site_url is optional
        $site_url = home_url();
        if (!empty($site_url)) {
            $params['site_url'] = $site_url;
        }

        // Get API base URL based on environment
        $base_url = $this->get_api_base_url();
        $url = add_query_arg($params, $base_url . self::NOTIFICATIONS_PATH);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Aether Notifications] Fetching from: ' . $url);
            error_log('[Aether Notifications] Environment: ' . $this->get_environment());
        }

        $args = [
            'timeout' => 5,
            'redirection' => 2,
            'headers' => [
                'User-Agent' => sprintf(
                    'Aether/%s (WP/%s; PHP/%s; %s)',
                    AETHER_VERSION,
                    get_bloginfo('version'),
                    PHP_VERSION,
                    home_url()
                ),
            ],
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Aether] Notifications fetch failed: ' . $response->get_error_message());
            }
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['notifications'])) {
            return false;
        }

        return $data['notifications'];
    }

    /**
     * Filter notifications based on dismissed status
     *
     * Note: Version filtering and expiration checking is done server-side.
     * Client only needs to filter out dismissed notifications.
     *
     * @param array $notifications
     * @return array
     */
    private function filter_notifications($notifications) {
        if (!is_array($notifications)) {
            return [];
        }

        $dismissed = $this->get_dismissed_notifications();
        $filtered = [];

        foreach ($notifications as $notification) {
            // Skip if already dismissed
            if (in_array($notification['id'] ?? '', $dismissed, true)) {
                continue;
            }

            $filtered[] = $notification;
        }

        // Sort by priority (high first)
        usort($filtered, function($a, $b) {
            $priority_order = ['high' => 0, 'normal' => 1];
            $a_priority = $priority_order[$a['priority'] ?? 'normal'] ?? 1;
            $b_priority = $priority_order[$b['priority'] ?? 'normal'] ?? 1;
            return $a_priority - $b_priority;
        });

        return $filtered;
    }

    /**
     * Get dismissed notification IDs for current user
     *
     * @return array
     */
    private function get_dismissed_notifications() {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return [];
        }

        $dismissed = get_user_meta($user_id, self::DISMISSED_META_KEY, true);
        return is_array($dismissed) ? $dismissed : [];
    }

    /**
     * Dismiss a notification for current user
     *
     * @param string $notification_id
     * @return bool
     */
    public function dismiss_notification($notification_id) {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return false;
        }

        $dismissed = $this->get_dismissed_notifications();

        if (!in_array($notification_id, $dismissed, true)) {
            $dismissed[] = $notification_id;
            update_user_meta($user_id, self::DISMISSED_META_KEY, $dismissed);
        }

        return true;
    }

    /**
     * AJAX handler for dismissing notifications
     */
    public function ajax_dismiss_notification() {
        // Verify nonce
        if (!check_ajax_referer('aether-editor', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        // Check capability
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $notification_id = sanitize_text_field($_POST['notification_id'] ?? '');

        if (empty($notification_id)) {
            wp_send_json_error(['message' => 'Missing notification ID'], 400);
        }

        $result = $this->dismiss_notification($notification_id);

        if ($result) {
            wp_send_json_success(['dismissed' => true]);
        } else {
            wp_send_json_error(['message' => 'Failed to dismiss notification'], 500);
        }
    }

    /**
     * Get aether theme version if active
     *
     * @return string
     */
    private function get_aether_theme_version() {
        $theme = wp_get_theme();

        // Check current theme
        if (strtolower($theme->get('Name')) === 'aether') {
            return $theme->get('Version');
        }

        // Check parent theme
        $parent = $theme->parent();
        if ($parent && strtolower($parent->get('Name')) === 'aether') {
            return $parent->get('Version');
        }

        return '';
    }

    /**
     * Get API base URL based on environment
     *
     * Supports:
     * - dev=1 (URL param or referer) → development (localhost)
     * - dev=2 (URL param or referer) → staging
     * - default → production
     *
     * @return string
     */
    private function get_api_base_url() {
        $env = $this->get_environment();
        return self::API_BASE_URLS[$env] ?? self::API_BASE_URLS['production'];
    }

    /**
     * Get current environment from URL parameters
     *
     * @return string 'development', 'staging', or 'production'
     */
    private function get_environment() {
        // Check referer first (for AJAX requests)
        $referer = wp_get_referer();
        if ($referer && strpos($referer, 'dev=') !== false) {
            if (strpos($referer, 'dev=1') !== false) {
                return 'development';
            }
            if (strpos($referer, 'dev=2') !== false) {
                return 'staging';
            }
        }

        // Check current request URL
        if (isset($_GET['dev'])) {
            $dev = sanitize_text_field($_GET['dev']);
            if ($dev === '1') {
                return 'development';
            }
            if ($dev === '2') {
                return 'staging';
            }
        }

        // Check AETHER_DEV_MODE constant for local development
        if (defined('AETHER_DEV_MODE') && AETHER_DEV_MODE && defined('WP_DEBUG') && WP_DEBUG) {
            return 'development';
        }

        return 'production';
    }

    /**
     * Get cache key with environment suffix
     *
     * @return string
     */
    private function get_cache_key() {
        $env = $this->get_environment();
        return self::CACHE_KEY_PREFIX . '_' . $env;
    }

    /**
     * Get backoff key with environment suffix
     *
     * @return string
     */
    private function get_backoff_key() {
        $env = $this->get_environment();
        return self::BACKOFF_KEY_PREFIX . '_' . $env;
    }

    /**
     * Clear notification cache (useful for testing)
     */
    public function clear_cache() {
        // Clear cache for current environment
        delete_transient($this->get_cache_key());
        delete_transient($this->get_backoff_key());

        // Also clear all environment caches
        foreach (['production', 'staging', 'development'] as $env) {
            delete_transient(self::CACHE_KEY_PREFIX . '_' . $env);
            delete_transient(self::BACKOFF_KEY_PREFIX . '_' . $env);
        }
    }

    /**
     * Get notifications for REST API response
     *
     * @return array
     */
    public function get_notifications_for_api() {
        $notifications = $this->get_notifications();

        return [
            'notifications' => $notifications,
            'count' => count($notifications),
            'has_high_priority' => !empty(array_filter($notifications, function($n) {
                return ($n['priority'] ?? 'normal') === 'high';
            })),
        ];
    }
}

// Initialize singleton
Aether_Remote_Notifications_Service::getInstance();
