<?php
/**
 * Admin Bar 提醒功能
 *
 * 当用户未开启自动速度优化时，在 Admin Bar 显示提醒
 *
 * @package aether
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Admin_Bar_Notice
{

    /**
     * 初始化
     */
    public static function init()
    {
        // 只对登录用户显示
        if (!is_user_logged_in()) {
            return;
        }

        // 只对有权限的用户显示
        if (!current_user_can('edit_posts')) {
            return;
        }

        // 添加 Admin Bar 项目
        add_action('admin_bar_menu', [__CLASS__, 'add_admin_bar_item'], 999);

        // 添加样式（前端和后台都需要）
        add_action('wp_head', [__CLASS__, 'add_admin_bar_styles']);
        add_action('admin_head', [__CLASS__, 'add_admin_bar_styles']);
    }

    /**
     * 检查是否开启了自动速度优化
     *
     * @return bool
     */
    private static function is_speed_optimization_enabled()
    {
        return (bool) get_option('aether_speed_optimization_enabled', false);
    }

    /**
     * 添加 Admin Bar 项目
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public static function add_admin_bar_item($wp_admin_bar)
    {
        // 只要未开启自动速度优化，就显示提醒
        if (self::is_speed_optimization_enabled()) {
            return;
        }

        // 获取设置页面链接
        $settings_url = admin_url('admin.php?page=aether#performance');

        $wp_admin_bar->add_node([
            'id' => 'aether-notice',
            'title' => '❕未优化',
            'href' => $settings_url,
            'meta' => [
                'class' => 'aether-dev-mode-notice',
                'title' => 'aether 自动速度优化未开启，点击前往设置'
            ]
        ]);
    }

    /**
     * 添加 Admin Bar 样式
     */
    public static function add_admin_bar_styles()
    {
        // 只要未开启自动速度优化，就显示样式
        if (self::is_speed_optimization_enabled()) {
            return;
        }

        ?>
        <style>
            #wp-admin-bar-aether-notice>.ab-item {
                background-color: #dc3545 !important;
                color: white !important;
                font-weight: 500 !important;
            }

            #wp-admin-bar-aether-notice:hover>.ab-item {
                background-color: #c82333 !important;
                color: white !important;
            }

            #wp-admin-bar-aether-notice .ab-item:before {
                content: none !important;
            }
        </style>
        <?php
    }
}

// 初始化 Admin Bar 提醒
Aether_Admin_Bar_Notice::init();
