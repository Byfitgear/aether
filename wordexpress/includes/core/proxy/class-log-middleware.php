<?php
/**
 * Log Middleware
 *
 * @package Zeroy
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles logging for proxy requests
 */
class ZeroY_Proxy_Log_Middleware implements ZeroY_Proxy_Middleware {
    
    /**
     * Process logging
     */
    public function process($request, $next) {
        $start_time = microtime(true);
        
        // Log request
        $this->log_request($request);
        
        // Execute request
        $response = $next($request);
        
        // Calculate duration
        $duration = microtime(true) - $start_time;
        
        // Log response
        $this->log_response($request, $response, $duration);
        
        return $response;
    }
    
    /**
     * Log request details
     */
    private function log_request($request) {
        $log_data = [
            'action' => 'proxy_request',
            'environment' => $request['environment'] ?? 'unknown',
            'proxy' => $request['proxy_config']['name'] ?? 'unknown',
            'endpoint' => $request['endpoint_config']['name'] ?? 'unknown',
            'method' => $request['method'],
            'url' => $request['url']
        ];

        // Don't log sensitive headers
        $safe_headers = $this->get_safe_headers($request['headers']);
        if (!empty($safe_headers)) {
            $log_data['headers'] = $safe_headers;
        }

        error_log('[Zeroy Proxy] Request: ' . json_encode($log_data));
    }
    
    /**
     * Log response details
     */
    private function log_response($request, $response, $duration) {
        $log_data = [
            'action' => 'proxy_response',
            'proxy' => $request['proxy_config']['name'] ?? 'unknown',
            'endpoint' => $request['endpoint_config']['name'] ?? 'unknown',
            'duration' => round($duration, 3) . 's'
        ];
        
        if (is_wp_error($response)) {
            $log_data['error'] = $response->get_error_message();
            $log_data['error_code'] = $response->get_error_code();
        } else {
            $log_data['status'] = $response['code'] ?? 'unknown';
            $log_data['success'] = $response['success'] ?? false;
            $log_data['cache'] = $response['headers']['X-Cache'] ?? 'NONE';
        }
        
        error_log('[Zeroy Proxy] Response: ' . json_encode($log_data));
    }
    
    /**
     * Get headers safe for logging (remove sensitive data)
     */
    private function get_safe_headers($headers) {
        $sensitive_headers = ['authorization', 'x-api-key', 'x-api-token', 'cookie'];
        $safe_headers = [];
        
        foreach ($headers as $key => $value) {
            $lower_key = strtolower($key);
            if (in_array($lower_key, $sensitive_headers)) {
                $safe_headers[$key] = '[REDACTED]';
            } else {
                $safe_headers[$key] = $value;
            }
        }
        
        return $safe_headers;
    }
}