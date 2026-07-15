<?php
/**
 * 编辑器 AJAX 处理
 * 
 * 处理编辑器相关的 AJAX 请求
 *
 * @package aether
 * @subpackage Editor
 */

defined('ABSPATH') || exit;

/**
 * AJAX 处理类
 */
class Aether_Editor_Ajax extends Aether_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        add_action('wp_ajax_aether_remove_edit_flag', [$this, 'ajax_remove_edit_flag']);
        add_action('wp_ajax_aether_save_draft', [$this, 'ajax_save_draft']);
        add_action('wp_ajax_aether_refresh_rest_nonce', [$this, 'ajax_refresh_rest_nonce']);
    }
    
    /**
     * AJAX 处理：移除 aether 编辑标记
     */
    public function ajax_remove_edit_flag() {
        // 验证 nonce
        if (!wp_verify_nonce($_POST['nonce'], 'aether_remove_edit_flag')) {
            wp_die();
        }
        
        $post_id = intval($_POST['post_id']);
        
        // 检查权限
        if (!Aether_Permission_Service::can_edit_post($post_id)) {
            wp_send_json_error(['message' => __('没有权限', 'aether')]);
        }
        
        // 移除 aether 编辑标记
        delete_post_meta($post_id, '_aether_edited');
        delete_post_meta($post_id, '_aether_last_edited');
        
        wp_send_json_success(['message' => __('已移除 aether 编辑标记', 'aether')]);
    }

    /**
     * AJAX 处理：在进入 aether 编辑器前保存草稿
     */
    public function ajax_save_draft() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aether_save_draft')) {
            wp_send_json_error(['message' => __('安全校验失败。', 'aether')]);
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => __('无效的文章 ID。', 'aether')]);
        }

        if (!Aether_Permission_Service::can_edit_post($post_id)) {
            wp_send_json_error(['message' => __('没有权限', 'aether')]);
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('文章不存在。', 'aether')]);
        }

        $update_args = [
            'ID' => $post_id,
        ];

        if (isset($_POST['title'])) {
            $update_args['post_title'] = sanitize_text_field(wp_unslash($_POST['title']));
        }

        if (isset($_POST['content'])) {
            $content = wp_unslash($_POST['content']);

            // 检查是否是 aether 编辑的内容
            $is_aether_edited = get_post_meta($post_id, '_aether_edited', true) === '1';
            $contains_php = strpos($content, '<?php') !== false || strpos($content, '<?=') !== false;

            // 如果不是 aether 编辑的内容，或者不包含 PHP，则应用标准过滤
            if (!$is_aether_edited && !current_user_can('unfiltered_html')) {
                $content = wp_kses_post($content);
            }
            // aether 编辑的内容由 PHP Protection Manager 系统处理

            $update_args['post_content'] = $content;
        }

        if (isset($_POST['excerpt'])) {
            $excerpt = wp_unslash($_POST['excerpt']);

            // 对于摘要，只有非 aether 内容才应用 kses 过滤
            $is_aether_edited = isset($is_aether_edited) ? $is_aether_edited : (get_post_meta($post_id, '_aether_edited', true) === '1');
            if (!$is_aether_edited && !current_user_can('unfiltered_html')) {
                $excerpt = wp_kses_post($excerpt);
            }

            $update_args['post_excerpt'] = $excerpt;
        }

        if ($post->post_status === 'auto-draft') {
            $update_args['post_status'] = 'draft';
        }

        $result = wp_update_post($update_args, true);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => sprintf(
                    __('保存草稿失败：%s', 'aether'),
                    $result->get_error_message()
                ),
            ]);
        }

        $updated_post = get_post($post_id);

        wp_send_json_success([
            'post_id' => $post_id,
            'post_status' => $updated_post ? $updated_post->post_status : $post->post_status,
        ]);
    }

    /**
     * AJAX: 返回一个新的 REST Nonce（无需现有 REST Nonce）
     * 仅要求用户已登录（通过 WP Admin cookies 判断）
     */
    public function ajax_refresh_rest_nonce() {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('未登录', 'aether')], 401);
        }

        wp_send_json_success([
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}
