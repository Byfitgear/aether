<?php
/**
 * 生产模式管理服务
 *
 * @deprecated 2.0.0 页面级 CSS 编译已取代全局生产模式。
 *             CSS 现在在每个页面保存时自动编译，无需手动切换模式。
 *             此类保留仅用于缓存清理钩子，生产模式相关方法已无效。
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @deprecated 2.0.0 Use page-level CSS compilation instead
 */
class Aether_Production_Mode_Manager {

    /**
     * Initialize hooks
     *
     * @deprecated 2.0.0 生产模式已废弃，仅保留缓存清理钩子
     */
    public static function init() {
        // 缓存清理钩子（这些仍然有用）
        add_action('aether_content_saved', [__CLASS__, 'on_content_cache_clear']);
        add_action('aether_template_saved', [__CLASS__, 'on_global_cache_clear']); // 模板影响全站，需全站清理
        add_action('aether_font_settings_updated', [__CLASS__, 'on_global_cache_clear']);

        // 字体设置变更后触发全站重编译（确保新字体 CSS 被包含）
        add_action('aether_font_settings_updated', [__CLASS__, 'on_font_settings_updated']);

        // 以下钩子已废弃，不再需要自动切换生产模式
        // add_action('aether_content_saved', [__CLASS__, 'on_aether_content_save']);
        // add_action('aether_template_saved', [__CLASS__, 'on_aether_content_save']);
        // add_action('aether_font_downloaded', [__CLASS__, 'on_aether_content_save']);
        // add_action('aether_font_settings_updated', [__CLASS__, 'on_aether_content_save']);
    }

    /**
     * 当 aether 内容保存时切换到开发模式
     *
     * @deprecated 2.0.0 页面级编译无需切换模式
     * @param mixed $data 保存的数据（可以是数组或其他）
     */
    public static function on_aether_content_save($data = null) {
        // 已废弃，不再执行任何操作
        _deprecated_function(__METHOD__, '2.0.0', 'Page-level CSS compilation');
    }

    /**
     * 禁用生产模式，切换到开发模式
     *
     * @deprecated 2.0.0 页面级编译无需切换模式
     */
    private static function disable_production_mode() {
        // 已废弃，不再执行任何操作
    }

    /**
     * 单页内容保存后清理缓存
     *
     * @param array $data 保存的数据（支持 post_id 或 id）
     */
    public static function on_content_cache_clear($data) {
        if (!class_exists('Aether_Cache_Helper')) {
            return;
        }

        // 兼容不同的数据结构：aether_content_saved 用 post_id，aether_template_saved 用 id
        $post_id = isset($data['post_id']) ? $data['post_id'] : (isset($data['id']) ? $data['id'] : null);

        if ($post_id) {
            $cache_cleared = Aether_Cache_Helper::clear_post_cache($post_id);
            if (!$cache_cleared) {
                error_log(sprintf('[aether] Warning: Content saved (post_id: %d) but cache clearing failed', $post_id));
            }
        }
    }

    /**
     * 全局设置保存后清理缓存
     *
     * @param mixed $data 保存的数据
     */
    public static function on_global_cache_clear($data) {
        if (class_exists('Aether_Cache_Helper')) {
            $cache_cleared = Aether_Cache_Helper::clear_page_cache();
            if (!$cache_cleared) {
                error_log('[aether] Warning: Global settings saved but page cache clearing failed');
            }
        }
    }

    /**
     * 字体设置变更后触发全站重编译
     *
     * @param mixed $data 保存的数据
     */
    public static function on_font_settings_updated($data) {
        // 清理所有已编译的 CSS（因为字体变更影响所有页面）
        if (class_exists('Aether_CSS_Storage_Service')) {
            Aether_CSS_Storage_Service::get_instance()->clear_all_css();
        }

        // 清除哈希缓存
        if (class_exists('Aether_CSS_Hash_Service')) {
            Aether_CSS_Hash_Service::get_instance()->clear_all_hash_cache();
        }

        // 调度全站重编译
        if (class_exists('Aether_Template_Compile_Manager')) {
            Aether_Template_Compile_Manager::schedule_recompile_all([
                'trigger' => 'font_settings_changed',
                'user_id' => get_current_user_id()
            ]);
        }

        error_log('[aether] Font settings updated, scheduled full site recompile');
    }

    /**
     * 获取当前状态信息
     *
     * @deprecated 2.0.0 Use page-level CSS status instead
     * @return array
     */
    public static function get_status() {
        _deprecated_function(__METHOD__, '2.0.0', 'Page-level CSS status');

        // 返回固定值，表示总是使用页面级编译
        return [
            'production_mode' => false,
            'has_compiled_css' => false,
            'should_use_smart_css' => false,
            'status' => 'page-level',
            'message' => '已迁移到页面级 CSS 编译，全局生产模式已废弃'
        ];
    }

    /**
     * 检查是否处于生产模式
     *
     * @deprecated 2.0.0 Use page-level CSS compilation instead
     * @return bool Always returns false
     */
    public static function is_production_mode() {
        _deprecated_function(__METHOD__, '2.0.0', 'Page-level CSS compilation');
        return false;
    }
}

// Initialize hooks
Aether_Production_Mode_Manager::init();
