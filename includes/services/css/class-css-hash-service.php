<?php
/**
 * CSS 哈希计算服务
 *
 * 提供 header/footer 和设计系统的哈希值计算，带缓存机制
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_CSS_Hash_Service {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 缓存过期时间（1 小时）
     */
    const CACHE_EXPIRATION = 3600;

    /**
     * 获取单例实例
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有构造函数
     */
    private function __construct() {
    }

    /**
     * 获取 header/footer 的内容哈希（带缓存）
     *
     * @return string 哈希值
     */
    public function get_header_footer_hash() {
        $cache_key = 'aether_header_footer_hash';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // 计算哈希
        $compile_service = Aether_CSS_Compile_Strategy_Service::get_instance();
        $common_templates = $compile_service->get_common_templates();
        $header = $common_templates['header'] ?? '';
        $footer = $common_templates['footer'] ?? '';
        $hash = md5($header . $footer);

        // 缓存 1 小时
        set_transient($cache_key, $hash, self::CACHE_EXPIRATION);

        return $hash;
    }

    /**
     * 获取设计系统的内容哈希（带缓存）
     *
     * @return string 哈希值
     */
    public function get_design_system_hash() {
        $cache_key = 'aether_design_system_hash';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // 计算哈希
        $design_system_html = Aether_Settings_Service::get('design_system_html', '');
        $hash = md5($design_system_html);

        // 缓存 1 小时
        set_transient($cache_key, $hash, self::CACHE_EXPIRATION);

        return $hash;
    }

    /**
     * 清除 header/footer 哈希缓存（模板保存时调用）
     */
    public function clear_header_footer_hash_cache() {
        delete_transient('aether_header_footer_hash');
    }

    /**
     * 清除设计系统哈希缓存（设计系统变更时调用）
     */
    public function clear_design_system_hash_cache() {
        delete_transient('aether_design_system_hash');
    }

    /**
     * 清除所有哈希缓存
     */
    public function clear_all_hash_cache() {
        $this->clear_header_footer_hash_cache();
        $this->clear_design_system_hash_cache();
    }

    /**
     * 检查页面的编译 CSS 是否有效
     *
     * @param int $post_id 文章 ID
     * @return bool 是否有效
     */
    public function is_page_css_valid($post_id) {
        // 检查是否有编译 CSS
        $has_css = get_post_meta($post_id, '_aether_has_compiled_css', true);
        if (!$has_css) {
            return false;
        }

        // 检查 header/footer 哈希
        $saved_hf_hash = get_post_meta($post_id, '_aether_css_header_footer_hash', true);
        $current_hf_hash = $this->get_header_footer_hash();

        if ($saved_hf_hash !== $current_hf_hash) {
            return false; // header/footer 已变更
        }

        // 检查设计系统哈希
        $saved_ds_hash = get_post_meta($post_id, '_aether_css_design_system_hash', true);
        $current_ds_hash = $this->get_design_system_hash();

        if ($saved_ds_hash !== $current_ds_hash) {
            return false; // 设计系统已变更
        }

        return true;
    }

    /**
     * 检查模板的编译 CSS 是否有效
     * 模板包含 header + 模板内容 + footer，需要同时检查两个哈希
     *
     * @param string $template_type 模板类型
     * @return bool 是否有效
     */
    public function is_template_css_valid($template_type) {
        $debug_enabled = defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;

        if (empty($template_type)) {
            return false;
        }

        // 检查是否有编译 CSS
        $compiled_at = get_option('aether_template_css_' . $template_type . '_compiled_at', 0);
        if (empty($compiled_at)) {
            if ($debug_enabled) {
                error_log(sprintf('[Aether CSS Hash] Template CSS invalid: no compiled_at for type=%s', $template_type));
            }
            return false;
        }

        // 检查设计系统哈希
        $saved_ds_hash = get_option('aether_template_css_' . $template_type . '_design_system_hash', '');
        $current_ds_hash = $this->get_design_system_hash();
        if ($saved_ds_hash !== $current_ds_hash) {
            if ($debug_enabled) {
                error_log(sprintf(
                    '[Aether CSS Hash] Template CSS invalid: design_system_hash mismatch for type=%s (saved=%s, current=%s)',
                    $template_type,
                    substr($saved_ds_hash, 0, 8) . '...',
                    substr($current_ds_hash, 0, 8) . '...'
                ));
            }
            return false;
        }

        // 检查 header/footer 哈希（模板也包含 header/footer）
        // 注意：必须有 header/footer 哈希才算有效，旧数据没有哈希的视为无效需要重编译
        $saved_hf_hash = get_option('aether_template_css_' . $template_type . '_header_footer_hash', '');
        $current_hf_hash = $this->get_header_footer_hash();
        if (empty($saved_hf_hash) || $saved_hf_hash !== $current_hf_hash) {
            if ($debug_enabled) {
                error_log(sprintf(
                    '[Aether CSS Hash] Template CSS invalid: header_footer_hash mismatch for type=%s (saved=%s, current=%s)',
                    $template_type,
                    $saved_hf_hash ? substr($saved_hf_hash, 0, 8) . '...' : 'empty',
                    substr($current_hf_hash, 0, 8) . '...'
                ));
            }
            return false;
        }

        if ($debug_enabled) {
            error_log(sprintf('[Aether CSS Hash] Template CSS valid for type=%s', $template_type));
        }

        return true;
    }
}
