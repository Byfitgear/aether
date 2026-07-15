<?php
/**
 * Preview API Routes
 * 
 * Handles preview-related REST API routes
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Preview API Routes Class
 */
class Aether_API_Routes_Preview extends Aether_API_Routes_Base {
    
    /**
     * Register preview routes
     */
    public function register_routes() {
        // 预览内容路由
        $this->register_route('/preview', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'handle_preview'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'content' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => function($value) {
                        // 保留原始 HTML，不做清理
                        return $value;
                    },
                ],
                'post_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }
    
    /**
     * 处理预览请求
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_preview($request) {
        $content = $request->get_param('content');
        $post_id = $request->get_param('post_id');
        
        // 设置全局 post 以便 PHP 代码能获取正确的上下文
        if ($post_id) {
            global $post;
            $post = get_post($post_id);
            setup_postdata($post);
        }
        
        // 检查是否有 PHP 代码标签
        $template_patterns = [
            '/<loop\\s+[^>]*>/i',
            '/<field\\s+[^>]*>/i',
            '/<if\\s+[^>]*>/i',
            '/\\{Field\\s+[^}]+\\}/i',  // 支持 {Field name} 语法
        ];
        
        $has_template_tags = false;
        foreach ($template_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $has_template_tags = true;
                break;
            }
        }
        
        // 如果有 PHP 标签，使用 PHP 执行渲染
        if ($has_template_tags && function_exists('aether_render_template')) {
            // 首先将小写标签转换回正确的大小写
            $content = preg_replace_callback(
                '/<(\\/?)([a-z]+)(\\s+[^>]*)?>/i',
                function($matches) {
                    $tag = strtolower($matches[2]);
                    $tag_map = [
                        'loop' => 'Loop',
                        'field' => 'Field',
                        'if' => 'If',
                        'else' => 'Else',
                    ];
                    
                    if (isset($tag_map[$tag])) {
                        $closing = $matches[1];
                        $attributes = isset($matches[3]) ? $matches[3] : '';
                        return '<' . $closing . $tag_map[$tag] . $attributes . '>';
                    }
                    
                    return $matches[0];
                },
                $content
            );
            
            // 使用 PHP 执行渲染内容
            $content = aether_render_template($content);
        }
        
        // 重置 post 数据
        if ($post_id) {
            wp_reset_postdata();
        }
        
        return $this->success_response([
            'content' => $content,
            'processed' => $has_template_tags
        ]);
    }
}