<?php
/**
 * HTML 渲染 Filter
 *
 * 在前端渲染时，通过 the_content 钩子替换为优化后的 HTML
 *
 * @package aether
 * @subpackage Services
 * @since 1.1.29
 */

defined('ABSPATH') || exit;

class Aether_HTML_Render_Filter
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
        // 在 WordPress 标准处理之后执行（wpautop 优先级 10）
        add_filter('the_content', [$this, 'maybe_use_optimized_html'], 20);
    }

    /**
     * 根据条件决定是否使用优化后的 HTML
     *
     * @param string $content 原始内容
     * @return string 最终输出的内容
     */
    public function maybe_use_optimized_html($content)
    {
        $this->log('进入 maybe_use_optimized_html');

        // 1. 后台不处理
        if (is_admin()) {
            $this->log('跳过: 后台请求');
            return $content;
        }

        // 2. REST API 请求不处理
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $this->log('跳过: REST API 请求');
            return $content;
        }

        // 3. 检查全局开关
        $service = Aether_HTML_Optimization_Service::get_instance();
        if (!$service->is_optimization_enabled()) {
            $this->log('跳过: 图片优化开关关闭');
            return $content;  // 关闭时直接返回原内容
        }

        // 4. 仅处理 Page（不处理 Post 和 CPT）
        if (!is_singular('page')) {
            $this->log('跳过: 非 Page 类型 (is_singular check failed)');
            return $content;
        }

        // 5. 获取当前页面 ID
        $post_id = get_the_ID();
        if (!$post_id) {
            $this->log('跳过: 无法获取 post_id');
            return $content;
        }

        $this->log(sprintf('处理 Page ID: %d', $post_id));

        // 6. 读取优化版本
        $optimized = $service->get_optimized_html($post_id);

        // 7. 如果优化版本无效，降级使用原始内容
        if (empty($optimized) || !is_string($optimized)) {
            $this->log(sprintf('跳过: 无优化版本 - Page ID: %d', $post_id));
            return $content;
        }

        // 8. 检查缓存版本，旧版本缓存首访自愈重建
        $cached_version = get_post_meta($post_id, '_aether_html_cache_version', true);
        $current_version = AETHER_VERSION;

        if (empty($cached_version) || version_compare($cached_version, $current_version, '<')) {
            $refresh_result = $service->refresh_page_optimized_html($post_id, true);
            $optimized = $service->get_optimized_html($post_id);

            if (empty($optimized) || !is_string($optimized)) {
                $this->log(sprintf(
                    '旧缓存重建后无可用优化 HTML，回退原始内容 - Page ID: %d, Result: %s',
                    $post_id,
                    wp_json_encode($refresh_result)
                ));
                return $content;
            }

            $this->log(sprintf('已重建旧缓存 - Page ID: %d, Version: %s -> %s', $post_id, $cached_version ?: 'none', $current_version));
        }

        $this->log(sprintf('使用优化 HTML - Page ID: %d', $post_id));

        return $optimized;
    }

    /**
     * 修复旧缓存中的 h-auto 冲突
     *
     * 移除同时包含固定高度类（h-80, h-[200px] 等）和 h-auto 的冲突
     * 这是 v1.1.28 版本的 bug，需要实时修复旧缓存
     *
     * @param string $html HTML 内容
     * @return string 修复后的 HTML
     */
    private function fix_height_class_conflict($html)
    {
        // 匹配包含固定高度类的 img 标签
        // 支持：h-80, h-[200px], h-[20rem], h-[calc(...)] 等
        $pattern = '/<img([^>]*class="[^"]*\bh-(?:\d+|\[[^\]]+\])[^"]*"[^>]*)>/i';

        return preg_replace_callback($pattern, function($matches) {
            $img_tag = $matches[0];

            // 移除 h-auto（可能有多个空格）
            $fixed = preg_replace('/\s*\bh-auto\b\s*/', ' ', $img_tag);

            // 清理多余空格
            $fixed = preg_replace('/\s+/', ' ', $fixed);
            $fixed = preg_replace('/class="\s+/', 'class="', $fixed);
            $fixed = preg_replace('/\s+"/', '"', $fixed);

            return $fixed;
        }, $html);
    }

    /**
     * 日志记录
     *
     * @param string $message 日志消息
     * @return void
     */
    private function log($message)
    {
        if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
            error_log('[Aether HTML Render Filter] ' . $message);
        }
    }
}
