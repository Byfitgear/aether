<?php
/**
 * Templates API Routes
 * 
 * Thin controller for template-related REST API endpoints
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Templates API Routes Class
 */
class Aether_API_Routes_Templates extends Aether_API_Routes_Base {
    
    /**
     * Template API service
     */
    private $template_api;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->template_api = Aether_Template_API::getInstance();
    }
    
    /**
     * Register template routes
     */
    public function register_routes() {
        // Template routes
        $this->register_route('/templates', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_templates'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_template'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
        ]);
        
        $this->register_route('/templates/types', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_template_types'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
        ]);
        
        $this->register_route('/templates/default/(?P<type>[a-zA-Z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_default_template'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
        ]);
        
        $this->register_route('/templates/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_template'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_template'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_template'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            ],
        ]);
        
        $this->register_route('/templates/(?P<id>\d+)/preview-url', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_template_preview_url'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
        ]);
    }
    
    /**
     * Get template types
     */
    public function get_template_types($request) {
        $result = $this->template_api->get_template_types();
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * Get all templates
     */
    public function get_templates($request) {
        $result = $this->template_api->get_templates();
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * Get single template
     */
    public function get_template($request) {
        $id = $request->get_param('id');
        $result = $this->template_api->get_template($id);
        
        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message(), $result->get_error_data()['status'] ?? 500);
        }
        
        return new WP_REST_Response(['template' => $result], 200);
    }
    
    /**
     * Create template
     */
    public function create_template($request) {
        $data = [
            'content' => $request->get_param('content'),
            'type' => $request->get_param('type'),
            'full_type' => $request->get_param('full_type'),
            'extractedCss' => $request->get_param('extractedCss'),
            'post_type' => $request->get_param('post_type'),
            'taxonomy' => $request->get_param('taxonomy'),
            'term' => $request->get_param('term'),
        ];
        
        $result = $this->template_api->create_template($data);
        
        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message(), $result->get_error_data()['status'] ?? 500);
        }
        
        return new WP_REST_Response([
            'template' => $result,
            'message' => __('模板创建成功', 'aether'),
        ], 201);
    }
    
    /**
     * Update template
     */
    public function update_template($request) {
        $id = $request->get_param('id');

        $data = [
            'title' => $request->get_param('title'),
            'content' => $request->get_param('content'),
            'extractedCss' => $request->get_param('extractedCss'),
            'slug' => $request->get_param('slug'),
        ];

        // Remove null values
        $data = array_filter($data, function($value) {
            return $value !== null;
        });

        $result = $this->template_api->update_template($id, $data);

        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message(), $result->get_error_data()['status'] ?? 500);
        }

        // 获取编译结果（如果有）
        $compile_result = null;
        if (class_exists('Aether_Template_Compile_Manager')) {
            $compile_result = Aether_Template_Compile_Manager::get_last_compile_result();
        }

        $response = [
            'template' => $result,
            'message' => __('模板更新成功', 'aether'),
        ];

        if ($compile_result) {
            $response['compile_result'] = $compile_result;
        }

        return new WP_REST_Response($response, 200);
    }
    
    /**
     * Delete template
     */
    public function delete_template($request) {
        $id = $request->get_param('id');
        $result = $this->template_api->delete_template($id);
        
        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message(), $result->get_error_data()['status'] ?? 500);
        }
        
        return $this->success_response(null, __('模板已删除', 'aether'));
    }
    
    /**
     * Get default template
     */
    public function get_default_template($request) {
        $type = $request->get_param('type');
        $result = $this->template_api->get_default_template($type);
        
        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message(), $result->get_error_data()['status'] ?? 500);
        }
        
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * Get template preview URL
     */
    public function get_template_preview_url($request) {
        $id = $request->get_param('id');
        $result = $this->template_api->get_template_preview_url($id);
        
        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message(), $result->get_error_data()['status'] ?? 500);
        }
        
        return new WP_REST_Response($result, 200);
    }
}