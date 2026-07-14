<?php
defined('ABSPATH') || exit;

class Aether_Editor_Ajax extends Aether_Base
{
    protected function init()
    {
        add_action('wp_ajax_aether_remove_edit_flag', [$this, 'ajax_remove_edit_flag']);
        add_action('wp_ajax_aether_save_draft', [$this, 'ajax_save_draft']);
        add_action('wp_ajax_aether_refresh_rest_nonce', [$this, 'ajax_refresh_rest_nonce']);
    }

    public function ajax_remove_edit_flag()
    {
        if (!wp_verify_nonce($_POST['nonce'], 'aether_remove_edit_flag')) wp_die();
        $post_id = intval($_POST['post_id']);
        if (!Aether_Permission_Service::can_edit_post($post_id)) {
            wp_send_json_error(['message' => __('没有权限', 'aether')]);
        }
        delete_post_meta($post_id, '_aether_edited');
        delete_post_meta($post_id, '_aether_last_edited');
        wp_send_json_success(['message' => __('已移除 Aether 编辑标记', 'aether')]);
    }

    public function ajax_save_draft()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aether_save_draft')) {
            wp_send_json_error(['message' => __('安全校验失败。', 'aether')]);
        }
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) wp_send_json_error(['message' => __('无效的文章 ID。', 'aether')]);
        if (!Aether_Permission_Service::can_edit_post($post_id)) {
            wp_send_json_error(['message' => __('没有权限', 'aether')]);
        }
        $post = get_post($post_id);
        if (!$post) wp_send_json_error(['message' => __('文章不存在。', 'aether')]);

        $update_args = ['ID' => $post_id];
        if (isset($_POST['title'])) $update_args['post_title'] = sanitize_text_field(wp_unslash($_POST['title']));
        if (isset($_POST['content'])) {
            $content = wp_unslash($_POST['content']);
            $is_we_edited = get_post_meta($post_id, '_aether_edited', true) === '1';
            if (!$is_we_edited && !current_user_can('unfiltered_html')) {
                $content = wp_kses_post($content);
            }
            $update_args['post_content'] = $content;
        }
        if (isset($_POST['excerpt'])) {
            $excerpt = wp_unslash($_POST['excerpt']);
            $is_we_edited = get_post_meta($post_id, '_aether_edited', true) === '1';
            if (!$is_we_edited && !current_user_can('unfiltered_html')) {
                $excerpt = wp_kses_post($excerpt);
            }
            $update_args['post_excerpt'] = $excerpt;
        }
        if ($post->post_status === 'auto-draft') $update_args['post_status'] = 'draft';

        $result = wp_update_post($update_args, true);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        $updated = get_post($post_id);
        wp_send_json_success(['post_id' => $post_id, 'post_status' => $updated ? $updated->post_status : $post->post_status]);
    }

    public function ajax_refresh_rest_nonce()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('未登录', 'aether')], 401);
        }
        wp_send_json_success(['nonce' => wp_create_nonce('wp_rest')]);
    }
}
