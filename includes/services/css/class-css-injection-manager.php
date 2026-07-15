<?php
/**
 * CSS Injection Manager
 *
 * Unified system for managing all CSS injection in the plugin
 * 改造为页面级 CSS 注入，移除全局生产模式判断
 *
 * @package Aether
 * @subpackage Services\CSS
 */

// 如果直接访问此文件，则中止执行
if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSS Injection Manager Class
 */
class Aether_CSS_Injection_Manager
{

    /**
     * Singleton instance
     *
     * @var Aether_CSS_Injection_Manager
     */
    private static $instance = null;

    /**
     * Registered CSS providers
     *
     * @var array
     */
    private $providers = [];

    /**
     * Get singleton instance
     *
     * @return Aether_CSS_Injection_Manager
     */
    public static function get_instance()
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
        // Load provider classes
        $this->load_providers();
    }

    /**
     * Load provider classes
     */
    private function load_providers()
    {
        $provider_files = [
            'class-design-system-css-provider.php',
            'class-font-css-provider.php',
            'class-page-css-provider.php',
            'class-tailwind-cdn-provider.php',
        ];

        foreach ($provider_files as $file) {
            $path = AETHER_PATH . 'includes/services/css/providers/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Register a CSS provider
     *
     * @param Aether_CSS_Provider_Interface $provider
     * @return void
     */
    public function register_provider(Aether_CSS_Provider_Interface $provider)
    {
        $this->providers[] = $provider;
    }

    /**
     * Inject all CSS
     *
     * @return void
     */
    public function inject()
    {
        // Skip editor pages
        if (isset($_GET['page']) && strpos($_GET['page'], 'aether-') === 0) {
            return;
        }

        // Skip admin pages
        if (is_admin()) {
            return;
        }

        // 1. 如果在编辑器预览模式，强制使用 CDN
        if ($this->is_editor_preview()) {
            $this->inject_cdn_mode();
            return;
        }

        // 2. 检查自动速度优化是否开启
        $speed_enabled = (bool) get_option('aether_speed_optimization_enabled', false);
        if (!$speed_enabled) {
            $this->inject_cdn_mode();
            return;
        }

        // 3. 正常访问：优先使用压缩 CSS
        $post_id = $this->get_current_post_id();

        if ($post_id && $this->has_valid_compiled_css($post_id)) {
            // 该页面有有效的编译 CSS，使用 Page CSS Provider
            if (class_exists('Aether_Page_CSS_Provider')) {
                $provider = new Aether_Page_CSS_Provider();
                $css = $provider->get_css_for_post($post_id);
                if (!empty($css)) {
                    $this->output_css(
                        $css,
                        $provider->get_css_id(),
                        $provider->get_css_attributes()
                    );

                    // 兜底检测：如果编译 CSS 不包含 @font-face，额外注入字体 CSS
                    $this->inject_font_fallback_if_needed($css);
                    $this->inject_design_system_global_patch();

                    return;
                } else {
                    // 有标记但无 CSS 内容，清除标记并降级到 CDN
                    delete_post_meta($post_id, '_aether_has_compiled_css');
                }
            }
        }

        // 没有编译 CSS 或编译无效，检查模板 CSS
        $template_type = $this->get_current_template_type();
        $has_valid_template = $template_type && $this->has_valid_template_css($template_type);

        if ($has_valid_template) {
            // 使用模板 CSS
            if (class_exists('Aether_Page_CSS_Provider')) {
                $provider = new Aether_Page_CSS_Provider();
                $css = $provider->get_css_for_template($template_type);
                if (!empty($css)) {
                    $this->output_css(
                        $css,
                        'aether-template-css-' . $template_type,
                        ['data-aether-css' => 'template']
                    );

                    // 兜底检测：如果编译 CSS 不包含 @font-face，额外注入字体 CSS
                    $this->inject_font_fallback_if_needed($css);
                    $this->inject_design_system_global_patch();

                    return;
                } else {
                    // 有标记但无 CSS 内容，清除标记并降级到 CDN
                    delete_option('aether_template_css_' . $template_type . '_compiled_at');
                }
            }
        }

        // Use CDN (CDN mode injects fonts separately)
        $this->inject_cdn_mode();
    }

    /**
     * 注入 CDN 模式（提取公共逻辑）
     */
    private function inject_cdn_mode()
    {
        if (class_exists('Aether_Tailwind_CDN_Provider')) {
            $provider = new Aether_Tailwind_CDN_Provider();
            if (method_exists($provider, 'inject_scripts')) {
                $provider->inject_scripts();
            }
            $css = $provider->get_css();
            if (!empty($css)) {
                $this->output_css(
                    $css,
                    $provider->get_css_id(),
                    $provider->get_css_attributes()
                );
            }
        }

        if (class_exists('Aether_Font_CSS_Provider')) {
            $font_provider = new Aether_Font_CSS_Provider();
            if ($font_provider->is_active()) {
                $css = $font_provider->get_css();
                if (!empty($css)) {
                    $this->output_css(
                        $css,
                        $font_provider->get_css_id(),
                        $font_provider->get_css_attributes()
                    );
                }
            }
        }

        $this->inject_design_system_full_css();
    }

    /**
     * 获取当前页面 ID（处理非单页情况）
     *
     * @return int|null
     */
    private function get_current_post_id()
    {
        // 单页：直接返回 ID
        if (is_singular()) {
            return get_the_ID();
        }

        // 非单页（archive、search 等）：返回 null
        return null;
    }

    /**
     * 检测是否在编辑器预览模式
     *
     * @return bool
     */
    private function is_editor_preview()
    {
        // aether 编辑器预览
        if (isset($_GET['aether_preview'])) {
            return true;
        }

        // WordPress 自定义器预览
        if (is_customize_preview()) {
            return true;
        }

        return false;
    }

    /**
     * 检查当前页面是否有有效的编译 CSS
     *
     * @param int $post_id
     * @return bool
     */
    private function has_valid_compiled_css($post_id)
    {
        if (!$post_id) {
            return false;
        }

        // 检查是否有编译 CSS
        $has_css = get_post_meta($post_id, '_aether_has_compiled_css', true);
        if (!$has_css) {
            return false;
        }

        // Use Hash Service to check哈希值是否过期
        if (class_exists('Aether_CSS_Hash_Service')) {
            $hash_service = Aether_CSS_Hash_Service::get_instance();
            return $hash_service->is_page_css_valid($post_id);
        }

        return true;
    }

    /**
     * 检查模板 CSS 是否有效
     *
     * @param string $template_type
     * @return bool
     */
    private function has_valid_template_css($template_type)
    {
        if (empty($template_type)) {
            return false;
        }

        // Use Hash Service to check
        if (class_exists('Aether_CSS_Hash_Service')) {
            $hash_service = Aether_CSS_Hash_Service::get_instance();
            return $hash_service->is_template_css_valid($template_type);
        }

        // 回退：检查是否有编译时间戳
        $compiled_at = get_option('aether_template_css_' . $template_type . '_compiled_at', 0);
        return !empty($compiled_at);
    }

    /**
     * 获取当前页面对应的模板类型
     *
     * 与 class-template-type-detector.php 保持一致
     *
     * @return string|null 模板类型
     */
    private function get_current_template_type()
    {
        // 特殊页面优先检查（顺序很重要）
        if (is_404()) {
            return '404';
        }

        if (is_search()) {
            return 'search';
        }

        // 静态首页（设置为某个 Page）
        if (is_front_page() && !is_home()) {
            return 'front_page';
        }

        // 博客首页（设置了单独的博客页面）
        if (is_home() && !is_front_page()) {
            return 'blog';
        }

        // 首页显示最新文章
        if (is_home() && is_front_page()) {
            return 'home';
        }

        // 单页类型（文章、页面、CPT）
        // 注意：模板类型统一使用 single_{post_type} 格式，与模板定义保持一致
        if (is_singular()) {
            $post_type = get_post_type();
            // 所有单页类型统一使用 single_{post_type} 格式
            return 'single_' . $post_type;
        }

        // Author page (core template)
        if (is_author()) {
            return 'author';
        }

        // 分类/标签/自定义分类法（统一使用 taxonomy_{taxonomy} 格式）
        if (is_category()) {
            return 'taxonomy_category';
        }
        if (is_tag()) {
            return 'taxonomy_post_tag';
        }
        if (is_tax()) {
            $taxonomy = get_queried_object()->taxonomy ?? '';
            return $taxonomy ? 'taxonomy_' . $taxonomy : null;
        }

        // 日期归档页（回退到 archive_post）
        if (is_date()) {
            return 'archive_post';
        }

        // 处理归档页
        if (is_archive()) {
            $post_type = get_queried_object()->name ?? get_post_type();
            return $post_type ? 'archive_' . $post_type : 'archive_post';
        }

        return null;
    }

    /**
     * Output CSS with proper formatting
     *
     * @param string $css CSS content
     * @param string $id Style tag ID
     * @param array $attributes Additional attributes
     * @return void
     */
    private function output_css($css, $id, $attributes = [])
    {
        // Start comment
        echo '<!-- aether CSS: ' . esc_attr($id) . ' -->' . "\n";

        // Build attributes string
        $attr_string = 'id="' . esc_attr($id) . '"';
        foreach ($attributes as $key => $value) {
            $attr_string .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }

        // Output style tag
        echo "\t" . '<style ' . $attr_string . '>' . "\n";
        echo $css;
        echo "\t" . '</style>' . "\n";

        // End comment
        echo '<!-- aether CSS End: ' . esc_attr($id) . ' -->' . "\n";
    }

    /**
     * Output script tag (for CDN providers)
     *
     * @param string $src Script source URL
     * @param string $id Script tag ID
     * @param array $attributes Additional attributes
     * @return void
     */
    public function output_script($src, $id, $attributes = [])
    {
        // Build attributes string
        $attr_string = 'id="' . esc_attr($id) . '"';
        $attr_string .= ' src="' . esc_url($src) . '"';
        foreach ($attributes as $key => $value) {
            $attr_string .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }

        // Output script tag
        echo '<script ' . $attr_string . '></script>' . "\n";
    }

    /**
     * Output inline script
     *
     * @param string $script JavaScript content
     * @param string $id Script tag ID
     * @return void
     */
    public function output_inline_script($script, $id)
    {
        echo '<script id="' . esc_attr($id) . '">' . "\n";
        echo $script . "\n";
        echo '</script>' . "\n";
    }

    /**
     * 兜底检测：如果编译 CSS 不包含 @font-face，额外注入字体 CSS
     *
     * @param string $css 编译后的 CSS
     * @return void
     */
    private function inject_font_fallback_if_needed($css)
    {
        // 检查编译 CSS 是否已包含 @font-face
        if (strpos($css, '@font-face') !== false) {
            return;
        }

        // 编译 CSS 不包含字体，尝试注入字体 CSS
        if (class_exists('Aether_Font_CSS_Provider')) {
            $font_provider = new Aether_Font_CSS_Provider();
            $font_css = $font_provider->get_css();
            if (!empty($font_css)) {
                $this->output_css(
                    $font_css,
                    $font_provider->get_css_id() . '-fallback',
                    array_merge($font_provider->get_css_attributes(), ['data-aether-fallback' => 'true'])
                );
            }
        }
    }

    /**
     * CDN/未编译模式下注入完整 design system custom CSS
     *
     * @return void
     */
    private function inject_design_system_full_css()
    {
        if (!class_exists('Aether_Design_System_CSS_Provider')) {
            return;
        }

        $provider = new Aether_Design_System_CSS_Provider('full');
        $css = $provider->get_css();
        if (empty($css)) {
            return;
        }

        $this->output_css(
            $css,
            $provider->get_css_id(),
            $provider->get_css_attributes()
        );
    }

    /**
     * 编译模式下注入全局 custom CSS 补丁（body/@keyframes 等）
     *
     * @return void
     */
    private function inject_design_system_global_patch()
    {
        if (!class_exists('Aether_Design_System_CSS_Provider')) {
            return;
        }

        $provider = new Aether_Design_System_CSS_Provider('global');
        $css = $provider->get_css();
        if (empty($css)) {
            return;
        }

        $this->output_css(
            $css,
            $provider->get_css_id(),
            $provider->get_css_attributes()
        );
    }

    /**
     * @deprecated 2.0.0 Use page-level compilation instead
     * Check if we're in production mode
     *
     * @return bool Always returns false in new architecture
     */
    public function is_production_mode()
    {
        return false;
    }
}
