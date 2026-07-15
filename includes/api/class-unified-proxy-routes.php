<?php
/**
 * Unified Proxy REST API Routes
 *
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles unified proxy REST API routes
 */
class Aether_Unified_Proxy_Routes {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Proxy instance
     */
    private $proxy;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->proxy = Aether_Unified_Proxy::get_instance();
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    /**
     * Register all proxy routes
     */
    public function register_routes() {
        $config = $this->proxy->get_proxy_config();
        
        foreach ($config as $proxy_name => $proxy_config) {
            foreach ($proxy_config['endpoints'] as $endpoint_name => $endpoint) {
                $this->register_proxy_route($proxy_name, $endpoint_name, $endpoint);
            }
        }
        
    }
    
    /**
     * Register a single proxy route
     */
    private function register_proxy_route($proxy_name, $endpoint_name, $endpoint_config) {
        // New unified route pattern: /proxy/{proxy}/{endpoint}
        $route = sprintf('proxy/%s/%s', $proxy_name, $endpoint_name);
        
        // Handle path parameters
        if (strpos($endpoint_config['path'], '{') !== false) {
            // Extract parameter names from path
            preg_match_all('/\{([^}]+)\}/', $endpoint_config['path'], $matches);
            foreach ($matches[1] as $param) {
                $route .= '/(?P<' . $param . '>[^/]+)';
            }
        }
        
        register_rest_route('aether/v1', $route, [
            'methods' => $endpoint_config['method'],
            'callback' => [$this, 'handle_proxy_request'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => $this->get_endpoint_args($proxy_name, $endpoint_name, $endpoint_config)
        ]);
    }
    
    /**
     * Get endpoint arguments schema
     */
    private function get_endpoint_args($proxy_name, $endpoint_name, $endpoint_config) {
        $args = [
            '_proxy' => [
                'default' => $proxy_name,
                'required' => true
            ],
            '_endpoint' => [
                'default' => $endpoint_name,
                'required' => true
            ]
        ];
        
        // Add path parameters if present
        if (strpos($endpoint_config['path'], '{') !== false) {
            preg_match_all('/\{([^}]+)\}/', $endpoint_config['path'], $matches);
            foreach ($matches[1] as $param) {
                $args[$param] = [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ];
            }
        }
        
        return $args;
    }
    
    /**
     * Handle proxy request
     */
    public function handle_proxy_request($request) {
        $proxy_name = $request->get_param('_proxy');
        $endpoint_name = $request->get_param('_endpoint');
        
        // Get request data
        $args = [
            'params' => $request->get_query_params(),
            'data' => $request->get_json_params() ?? $request->get_body_params(),
            'path_params' => []
        ];
        
        // Remove internal parameters
        unset($args['params']['_proxy']);
        unset($args['params']['_endpoint']);
        
        // Extract path parameters
        $route_params = $request->get_url_params();
        foreach ($route_params as $key => $value) {
            if ($key !== '_proxy' && $key !== '_endpoint') {
                $args['path_params'][$key] = $value;
            }
        }
        
        // Make proxy request
        $response = $this->proxy->request($proxy_name, $endpoint_name, $args);
        
        // Handle errors
        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $response->get_error_message(),
                'code' => $response->get_error_code()
            ], 400);
        }
        
        // Return response
        return new WP_REST_Response($response['data'], $response['code']);
    }
    
    /**
     * Check permission
     */
    public function check_permission() {
        return current_user_can('edit_posts');
    }
}