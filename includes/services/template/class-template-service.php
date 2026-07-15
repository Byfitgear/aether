<?php
/**
 * Template Service
 * 
 * Provides template parsing and rendering using Tangible Template System
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// 基类通过自动加载器加载

class Aether_Template_Service extends Aether_Base {
    
    protected function init() {
        // Register cache clearing hooks
        add_action('save_post', [$this, 'clear_post_cache']);
        add_action('aether_template_saved', [$this, 'clear_all_caches']);
        add_action('wp_insert_post', [$this, 'clear_post_cache']);
        add_action('edit_post', [$this, 'clear_post_cache']);
    }
    
    /**
     * Parse template content and extract dynamic tags
     * 
     * @param string $content The content to parse
     * @return array Parsed information
     */
    public function parse_content($content) {
        $result = [
            'has_templates' => false,
            'template_tags' => [],
            'errors' => []
        ];
        
        // Check if template system is loaded
        if (!function_exists('tangible_template')) {
            $result['errors'][] = 'Template system not loaded';
            return $result;
        }
        
        // Look for Tangible template tags
        $patterns = [
            'loop' => '/\<Loop\s+([^>]+)\>/i',
            'field' => '/\<Field\s+([^>]+)\>/i',
            'if' => '/\<If\s+([^>]+)\>/i',
            'template' => '/\[template[^\]]*\]/i'
        ];
        
        foreach ($patterns as $type => $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                $result['has_templates'] = true;
                $result['template_tags'][$type] = $matches[0];
            }
        }
        
        return $result;
    }
    
    /**
     * Render content with template processing
     *
     * @param string $content The content to render
     * @param array|int $context Optional context data or post ID
     * @return string Rendered content
     */
    public function render($content, $context = []) {
        $content = is_string($content) ? $content : '';

        // 检查是否包含 PHP 代码
        if ($this->contains_php_code($content)) {
            // 执行 PHP 代码
            $output = $this->execute_php($content, $context);
            return $this->process_shortcodes($output);
        }

        // If no template tags found, return as-is
        $parsed = $this->parse_content($content);
        if (!$parsed['has_templates']) {
            return $this->process_shortcodes($content);
        }

        // Use Tangible to render
        $rendered = aether_render_template($content);

        return $this->process_shortcodes($rendered);
    }

    /**
     * Render content with image optimization
     *
     * 用于模板最外层调用，支持图片优化功能
     *
     * @param string $content The content to render
     * @param int $post_id Post ID
     * @return string Rendered and optimized content
     */
    public function render_with_optimization($content, $post_id) {
        $final = is_string($content) ? $content : '';

        // 检查图片优化是否启用
        if (class_exists('Aether_HTML_Optimization_Service')) {
            $html_service = Aether_HTML_Optimization_Service::get_instance();

            if ($html_service->is_optimization_enabled()) {
                // 尝试获取优化后的 HTML
                $optimized_html = $html_service->get_optimized_html($post_id);

                if (is_string($optimized_html) && $optimized_html !== '') {
                    $final = $optimized_html;
                }
            }
        }

        // 统一走 render，确保 PHP/模板标签/短代码能执行
        return $this->render($final, $post_id);
    }

    /**
     * 检查内容是否包含 PHP 代码
     *
     * @param string $content The content to check
     * @return bool
     */
    private function contains_php_code($content) {
        if ($content === null || $content === '') {
            return false;
        }

        return strpos($content, '<?php') !== false || strpos($content, '<?=') !== false;
    }

    /**
     * 处理内容中的短代码
     *
     * @param string $content 内容
     * @return string
     */
    private function process_shortcodes($content) {
        if (!is_string($content) || $content === '') {
            return is_string($content) ? $content : '';
        }

        if (!function_exists('do_shortcode')) {
            return $content;
        }

        if (strpos($content, '[') === false) {
            return $content;
        }

        return do_shortcode($content);
    }
    
    /**
     * Execute PHP code in content
     * 
     * @param string $content The content with PHP code
     * @param mixed $context Context data or post ID
     * @return string Executed content
     */
    private function execute_php($content, $context = null) {
        $post_id = is_numeric($context) ? $context : null;
        
        // 设置 WordPress 上下文
        if ($post_id) {
            global $post;
            $original_post = $post;
            $post = get_post($post_id);
            setup_postdata($post);
        }
        
        // 捕获输出
        ob_start();
        $error = null;
        
        try {
            // 执行 PHP 代码
            $temp_file = tempnam(sys_get_temp_dir(), 'aether_tpl_');
            file_put_contents($temp_file, $content);
            include $temp_file;
            if (file_exists($temp_file)) wp_delete_file($temp_file);
        } catch (ParseError $e) {
            $error = 'Parse Error: ' . $e->getMessage();
        } catch (Error $e) {
            $error = 'Fatal Error: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
        
        $output = ob_get_clean();
        
        // Restore original post
        if (isset($original_post)) {
            $post = $original_post;
            if ($original_post) {
                setup_postdata($original_post);
            }
        }
        
        if ($error) {
            error_log('[Aether Template Render] ' . $error);
        }

        if ($error && $this->should_show_php_error()) {
            return '<div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb;">' . 
                   '<strong>PHP Error:</strong> ' . esc_html($error) . '</div>';
        }
        
        return $output ?: '';
    }

    /**
     * Check whether PHP render errors should be visible in the response.
     *
     * @return bool
     */
    private function should_show_php_error() {
        if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
            return true;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }

        return function_exists('current_user_can') && current_user_can('manage_options');
    }
    
    /**
     * Validate template syntax
     * 
     * @param string $content The content to validate
     * @return array Validation result
     */
    public function validate($content) {
        $result = [
            'valid' => true,
            'error' => ''
        ];
        
        // Basic validation for unclosed tags
        $open_tags = [];
        $pattern = '/\<(Loop|If|Field|Else)\s*([^>]*)\>/i';
        
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $match) {
                $tag = strtolower($match[0]);
                $position = $match[1];
                
                if (in_array($tag, ['loop', 'if'])) {
                    $open_tags[] = [
                        'tag' => $tag,
                        'position' => $position
                    ];
                }
            }
        }
        
        // Check for closing tags
        $close_pattern = '/\<\/(Loop|If)\>/i';
        if (preg_match_all($close_pattern, $content, $close_matches, PREG_OFFSET_CAPTURE)) {
            foreach ($close_matches[1] as $match) {
                if (!empty($open_tags)) {
                    array_pop($open_tags);
                }
            }
        }
        
        // Report unclosed tags
        if (!empty($open_tags)) {
            $result['valid'] = false;
            $errors = [];
            foreach ($open_tags as $tag) {
                $errors[] = sprintf(
                    '未闭合的 <%s> 标签',
                    ucfirst($tag['tag'])
                );
            }
            $result['error'] = implode(', ', $errors);
        }
        
        return $result;
    }
    
    /**
     * Get available template tags and documentation
     * 
     * @return array
     */
    public function get_available_tags() {
        return [
            'Loop' => [
                'description' => 'Loop through posts, pages, or custom post types',
                'example' => '<Loop type="post" count="5">',
                'attributes' => [
                    'type' => 'Post type to query',
                    'count' => 'Number of items',
                    'orderby' => 'Order by field',
                    'order' => 'ASC or DESC'
                ]
            ],
            'Field' => [
                'description' => 'Display a field value',
                'example' => '<Field title />',
                'attributes' => [
                    'name' => 'Field name to display'
                ]
            ],
            'If' => [
                'description' => 'Conditional display',
                'example' => '<If field="title">',
                'attributes' => [
                    'field' => 'Field to check',
                    'value' => 'Value to compare'
                ]
            ]
        ];
    }
    
    /**
     * Clear cache when a post is saved
     * 
     * @param int $post_id Post ID
     */
    public function clear_post_cache($post_id) {
        // 清除该文章的 aether 内部 PHP 输出缓存
        Aether_PHP_Runtime_Cache_Policy::clear_output_cache($post_id);

        // 清除外部缓存插件的页面缓存
        if (class_exists('Aether_Cache_Helper')) {
            Aether_Cache_Helper::clear_post_cache($post_id);
        }

        // 触发自定义钩子
        do_action('aether_clear_post_cache', $post_id);
    }
    
    /**
     * Clear all template caches
     */
    public function clear_all_caches() {
        // 清除所有 aether 内部缓存（post_meta）
        Aether_PHP_Runtime_Cache_Policy::clear_all_output_caches();

        // 清除外部缓存插件的页面缓存（LiteSpeed, WP Rocket 等）
        if (class_exists('Aether_Cache_Helper')) {
            Aether_Cache_Helper::clear_page_cache();
        }

        // 触发自定义钩子
        do_action('aether_clear_all_caches');
    }
}
