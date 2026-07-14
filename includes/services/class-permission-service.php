<?php
defined('ABSPATH') || exit;

class WordExpress_Permission_Service
{
    public static function can_use_editor()
    {
        return current_user_can('edit_posts');
    }

    public static function can_edit_post($post_id)
    {
        return current_user_can('edit_post', $post_id);
    }

    public static function can_manage_settings()
    {
        return current_user_can('manage_options');
    }

    public static function check_rest_permission()
    {
        if (!self::can_use_editor()) {
            return new WP_Error(
                'rest_forbidden',
                __('您没有权限执行此操作。', 'wordexpress'),
                ['status' => 403]
            );
        }
        return true;
    }

    public static function check_post_permission($request)
    {
        $post_id = $request->get_param('id');
        if (!$post_id || !self::can_edit_post($post_id)) {
            return new WP_Error(
                'rest_forbidden',
                __('您没有权限编辑此文章。', 'wordexpress'),
                ['status' => 403]
            );
        }
        return true;
    }
}
