<?php
/**
 * Fields API Routes
 * 
 * Thin controller for field discovery REST API endpoints
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Fields API Routes Class
 */
class Aether_API_Routes_Fields extends Aether_API_Routes_Base {
    
    /**
     * Field Discovery API service
     */
    private $field_api;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->field_api = new Aether_Field_Discovery_API();
    }
    
    /**
     * Register field routes
     */
    public function register_routes() {
        // Get fields for a specific post type
        $this->register_route('/fields/(?P<post_type>\w+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_fields'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'post_type' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Post type to get fields for',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'include_system' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include system fields',
                ],
                'optimize_for_ai' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Optimize response for AI usage',
                ],
            ],
        ]);
        
        // Get all post types
        $this->register_route('/fields/post-types', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_post_types'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'include_system' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include system post types',
                ],
            ],
        ]);
        
        // Get AI-optimized context
        $this->register_route('/fields/ai-context', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_ai_context'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
        ]);
    }
    
    /**
     * Get fields for a post type
     */
    public function get_fields($request) {
        $post_type = $request->get_param('post_type');
        
        // Verify post type exists
        if (!post_type_exists($post_type)) {
            return $this->error_response(__('文章类型不存在', 'aether'), 404);
        }
        
        $options = [
            'include_system' => $request->get_param('include_system'),
            'optimize_for_ai' => $request->get_param('optimize_for_ai'),
        ];
        
        $fields = $this->field_api->get_fields($post_type, $options);
        
        return $this->success_response([
            'post_type' => $post_type,
            'fields' => $fields,
            'total' => count($fields)
        ]);
    }
    
    /**
     * Get all post types
     */
    public function get_post_types($request) {
        $include_system = $request->get_param('include_system');
        $post_types = $this->field_api->get_post_types($include_system);
        
        return $this->success_response([
            'post_types' => $post_types,
            'total' => count($post_types)
        ]);
    }
    
    /**
     * Get AI-optimized context
     */
    public function get_ai_context($request) {
        $context = $this->field_api->get_ai_context();
        return $this->success_response($context);
    }
}