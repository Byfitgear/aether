<?php
/**
 * Authentication Middleware
 *
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles authentication for proxy requests
 */
class Aether_Proxy_Auth_Middleware implements Aether_Proxy_Middleware
{

    /**
     * Process authentication
     */
    public function process($request, $next)
    {
        // Free version - skip authentication entirely
        return $next($request);
    }

    /**
     * Original auth process (kept for reference)
     * @deprecated Free version skips auth
     */
    private function _original_process($request, $next)
    {
        $auth_config = $request['endpoint_config']['auth'] ?? $request['global_config']['default_auth'] ?? null;

        // Skip auth if explicitly set to false or null
        if ($auth_config === false || $auth_config === null) {
            return $next($request);
        }

        // Parse auth configuration
        $auth_config = $this->parse_auth_config($auth_config, $request['global_config'] ?? []);

        // Get token based on source
        $token = $this->get_token($auth_config['token_source']);

        if (!$token) {
            return new WP_Error('auth_failed', __('Authentication token not found', 'aether'));
        }

        // Apply authentication based on type
        switch ($auth_config['type']) {
            case 'bearer':
                $request['headers']['Authorization'] = 'Bearer ' . $token;
                break;

            case 'header':
                $header_name = $auth_config['header'] ?? 'Authorization';
                $request['headers'][$header_name] = $token;
                break;

            case 'query':
                $param_name = $auth_config['param'] ?? 'api_key';
                $request['url'] = add_query_arg($param_name, $token, $request['url']);
                break;
        }

        return $next($request);
    }

    /**
     * Parse auth configuration from shorthand or full format
     */
    private function parse_auth_config($auth_config, $global_config = [])
    {
        // Handle shorthand string format: "bearer" or "bearer:source"
        if (is_string($auth_config)) {
            $parts = explode(':', $auth_config, 2);
            return [
                'type' => $parts[0],
                'token_source' => $parts[1] ?? $global_config['default_token_source'] ?? 'settings:aether_settings[api_token]'
            ];
        }

        // Handle full object format
        if (is_array($auth_config)) {
            // Ensure token_source is set
            if (!isset($auth_config['token_source'])) {
                $auth_config['token_source'] = $global_config['default_token_source'] ?? 'settings:aether_settings[api_token]';
            }
            return $auth_config;
        }

        // Default to bearer auth
        return [
            'type' => 'bearer',
            'token_source' => $global_config['default_token_source'] ?? 'settings:aether_settings[api_token]'
        ];
    }

    /**
     * Get token from source
     */
    private function get_token($source)
    {
        if (!$source) {
            return null;
        }

        list($type, $key) = explode(':', $source, 2);

        switch ($type) {
            case 'settings':
                // Check if it's an array access pattern
                if (preg_match('/^(.+)\[(.+)\]$/', $key, $matches)) {
                    $option_name = $matches[1];
                    $array_key = $matches[2];
                    $settings = get_option($option_name, []);
                    return isset($settings[$array_key]) ? $settings[$array_key] : null;
                }
                // Get from WordPress options
                return get_option($key);

            case 'env':
                // Get from environment variable or constant
                if (defined($key)) {
                    return constant($key);
                }
                return getenv($key);

            case 'config':
                // Get from config file
                $config = get_option('aether_api_tokens', []);
                return $config[$key] ?? null;

            default:
                return apply_filters('aether_proxy_get_token', null, $type, $key);
        }
    }
}