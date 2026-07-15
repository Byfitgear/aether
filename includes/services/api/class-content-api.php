<?php
/**
 * 内容 API 服务
 * 
 * 处理文章内容的 REST API 操作
 *
 * @package aether
 * @subpackage Services
 */

defined('ABSPATH') || exit;

/**
 * 内容 API 类
 */
class Aether_Content_API {
    
    /**
     * 获取文章内容
     * 
     * @param WP_REST_Request $request REST 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function get_content($request) {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error(
                'post_not_found',
                __('文章不存在', 'aether'),
                ['status' => 404]
            );
        }
        
        // 获取内容 - 模板从 meta 读取，其他从 post_content 读取
        $content = $post->post_content;
        if ($post->post_type === 'aether_template') {
            $content = get_post_meta($post_id, Aether_Templates::CONTENT_META_KEY, true) ?: '';
        }
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'content' => $content,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'aether_edited' => get_post_meta($post_id, '_aether_edited', true),
                'aether_last_edited' => get_post_meta($post_id, '_aether_last_edited', true),
                'aether_extracted_css' => get_post_meta($post_id, '_aether_extracted_css', true),
            ]
        ], 200);
    }
    
    /**
     * 更新文章内容
     * 
     * @param WP_REST_Request $request REST 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function update_content($request) {
        $post_id = $request->get_param('id');
        $content = $request->get_param('content');
        $extracted_css = $request->get_param('extractedCss');
        
        // 验证文章是否存在
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error(
                'post_not_found',
                __('文章不存在', 'aether'),
                ['status' => 404]
            );
        }

        $php_permission = Aether_Permission_Service::check_php_content_save_permission($content);
        if (is_wp_error($php_permission)) {
            return $php_permission;
        }
        
        // 检查是否是模板
        if ($post->post_type === 'aether_template') {
            // 模板内容保存在 meta 中
            Aether_Templates::update_content_meta($post_id, $content);
            $updated = true;

            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Content API] Template #%d content 已更新 (长度: %d)',
                    $post_id,
                    strlen($content)
                ));
            }
        } else {
            // 普通文章内容更新
            // To prevent WordPress KSES filter from deleting script tags, etc.,
            // 我们需要暂时提升权限或直接使用 wpdb
            global $wpdb;
            
            // 直接更新数据库，绕过 WordPress 的内容过滤
            $updated = $wpdb->update(
                $wpdb->posts,
                ['post_content' => $content],
                ['ID' => $post_id],
                ['%s'],
                ['%d']
            );
            
            if ($updated === false) {
                return new WP_Error(
                    'update_failed',
                    __('保存失败: 数据库更新错误', 'aether'),
                    ['status' => 500]
                );
            }
        }
        
        // 标记此文章使用 aether 编辑
        update_post_meta($post_id, '_aether_edited', true);
        update_post_meta($post_id, '_aether_last_edited', current_time('mysql'));
        
        // 保存提取的 CSS
        // 强制删除旧的 CSS 以确保更新
        delete_post_meta($post_id, '_aether_extracted_css');
        
        if (!empty($extracted_css)) {
            // WordPress 的 update_post_meta 会移除反斜杠
            // 使用 base64 编码来保护 CSS 内容
            $encoded_css = base64_encode($extracted_css);
            update_post_meta($post_id, '_aether_extracted_css', $encoded_css);
            // 添加一个标记，表示这是 base64 编码的
            update_post_meta($post_id, '_aether_css_encoded', '1');
        }
        
        // Clear cache
        clean_post_cache($post_id);

        // 触发统一的 aether 内容保存钩子
        do_action('aether_content_saved', [
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'source' => 'content_api'
        ]);

        // 如果是模板，额外触发模板专用钩子
        if ($post->post_type === 'aether_template') {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Content API] 准备触发 aether_template_saved hook，Template #%d',
                    $post_id
                ));
            }

            do_action('aether_template_saved', [
                'id' => $post_id,
                'type' => get_post_meta($post_id, '_aether_template_type', true),
                'content' => $content,
                'action' => 'update',
                'source' => 'content_api'
            ]);

            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Content API] aether_template_saved hook 已触发，Template #%d',
                    $post_id
                ));
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('内容已保存', 'aether'),
            'data' => [
                'post_id' => $post_id,
                'content' => $content,
            ]
        ], 200);
    }
    
    /**
     * 获取请求参数架构
     * 
     * @return array
     */
    public static function get_content_schema() {
        return [
            'content' => [
                'description' => __('文章内容', 'aether'),
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => function($content) {
                    // 对于 aether 编辑器，我们信任用户输入的 HTML
                    // 只进行最基本的清理，保持原始格式
                    return $content;
                },
            ],
            'extractedCss' => [
                'description' => __('提取的 Tailwind CSS', 'aether'),
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => function($css) {
                    // CSS 需要保留特殊字符，包括反斜杠
                    // Don't use wp_strip_all_tags as it may break CSS
                    // 只进行最基本的安全检查
                    
                    // 保留原始 CSS，只移除可能的 script 标签
                    $clean_css = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $css);
                    
                    return $clean_css;
                },
            ],
        ];
    }
}
