<?php
/**
 * CSS 编译策略服务
 * 
 * 负责处理页面和模板的 CSS 编译逻辑
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_CSS_Compile_Strategy_Service
{
    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有Constructor
     */
    private function __construct()
    {
    }

    /**
     * 编译页面 CSS
     * 
     * @param WP_Post $page 页面对象
     * @param array $common_templates 公共模板内容
     * @return array 包含 css 和 stats 的数组
     */
    public function compile_page($page, $common_templates = [])
    {
        // 组合 HTML 内容
        $combined_html = $this->build_page_html($page, $common_templates);

        // 调用编译
        return $this->compile_html($combined_html);
    }

    /**
     * 编译模板 CSS
     * 
     * @param string $type 模板类型
     * @param array $config 模板配置
     * @param array $common_templates 公共模板内容
     * @return array 包含 css 和 stats 的数组
     */
    public function compile_template($type, $config, $common_templates = [])
    {
        // 组合 HTML 内容
        $combined_html = $this->build_template_html($type, $config, $common_templates);

        if (empty($combined_html)) {
            return [
                'css' => '',
                'stats' => [
                    'original_size' => 0,
                    'optimized_size' => 0
                ]
            ];
        }

        // 调用编译
        return $this->compile_html($combined_html);
    }

    /**
     * 构建页面 HTML
     * 
     * @param WP_Post $page 页面对象
     * @param array $common_templates 公共模板内容
     * @return string
     */
    private function build_page_html($page, $common_templates)
    {
        $combined_html = '';

        // 检查是否应该排除页眉页脚
        $exclude_header_footer = $this->page_uses_excluded_template($page->ID);

        // 添加页眉
        if (!$exclude_header_footer && !empty($common_templates['header'])) {
            $combined_html .= $common_templates['header'] . "\n";
        }

        // 添加页面内容
        $page_content = get_post_field('post_content', $page->ID);
        if (!empty($page_content)) {
            // 检查页面内容是否为纯文本，如果是则包装在 div 中
            if (strip_tags($page_content) === $page_content) {
                $page_content = '<div>' . esc_html($page_content) . '</div>';
            }
            $combined_html .= $page_content . "\n";
        }

        // 添加页脚
        if (!$exclude_header_footer && !empty($common_templates['footer'])) {
            $combined_html .= $common_templates['footer'] . "\n";
        }

        return $combined_html;
    }

    /**
     * 构建模板 HTML
     * 
     * @param string $type 模板类型
     * @param array $config 模板配置
     * @param array $common_templates 公共模板内容
     * @return string
     */
    private function build_template_html($type, $config, $common_templates)
    {
        $aether_templates = Aether_Templates::getInstance();
        $combined_html = '';

        // 如果是页眉或页脚模板，只编译它们自己的内容
        if ($type === 'header' || $type === 'footer') {
            $template = $aether_templates->get_active_template($type);
            if ($template && !empty($template['content'])) {
                $combined_html = $template['content'];
            }
            return $combined_html;
        }

        // 对于其他模板类型，包含页眉和页脚
        // 添加页眉
        if (!empty($common_templates['header'])) {
            $combined_html .= $common_templates['header'] . "\n";
        }

        // 添加主体模板内容
        $template = $aether_templates->get_active_template($type);
        if ($template && !empty($template['content'])) {
            $combined_html .= $template['content'] . "\n";

            // 如果是归档类页面，添加示例文章内容
            if ($this->is_archive_type($type)) {
                $combined_html .= $this->get_sample_posts_html();
            }
        }

        // 添加页脚
        if (!empty($common_templates['footer'])) {
            $combined_html .= $common_templates['footer'] . "\n";
        }

        return $combined_html;
    }

    /**
     * 编译 HTML 为 CSS
     * 
     * @param string $html HTML 内容
     * @return array|WP_Error
     */
    private function compile_html($html)
    {
        // 注入内置 safelist 以覆盖常见动态类、状态与断点（无用户负担）
        $html_with_safelist = $html . "\n" . Aether_Safelist_Service::get_html();

        $original_size = strlen($html_with_safelist);

        // 获取设计系统 HTML
        $design_system_html = Aether_Settings_Service::get('design_system_html', '');

        // 使用统一的 compile 端点
        $unified_proxy = Aether_Unified_Proxy::get_instance();

        $result = $unified_proxy->request('css_compiler', 'compile', [
            'data' => [
                'html' => $html_with_safelist,
                'designSystemHtml' => $design_system_html,
                'options' => [
                    'minify' => true
                ]
            ]
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        // 检查响应状态
        if (!$result['success']) {
            $error_message = $result['data']['message'] ?? $result['data']['error'] ?? 'CSS 编译失败';
            return new WP_Error('css_compile_failed', $error_message);
        }

        // 获取编译后的 CSS
        $css = $result['data']['css'] ?? '';

        // 添加字体 CSS
        $font_css = Aether_Font_Manager_Service::get_font_css();
        if (!empty($font_css)) {
            $css = $font_css . "\n" . $css;
        }

        return [
            'css' => $css,
            'stats' => [
                'original_size' => $original_size,
                'optimized_size' => strlen($css),
                'hash' => $result['data']['hash'] ?? '',
                'compilation_stats' => $result['data']['stats'] ?? []
            ]
        ];
    }

    /**
     * 检查页面是否使用排除头尾的模板
     * 
     * @param int $page_id 页面 ID
     * @return bool
     */
    private function page_uses_excluded_template($page_id)
    {
        if (get_post_type($page_id) !== 'page') {
            return false;
        }

        $template = get_page_template_slug($page_id);
        if (!$template) {
            return false;
        }

        // Get excluded templates from settings
        $excluded_templates = Aether_Settings_Service::get('excluded_templates', array(
            'page-landing.php',
            'template-landing.php',
            'landing-page.php',
            'page-blank.php',
            'template-blank.php',
            'blank-page.php'
        ));

        // 允许通过过滤器自定义排除的模板
        $excluded_templates = apply_filters('aether_excluded_header_footer_templates', $excluded_templates);

        return in_array($template, $excluded_templates);
    }

    /**
     * 判断是否为归档类型模板
     * 
     * @param string $type 模板类型
     * @return bool
     */
    private function is_archive_type($type)
    {
        return in_array($type, ['archive', 'home', 'blog', 'search', 'category', 'tag', 'author']);
    }

    /**
     * 获取示例文章 HTML
     * 
     * @return string
     */
    private function get_sample_posts_html()
    {
        $html = '';

        // 获取最近的文章作为示例
        $sample_posts = get_posts([
            'posts_per_page' => 3,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_aether_edited',
                    'value' => '1',
                    'compare' => '='
                ]
            ]
        ]);

        /** @var WP_Post $post */
        foreach ($sample_posts as $post) {
            $content = get_post_field('post_content', $post->ID);
            if (!empty($content)) {
                // 检查内容是否为纯文本，如果是则包装在 div 中
                if (strip_tags($content) === $content) {
                    $content = '<div>' . esc_html($content) . '</div>';
                }
                $html .= $content . "\n";
            }
        }

        return $html;
    }

    /**
     * Get common template content
     * 
     * @return array
     */
    public function get_common_templates()
    {
        $templates = [];
        $aether_templates = Aether_Templates::getInstance();

        // 获取页眉模板
        $header = $aether_templates->get_active_template('header');
        if ($header && !empty($header['content'])) {
            $templates['header'] = $header['content'];
        }

        // 获取页脚模板
        $footer = $aether_templates->get_active_template('footer');
        if ($footer && !empty($footer['content'])) {
            $templates['footer'] = $footer['content'];
        }

        return $templates;
    }

    /**
     * 编译单个页面的 CSS
     *
     * @param int $post_id 页面 ID
     * @param string $content 页面内容
     * @param array $options 编译选项
     *   - excludeHeaderFooter: bool 是否排除页眉页脚
     *   - timeout: int 超时时间（秒）
     * @return array|WP_Error 包含 css 和 stats 的数组
     */
    public function compile_single_page($post_id, $content, $options = [])
    {
        try {
            // 获取设计系统配置
            $design_system_html = Aether_Settings_Service::get('design_system_html', '');

            // 根据 excludeHeaderFooter 选项决定是否获取公共模板
            $exclude_header_footer = !empty($options['excludeHeaderFooter']);
            $common_templates = $exclude_header_footer ? [] : $this->get_common_templates();

            // 组装 HTML
            $full_html = $this->assemble_page_html($content, $common_templates, $exclude_header_footer);

            // 添加 safelist
            $full_html .= "\n" . Aether_Safelist_Service::get_html();

            // 调用外部编译服务
            $proxy = Aether_Unified_Proxy::get_instance();
            $result = $proxy->request('css_compiler', 'compile', [
                'data' => [
                    'html' => $full_html,
                    'designSystemHtml' => $design_system_html,
                    'options' => ['minify' => true]
                ],
                'timeout' => $options['timeout'] ?? 10
            ]);

            if (is_wp_error($result)) {
                return $result;
            }

            // 检查响应状态
            if (!$result['success']) {
                $error_message = $result['data']['message'] ?? $result['data']['error'] ?? 'CSS 编译失败';
                return new WP_Error('css_compile_failed', $error_message);
            }

            // 获取编译后的 CSS
            $css = $result['data']['css'] ?? '';

            // 添加字体 CSS
            $font_css = Aether_Font_Manager_Service::get_font_css();
            if (!empty($font_css)) {
                $css = $font_css . "\n" . $css;
            }

            return [
                'css' => $css,
                'stats' => [
                    'original_size' => strlen($full_html),
                    'optimized_size' => strlen($css),
                    'hash' => $result['data']['hash'] ?? '',
                    'compilation_stats' => $result['data']['stats'] ?? []
                ]
            ];
        } catch (Exception $e) {
            error_log('aether: Backend compile failed: ' . $e->getMessage());
            return new WP_Error('compile_exception', $e->getMessage());
        }
    }

    /**
     * 组装页面 HTML（支持 excludeHeaderFooter 选项）
     *
     * @param string $content 页面内容
     * @param array $common_templates 公共模板
     * @param bool $exclude_header_footer 是否排除页眉页脚
     * @return string
     */
    private function assemble_page_html($content, $common_templates, $exclude_header_footer = false)
    {
        if ($exclude_header_footer) {
            return $content;
        }

        $parts = [];

        // 添加页眉
        if (!empty($common_templates['header'])) {
            $parts[] = $common_templates['header'];
        }

        // 添加页面内容
        if (!empty($content)) {
            $parts[] = $content;
        }

        // 添加页脚
        if (!empty($common_templates['footer'])) {
            $parts[] = $common_templates['footer'];
        }

        return implode("\n", $parts);
    }
}
