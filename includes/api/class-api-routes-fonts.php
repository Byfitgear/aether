<?php
/**
 * Fonts API Routes
 * 
 * Thin controller for font-related REST API endpoints
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Fonts API Routes Class
 */
class Aether_API_Routes_Fonts extends Aether_API_Routes_Base {
    
    /**
     * 注册路由
     */
    public function register_routes() {
        // Font settings
        $this->register_route('/fonts/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_font_settings'],
                'permission_callback' => [$this, 'check_permission']
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_font_settings'],
                'permission_callback' => [$this, 'check_permission']
            ]
        ]);
        
        // Clear font cache
        $this->register_route('/fonts/clear-cache', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'clear_font_cache'],
            'permission_callback' => [$this, 'check_permission']
        ]);
    }
    
    /**
     * Get font settings
     */
    public function get_font_settings($request) {
        $settings = Aether_Font_Manager_Service::get_font_settings();
        return $this->success_response($settings);
    }
    
    /**
     * Update font settings
     */
    public function update_font_settings($request) {
        $settings = [
            'heading_font' => sanitize_text_field($request->get_param('heading_font')),
            'body_font' => sanitize_text_field($request->get_param('body_font')),
            'code_font' => sanitize_text_field($request->get_param('code_font')),
            'heading_weight' => sanitize_text_field($request->get_param('heading_weight')),
            'body_weight' => sanitize_text_field($request->get_param('body_weight')),
            'code_weight' => sanitize_text_field($request->get_param('code_weight'))
        ];
        
        $result = Aether_Font_Manager_Service::update_font_settings($settings);
        
        if (!$result) {
            return $this->error_response('字体设置更新失败');
        }
        
        return $this->success_response(null, 'Font settings updated');
    }
    
    /**
     * Clear font cache
     */
    public function clear_font_cache($request) {
        Aether_Font_Manager_Service::clear_font_cache();
        return $this->success_response(null, 'Font cache cleared');
    }
}