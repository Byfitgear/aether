<?php

/**
 * Plugin Name: aether
 * Plugin URI: https://www.aether.app
 * Description: A minimalist WordPress visual editor with contact form, image optimization, and CSS compilation. No registration required.
 * Version: 1.1.45
 * Author: Aether Team
 * License: GPL v2 or later
 * Text Domain: aether
 */

// Prevent direct access
defined('ABSPATH') || exit;

// Define plugin constants
define('AETHER_VERSION', '1.1.45');
define('AETHER_PATH', plugin_dir_path(__FILE__));
define('AETHER_URL', plugin_dir_url(__FILE__));
define('AETHER_BASENAME', plugin_basename(__FILE__));
define('AETHER_FILE', __FILE__);

// Debug mode - can be overridden in wp-config.php
if (!defined('AETHER_DEBUG')) {
    define('AETHER_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
}

// Development mode - can be overridden in wp-config.php
if (!defined('AETHER_DEV_MODE')) {
    define('AETHER_DEV_MODE', false);
}

// Disable update checks - can be overridden in wp-config.php
if (!defined('AETHER_DISABLE_UPDATE_CHECKS')) {
    define('AETHER_DISABLE_UPDATE_CHECKS', false);
}

// Load autoloader
require_once AETHER_PATH . 'includes/core/class-autoloader.php';

// Register autoloader
Aether_Autoloader::register();

// Load contact form service
require_once AETHER_PATH . 'includes/services/class-contact-form-service.php';

// Process aether settings early to avoid object cache interference:
// - Force autoload = 'no' to prevent entering alloptions strong cache
// - Short-circuit read via pre_option_aether_settings, return latest value directly from database
add_action('plugins_loaded', function () {
    global $wpdb;

    if (!class_exists('Aether_Settings_Service')) {
        // Trigger autoloader
        Aether_Autoloader::load_class('Aether_Settings_Service');
    }

    if (!class_exists('Aether_Settings_Service')) {
        return;
    }

    $option = Aether_Settings_Service::OPTION_NAME;

    // Ensure option exists and autoload = 'no'
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option));
    if (!$row) {
        add_option($option, [], '', 'no');
    } elseif ($row->autoload !== 'no') {
        $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $option]);
        // Invalidate alloptions immediately to avoid old cache interference
        wp_cache_delete('alloptions', 'options');
    }

    // Short-circuit read, any get_option('aether_settings') hits database directly, bypassing object cache
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
 * Main plugin class
 */
class Aether
{

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        // PHP protection system must initialize earliest (load directly, no hook waiting)
        $this->init_php_protection();

        // Initialize hooks
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Hide Tangible menu
        add_action('admin_menu', [$this, 'hide_tangible_menu'], 999);

        // Activation/deactivation hooks
        // Contact form activation hook
        register_activation_hook(__FILE__, function () {
            \Aether\Services\Aether_Contact_Form_Service::activate();
        });

        // Original activation hook
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Plugin upgrade hook (detect upgrades and execute migrations)
        add_action('upgrader_process_complete', [$this, 'on_plugin_upgrade'], 10, 2);

        // Image optimization: Mark ready after WP completes size generation (priority 999 ensures after all plugins)
        add_filter('wp_generate_attachment_metadata', [$this, 'mark_attachment_ready_for_optimization'], 999, 2);
    }

    /**
     * Initialize PHP protection system
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
                // Log error without affecting other plugin functions
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Aether] Failed to initialize PHP Protection: ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        // Register custom image sizes (call directly, no hook needed)
        $this->register_custom_image_sizes();

        // Add WASM MIME type support (for jsquash library)
        add_filter('upload_mimes', [$this, 'add_wasm_mime_type']);
        add_filter('wp_check_filetype_and_ext', [$this, 'check_wasm_filetype'], 10, 4);

        // Initialize various modules
        Aether_Editor::getInstance();
        Aether_API::getInstance();
        Aether_Settings::getInstance();
        Aether_Templates::getInstance();
        Aether_Template_Service::getInstance();
        Aether_Submissions_Service::getInstance();
        Aether_Submission_Geo_Backfill_Service::init();
        Aether_Submission_Geo_Frontend_Service::getInstance();

        // Initialize PHP processor - handles PHP code in regular pages
        Aether_PHP_Processor::getInstance();

        // Use template override system, no longer force wrapper tags
        Aether_Template_Override::getInstance();

        // Initialize image optimization system
        if (class_exists('Aether_Image_Optimizer')) {
            Aether_Image_Optimizer::get_instance();
        }

        // Initialize HTML optimization service (generate optimized version on save)
        if (class_exists('Aether_HTML_Optimization_Service')) {
            Aether_HTML_Optimization_Service::get_instance();
        }

        // Initialize image cleanup service (sync cleanup WebP when deleting images)
        if (class_exists('Aether_Image_Cleanup_Service')) {
            Aether_Image_Cleanup_Service::get_instance();
        }

        // Initialize HTML optimization render Filter (frontend outputs picture tag)
        if (class_exists('Aether_HTML_Render_Filter')) {
            Aether_HTML_Render_Filter::get_instance();
            if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
                error_log('[aether] HTML Render Filter initialization successful');
            }
        } else {
            if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
                error_log('[aether] HTML Render Filter class not found!');
            }
        }

        // Load production mode manager (deprecated, keep only cache cleanup function)
        require_once AETHER_PATH . 'includes/services/core/class-production-mode-manager.php';

        // Load template compilation manager (core of page-level CSS compilation)
        require_once AETHER_PATH . 'includes/services/core/class-template-compile-manager.php';
        Aether_Template_Compile_Manager::init();

        // Load data migration scripts
        require_once AETHER_PATH . 'includes/migrations/add-css-indexes.php';
        require_once AETHER_PATH . 'includes/migrations/migrate-to-page-level-css.php';
        require_once AETHER_PATH . 'includes/migrations/rollback-page-level-css.php';
        require_once AETHER_PATH . 'includes/migrations/restore-picture-to-img.php';

        // Version detection and automatic migration
        add_action('admin_init', function() {
            if (!current_user_can('manage_options')) {
                return;
            }

            $current_version = AETHER_VERSION;
            $saved_version = get_option('aether_version', '0.0.0');

            // Version upgrade detected
            if (version_compare($saved_version, $current_version, '<')) {

                // When upgrading to 1.1.29, execute migration tasks
                if (version_compare($current_version, '1.1.29', '>=')) {
                    $migration_key = 'aether_migration_v1_1_29';

                    if (!get_option($migration_key)) {
                        // 1. Restore picture tags to img
                        if (function_exists('aether_migrate_restore_picture_to_img')) {
                            aether_migrate_restore_picture_to_img(true);
                        }

                        // 2. Disable speed optimization and image optimization
                        if (function_exists('aether_migrate_disable_auto_optimization')) {
                            aether_migrate_disable_auto_optimization(true);
                        }

                        // Mark as executed
                        update_option($migration_key, current_time('mysql'));
                    }
                }

                // When upgrading to 1.1.35: Reset legacy webhook_enabled to prevent new Webhook features from misusing legacy configuration
                if (version_compare($saved_version, '1.1.35', '<')) {
                    $migration_key = 'aether_migration_v1_1_35_webhook';
                    if (!get_option($migration_key)) {
                        $settings = get_option('aether_settings', []);
                        if (is_array($settings) && isset($settings['submissions']['webhook_enabled']) && $settings['submissions']['webhook_enabled']) {
                            // Legacy webhook_enabled=true without new webhook_secret_key → clear it
                            if (empty($settings['submissions']['webhook_secret_key'])) {
                                $settings['submissions']['webhook_enabled'] = false;
                                update_option('aether_settings', $settings);
                            }
                        }
                        update_option($migration_key, current_time('mysql'));
                    }
                }

                $this->maybe_initialize_submission_geo_backfill($saved_version, $current_version);

                // Update saved version number
                update_option('aether_version', $current_version);
            }
        }, 5);

        // Register smart CSS compilation cron hook (for backend recompilation)
        if (class_exists('Aether_Smart_CSS_Compiler_Service')) {
            Aether_Smart_CSS_Compiler_Service::register_cron_hook();
        }

        // Load Admin Bar 'Edit with Aether' button
        require_once AETHER_PATH . 'includes/admin/class-admin-bar-aether-edit.php';

        // Load Admin Bar speed optimization reminder (shown when not enabled)
        // require_once AETHER_PATH . 'includes/admin/class-admin-bar-notice.php';  // Disabled - free version

        // Load plugin info processor
        // require_once AETHER_PATH . 'includes/admin/class-plugin-info.php';  // Disabled - free version

        // Load remote notification service
        // require_once AETHER_PATH . 'includes/services/class-remote-notifications-service.php';  // Disabled - free version

        // Load Admin notification display (backend only)
        if (is_admin()) {
            // require_once AETHER_PATH . 'includes/admin/class-admin-notifications.php';  // Disabled - free version
        }

        // Temporarily disable SSL verification to resolve LibreSSL handshake issues
        add_filter('aether_proxy_verify_ssl', '__return_false');

        // Load WP-CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            require_once AETHER_PATH . 'includes/services/core/class-php-fix-command.php';
            require_once AETHER_PATH . 'includes/services/core/class-cache-cli-command.php';
        }

        // Load text domain
        load_plugin_textdomain('aether', false, dirname(AETHER_BASENAME) . '/languages');

        // Debug feature - only enabled in debug mode
        if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
            add_action('init', [$this, 'handle_debug_request']);
        }

        // Load plugin updater
        // require_once AETHER_PATH . 'includes/class-plugin-updater.php';  // Disabled - free version

        // Register post meta fields to support REST API
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


        // Output extracted CSS - use later priority to ensure other plugins don't interfere
        add_action('wp_head', [$this, 'output_extracted_css'], 100);

        // Initialize Swiper.js on-demand loader
        \Aether\Core\Swiper_Loader::init();
    }

    /**
     * 注册自定义图片尺寸
     */
    public function register_custom_image_sizes()
    {
        // Read size definitions from config class, maintain single source of truth
        $sizes = Aether_Image_Sizes::get_sizes();

        foreach ($sizes as $size) {
            add_image_size(
                "aether_{$size}",  // Size name
                $size,            // Width
                9999,             // Height (unlimited)
                false             // No crop
            );
        }
    }

    /**
     * 添加管理菜单
     */
    public function add_admin_menu()
    {
        // Always show aether menu, let settings page itself handle token checks
        // This allows users to access token settings via menu
        add_menu_page(
            __('aether', 'aether'),
            __('aether', 'aether'),
            'manage_options',
            'aether',
            [Aether_Settings::getInstance(), 'render_settings_page'], // Directly use settings page callback
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
     * Hide Tangible menu
     */
    public function hide_tangible_menu()
    {
        // Remove Tangible main menu
        remove_menu_page('tangible');

        // Remove Tangible submenu items (if any)
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_template');
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_layout');
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_style');
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_script');
        remove_submenu_page('tangible', 'edit.php?post_type=tangible_content');
        remove_submenu_page('tangible', 'tangible_template_import_export');

        // Remove Tangible menu from top admin bar
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
        // Check if file extension is .wasm
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
        // Check if debug parameters exist
        if (isset($_GET['aether_debug_taxonomies']) && $_GET['aether_debug_taxonomies'] === '1') {
            // Include debug page
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
        // Only process image types
        $mime_type = get_post_mime_type($attachment_id);
        if (!$mime_type || strpos($mime_type, 'image/') !== 0) {
            return $metadata;
        }

        // Set ready marker
        update_post_meta($attachment_id, '_aether_ready_for_optimization', '1');

        return $metadata;
    }
}

// Initialize plugin
Aether::getInstance();

// Register contact form menu
add_action('admin_menu', ['\Aether\Services\Aether_Contact_Form_Service', 'register_menu']);

// Register contact form shortcode
add_shortcode('aether_contact_form', ['\Aether\Services\Aether_Contact_Form_Service', 'shortcode']);
