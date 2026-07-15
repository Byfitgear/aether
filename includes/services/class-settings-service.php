<?php
/**
 * 统一的设置服务
 * 
 * 负责设置数据的存储和检索
 *
 * @package aether
 * @subpackage Services
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Settings_Service
{

    /**
     * 设置选项名称
     */
    const OPTION_NAME = 'aether_settings';

    /**
     * 获取所有设置
     * 
     * @return array
     */
    public static function get_all()
    {
        // 确保验证器已加载
        if (!class_exists('Aether_Settings_Validator')) {
            require_once AETHER_PATH . 'includes/services/core/class-settings-validator.php';
        }

        $settings = get_option(self::OPTION_NAME, []);
        return wp_parse_args($settings, Aether_Settings_Validator::get_defaults());
    }

    /**
     * 获取单个设置值
     * 
     * @param string $key 设置键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $settings = self::get_all();

        if (isset($settings[$key])) {
            return $settings[$key];
        }

        // 如果提供了默认值，使用它
        if ($default !== null) {
            return $default;
        }

        // 从验证器获取默认值
        if (!class_exists('Aether_Settings_Validator')) {
            require_once AETHER_PATH . 'includes/services/core/class-settings-validator.php';
        }

        $defaults = Aether_Settings_Validator::get_defaults();
        return $defaults[$key] ?? null;
    }

    /**
     * 保存设置
     * 
     * @param array $settings 新设置
     * @return bool|string 返回 true（成功）、false（失败）或 'unchanged'（无变化）
     */
    public static function save($settings)
    {
        // 确保验证器已加载
        if (!class_exists('Aether_Settings_Validator')) {
            require_once AETHER_PATH . 'includes/services/core/class-settings-validator.php';
        }

        $current = self::get_all();
        $validated = Aether_Settings_Validator::validate($settings);

        // 检查是否有实际变化
        if ($validated == $current) {
            return 'unchanged';
        }

        // 使用 autoload = 'no'，并在更新过程中暂停新增缓存，避免对象缓存干扰
        wp_suspend_cache_addition(true);
        $result = update_option(self::OPTION_NAME, $validated, 'no');
        wp_suspend_cache_addition(false);

        // 清理对象缓存，确保配置立即生效
        wp_cache_delete(self::OPTION_NAME, 'options');
        wp_cache_delete('alloptions', 'options');

        // 清理全站页面缓存（设置影响全站）
        if (class_exists('Aether_Cache_Helper')) {
            $cache_cleared = Aether_Cache_Helper::clear_page_cache();
            if (!$cache_cleared) {
                error_log('[aether] Warning: Settings saved but page cache clearing failed');
            }
        }

        return $result;
    }

    /**
     * 重置设置到默认值
     * 
     * @return bool
     */
    public static function reset()
    {
        // 确保验证器已加载
        if (!class_exists('Aether_Settings_Validator')) {
            require_once AETHER_PATH . 'includes/services/core/class-settings-validator.php';
        }

        // 使用 autoload = 'no'，并在更新过程中暂停新增缓存
        wp_suspend_cache_addition(true);
        $result = update_option(self::OPTION_NAME, Aether_Settings_Validator::get_defaults(), 'no');
        wp_suspend_cache_addition(false);

        // 清理对象缓存，确保配置立即生效
        wp_cache_delete(self::OPTION_NAME, 'options');
        wp_cache_delete('alloptions', 'options');

        // 清理全站页面缓存（设置影响全站）
        if (class_exists('Aether_Cache_Helper')) {
            $cache_cleared = Aether_Cache_Helper::clear_page_cache();
            if (!$cache_cleared) {
                error_log('[aether] Warning: Settings reset but page cache clearing failed');
            }
        }

        return $result;
    }


    /**
     * 检查 AI 功能是否已配置
     * 
     * @return bool
     */
    public static function is_configured()
    {
        $settings = self::get_all();
        return !empty($settings['api_key']) &&
            !empty($settings['base_url']) &&
            !empty($settings['api_token']) &&
            $settings['enabled'];
    }

    /**
     * 检查指定文章类型是否启用了 AI 功能
     * 
     * @param string $post_type 文章类型
     * @return bool
     */
    public static function is_enabled_for_post_type($post_type)
    {
        if (!self::is_configured()) {
            return false;
        }

        $enabled_types = self::get('post_types', []);
        return in_array($post_type, $enabled_types);
    }

    /**
     * 获取正式上线模式状态
     *
     * @deprecated 2.0.0 页面级 CSS 编译已取代全局生产模式，此方法始终返回 false
     * @return bool Always returns false
     */
    public static function get_production_mode()
    {
        _deprecated_function(__METHOD__, '2.0.0', 'Page-level CSS compilation');
        return false;
    }

    /**
     * 设置正式上线模式状态
     *
     * @deprecated 2.0.0 页面级 CSS 编译已取代全局生产模式，此方法不再生效
     * @param bool $enabled 是否启用正式上线模式（已忽略）
     * @return bool Always returns true
     */
    public static function set_production_mode($enabled)
    {
        _deprecated_function(__METHOD__, '2.0.0', 'Page-level CSS compilation');
        // 不再保存 production_mode 设置，返回 true 表示"成功"以保持向后兼容
        return true;
    }

    /**
     * 获取设置页面的本地化数据
     * 
     * @return array
     */
    public static function get_localized_data()
    {
        $post_types = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($post_types as $post_type) {
            // 排除附件类型
            if (is_object($post_type) && $post_type->name === 'attachment') {
                continue;
            }

            if (is_object($post_type)) {
                $result[$post_type->name] = $post_type->label;
            }
        }

        return [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aether-settings'),
            'settings' => self::get_all(),
            'post_types' => $result,
            'messages' => [
                'save_success' => __('设置已保存', 'aether'),
                'save_error' => __('保存失败，请重试', 'aether'),
                'save_unchanged' => __('设置未发生变化', 'aether'),
                'reset_success' => __('设置已重置为默认值', 'aether'),
                'reset_error' => __('重置失败，请重试', 'aether'),
                'reset_confirm' => __('确定要重置所有设置为默认值吗？', 'aether'),
            ]
        ];
    }

    /**
     * 获取可用的文章类型（排除系统类型）
     * 
     * @return array
     */
    public static function get_available_post_types()
    {
        $post_types = get_post_types(['public' => true], 'objects');
        $excluded_types = [
            'attachment',
            'aether_template'
        ];

        $available_post_types = [];
        foreach ($post_types as $post_type) {
            if (is_object($post_type) && !in_array($post_type->name, $excluded_types)) {
                $available_post_types[] = [
                    'name' => $post_type->name,
                    'label' => $post_type->label
                ];
            }
        }

        return $available_post_types;
    }

    /**
     * 验证 API Token
     * 
     * @param string $token API Token
     * @return array 验证结果
     */
    /**
     * 验证 API Token - 免费版无需验证
     */

    /**
     * 验证 API Token - 免费版无需验证
     *
     * @param string $token API Token (ignored in free version)
     * @return array 验证结果
     */
    public static function verify_api_token($token)
    {
        return [
            'valid' => true,
            'message' => 'Token 验证通过（免费版无需验证）'
        ];
    }
