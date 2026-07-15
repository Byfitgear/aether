<?php
/**
 * Proxy Middleware Interface
 *
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for proxy middleware
 */
interface Aether_Proxy_Middleware {
    
    /**
     * Process the request
     *
     * @param array $request The request array
     * @param callable $next The next middleware
     * @return array|WP_Error The response
     */
    public function process($request, $next);
}