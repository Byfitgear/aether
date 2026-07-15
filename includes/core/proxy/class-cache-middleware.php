<?php
/**
 * Cache Middleware
 *
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles caching for proxy requests
 */
class Aether_Proxy_Cache_Middleware implements Aether_Proxy_Middleware {
    
    /**
     * Process caching
     */
    public function process($request, $next) {
        $cache_config = $request['endpoint_config']['cache'] ?? null;
        
        // Skip if caching not enabled or not a GET request
        if (!$cache_config || empty($cache_config['enabled']) || $request['method'] !== 'GET') {
            return $next($request);
        }
        
        // Generate cache key
        $cache_key = $this->generate_cache_key($request);
        
        // Try to get from cache
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            // Add cache hit header
            $cached['headers']['X-Cache'] = 'HIT';
            return $cached;
        }
        
        // Execute request
        $response = $next($request);
        
        // Cache successful responses
        if (!is_wp_error($response) && $response['success']) {
            $ttl = $cache_config['ttl'] ?? 300; // Default 5 minutes
            set_transient($cache_key, $response, $ttl);
        }
        
        // Add cache miss header
        if (is_array($response)) {
            $response['headers']['X-Cache'] = 'MISS';
        }
        
        return $response;
    }
    
    /**
     * Generate cache key
     */
    private function generate_cache_key($request) {
        $key_parts = [
            'aether_proxy',
            $request['proxy_config']['name'] ?? 'unknown',
            $request['endpoint_config']['name'] ?? 'unknown',
            md5($request['url'])
        ];
        
        // Include relevant request data in cache key
        if (!empty($request['args']['params'])) {
            $key_parts[] = md5(serialize($request['args']['params']));
        }
        
        return implode('_', $key_parts);
    }
    
    /**
     * Clear cache for a specific proxy/endpoint
     */
    public static function clear_cache($proxy_name = null, $endpoint_name = null) {
        global $wpdb;
        
        $pattern = 'aether_proxy';
        if ($proxy_name) {
            $pattern .= '_' . $proxy_name;
            if ($endpoint_name) {
                $pattern .= '_' . $endpoint_name;
            }
        }
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_' . $pattern . '%'
        ));
    }
}