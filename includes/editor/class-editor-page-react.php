<?php
/**
 * React 编辑器页面
 * 
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Editor_Page_React extends Aether_Editor_Page_Base {
    
    /**
     * Get the page slug
     */
    protected function get_page_slug() {
        return 'aether-editor';
    }
    
    /**
     * Get the page title
     */
    protected function get_page_title() {
        return __('aether 编辑器', 'aether');
    }
    
    /**
     * Get the required capability
     */
    protected function get_capability() {
        return 'edit_posts';
    }
    
    /**
     * Get the URL parameter name
     */
    protected function get_param_name() {
        return 'post_id';
    }
    
    /**
     * Get content ID from request
     */
    protected function get_content_id_from_request() {
        return isset($_GET['post_id']) ? intval($_GET['post_id']) : null;
    }
    
    /**
     * Get body CSS class
     */
    protected function get_body_class() {
        return 'aether-editor-body';
    }
    
    /**
     * Validate content and get content object
     */
    protected function validate_content($content_id) {
        // 检查权限
        if (!current_user_can('edit_post', $content_id)) {
            wp_die(__('您没有权限编辑此内容。', 'aether'));
        }
        
        // 获取文章
        $post = get_post($content_id);
        if (!$post) {
            wp_die(__('文章不存在。', 'aether'));
        }
        
        return $post;
    }
    
    /**
     * Get localized data for the editor
     */
    protected function get_localized_data($content_id, $content) {
        return [
            'postId' => $content_id,
            'postType' => $content->post_type,
            'homeUrl' => home_url(),
        ];
    }
    
    /**
     * Get additional inline scripts
     */
    protected function get_inline_scripts($settings) {
        $script = '';
        
        // 传递 CSS 编译器 API URL 到 window 对象
        if (!empty($settings['css_compiler_api_url'])) {
            $script = 'window.aetherSettings = window.aetherSettings || {};' .
                     'window.aetherSettings.cssCompilerApiUrl = ' . json_encode($settings['css_compiler_api_url']) . ';';
        }
        
        return $script;
    }
    
}