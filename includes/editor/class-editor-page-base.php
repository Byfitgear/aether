<?php
defined('ABSPATH') || exit;

class Aether_Editor_Page_Base extends Aether_Base
{
    protected function init()
    {
        // Base class — no hooks
    }

    protected function get_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            wp_die(__('无效的文章 ID。', 'aether'));
        }
        if (!Aether_Permission_Service::can_edit_post($post_id)) {
            wp_die(__('文章不存在或您没有权限编辑。', 'aether'));
        }
        return $post;
    }

    protected function check_api_token()
    {
        $token = Aether_Settings_Service::get('api_token');
        if (empty($token)) {
            return false;
        }
        return true;
    }
}
