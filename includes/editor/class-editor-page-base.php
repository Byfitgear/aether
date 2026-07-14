<?php
defined('ABSPATH') || exit;

class WordExpress_Editor_Page_Base extends WordExpress_Base
{
    protected function init()
    {
        // Base class — no hooks
    }

    protected function get_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            wp_die(__('无效的文章 ID。', 'wordexpress'));
        }
        if (!WordExpress_Permission_Service::can_edit_post($post_id)) {
            wp_die(__('文章不存在或您没有权限编辑。', 'wordexpress'));
        }
        return $post;
    }

    protected function check_api_token()
    {
        $token = WordExpress_Settings_Service::get('api_token');
        if (empty($token)) {
            return false;
        }
        return true;
    }
}
