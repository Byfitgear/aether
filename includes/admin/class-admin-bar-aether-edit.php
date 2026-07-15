<?php
/**
 * Admin Bar aether 编辑入口
 * 
 * 在 Admin Bar 中显示 aether 编辑器的快速访问入口
 * 支持检测和显示页面的多个 aether 组件（内容、页眉、页脚等）
 *
 * @package aether
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Admin_Bar_Edit
{

    /**
     * 初始化
     */
    public static function init()
    {
        // 只在前端显示
        if (is_admin()) {
            return;
        }

        // 只对登录用户显示
        if (!is_user_logged_in()) {
            return;
        }

        // 只对有编辑权限的用户显示
        if (!current_user_can('edit_posts')) {
            return;
        }

        // 添加 Admin Bar 项目
        add_action('admin_bar_menu', [__CLASS__, 'add_admin_bar_items'], 100);

        // 添加样式
        add_action('wp_head', [__CLASS__, 'add_styles']);
        
        // 确保 Admin Bar 脚本正确加载
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_admin_bar_scripts']);
    }

    /**
     * 添加 Admin Bar 项目
     * 
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public static function add_admin_bar_items($wp_admin_bar)
    {
        // 获取当前页面的所有 aether 组件
        $aether_components = self::get_aether_components();

        if (empty($aether_components)) {
            return;
        }

        // 计算组件数量
        $component_count = count($aether_components);

        if ($component_count === 1) {
            // 只有一个组件，直接显示编辑链接
            $component = reset($aether_components);
            $wp_admin_bar->add_node([
                'id' => 'aether-edit',
                'title' => '🎨 使用 aether 编辑',
                'href' => $component['edit_url'],
                'meta' => [
                    'class' => 'aether-edit-button',
                    'title' => '编辑 ' . $component['title']
                ]
            ]);
        } else {
            // 多个组件，显示下拉菜单
            $wp_admin_bar->add_node([
                'id' => 'aether-edit',
                'title' => sprintf('🎨 使用 aether 编辑 (%d)', $component_count),
                'href' => '#',
                'meta' => [
                    'class' => 'aether-edit-button aether-has-submenu',
                    'title' => '使用 aether 编辑的组件'
                ]
            ]);

            // 添加子菜单项
            foreach ($aether_components as $component) {
                $wp_admin_bar->add_node([
                    'id' => 'aether-edit-' . $component['type'],
                    'parent' => 'aether-edit',
                    'title' => $component['icon'] . ' ' . $component['title'],
                    'href' => $component['edit_url'],
                    'meta' => [
                        'title' => '编辑 ' . $component['title']
                    ]
                ]);
            }
        }
    }

    /**
     * 获取当前页面的所有 aether 组件
     * 
     * @return array
     */
    private static function get_aether_components()
    {
        $components = [];

        // 1. 检查主内容
        if (is_singular()) {
            global $post;
            if ($post && get_post_meta($post->ID, '_aether_edited', true) === '1') {
                $components['content'] = [
                    'type' => 'content',
                    'title' => '页面内容',
                    'icon' => '📄',
                    'edit_url' => self::get_edit_url($post->ID)
                ];
            }
        }

        // 2. 检查动态模板
        $template_components = self::get_template_components();
        $components = array_merge($components, $template_components);

        return $components;
    }

    /**
     * 获取模板组件
     * 
     * @return array
     */
    private static function get_template_components()
    {
        $components = [];

        // 获取模板实例
        $aether_templates = Aether_Templates::getInstance();

        // 检查页眉
        $header_template = $aether_templates->get_active_template('header');
        if ($header_template) {
            $components['header'] = [
                'type' => 'header',
                'title' => '页眉模板',
                'icon' => '🔝',
                'edit_url' => self::get_template_edit_url($header_template['id'])
            ];
        }

        // 检查页脚
        $footer_template = $aether_templates->get_active_template('footer');
        if ($footer_template) {
            $components['footer'] = [
                'type' => 'footer',
                'title' => '页脚模板',
                'icon' => '🔻',
                'edit_url' => self::get_template_edit_url($footer_template['id'])
            ];
        }

        // 检查当前页面类型的模板
        $template_types = Aether_Dynamic_Template_Types::getInstance();
        $current_type = $template_types->get_current_page_template_type();

        if ($current_type) {
            $template = $aether_templates->get_active_template($current_type);
            if ($template) {
                // 动态生成模板信息
                $template_display = self::get_template_display_info($current_type);
                
                $components[$current_type] = [
                    'type' => $current_type,
                    'title' => $template_display['title'],
                    'icon' => $template_display['icon'],
                    'edit_url' => self::get_template_edit_url($template['id'])
                ];
            }
        }

        return $components;
    }

    /**
     * 获取模板显示信息
     * 
     * @param string $template_type
     * @return array
     */
    private static function get_template_display_info($template_type)
    {
        // 核心模板类型
        $core_templates = [
            '404' => ['title' => '404 模板', 'icon' => '❌'],
            'search' => ['title' => '搜索模板', 'icon' => '🔍'],
            'home' => ['title' => '首页模板', 'icon' => '🏠'],
            'blog' => ['title' => '博客模板', 'icon' => '📰'],
            'author' => ['title' => '作者模板', 'icon' => '👤'],
            'header' => ['title' => '页眉模板', 'icon' => '🔝'],
            'footer' => ['title' => '页脚模板', 'icon' => '🔻']
        ];

        // 如果是核心模板，直接返回
        if (isset($core_templates[$template_type])) {
            return $core_templates[$template_type];
        }

        // 解析动态模板类型
        $template_types_instance = Aether_Dynamic_Template_Types::getInstance();
        $context = $template_types_instance->parse_template_type($template_type);

        switch ($context['type']) {
            case 'single':
                // 单页模板
                $post_type_obj = get_post_type_object($context['subtype']);
                $post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $context['subtype'];
                return [
                    'title' => sprintf('%s (单页)', $post_type_name),
                    'icon' => '📝'
                ];

            case 'archive':
                // 归档模板
                $post_type_obj = get_post_type_object($context['subtype']);
                $post_type_name = $post_type_obj ? $post_type_obj->labels->name : $context['subtype'];
                return [
                    'title' => sprintf('%s (列表)', $post_type_name),
                    'icon' => '📚'
                ];

            case 'taxonomy':
                // 分类法模板
                $taxonomy_obj = get_taxonomy($context['subtype']);
                $taxonomy_name = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : $context['subtype'];
                
                // 特殊图标
                $icon = '🏷️';
                if ($context['subtype'] === 'category') {
                    $icon = '📁';
                } elseif ($context['subtype'] === 'post_tag') {
                    $icon = '🏷️';
                }
                
                return [
                    'title' => sprintf('%s (分类)', $taxonomy_name),
                    'icon' => $icon
                ];

            default:
                // 未知类型，使用默认
                return [
                    'title' => $template_type . ' 模板',
                    'icon' => '📄'
                ];
        }
    }

    /**
     * 获取内容编辑链接
     * 
     * @param int $post_id
     * @return string
     */
    private static function get_edit_url($post_id)
    {
        return add_query_arg([
            'page' => 'aether-editor',
            'post_id' => $post_id
        ], admin_url('admin.php'));
    }

    /**
     * 获取模板编辑链接
     * 
     * @param int $template_id
     * @return string
     */
    private static function get_template_edit_url($template_id)
    {
        return add_query_arg([
            'page' => 'aether-template-editor',
            'template_id' => $template_id
        ], admin_url('admin.php'));
    }

    /**
     * 添加样式
     */
    public static function add_styles()
    {
        // 获取当前页面的 aether 组件
        $aether_components = self::get_aether_components();

        if (empty($aether_components)) {
            return;
        }

        ?>
        <style>
            /* aether 编辑按钮样式 */
            #wp-admin-bar-aether-edit>.ab-item {
                background-color: #1e40af !important;
                color: white !important;
                font-weight: 500 !important;
                padding: 0 12px !important;
            }

            #wp-admin-bar-aether-edit:hover>.ab-item {
                background-color: #2563eb !important;
                color: white !important;
            }
            
            /* 单个项目时可点击 */
            #wp-admin-bar-aether-edit:not(.aether-has-submenu) > .ab-item {
                cursor: pointer !important;
            }
            
            /* 有子菜单时父菜单不可点击 */
            #wpadminbar .aether-has-submenu > .ab-item {
                cursor: default !important;
            }

            /* 子菜单样式 */
            #wpadminbar .aether-edit-button .ab-submenu {
                background-color: #1e40af !important;
            }

            #wpadminbar .aether-edit-button .ab-submenu .ab-item {
                color: white !important;
                padding-left: 20px !important;
                cursor: pointer !important;
            }

            #wpadminbar .aether-edit-button .ab-submenu .ab-item:hover {
                background-color: #2563eb !important;
            }

            /* 移除默认的 before 伪元素 */
            #wp-admin-bar-aether-edit .ab-item:before {
                content: none !important;
            }

            /* 确保图标正确显示 */
            #wpadminbar .aether-edit-button .ab-item {
                line-height: 32px;
            }

            /* 响应式调整 */
            @media screen and (max-width: 782px) {
                #wp-admin-bar-aether-edit>.ab-item {
                    text-indent: 0;
                    padding: 0 10px !important;
                }
            }
        </style>
        <?php
    }

    /**
     * 确保 Admin Bar 脚本正确加载
     */
    public static function enqueue_admin_bar_scripts()
    {
        // 获取当前页面的 aether 组件
        $aether_components = self::get_aether_components();

        if (empty($aether_components)) {
            return;
        }

        // 确保 Admin Bar 相关脚本已加载
        if (function_exists('wp_enqueue_script')) {
            // 确保 hoverIntent 插件已加载 - WordPress 内置的
            wp_enqueue_script('hoverIntent');
            
            // 确保 Admin Bar 脚本已加载
            wp_enqueue_script('admin-bar');
        }
    }
}

// 初始化
Aether_Admin_Bar_Edit::init();