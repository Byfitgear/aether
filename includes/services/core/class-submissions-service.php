<?php
/**
 * Submissions Service
 *
 * Registers a private post type to persist form submissions
 * and provides helpers in the future if needed.
 */

defined('ABSPATH') || exit;

class Aether_Submissions_Service extends Aether_Base
{
    const POST_TYPE = 'aether_submission';

    protected function init()
    {
        add_action('init', [$this, 'register_post_type']);
    }

    public function register_post_type()
    {
        $labels = [
            'name'               => '表单提交',
            'singular_name'      => '表单提交',
            'menu_name'          => '表单提交',
            'add_new'            => '添加提交',
            'add_new_item'       => '新提交',
            'edit_item'          => '编辑提交',
            'new_item'           => '新提交',
            'view_item'          => '查看提交',
            'search_items'       => '搜索提交',
            'not_found'          => '未找到提交',
            'not_found_in_trash' => '回收站中未找到提交',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => false, // managed via custom SPA in settings
            'show_in_menu'       => false,
            'query_var'          => false,
            'rewrite'            => false,
            'capability_type'    => 'post',
            'has_archive'        => false,
            'hierarchical'       => false,
            'supports'           => ['title', 'editor'], // store payload JSON in content
            'show_in_rest'       => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}

