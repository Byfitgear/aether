<?php
/**
 * CSS 存储管理服务
 * 
 * 负责统一管理 CSS 的保存、检索和组合
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_CSS_Storage_Service
{
    /**
     * 单例实例
     */
    private static $instance = null;


    /**
     * 获取单例实例
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有构造函数
     */
    private function __construct()
    {
    }

    /**
     * 保存页面 CSS
     * 
     * @param int $page_id 页面 ID
     * @param string $css CSS 内容
     * @param array $stats 统计信息
     * @return void
     */
    public function save_page_css($page_id, $css, $stats = [])
    {
        if (!empty($css)) {
            // 使用 base64 编码来保存 CSS，避免 WordPress 转义字符问题
            $encoded_css = base64_encode($css);
            update_post_meta($page_id, '_aether_compiled_css', $encoded_css);
            update_post_meta($page_id, '_aether_css_encoded', '1');
            update_post_meta($page_id, '_aether_css_size', $stats['optimized_size'] ?? strlen($css));
            update_post_meta($page_id, '_aether_original_size', $stats['original_size'] ?? 0);
            update_post_meta($page_id, '_aether_has_compiled_css', '1'); // 标记有编译 CSS

            // 保存哈希值（用于判断 CSS 是否过期）
            if (class_exists('Aether_CSS_Hash_Service')) {
                $hash_service = Aether_CSS_Hash_Service::get_instance();
                update_post_meta($page_id, '_aether_css_header_footer_hash', $hash_service->get_header_footer_hash());
                update_post_meta($page_id, '_aether_css_design_system_hash', $hash_service->get_design_system_hash());
            }
        } else {
            update_post_meta($page_id, '_aether_compiled_css', '');
            update_post_meta($page_id, '_aether_css_encoded', '0');
            update_post_meta($page_id, '_aether_css_size', 0);
            update_post_meta($page_id, '_aether_original_size', 0);
            delete_post_meta($page_id, '_aether_has_compiled_css'); // 清除标记
        }
        update_post_meta($page_id, '_aether_css_compiled_at', current_time('timestamp'));
    }

    /**
     * 保存模板 CSS
     *
     * @param string $type 模板类型
     * @param string $css CSS 内容
     * @param array $stats 统计信息
     * @return void
     */
    public function save_template_css($type, $css, $stats = [])
    {
        if (!empty($css)) {
            // 使用 base64 编码来保存 CSS，避免 WordPress 转义字符问题
            $encoded_css = base64_encode($css);
            update_option('aether_template_css_' . $type, $encoded_css, false);
            update_option('aether_template_css_' . $type . '_encoded', '1', false);
            update_option('aether_template_css_' . $type . '_size', $stats['optimized_size'] ?? strlen($css), false);
            update_option('aether_template_css_' . $type . '_original_size', $stats['original_size'] ?? 0, false);
        } else {
            update_option('aether_template_css_' . $type, '', false);
            update_option('aether_template_css_' . $type . '_encoded', '0', false);
            update_option('aether_template_css_' . $type . '_size', 0, false);
            update_option('aether_template_css_' . $type . '_original_size', 0, false);
        }
        update_option('aether_template_css_' . $type . '_compiled_at', current_time('timestamp'), false);

        // 保存哈希值（用于判断 CSS 是否过期）
        if (class_exists('Aether_CSS_Hash_Service')) {
            $hash_service = Aether_CSS_Hash_Service::get_instance();
            update_option('aether_template_css_' . $type . '_design_system_hash', $hash_service->get_design_system_hash(), false);
            update_option('aether_template_css_' . $type . '_header_footer_hash', $hash_service->get_header_footer_hash(), false);
        }

        // 清理对象缓存，确保配置立即生效
        $this->clear_template_option_cache($type);
    }

    /**
     * 获取页面 CSS
     * 
     * @param int $page_id 页面 ID
     * @param bool $decode 是否解码
     * @return string
     */
    public function get_page_css($page_id, $decode = true)
    {
        $css = get_post_meta($page_id, '_aether_compiled_css', true);
        $is_encoded = get_post_meta($page_id, '_aether_css_encoded', true);

        // 如果 CSS 是 base64 编码的，先解码
        if ($decode && $is_encoded === '1' && !empty($css)) {
            $css = base64_decode($css);
        }

        return $css;
    }

    /**
     * 获取模板 CSS
     * 
     * @param string $type 模板类型
     * @param bool $decode 是否解码
     * @return string
     */
    public function get_template_css($type, $decode = true)
    {
        $css = get_option('aether_template_css_' . $type, '');
        $is_encoded = get_option('aether_template_css_' . $type . '_encoded', '0');

        // 如果 CSS 是 base64 编码的，先解码
        if ($decode && $is_encoded === '1' && !empty($css)) {
            $css = base64_decode($css);
        }

        return $css;
    }

    /**
     * 获取当前页面的完整 CSS
     *
     * @param int $post_id 文章 ID
     * @return string
     */
    public function get_page_complete_css($post_id = null)
    {
        $combined_css = '';

        // 获取主模板或页面的 CSS（已包含页眉页脚）
        $template_types = Aether_Dynamic_Template_Types::getInstance();
        $current_type = $template_types->get_current_page_template_type();

        if (is_singular()) {
            // 单个页面/文章 - 优先使用页面特定的 CSS
            if (!$post_id) {
                $post_id = get_the_ID();
            }

            if ($post_id) {
                $css = $this->get_page_css($post_id);
                if (!empty($css)) {
                    $combined_css .= "/* Page/Post CSS */\n" . $css . "\n";
                } elseif ($current_type) {
                    // 如果页面没有特定 CSS，尝试使用模板 CSS
                    $css = $this->get_template_css($current_type);
                    if (!empty($css)) {
                        $combined_css .= "/* Template: {$current_type} CSS */\n" . $css . "\n";
                    }
                }
            }
        } elseif ($current_type) {
            // 非单页（归档、分类等）使用模板 CSS
            $css = $this->get_template_css($current_type);
            if (!empty($css)) {
                $combined_css .= "/* Template: {$current_type} CSS */\n" . $css . "\n";
            }
        }

        return $combined_css;
    }

    /**
     * 获取页面统计信息
     * 
     * @param int $page_id 页面 ID
     * @return array
     */
    public function get_page_stats($page_id)
    {
        return [
            'css_size' => get_post_meta($page_id, '_aether_css_size', true) ?: 0,
            'original_size' => get_post_meta($page_id, '_aether_original_size', true) ?: 0,
            'compiled_at' => get_post_meta($page_id, '_aether_css_compiled_at', true) ?: 0,
        ];
    }

    /**
     * 获取模板统计信息
     * 
     * @param string $type 模板类型
     * @return array
     */
    public function get_template_stats($type)
    {
        return [
            'css_size' => get_option('aether_template_css_' . $type . '_size', 0),
            'original_size' => get_option('aether_template_css_' . $type . '_original_size', 0),
            'compiled_at' => get_option('aether_template_css_' . $type . '_compiled_at', 0),
        ];
    }

    /**
     * 清除页面 CSS
     * 
     * @param int $page_id 页面 ID
     * @return void
     */
    public function clear_page_css($page_id)
    {
        delete_post_meta($page_id, '_aether_compiled_css');
        delete_post_meta($page_id, '_aether_css_encoded');
        delete_post_meta($page_id, '_aether_css_size');
        delete_post_meta($page_id, '_aether_original_size');
        delete_post_meta($page_id, '_aether_css_compiled_at');
    }

    /**
     * 清除模板 CSS
     * 
     * @param string $type 模板类型
     * @return void
     */
    public function clear_template_css($type)
    {
        delete_option('aether_template_css_' . $type);
        delete_option('aether_template_css_' . $type . '_encoded');
        delete_option('aether_template_css_' . $type . '_size');
        delete_option('aether_template_css_' . $type . '_original_size');
        delete_option('aether_template_css_' . $type . '_compiled_at');
    }

    /**
     * 清除所有编译的 CSS
     *
     * @param bool $clear_markers 是否同时清除编译标记（默认 true）
     * @return void
     */
    public function clear_all_css($clear_markers = true)
    {
        global $wpdb;

        // 清除所有页面的 CSS 内容（使用 WordPress 原生函数，自动清理缓存）
        $css_meta_keys = [
            '_aether_compiled_css',
            '_aether_css_encoded',
            '_aether_css_size',
            '_aether_original_size',
            '_aether_css_compiled_at',
        ];
        foreach ($css_meta_keys as $meta_key) {
            delete_metadata('post', 0, $meta_key, '', true);
        }

        // 清除所有模板的 CSS（options 表可以用 SQL，因为没有对象缓存问题）
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'aether_template_css_%'");

        // 清除全局编译标记
        delete_option('aether_use_smart_css');
        delete_option('aether_smart_css_compiled_at');
        delete_option('aether_smart_css_stats');

        // 同时清除页面编译标记（避免"有标记无 CSS"问题）
        if ($clear_markers) {
            $marker_meta_keys = [
                '_aether_has_compiled_css',
                '_aether_css_header_footer_hash',
                '_aether_css_design_system_hash',
                '_aether_css_compile_source',
                '_aether_compile_failed_at',
            ];
            foreach ($marker_meta_keys as $meta_key) {
                delete_metadata('post', 0, $meta_key, '', true);
            }
        }
    }

    /**
     * 清除特定的旧缓存（header 和 footer）
     * 
     * @return void
     */
    public function clear_legacy_header_footer_css()
    {
        // 清除页眉 CSS
        delete_option('aether_template_css_header');
        delete_option('aether_template_css_header_encoded');
        delete_option('aether_template_css_header_size');
        delete_option('aether_template_css_header_original_size');
        delete_option('aether_template_css_header_compiled_at');

        // 清除页脚 CSS
        delete_option('aether_template_css_footer');
        delete_option('aether_template_css_footer_encoded');
        delete_option('aether_template_css_footer_size');
        delete_option('aether_template_css_footer_original_size');
        delete_option('aether_template_css_footer_compiled_at');
    }

    /**
     * 清理模板 CSS 相关的对象缓存
     *
     * @param string $type 模板类型
     * @return void
     */
    private function clear_template_option_cache($type)
    {
        wp_cache_delete('aether_template_css_' . $type, 'options');
        wp_cache_delete('aether_template_css_' . $type . '_encoded', 'options');
        wp_cache_delete('aether_template_css_' . $type . '_size', 'options');
        wp_cache_delete('aether_template_css_' . $type . '_original_size', 'options');
        wp_cache_delete('aether_template_css_' . $type . '_compiled_at', 'options');
        wp_cache_delete('alloptions', 'options');
    }
}
