<?php

/**
 * Plugin Name: aether
 * Plugin URI: https://www.aether.app
 * Description: 极简的 WordPress 可视化编辑器，支持纯 HTML 编辑
 * Version: 1.1.45
 * Author: Aether Team
 * License: GPL v2 or later
 * Text Domain: aether
 */

// 防止直接访问
defined('ABSPATH') || exit;

// 定义插件常量
define('AETHER_VERSION', '1.1.45');
define('AETHER_PATH', plugin_dir_path(__FILE__));
define('AETHER_URL', plugin_dir_url(__FILE__));
define('AETHER_BASENAME', plugin_basename(__FILE__));
define('AETHER_FILE', __FILE__);

// 调试模式 - 可以在 wp-config.php 中覆盖
if (!defined('AETHER_DEBUG')) {
    define('AETHER_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
}

// 开发模式 - 可以在 wp-config.php 中覆盖
if (!defined('AETHER_DEV_MODE')) {
    define('AETHER_DEV_MODE', false);
}

// 禁用更新检查 - 可以在 wp-config.php 中覆盖
if (!defined('AETHER_DISABLE_UPDATE_CHECKS')) {
    define('AETHER_DISABLE_UPDATE_CHECKS', false);
}

// 加载自动加载器
require_once AETHER_PATH . 'includes/core/class-autoloader.php';

// 注册自动加载器
Aether_Autoloader::register();

// 加载联系表单服务
require_once AETHER_PATH . 'includes/services/class-contact-form-service.php';

// 提前处理 aether 设置项，避免对象缓存干扰：
// - 强制 autoload = 'no'，避免进入 alloptions 强缓存
// - 通过 pre_option_aether_settings 短路读取，直接从数据库返回最新值
add_action('plugins_loaded', function () {
    global $wpdb;

    if (!class_exists('Aether_Settings_Service')) {
        // 触发自动加载
        Aether_Autoloader::load_class('Aether_Settings_Service');
    }

    if (!class_exists('Aether_Settings_Service')) {
        return;
    }

    $option = Aether_Settings_Service::OPTION_NAME;

    // 确保选项存在且 autoload = 'no'
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option));
    if (!$row) {
        add_option($option, [], '', 'no');
    } elseif ($row->autoload !== 'no') {
        $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $option]);
        // 让 alloptions 立刻失效，避免旧缓存干扰
        wp_cache_delete('alloptions', 'options');
    }

    // 短路读取，任何 get_option('aether_settings') 都直接命中数据库，绕开对象缓存
    add_filter("pre_option_{$option}", function ($pre, $opt, $default) use ($wpdb) {
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $opt
        ));
        if ($value === null) {
            return $default;
        }
        return maybe_unserialize($value);
    }, 10, 3);
}, 0);

/**
 * 插件主类
 */
class Aether
{

    private static $instance = null;

    /**
     * 获取单例实例
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct()
    {
        // PHP 保护系统必须最早初始化（直接加载，不等待钩子）
        $this->init_php_protection();

        // 初始化钩子
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // 隐藏 Tangible 菜单
        add_action('admin_menu', [$this, 'hide_tangible_menu'], 999);

        // 激活/停用钩子
        // 联系表单激活钩子
        register_activation_hook(__FILE__, function () {
            \Aether\Services\Aether_Contact_Form_Service::activate();
        });

        // 原有激活钩子
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // 插件升级钩子（检测升级并执行迁移）
        add_action('upgrader_process_complete', [$this, 'on_plugin_upgrade'], 10, 2);

        // 图片优化：WP 完成尺寸生成后标记 ready（优先级 999 确保在所有插件之后）
        add_filter('wp_generate_attachment_metadata', [$this, 'mark_attachment_ready_for_optimization'], 999, 2);
    }

    /**
     * 初始化 PHP 保护系统
     */
    private function init_php_protection()
    {
        $protection_file = AETHER_PATH . 'includes/services/core/class-php-protection-manager.php';

        if (file_exists($protection_file)) {
            try {
                require_once $protection_file;

                if (class_exists('Aether_PHP_Protection_Manager')) {
                    Aether_PHP_Protection_Manager::getInstance();
                }
            } catch (Exception $e) {
                // 记录错误但不影响插件其他功能
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Aether] Failed to initialize PHP Protection: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * 初始化插件
     */
    public function init()
    {
        // 注册自定义图片尺寸（直接调用，不需要再加 hook）
        $this->register_custom_image_sizes();

        // 添加 WASM MIME 类型支持（用于 jsquash 库）
        add_filter('upload_mimes', [$this, 'add_wasm_mime_type']);
        add_filter('wp_check_filetype_and_ext', [$this, 'check_wasm_filetype'], 10, 4);

        // 初始化各个模块
        Aether_Editor::getInstance();
        Aether_API::getInstance();
        Aether_Settings::getInstance();
        Aether_Templates::getInstance();
        Aether_Template_Service::getInstance();
        Aether_Submissions_Service::getInstance();
        Aether_Submission_Geo_Backfill_Service::init();
        Aether_Submission_Geo_Frontend_Service::getInstance();

        // 初始化 PHP 处理器 - 用于处理普通页面中的 PHP 代码
        Aether_PHP_Processor::getInstance();

        // 使用模板覆盖系统，不再强制包裹标签
        Aether_Template_Override::getInstance();

        // 初始化图片优化系统
        if (class_exists('Aether_Image_Optimizer')) {
            Aether_Image_Optimizer::get_instance();
        }

        // 初始化 HTML 优化服务（保存时生成优化版本）
        if (class_exists('Aether_HTML_Optimization_Service')) {
            Aether_HTML_Optimization_Service::get_instance();
        }

        // 初始化图片清理服务（删除图片时同步清理 WebP）
        if (class_exists('Aether_Image_Cleanup_Service')) {
            Aether_Image_Cleanup_Service::get_instance();
        }

        // 初始化 HTML 优化渲染 Filter（前端输出 picture 标签）
        if (class_exists('Aether_HTML_Render_Filter')) {
            Aether_HTML_Render_Filter::get_instance();
            if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
                error_log('[aether] HTML Render Filter 初始化成功');
            }
        } else {
            if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
                error_log('[aether] HTML Render Filter 类不存在！');
            }
        }

        // 加载生产模式管理器（已废弃，仅保留缓存清理功能）
        require_once AETHER_PATH . 'includes/services/core/class-production-mode-manager.php';

        // 加载模板编译管理器（页面级 CSS 编译的核心）
        require_once AETHER_PATH . 'includes/services/core/class-template-compile-manager.php';
        Aether_Template_Compile_Manager::init();

        // 加载数据迁移脚本
        require_once AETHER_PATH . 'includes/migrations/add-css-indexes.php';
        require_once AETHER_PATH . 'includes/migrations/migrate-to-page-level-css.php';
        require_once AETHER_PATH . 'includes/migrations/rollback-page-level-css.php';
        require_once AETHER_PATH . 'includes/migrations/restore-picture-to-img.php';

        // 版本检测与自动迁移
        add_action('admin_init', function() {
            if (!current_user_can('manage_options')) {
                return;
            }

            $current_version = AETHER_VERSION;
            $saved_version = get_option('aether_version', '0.0.0');

            // 检测到版本升级
            if (version_compare($saved_version, $current_version, '<')) {

                // 升级到 1.1.29 时，执行迁移任务
                if (version_compare($current_version, '1.1.29', '>=')) {
                    $migration_key = 'aether_migration_v1_1_29';

                    if (!get_option($migration_key)) {
                        // 1. 还原 picture 标签为 img
                        if (function_exists('aether_migrate_restore_picture_to_img')) {
                            aether_migrate_restore_picture_to_img(true);
                        }

                        // 2. 关闭速度优化和图片优化
                        if (function_exists('aether_migrate_disable_auto_optimization')) {
                            aether_migrate_disable_auto_optimization(true);
                        }

                        // 标记已执行
                        update_option($migration_key, current_time('mysql'));
                    }
                }

                // 升级到 1.1.35 时：重置旧版 webhook_enabled，防止新 Webhook 功能误用遗留配置
                if (version_compare($saved_version, '1.1.35', '<')) {
                    $migration_key = 'aether_migration_v1_1_35_webhook';
                    if (!get_option($migration_key)) {
                        $settings = get_option('aether_settings', []);
                        if (is_array($settings) && isset($settings['submissions']['webhook_enabled']) && $settings['submissions']['webhook_enabled']) {
                            // 旧版遗留的 webhook_enabled=true，但没有新的 webhook_secret_key → 清掉
                            if (empty($settings['submissions']['webhook_secret_key'])) {
                                $settings['submissions']['webhook_enabled'] = false;
                                update_option('aether_settings', $settings);
                            }
                        }
                        update_option($migration_key, current_time('mysql'));
                    }
                }

                $this->maybe_initialize_submission_geo_backfill($saved_version, $current_version);

                // 更新保存的版本号
                update_option('aether_version', $current_version);
            }
        }, 5);

        // 注册智能 CSS 编译计划任务 Hook（用于后台重编译）
        if (class_exists('Aether_Smart_CSS_Compiler_Service')) {
            Aether_Smart_CSS_Compiler_Service::register_cron_hook();
        }

        // 加载 Admin Bar「使用 aether 编辑」按钮
        require_once AETHER_PATH . 'includes/admin/class-admin-bar-aether-edit.php';

        // 加载 Admin Bar 速度优化提醒（未开启时显示）
        // require_once AETHER_PATH . 'includes/admin/class-admin-bar-notice.php';  // Disabled - free version

        // 加载插件信息处理器
        // require_once AETHER_PATH . 'includes/admin/class-plugin-info.php';  // Disabled - free version

        // 加载远程通知服务
        // require_once AETHER_PATH . 'includes/services/class-remote-notifications-service.php';  // Disabled - free version

        // 加载 Admin 通知显示 (仅在后台)
        if (is_admin()) {
            // require_once AETHER_PATH . 'includes/admin/class-admin-notifications.php';  // Disabled - free version
        }

        // 临时禁用 SSL 验证以解决 LibreSSL 握手问题
        add_filter('aether_proxy_verify_ssl', '__return_false');

        // 加载 WP-CLI 命令
        if (defined('WP_CLI') && WP_CLI) {
            require_once AETHER_PATH . 'includes/services/core/class-php-fix-command.php';
            require_once AETHER_PATH . 'includes/services/core/class-cache-cli-command.php';
        }

        // 加载文本域
        load_plugin_textdomain('aether', false, dirname(AETHER_BASENAME) . '/languages');

        // 调试功能 - 只在调试模式下启用
        if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
            add_action('init', [$this, 'handle_debug_request']);
        }

        // 加载插件更新器
        // require_once AETHER_PATH . 'includes/class-plugin-updater.php';  // Disabled - free version

        // 注册 post meta 字段以支持 REST API
        register_post_meta('', '_aether_edited', [
            'type' => 'boolean',
            'description' => 'Whether the post was edited with aether',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_post_meta('', '_aether_last_edited', [
            'type' => 'string',
            'description' => 'Last time the post was edited with aether',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);


        // 输出提取的 CSS - 使用较晚的优先级确保其他插件不会干扰
        add_action('wp_head', [$this, 'output_extracted_css'], 100);

        // 初始化 Swiper.js 按需加载器
        \Aether\Core\Swiper_Loader::init();
    }

    /**
     * 注册自定义图片尺寸
     */
    public function register_custom_image_sizes()
    {
        // 从配置类读取尺寸定义，保持单一数据源
        $sizes = Aether_Image_Sizes::get_sizes();

        foreach ($sizes as $size) {
            add_image_size(
                "aether_{$size}",  // 尺寸名称
                $size,            // 宽度
                9999,             // 高度（不限制）
                false             // 不裁剪
            );
        }
    }

    /**
     * 添加管理菜单
     */
    public function add_admin_menu()
    {
        // 始终显示aether菜单，让设置页面本身处理token检查
        // 这样用户可以通过菜单访问token设置界面
        add_menu_page(
            __('aether', 'aether'),
            __('aether', 'aether'),
            'manage_options',
            'aether',
            [Aether_Settings::getInstance(), 'render_settings_page'], // 直接使用设置页面回调
            'dashicons-edit-page',
            30
        );
    }

    /**
     * Plugin activation hook
     */
    public function activate()
    {
        // Create necessary database tables or options
        flush_rewrite_rules();

        // Run migration scripts
        $this->run_migrations();

        // Add static cache rules to .htaccess
        if (class_exists('Aether_Static_Cache_Manager')) {
            $result = Aether_Static_Cache_Manager::add_rules();
            if (is_wp_error($result)) {
                error_log('[Aether] Static cache rules configuration failed: ' . $result->get_error_message());
            }
        }
    }

    /**
     * Plugin deactivation hook
     */
    public function deactivate()
    {
        flush_rewrite_rules();

        // Remove static cache rules from .htaccess
        if (class_exists('Aether_Static_Cache_Manager')) {
            Aether_Static_Cache_Manager::remove_rules();
        }
    }

    /**
     * Execute migrations on plugin upgrade
     *
     * @param WP_Upgrader $upgrader_object
     * @param array $options
     */
    public function on_plugin_upgrade($upgrader_object, $options)
    {
        // Check if it's a plugin update
        if ($options['action'] !== 'update' || $options['type'] !== 'plugin') {
            return;
        }

        // Check if aether plugin is being updated
        $aether_updated = false;

        if (isset($options['plugins']) && is_array($options['plugins'])) {
            foreach ($options['plugins'] as $plugin) {
                if ($plugin === AETHER_BASENAME) {
                    $aether_updated = true;
                    break;
                }
            }
        } elseif (isset($options['plugin']) && $options['plugin'] === AETHER_BASENAME) {
            $aether_updated = true;
        }

        // If aether is being upgraded, run migrations with force flag
        if ($aether_updated) {
            $this->run_migrations(true);
        }
    }

    /**
     * Run migration scripts (shared by activation and upgrade)
     *
     * @param bool $is_upgrade Whether this is triggered by an upgrade (default: false)
     */
    private function run_migrations($is_upgrade = false)
    {
        // Load and execute migration scripts
        require_once AETHER_PATH . 'includes/migrations/add-css-indexes.php';
        require_once AETHER_PATH . 'includes/migrations/migrate-to-page-level-css.php';

        // Execute index migration
        if (function_exists('aether_migration_add_css_indexes')) {
            aether_migration_add_css_indexes();
        }

        // Execute data migration
        if (function_exists('aether_migrate_to_page_level_css')) {
            aether_migrate_to_page_level_css();
        }

        // Add/update static cache rules
        // Force overwrite on upgrade to ensure latest rules are applied
        if (class_exists('Aether_Static_Cache_Manager')) {
            $result = Aether_Static_Cache_Manager::add_rules($is_upgrade);
            if (is_wp_error($result)) {
                error_log('[Aether] Static cache rules configuration failed: ' . $result->get_error_message());
            }
        }

        if ($is_upgrade) {
            $this->maybe_initialize_submission_geo_backfill(
                get_option('aether_version', '0.0.0'),
                AETHER_VERSION
            );
        }

        // Trigger activation hook (for other components)
        do_action('aether_activated');
    }

    /**
     * 初始化旧版 submissions geo 回填迁移。
     *
     * @param string $saved_version
     * @param string $current_version
     * @return void
     */
    private function maybe_initialize_submission_geo_backfill($saved_version, $current_version)
    {
        $saved_version = is_string($saved_version) ? $saved_version : '0.0.0';
        $current_version = is_string($current_version) ? $current_version : AETHER_VERSION;

        if (
            !version_compare($saved_version, '1.1.37', '<') ||
            !version_compare($current_version, '1.1.37', '>=')
        ) {
            return;
        }

        $migration_key = 'aether_migration_v1_1_37_submission_geo_backfill';
        if (get_option($migration_key)) {
            return;
        }

        Aether_Submission_Geo_Backfill_Service::initialize_for_upgrade();
        update_option($migration_key, current_time('mysql'));
    }

    /**
     * 隐藏 Tangible 菜单
     */
    public function hide_tangible_menu()
    {
        // 移除 Tangible 主菜单
        remove_menu_page('tangible');

        // 移除 Tangible 的子菜单项（如果有的话）
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_template');
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_layout');
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_style');
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_script');
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_content');
        remove_submenu_page('tangible', 'tangible_template_import_export');

        // 移除顶部管理栏中的 Tangible 菜单
        add_action('admin_bar_menu', function ($wp_admin_bar) {
            $wp_admin_bar->remove_node('tangible');
        }, 999);
    }

    /**
     * 添加 WASM MIME 类型到允许的上传类型
     * 用于支持 jsquash 库的 WebAssembly 文件
     *
     * @param array $mimes 已注册的 MIME 类型
     * @return array 更新后的 MIME 类型
     */
    public function add_wasm_mime_type($mimes)
    {
        $mimes['wasm'] = 'application/wasm';
        return $mimes;
    }

    /**
     * 修正 WASM 文件的文件类型检测
     *
     * @param array $data 文件数据
     * @param string $file 文件路径
     * @param string $filename 文件名
     * @param array $mimes 允许的 MIME 类型
     * @return array 修正后的文件数据
     */
    public function check_wasm_filetype($data, $file, $filename, $mimes)
    {
        // 检查文件扩展名是否为 .wasm
        if (substr($filename, -5) === '.wasm') {
            $data['ext'] = 'wasm';
            $data['type'] = 'application/wasm';
        }
        return $data;
    }

    /**
     * 处理调试请求
     */
    public function handle_debug_request()
    {
        // 检查是否有调试参数
        if (isset($_GET['aether_debug_taxonomies']) && $_GET['aether_debug_taxonomies'] === '1') {
            // 包含调试页面
            include AETHER_PATH . 'debug-taxonomies.php';
            exit;
        }
    }

    /**
     * 输出提取的 CSS 到页面头部
     */
    public function output_extracted_css()
    {
        // Use the new CSS Injection Manager
        if (class_exists('Aether_CSS_Injection_Manager')) {
            $manager = Aether_CSS_Injection_Manager::get_instance();
            $manager->inject();
        }
    }

    /**
     * 标记图片准备好进行优化
     *
     * 在 WP 完成所有缩略图生成后调用，设置 ready 标记
     * 优先级 999 确保在所有其他插件处理完之后执行
     *
     * @param array $metadata 附件元数据
     * @param int $attachment_id 附件 ID
     * @return array 原样返回元数据
     */
    public function mark_attachment_ready_for_optimization($metadata, $attachment_id)
    {
        // 只处理图片类型
        $mime_type = get_post_mime_type($attachment_id);
        if (!$mime_type || strpos($mime_type, 'image/') !== 0) {
            return $metadata;
        }

        // 设置 ready 标记
        update_post_meta($attachment_id, '_aether_ready_for_optimization', '1');

        return $metadata;
    }
}

// 初始化插件
Aether::getInstance();

// 注册联系表单菜单
add_action('admin_menu', ['\Aether\Services\Aether_Contact_Form_Service', 'register_menu']);

// 注册联系表单短代码
add_shortcode('aether_contact_form', ['\Aether\Services\Aether_Contact_Form_Service', 'shortcode']);
