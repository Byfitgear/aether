<?php
/**
 * API Routes Base Class
 * 
 * Base class for all API route modules
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Base class for API route modules
 */
abstract class Aether_API_Routes_Base {
    
    /**
     * API namespace
     */
    const NAMESPACE = 'aether/v1';
    
    /**
     * Register routes for this module
     */
    abstract public function register_routes();
    
    /**
     * Get the route namespace
     *
     * @return string
     */
    protected function get_namespace() {
        return self::NAMESPACE;
    }
    
    /**
     * Register a single route
     *
     * @param string $route
     * @param array $args
     * @return bool True on success, false on failure
     */
    protected function register_route($route, $args) {
        return register_rest_route($this->get_namespace(), $route, $args);
    }
    
    /**
     * Create success response
     *
     * @param mixed $data
     * @param string $message
     * @param int $status
     * @return WP_REST_Response
     */
    protected function success_response($data = null, $message = '', $status = 200) {
        $response = ['success' => true];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        if (!empty($message)) {
            $response['message'] = $message;
        }
        
        return new WP_REST_Response($response, $status);
    }
    
    /**
     * Create error response
     *
     * @param string $message
     * @param int $status
     * @param array $data Additional data to include
     * @return WP_REST_Response
     */
    protected function error_response($message, $status = 400, $data = null) {
        $response = [
            'success' => false,
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        return new WP_REST_Response($response, $status);
    }
    
    /**
     * Validate numeric parameter
     *
     * @param mixed $param
     * @return bool
     */
    public function validate_numeric($param) {
        return is_numeric($param);
    }
    
    /**
     * Validate positive numeric parameter
     *
     * @param mixed $param
     * @return bool
     */
    public function validate_positive_numeric($param) {
        return is_numeric($param) && $param > 0;
    }
    
    /**
     * Check if user has permission to access the endpoint
     *
     * @return bool
     */
    public function check_permission() {
        return current_user_can('edit_posts');
    }
}