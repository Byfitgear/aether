<?php
/**
 * CSS API Routes
 * 
 * Handles CSS compilation endpoints that are not proxy services
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * CSS API Routes Class
 */
class Aether_API_Routes_CSS extends Aether_API_Routes_Base
{

    /**
     * 注册路由
     */
    public function register_routes()
    {
        // 统一的 CSS 编译端点
        $this->register_route('/css/compile', [
            'methods' => 'POST',
            'callback' => [$this, 'compile_css'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // CSS 统计信息（页面级 CSS 编译状态）
        $this->register_route('/css/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_css_stats'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // 重编译进度
        $this->register_route('/css/recompile-progress', [
            'methods' => 'GET',
            'callback' => [$this, 'get_recompile_progress'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // Trigger full site recompilation
        $this->register_route('/css/recompile-all', [
            'methods' => 'POST',
            'callback' => [$this, 'trigger_recompile_all'],
            'permission_callback' => [$this, 'check_manage_options']
        ]);

        // 清除所有 CSS
        $this->register_route('/css/clear-all', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_all_css'],
            'permission_callback' => [$this, 'check_manage_options']
        ]);

        // 清理缓存（旧端点，保持兼容）
        $this->register_route('/css/clear-cache', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_cache'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // 获取本地编译所需的数据
        $this->register_route('/css/compile-data', [
            'methods' => 'GET',
            'callback' => [$this, 'get_compile_data'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // 保存本地编译结果
        $this->register_route('/css/save-compiled', [
            'methods' => 'POST',
            'callback' => [$this, 'save_compiled_css'],
            'permission_callback' => [$this, 'check_permission']
        ]);
    }

    /**
     * 检查管理员权限
     */
    public function check_manage_options()
    {
        return current_user_can('manage_options');
    }

    /**
     * 统一的 CSS 编译方法
     */
    public function compile_css($request)
    {
        $type = $request->get_param('type') ?? 'html';

        switch ($type) {
            case 'smart':
                return $this->compile_smart_css_internal($request);

            case 'html':
            default:
                return $this->compile_html_internal($request);
        }
    }

    /**
     * Compile smart CSS for all pages
     */
    private function compile_smart_css_internal($request)
    {
        $service = Aether_Smart_CSS_Compiler_Service::get_instance();

        $options = $request->get_param('options') ?? [
            'minify' => true,
        ];

        $result = $service->compile_all_pages($options);

        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message());
        }

        // Check if compilation had errors
        if (isset($result['success']) && !$result['success'] && !empty($result['stats']['errors'])) {
            // Return error response with stats
            return $this->error_response('Compilation failed with errors', 500, $result);
        }

        return $this->success_response($result);
    }

    /**
     * Compile HTML to CSS using Tailwind
     */
    private function compile_html_internal($request)
    {
        $html = $request->get_param('html');
        $options = $request->get_param('options') ?? [];

        if (empty($html)) {
            return $this->error_response('HTML content is required');
        }

        // 准备数据适配新的统一 API
        $proxy_data = [
            'html' => $html,
            'designSystemHtml' => '', // 普通编译不需要设计系统
            'options' => [
                'minify' => $options['minify'] ?? true,
            ]
        ];

        // Use the CSS compiler proxy to compile HTML
        $proxy = Aether_Unified_Proxy::get_instance();
        $result = $proxy->request('css_compiler', 'compile', [
            'data' => $proxy_data
        ]);

        if (is_wp_error($result)) {
            return $this->error_response($result->get_error_message());
        }

        // 检查响应状态
        if (!$result['success']) {
            $error_message = $result['data']['message'] ?? $result['data']['error'] ?? 'CSS 编译失败';
            return $this->error_response($error_message);
        }

        // The unified proxy returns the response data directly
        return $this->success_response($result['data']);
    }

    /**
     * 获取 CSS 编译统计信息（页面级 CSS 编译状态）
     */
    public function get_css_stats($request)
    {
        global $wpdb;

        // 统计已编译页面数
        $compiled = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_aether_has_compiled_css'
            AND meta_value = '1'
        ");

        // 统计所有 aether 编辑过的页面数
        $total = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_aether_edited'
            AND meta_value = '1'
        ");

        // 获取最近编译时间
        $last_compile = $wpdb->get_var("
            SELECT MAX(meta_value)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_aether_css_compiled_at'
        ");

        // 检查是否正在重编译
        $progress = get_option('aether_recompile_progress');

        return $this->success_response([
            'compiled' => (int) $compiled,
            'total' => (int) $total,
            'percentage' => $total > 0 ? round($compiled / $total * 100) : 100,
            'lastCompileTime' => $last_compile ?: null,
            'isRecompiling' => $progress && isset($progress['status']) && $progress['status'] === 'running'
        ]);
    }

    /**
     * 获取重编译进度
     */
    public function get_recompile_progress($request)
    {
        $progress = get_option('aether_recompile_progress');

        if (!$progress) {
            return $this->success_response([
                'status' => 'idle',
                'total' => 0,
                'completed' => 0,
                'failed' => 0
            ]);
        }

        return $this->success_response($progress);
    }

    /**
     * Trigger full site recompilation
     */
    public function trigger_recompile_all($request)
    {
        // 检查是否已有任务在运行
        $progress = get_option('aether_recompile_progress');
        if ($progress && isset($progress['status']) && $progress['status'] === 'running') {
            return $this->error_response('已有重编译任务在运行', 409);
        }

        // 清除哈希缓存，确保重编译使用最新的 header/footer 内容
        if (class_exists('Aether_CSS_Hash_Service')) {
            $hash_service = Aether_CSS_Hash_Service::get_instance();
            $hash_service->clear_all_hash_cache();
        }

        // 调度任务
        wp_schedule_single_event(time(), 'aether_recompile_all_pages', [
            [
                'trigger' => 'manual',
                'user_id' => get_current_user_id()
            ]
        ]);

        return $this->success_response([
            'success' => true,
            'message' => '整站重编译已启动'
        ]);
    }

    /**
     * 清除所有 CSS
     */
    public function clear_all_css($request)
    {
        global $wpdb;

        // 1. 清理存储层（CSS 内容）
        $storage = Aether_CSS_Storage_Service::get_instance();
        $storage->clear_all_css();

        // 2. 清理所有页面的编译标记 meta
        $wpdb->query("
            DELETE FROM {$wpdb->postmeta}
            WHERE meta_key IN (
                '_aether_has_compiled_css',
                '_aether_css_header_footer_hash',
                '_aether_css_design_system_hash',
                '_aether_css_compile_source',
                '_aether_css_compiled_at',
                '_aether_compile_failed_at'
            )
        ");

        // 3. 清除对象缓存
        wp_cache_flush();

        error_log(sprintf(
            'aether: All compiled CSS cleared by user %d',
            get_current_user_id()
        ));

        return $this->success_response([
            'success' => true,
            'message' => '所有编译 CSS 和标记已清除'
        ]);
    }

    /**
     * 清理缓存
     */
    public function clear_cache($request)
    {
        $storage_service = Aether_CSS_Storage_Service::get_instance();

        // 清除旧的页眉页脚 CSS
        $storage_service->clear_legacy_header_footer_css();

        return $this->success_response([
            'success' => true,
            'message' => 'Legacy cache cleared successfully'
        ]);
    }

    /**
     * 获取本地编译所需的数据
     *
     * 返回所有待编译的页面/模板的 HTML 内容，供前端本地编译使用
     */
    public function get_compile_data($request)
    {
        $compile_strategy = Aether_CSS_Compile_Strategy_Service::get_instance();
        $aether_templates = Aether_Templates::getInstance();

        // 获取设计系统 HTML
        $design_system_html = Aether_Settings_Service::get('design_system_html', '');
        $extracted_tailwind_config = Aether_Settings_Service::get('extracted_tailwind_config', '');

        // 获取公共模板
        $common_templates = $compile_strategy->get_common_templates();

        // 获取 safelist HTML
        $safelist = Aether_Safelist_Service::get_html();

        // 获取所有待编译的页面
        $pages = $this->get_pages_to_compile();

        // 获取所有待编译的模板
        $templates = $this->get_templates_to_compile($aether_templates);

        // DEBUG: 记录编译数据详情 (当 WP_DEBUG 开启时)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log_compile_data_debug([
                'designSystemHtml' => $design_system_html,
                'extractedTailwindConfig' => $extracted_tailwind_config,
                'commonTemplates' => $common_templates,
                'pages' => $pages,
                'templates' => $templates,
                'safelist' => $safelist,
            ]);
        }

        return $this->success_response([
            'designSystemHtml' => $design_system_html,
            'extractedTailwindConfig' => $extracted_tailwind_config,
            'commonTemplates' => $common_templates,
            'pages' => $pages,
            'templates' => $templates,
            'safelist' => $safelist,
        ]);
    }

    /**
     * DEBUG: 记录编译数据详情到日志
     */
    private function log_compile_data_debug($data)
    {
        $log_file = WP_CONTENT_DIR . '/aether-compile-debug.log';
        $timestamp = current_time('Y-m-d H:i:s');

        $log = "\n" . str_repeat('=', 80) . "\n";
        $log .= "[{$timestamp}] Aether Local Compile Data Debug\n";
        $log .= str_repeat('=', 80) . "\n\n";

        // 1. 设计系统 HTML 概要
        $design_html = $data['designSystemHtml'] ?? '';
        $log .= "## 设计系统 HTML\n";
        $log .= "   长度: " . strlen($design_html) . " bytes\n";
        $log .= "   是否为空: " . (empty($design_html) ? '是' : '否') . "\n";

        // 检查是否包含 tailwind.config
        if (preg_match('/tailwind\.config\s*=\s*(\{[\s\S]*?\})\s*;?/i', $design_html, $matches)) {
            $log .= "   包含 tailwind.config: 是\n";
            $log .= "   tailwind.config 内容:\n";
            $log .= "   " . str_replace("\n", "\n   ", $matches[0]) . "\n";
        } else {
            $log .= "   包含 tailwind.config: 否\n";
        }

        // 检查是否包含自定义颜色定义
        if (preg_match('/colors\s*:\s*\{([^}]*)\}/i', $design_html, $color_matches)) {
            $log .= "   包含 colors 配置: 是\n";
            $log .= "   colors 内容: " . substr($color_matches[0], 0, 500) . "...\n";
        } else {
            $log .= "   包含 colors 配置: 否\n";
        }

        // 检查是否使用 brand 颜色类
        preg_match_all('/(?:text|bg|border|ring)-brand-\d+/', $design_html, $brand_matches);
        $brand_classes = array_unique($brand_matches[0] ?? []);
        $log .= "   使用的 brand 颜色类: " . (empty($brand_classes) ? '无' : implode(', ', $brand_classes)) . "\n\n";

        // 2. 已提取的 Tailwind 配置
        $extracted_config = $data['extractedTailwindConfig'] ?? '';
        $log .= "## 已提取的 Tailwind 配置\n";
        $log .= "   长度: " . strlen($extracted_config) . " bytes\n";
        $log .= "   是否为空: " . (empty($extracted_config) ? '是' : '否') . "\n";
        if (!empty($extracted_config)) {
            $log .= "   内容:\n   " . str_replace("\n", "\n   ", substr($extracted_config, 0, 2000)) . "\n";
        }
        $log .= "\n";

        // 3. 页面列表
        $pages = $data['pages'] ?? [];
        $log .= "## 待编译页面 (" . count($pages) . " 个)\n";
        foreach ($pages as $index => $page) {
            $html = $page['html'] ?? '';

            // 检查页面中使用的 brand 颜色类
            preg_match_all('/(?:text|bg|border|ring)-brand-\d+/', $html, $page_brand_matches);
            $page_brand_classes = array_unique($page_brand_matches[0] ?? []);

            $log .= "   [{$index}] ID={$page['id']} \"{$page['title']}\" ({$page['type']})\n";
            $log .= "       HTML 长度: " . strlen($html) . " bytes\n";
            $log .= "       使用的 brand 类: " . (empty($page_brand_classes) ? '无' : implode(', ', $page_brand_classes)) . "\n";
        }
        $log .= "\n";

        // 4. 模板列表
        $templates = $data['templates'] ?? [];
        $log .= "## 待编译模板 (" . count($templates) . " 个)\n";
        foreach ($templates as $index => $template) {
            $html = $template['html'] ?? '';

            // 检查模板中使用的 brand 颜色类
            preg_match_all('/(?:text|bg|border|ring)-brand-\d+/', $html, $tpl_brand_matches);
            $tpl_brand_classes = array_unique($tpl_brand_matches[0] ?? []);

            $log .= "   [{$index}] type={$template['type']} \"{$template['name']}\"\n";
            $log .= "       HTML 长度: " . strlen($html) . " bytes\n";
            $log .= "       使用的 brand 类: " . (empty($tpl_brand_classes) ? '无' : implode(', ', $tpl_brand_classes)) . "\n";
        }
        $log .= "\n";

        // 5. 公共模板
        $common = $data['commonTemplates'] ?? [];
        $log .= "## 公共模板\n";
        $header_html = $common['header'] ?? '';
        $footer_html = $common['footer'] ?? '';

        // Header
        preg_match_all('/(?:text|bg|border|ring)-brand-\d+/', $header_html, $header_brand_matches);
        $header_brand = array_unique($header_brand_matches[0] ?? []);
        $log .= "   Header: " . strlen($header_html) . " bytes\n";
        $log .= "       使用的 brand 类: " . (empty($header_brand) ? '无' : implode(', ', $header_brand)) . "\n";

        // Footer
        preg_match_all('/(?:text|bg|border|ring)-brand-\d+/', $footer_html, $footer_brand_matches);
        $footer_brand = array_unique($footer_brand_matches[0] ?? []);
        $log .= "   Footer: " . strlen($footer_html) . " bytes\n";
        $log .= "       使用的 brand 类: " . (empty($footer_brand) ? '无' : implode(', ', $footer_brand)) . "\n\n";

        // 6. Safelist
        $safelist = $data['safelist'] ?? '';
        $log .= "## Safelist\n";
        $log .= "   长度: " . strlen($safelist) . " bytes\n";
        $log .= "   内容: " . substr($safelist, 0, 500) . "...\n\n";

        // 7. 汇总所有使用的 brand 类
        $all_html = $design_html . $header_html . $footer_html . $safelist;
        foreach ($pages as $page) {
            $all_html .= $page['html'] ?? '';
        }
        foreach ($templates as $template) {
            $all_html .= $template['html'] ?? '';
        }

        preg_match_all('/(?:text|bg|border|ring|hover:text|hover:bg|focus:ring)-brand-\d+/', $all_html, $all_brand_matches);
        $all_brand_classes = array_unique($all_brand_matches[0] ?? []);
        sort($all_brand_classes);

        $log .= "## 汇总: 所有使用的 brand 颜色类\n";
        $log .= "   共 " . count($all_brand_classes) . " 个: " . implode(', ', $all_brand_classes) . "\n";

        $log .= "\n" . str_repeat('=', 80) . "\n";

        // 写入日志文件
        file_put_contents($log_file, $log, FILE_APPEND);

        // 同时记录到 WordPress 调试日志
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[Aether] Compile data debug written to: ' . $log_file);
        }
    }

    /**
     * 获取所有待编译的页面
     */
    private function get_pages_to_compile()
    {
        $pages = get_posts([
            'post_type' => ['page', 'post'],
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_aether_edited',
                    'value' => '1',
                    'compare' => '='
                ]
            ]
        ]);

        $result = [];

        /** @var WP_Post $page */
        foreach ($pages as $page) {
            $page_content = get_post_field('post_content', $page->ID);

            // 检查页面内容是否为纯文本，如果是则包装在 div 中
            if (!empty($page_content) && strip_tags($page_content) === $page_content) {
                $page_content = '<div>' . esc_html($page_content) . '</div>';
            }

            $result[] = [
                'id' => $page->ID,
                'title' => $page->post_title,
                'type' => $page->post_type,
                'html' => $page_content,
                'excludeHeaderFooter' => $this->page_uses_excluded_template($page->ID),
            ];
        }

        return $result;
    }

    /**
     * 获取所有待编译的模板
     */
    private function get_templates_to_compile($aether_templates)
    {
        // 获取所有已创建的 aether 模板
        $templates = get_posts([
            'post_type' => 'aether_template',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $result = [];

        /** @var WP_Post $template */
        foreach ($templates as $template) {
            $template_type = get_post_meta($template->ID, '_aether_template_type', true);

            if (empty($template_type)) {
                continue;
            }

            // 跳过页眉和页脚的单独编译（它们已包含在其他模板中）
            if ($template_type === 'header' || $template_type === 'footer') {
                continue;
            }

            // 获取模板内容
            $template_data = $aether_templates->get_active_template($template_type);
            if (!$template_data || empty($template_data['content'])) {
                continue;
            }

            $result[] = [
                'type' => $template_type,
                'name' => $template->post_title,
                'html' => $template_data['content'],
            ];
        }

        return $result;
    }

    /**
     * 检查页面是否使用排除头尾的模板
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
     * 保存本地编译结果
     */
    public function save_compiled_css($request)
    {
        $pages = $request->get_param('pages') ?? [];
        $templates = $request->get_param('templates') ?? [];

        $storage_service = Aether_CSS_Storage_Service::get_instance();

        $stats = [
            'totalPages' => count($pages),
            'totalTemplates' => count($templates),
            'compiledPages' => 0,
            'compiledTemplates' => 0,
            'errors' => [],
            'originalSize' => 0,
            'optimizedSize' => 0,
            'cdnSize' => 342016, // Tailwind CDN 大约 340KB
            'compiledItems' => [],
        ];

        // 保存页面 CSS
        foreach ($pages as $page) {
            $page_id = intval($page['id'] ?? 0);
            $css = $page['css'] ?? '';
            $page_stats = $page['stats'] ?? [];

            if ($page_id <= 0) {
                $stats['errors'][] = [
                    'page_id' => $page_id,
                    'error' => 'Invalid page ID'
                ];
                continue;
            }

            // 添加字体 CSS（如果前端编译的 CSS 不包含字体）
            $font_css = Aether_Font_Manager_Service::get_font_css();
            if (!empty($font_css) && strpos($css, '@font-face') === false) {
                $css = $font_css . "\n" . $css;
            }

            try {
                $storage_service->save_page_css($page_id, $css, [
                    'original_size' => $page_stats['originalSize'] ?? 0,
                    'optimized_size' => $page_stats['optimizedSize'] ?? strlen($css),
                ]);

                $stats['compiledPages']++;
                $stats['originalSize'] += $page_stats['originalSize'] ?? 0;
                $stats['optimizedSize'] += $page_stats['optimizedSize'] ?? strlen($css);

                // 获取页面标题
                $page_title = get_the_title($page_id);
                $stats['compiledItems'][] = [
                    'page_id' => $page_id,
                    'page_title' => $page_title,
                    'type' => 'page',
                    'css_size' => strlen($css),
                ];
            } catch (Exception $e) {
                $stats['errors'][] = [
                    'page_id' => $page_id,
                    'error' => $e->getMessage()
                ];
            }
        }

        // 保存模板 CSS
        foreach ($templates as $template) {
            $template_type = $template['type'] ?? '';
            $css = $template['css'] ?? '';
            $template_stats = $template['stats'] ?? [];

            if (empty($template_type)) {
                $stats['errors'][] = [
                    'template' => $template_type,
                    'error' => 'Invalid template type'
                ];
                continue;
            }

            // 添加字体 CSS（如果前端编译的 CSS 不包含字体）
            $font_css = Aether_Font_Manager_Service::get_font_css();
            if (!empty($font_css) && strpos($css, '@font-face') === false) {
                $css = $font_css . "\n" . $css;
            }

            try {
                $storage_service->save_template_css($template_type, $css, [
                    'original_size' => $template_stats['originalSize'] ?? 0,
                    'optimized_size' => $template_stats['optimizedSize'] ?? strlen($css),
                ]);

                $stats['compiledTemplates']++;
                $stats['originalSize'] += $template_stats['originalSize'] ?? 0;
                $stats['optimizedSize'] += $template_stats['optimizedSize'] ?? strlen($css);

                $stats['compiledItems'][] = [
                    'template' => $template_type,
                    'template_name' => $template_type,
                    'type' => 'template',
                    'css_size' => strlen($css),
                ];
            } catch (Exception $e) {
                $stats['errors'][] = [
                    'template' => $template_type,
                    'error' => $e->getMessage()
                ];
            }
        }

        // 计算 CDN 总大小
        $totalCompiledItems = $stats['compiledPages'] + $stats['compiledTemplates'];
        if ($totalCompiledItems > 0) {
            $stats['cdnSize'] = 342016 * $totalCompiledItems;
        }

        // 标记使用智能 CSS 模式
        update_option('aether_use_smart_css', true);
        update_option('aether_smart_css_compiled_at', current_time('timestamp'));
        update_option('aether_smart_css_stats', $stats, false);

        // 清理对象缓存
        wp_cache_delete('aether_use_smart_css', 'options');
        wp_cache_delete('aether_smart_css_compiled_at', 'options');
        wp_cache_delete('aether_smart_css_stats', 'options');
        wp_cache_delete('alloptions', 'options');

        return $this->success_response([
            'success' => empty($stats['errors']),
            'stats' => $stats,
        ]);
    }
}