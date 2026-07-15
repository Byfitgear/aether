<?php
/**
 * 内容处理器
 * 
 * 处理编辑器内容的过滤和转换
 *
 * @package aether
 * @subpackage Editor
 */

defined('ABSPATH') || exit;

/**
 * 内容处理类
 */
class Aether_Editor_Content extends Aether_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        // 禁用 wpautop
        add_filter('the_content', [$this, 'maybe_disable_wpautop'], 1);
        // 禁用可能干扰 PHP 代码的过滤器
        add_filter('the_content', [$this, 'maybe_disable_content_filters'], 0);
    }
    
    /**
     * 可能禁用 wpautop
     * 
     * @param string $content 文章内容
     * @return string
     */
    public function maybe_disable_wpautop($content) {
        global $post;
        
        // Ensure content is not null
        if ($content === null) {
            $content = '';
        }
        
        if (!$post) {
            return $content;
        }
        
        // 检查是否使用 aether 编辑
        $aether_edited = get_post_meta($post->ID, '_aether_edited', true);
        
        if ($aether_edited) {
            // 移除 wpautop 过滤器
            remove_filter('the_content', 'wpautop');
            // 移除 wptexturize（避免引号等字符被转换）
            remove_filter('the_content', 'wptexturize');
            // 移除可能干扰 PHP 标签的过滤器
            remove_filter('the_content', 'convert_chars');
            remove_filter('the_content', 'capital_P_dangit');
        }
        
        return $content;
    }

    /**
     * 可能禁用内容过滤器
     * 
     * @param string $content 内容
     * @return string
     */
    public function maybe_disable_content_filters($content) {
        global $post;
        
        if ($content === null) {
            $content = '';
        }
        
        if (!$post) {
            return $content;
        }
        
        // 检查是否使用 aether 编辑
        $aether_edited = get_post_meta($post->ID, '_aether_edited', true);
        
        if ($aether_edited) {
            // 检查是否包含 PHP 代码
            if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
                // 移除所有可能干扰 PHP 标签的过滤器
                remove_filter('the_content', 'wp_make_content_images_responsive');
                remove_filter('the_content', 'do_shortcode', 11);

                // 注意：content_save_pre 过滤器已由 PHP Protection Manager 系统处理
                // No need to remove save stage filters in display stage (invalid operation)
                // 详见: includes/services/core/class-php-protection-manager.php
            }
        }
        
        return $content;
    }
}