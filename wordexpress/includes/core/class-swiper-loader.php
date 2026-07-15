<?php
/**
 * Swiper.js Loader
 *
 * Handles conditional loading of Swiper.js library from CDN for WordPress frontend.
 * Detects Swiper usage at save time and stores a meta flag for efficient frontend loading.
 *
 * @package ZeroY
 * @since 1.1.19
 */

namespace ZeroY\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Swiper_Loader
 *
 * Manages conditional Swiper.js CDN resources loading.
 */
class Swiper_Loader {
    /**
     * Swiper version
     */
    private const SWIPER_VERSION = '11.1.15';

    /**
     * CDN base URL
     */
    private const CDN_BASE = 'https://cdn.jsdelivr.net/npm/swiper@';

    /**
     * Meta key for Swiper requirement flag
     */
    private const META_KEY = '_zeroy_needs_swiper';

    /**
     * Swiper class patterns to detect
     * Only check essential structural classes that are always required
     */
    private const SWIPER_PATTERNS = [
        'swiper-wrapper',
        'swiper-slide',
    ];

    /**
     * Initialize the loader
     */
    public static function init(): void {
        // Frontend: conditionally load Swiper based on meta
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_swiper'], 100);

        // Backend: detect Swiper on content save
        add_action('zeroy_content_saved', [__CLASS__, 'on_content_saved']);
        add_action('zeroy_template_saved', [__CLASS__, 'on_template_saved']);
    }

    /**
     * Handle content save - detect and mark Swiper requirement
     *
     * @param array $data Save data with post_id, post_type, source
     */
    public static function on_content_saved(array $data): void {
        $post_id = $data['post_id'] ?? 0;
        $post_type = $data['post_type'] ?? '';

        if (!$post_id) {
            return;
        }

        // Skip templates - they trigger zeroy_template_saved hook separately
        // Both Content API and Template API will trigger zeroy_template_saved for templates
        if ($post_type === 'zeroy_template') {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Swiper Loader] zeroy_content_saved 收到 template #%d，跳过（由 zeroy_template_saved 处理）',
                    $post_id
                ));
            }
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $content = $post->post_content;
        self::update_swiper_meta($post_id, $content);
    }

    /**
     * Handle template save - detect and mark Swiper requirement
     *
     * @param array $data Save data with id, type, content, action
     */
    public static function on_template_saved(array $data): void {
        $post_id = $data['id'] ?? 0;
        $content = $data['content'] ?? null;  // 使用 null 区分"未提供"和"空字符串"
        $content_provided = isset($data['content']);

        if (!$post_id) {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[Swiper Loader] Template #%d 保存触发，content %s (长度: %d)',
                $post_id,
                $content_provided ? '已提供' : '未提供',
                is_string($content) ? strlen($content) : 0
            ));
        }

        // If content not provided in hook data, fetch from meta
        if ($content === null || $content === '') {
            $content = get_post_meta($post_id, \ZeroY_Templates::CONTENT_META_KEY, true);

            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Swiper Loader] Template #%d 从 meta 读取内容 (长度: %d)',
                    $post_id,
                    is_string($content) ? strlen($content) : 0
                ));
            }

            // If still no content, skip detection to avoid false negatives
            if (empty($content)) {
                if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    error_log(sprintf(
                        '[Swiper Loader] Template #%d 无内容可检测，跳过（保持现有 meta）',
                        $post_id
                    ));
                }
                return;  // 保持现有 meta 不变
            }
        }

        self::update_swiper_meta($post_id, $content);
    }

    /**
     * Update Swiper meta based on content detection
     *
     * @param int $post_id Post ID
     * @param string $content Content to check
     */
    private static function update_swiper_meta(int $post_id, string $content): void {
        $needs_swiper = self::content_has_swiper($content);
        $content_length = strlen($content);

        if ($needs_swiper) {
            update_post_meta($post_id, self::META_KEY, '1');

            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Swiper Loader] Post #%d: Swiper 检测到，已设置 meta (内容长度: %d)',
                    $post_id,
                    $content_length
                ));
            }
        } else {
            delete_post_meta($post_id, self::META_KEY);

            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Swiper Loader] Post #%d: 未检测到 Swiper，已删除 meta (内容长度: %d)',
                    $post_id,
                    $content_length
                ));
            }
        }
    }

    /**
     * Check if content contains Swiper patterns
     *
     * @param string $content Content to check
     * @return bool
     */
    public static function content_has_swiper(string $content): bool {
        if (empty($content)) {
            return false;
        }

        foreach (self::SWIPER_PATTERNS as $pattern) {
            if (stripos($content, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Conditionally enqueue Swiper.js based on meta flags
     */
    public static function maybe_enqueue_swiper(): void {
        if (!self::page_needs_swiper()) {
            return;
        }

        self::enqueue_swiper();
    }

    /**
     * Check if current page needs Swiper by reading meta flags
     *
     * @return bool
     */
    private static function page_needs_swiper(): bool {
        // Check singular post/page
        if (is_singular()) {
            global $post;
            if ($post && get_post_meta($post->ID, self::META_KEY, true)) {
                return true;
            }
        }

        // Check dynamic templates
        if (!class_exists('ZeroY_Templates') || !class_exists('ZeroY_Dynamic_Template_Types')) {
            return false;
        }

        $templates = \ZeroY_Templates::getInstance();
        $template_types = \ZeroY_Dynamic_Template_Types::getInstance();

        // Check header template
        $header = $templates->get_active_template('header');
        if ($header && !empty($header['id']) && get_post_meta($header['id'], self::META_KEY, true)) {
            return true;
        }

        // Check footer template
        $footer = $templates->get_active_template('footer');
        if ($footer && !empty($footer['id']) && get_post_meta($footer['id'], self::META_KEY, true)) {
            return true;
        }

        // Check current page type template
        $current_type = $template_types->get_current_page_template_type();
        if ($current_type) {
            $page_template = $templates->get_active_template($current_type);
            if ($page_template && !empty($page_template['id']) && get_post_meta($page_template['id'], self::META_KEY, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enqueue Swiper.js CSS and JavaScript
     */
    public static function enqueue_swiper(): void {
        $version = self::SWIPER_VERSION;
        $cdn_base = self::CDN_BASE . $version;

        // Enqueue Swiper CSS
        wp_enqueue_style(
            'swiper',
            $cdn_base . '/swiper-bundle.min.css',
            [],
            $version,
            'all'
        );

        // Enqueue Swiper JS
        wp_enqueue_script(
            'swiper',
            $cdn_base . '/swiper-bundle.min.js',
            [],
            $version,
            true
        );

        // Dispatch event after Swiper is loaded for AI-generated code to listen
        wp_add_inline_script(
            'swiper',
            "window.dispatchEvent(new CustomEvent('zeroy:swiper:ready'));",
            'after'
        );
    }

    /**
     * Get Swiper version
     *
     * @return string
     */
    public static function get_version(): string {
        return self::SWIPER_VERSION;
    }

    /**
     * Get CDN URL for Swiper CSS
     *
     * @return string
     */
    public static function get_css_url(): string {
        return self::CDN_BASE . self::SWIPER_VERSION . '/swiper-bundle.min.css';
    }

    /**
     * Get CDN URL for Swiper JS
     *
     * @return string
     */
    public static function get_js_url(): string {
        return self::CDN_BASE . self::SWIPER_VERSION . '/swiper-bundle.min.js';
    }
}
