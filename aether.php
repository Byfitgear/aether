<?php
/**
 * Plugin Name: Aether
 * Plugin URI: https://www.wordexpress.app
 * Description: 极简的 WordPress 可视化编辑器，支持纯 HTML 编辑
 * Version: 1.0.0
 * Author: Yansir
 * License: GPL v2 or later
 * Text Domain: aether
 */

// 防止直接访问
defined('ABSPATH') || exit;

// 定义插件常量
define('AETHER_VERSION', '1.0.0');
define('AETHER_PATH', plugin_dir_path(__FILE__));
define('AETHER_URL', plugin_dir_url(__FILE__));
define('AETHER_BASENAME', plugin_basename(__FILE__));
define('AETHER_FILE', __FILE__);

// 调试模式
if (!defined('AETHER_DEBUG')) {
    define('AETHER_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
}

// 开发模式
if (!defined('AETHER_DEV_MODE')) {
    define('AETHER_DEV_MODE', false);
}

// 加载自动加载器
require_once AETHER_PATH . 'includes/core/class-autoloader.php';

// 注册自动加载器
Aether_Autoloader::register();

// 初始化插件
Aether::getInstance();

/**
 * 插件主类
 */
class Aether
{
    private static $instance = null;

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        add_filter('upload_mimes', [$this, 'add_wasm_mime_type']);
    }

    public function init()
    {
        // 加载编辑器模块
        if (class_exists('Aether_Editor')) {
            Aether_Editor::getInstance();
        }

        // 加载设置页面
        if (class_exists('Aether_Settings')) {
            Aether_Settings::getInstance();
        }

        // 加载联系表单服务
        if (class_exists('Aether_Contact_Form_Service')) {
            Aether_Contact_Form_Service::getInstance();
        }

        // 加载 REST API 路由
        if (class_exists('Aether_Unified_Proxy_Routes')) {
            Aether_Unified_Proxy_Routes::get_instance();
        }

        // 加载 CSS 注入管理器
        if (class_exists('Aether_CSS_Injection_Manager')) {
            Aether_CSS_Injection_Manager::get_instance();
        }

        // 加载 HTML 优化服务
        if (class_exists('Aether_HTML_Optimization_Service')) {
            Aether_HTML_Optimization_Service::getInstance();
        }
    }

    public function activate()
    {
        // 确保设置项存在
        if (!get_option('aether_settings')) {
            update_option('aether_settings', []);
        }
        // 确保版本记录存在
        if (!get_option('aether_version')) {
            update_option('aether_version', AETHER_VERSION);
        }
        // 创建联系表单数据表
        if (class_exists('Aether_Contact_Form_Service')) {
            Aether_Contact_Form_Service::install();
        }
    }

    public function deactivate()
    {
        // 清理工作
    }

    public function add_wasm_mime_type($mimes)
    {
        $mimes['wasm'] = 'application/wasm';
        return $mimes;
    }

    public function add_admin_menu()
    {
        add_menu_page(
            __('Aether', 'aether'),
            __('Aether', 'aether'),
            'edit_posts',
            'aether',
            [Aether_Settings::class, 'render_settings_page'],
            'dashicons-edit',
            25
        );

        add_submenu_page(
            'aether',
            __('编辑器', 'aether'),
            __('编辑器', 'aether'),
            'edit_posts',
            'aether-editor',
            function () {
                echo '<div class="wrap"><h1>' . esc_html__('Aether 编辑器', 'aether') . '</h1><p>' . esc_html__('请选择要编辑的文章。', 'aether') . '</p></div>';
            }
        );
    }
}
