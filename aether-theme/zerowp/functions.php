<?php
/**
 * Aether Theme Functions
 */

// 主题支持
function aether_theme_support() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
    ));

    // 自定义 Logo
    add_theme_support('custom-logo', array(
        'height'      => 60,
        'width'       => 200,
        'flex-height' => true,
        'flex-width'  => true,
    ));
}
add_action('after_setup_theme', 'aether_theme_support');

// 注册菜单
function aether_register_menus() {
    register_nav_menus(array(
        'primary' => __('Primary Menu', 'aether'),
    ));
}
add_action('init', 'aether_register_menus');

// 移除 WordPress 默认样式和功能
function aether_remove_default_styles() {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('classic-theme-styles');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('wc-blocks-style');

    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
}
add_action('wp_enqueue_scripts', 'aether_remove_default_styles', 100);

// 清理 wp_head 输出
function aether_clean_head() {
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wp_shortlink_wp_head');
    remove_action('wp_head', 'rest_output_link_wp_head');
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'feed_links', 2);
    remove_action('wp_head', 'feed_links_extra', 3);
    remove_action('wp_head', 'adjacent_posts_rel_link_wp_head');
    remove_filter('wp_robots', 'wp_robots_max_image_preview_large');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    remove_action('wp_head', 'wp_robots', 1);
    remove_action('wp_head', 'wp_resource_hints', 2);
}
add_action('init', 'aether_clean_head');

// rel_canonical 由 template_redirect 钩子添加，需在此钩子上移除
function aether_clean_rels() {
    remove_action('template_redirect', 'rel_canonical', 10);
}
add_action('template_redirect', 'aether_clean_rels', 1);

// 移除 speculation rules 脚本
add_filter('wp_speculation_rules_configuration', '__return_null');

// 禁用 XML-RPC
add_filter('xmlrpc_enabled', '__return_false');

// 移除查询字符串
function aether_remove_query_strings($src) {
    $parts = explode('?', $src);
    return $parts[0];
}
add_filter('script_loader_src', 'aether_remove_query_strings', 15, 1);
add_filter('style_loader_src', 'aether_remove_query_strings', 15, 1);

// 主题自动更新功能
if (!defined('AETHER_DISABLE_UPDATE_CHECKS')) {
    define('AETHER_DISABLE_UPDATE_CHECKS', false);
}
require_once get_template_directory() . '/inc/theme-updater.php';

// 处理主题更新时的备份权限问题
add_filter('upgrader_package_options', function($options) {
    if (isset($options['hook_extra']['theme']) && $options['hook_extra']['theme'] === get_template()) {
        $options['clear_destination'] = true;
        $options['overwrite_package'] = true;
    }
    return $options;
});

// 前台加载静态资源
function aether_enqueue_front_assets() {
    if (is_admin()) {
        return;
    }

    // htmx
    $htmx_file = get_template_directory() . '/assets/js/htmx.min.js';
    $version = file_exists($htmx_file) ? filemtime($htmx_file) : null;
    wp_enqueue_script(
        'aether-htmx',
        get_template_directory_uri() . '/assets/js/htmx.min.js',
        array(),
        $version,
        true
    );

    // Dark mode JS
    $dark_mode_file = get_template_directory() . '/assets/js/dark-mode.js';
    $dv = file_exists($dark_mode_file) ? filemtime($dark_mode_file) : null;
    wp_enqueue_script(
        'aether-dark-mode',
        get_template_directory_uri() . '/assets/js/dark-mode.js',
        array(),
        $dv,
        true
    );
}
add_action('wp_enqueue_scripts', 'aether_enqueue_front_assets', 20);

// ============================================================
// Custom Logo — 在 Header 中显示（替代纯文字）
// ============================================================
function aether_site_logo() {
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo_img = wp_get_attachment_image_src($custom_logo_id, 'full');
        if ($logo_img) {
            echo '<a href="' . esc_url(home_url('/')) . '" class="flex items-center gap-3">';
            echo '<img src="' . esc_url($logo_img[0]) . '" alt="' . esc_attr(get_bloginfo('name')) . '" class="h-8 w-auto">';
            echo '</a>';
            return;
        }
    }
    // Fallback: 文字 Logo
    echo '<a href="' . esc_url(home_url('/')) . '" class="text-xl font-bold tracking-tight hover:text-gray-600 dark:hover:text-gray-300 transition-colors">';
    bloginfo('name');
    echo '</a>';
}

// ============================================================
// 搜索表单 — 改进样式
// ============================================================
function aether_search_form($form) {
    $form = '<form role="search" method="get" class="flex items-center gap-2" action="' . esc_url(home_url('/')) . '">
        <input type="search"
               class="px-3 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent min-w-[180px]"
               placeholder="' . esc_attr__('Search…', 'aether') . '"
               value="' . esc_attr(get_search_query()) . '"
               name="s" />
        <button type="submit"
                class="px-3 py-1.5 text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg hover:bg-gray-700 dark:hover:bg-gray-300 transition-colors font-medium">
            ' . esc_html__('Search', 'aether') . '
        </button>
    </form>';
    return $form;
}
add_filter('get_search_form', 'aether_search_form');
