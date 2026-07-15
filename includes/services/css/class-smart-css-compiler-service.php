<?php
/**
 * 智能 CSS 编译服务
 * 
 * 为每个页面生成专属的优化 CSS
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Smart_CSS_Compiler_Service
{
    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 编译策略服务
     */
    private $compile_strategy;

    /**
     * 存储服务
     */
    private $storage_service;


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
     * 私有构造函数
     */
    private function __construct()
    {
        $this->compile_strategy = Aether_CSS_Compile_Strategy_Service::get_instance();
        $this->storage_service = Aether_CSS_Storage_Service::get_instance();
    }

    /**
     * 注册定时编译任务的 Hook（确保在插件加载时可用）
     */
    public static function register_cron_hook()
    {
        add_action('aether_compile_smart_css', function () {
            try {
                $service = self::get_instance();
                $service->compile_all_pages(['minify' => true]);
            } catch (Throwable $e) {
                error_log('Aether compile cron error: ' . $e->getMessage());
            }
        });
    }

    /**
     * 安排一次延迟的全量编译（后台执行，避免阻塞保存接口）
     *
     * @param int $delay_seconds 延迟秒数
     * @return void
     */
    public static function schedule_full_compile($delay_seconds = 5)
    {
        if (!wp_next_scheduled('aether_compile_smart_css')) {
            wp_schedule_single_event(time() + max(1, (int) $delay_seconds), 'aether_compile_smart_css');
        }
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

        // 从设置中获取排除的模板
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
     * 执行智能 CSS 编译
     * 
     * @return array|WP_Error
     */
    public function compile_all_pages($options = [])
    {
        // 强制刷新对象缓存，确保读取到最新的字体 CSS 和设计系统配置
        // 这对于 Cron 任务至关重要，因为它可能在独立的 PHP 进程中运行
        wp_cache_delete('aether_font_css', 'options');
        wp_cache_delete('aether_font_formats', 'options');
        wp_cache_delete('aether_font_settings', 'options');
        wp_cache_delete('aether_design_system_html', 'options');
        wp_cache_delete('alloptions', 'options');

        $stats = [
            'totalPages' => 0,
            'totalTemplates' => 0,
            'compiledPages' => 0,
            'errors' => [],
            'originalSize' => 0,
            'optimizedSize' => 0,
            'cdnSize' => 342016, // Tailwind CDN 大约 340KB
            'compiledItems' => [], // 记录成功编译的项目
        ];

        // 1. 获取公共模板内容
        $common_templates = $this->compile_strategy->get_common_templates();

        // 3. 处理所有使用 aether 编辑的页面
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

        /** @var WP_Post[] $pages */
        foreach ($pages as $page) {
            $result = $this->compile_page_css($page, $common_templates, $stats);
            if (is_wp_error($result)) {
                $stats['errors'][] = [
                    'page_id' => $page->ID,
                    'page_title' => $page->post_title,
                    'error' => $result->get_error_message()
                ];
            } else {
                $stats['compiledPages']++;
                // 获取页面统计
                $page_stats = $this->storage_service->get_page_stats($page->ID);
                $stats['compiledItems'][] = [
                    'page_id' => $page->ID,
                    'page_title' => $page->post_title,
                    'type' => 'page',
                    'css_size' => intval($page_stats['css_size'])
                ];
            }
            $stats['totalPages']++;
        }

        // 4. 处理动态模板页面 - 只处理实际存在的模板
        $template_types = $this->get_template_types_to_compile();
        $aether_templates = Aether_Templates::getInstance();

        foreach ($template_types as $type => $config) {
            // 跳过页眉和页脚的单独编译
            if ($type === 'header' || $type === 'footer') {
                continue;
            }
            
            // 检查模板是否实际存在
            $template = $aether_templates->get_active_template($type);
            if (!$template || empty($template['content'])) {
                continue; // 跳过不存在的模板
            }

            $result = $this->compile_template_page_css($type, $config, $common_templates, $stats);
            if (is_wp_error($result)) {
                $stats['errors'][] = [
                    'template' => $type,
                    'template_name' => $config['label'],
                    'error' => $result->get_error_message()
                ];
            } else {
                $stats['compiledPages']++;
                // 获取模板统计
                $template_stats = $this->storage_service->get_template_stats($type);
                $stats['compiledItems'][] = [
                    'template' => $type,
                    'template_name' => $config['label'],
                    'type' => 'template',
                    'css_size' => intval($template_stats['css_size'])
                ];
            }
            $stats['totalTemplates']++;
        }

        // 5. 标记使用智能 CSS 模式
        update_option('aether_use_smart_css', true);
        update_option('aether_smart_css_compiled_at', current_time('timestamp'));

        // 6. 计算实际的 CDN 总大小（每个页面都会加载完整的 CDN）
        $totalCompiledPages = $stats['compiledPages'];
        if ($totalCompiledPages > 0) {
            $stats['cdnSize'] = 342016 * $totalCompiledPages; // Every page must load 340KB of CDN
        }

        // 7. 保存统计信息
        // 统计信息可能较大，避免加入 alloptions
        update_option('aether_smart_css_stats', $stats, false);

        // 清理对象缓存，确保配置立即生效
        wp_cache_delete('aether_use_smart_css', 'options');
        wp_cache_delete('aether_smart_css_compiled_at', 'options');
        wp_cache_delete('aether_smart_css_stats', 'options');
        wp_cache_delete('alloptions', 'options');

        return [
            'success' => empty($stats['errors']),
            'stats' => $stats,
        ];
    }

    /**
     * 编译单个页面的 CSS
     * 
     * @param WP_Post $page
     * @param array $common_templates
     * @param array|null $stats
     * @return bool|WP_Error
     */
    private function compile_page_css($page, $common_templates, &$stats = null)
    {
        // 使用编译策略服务编译页面
        $result = $this->compile_strategy->compile_page($page, $common_templates);
        
        if (is_wp_error($result)) {
            return $result;
        }

        // 更新统计信息
        if ($stats !== null) {
            $stats['originalSize'] += $result['stats']['original_size'];
            $stats['optimizedSize'] += $result['stats']['optimized_size'];
        }

        // 使用存储服务保存 CSS
        $this->storage_service->save_page_css($page->ID, $result['css'], $result['stats']);

        return true;
    }

    /**
     * 编译模板页面的 CSS
     */
    private function compile_template_page_css($type, $config, $common_templates, &$stats = null)
    {
        // 使用编译策略服务编译模板
        $result = $this->compile_strategy->compile_template($type, $config, $common_templates);
        
        if (is_wp_error($result)) {
            return $result;
        }

        // 更新统计信息
        if ($stats !== null) {
            $stats['originalSize'] += $result['stats']['original_size'];
            $stats['optimizedSize'] += $result['stats']['optimized_size'];
        }

        // 使用存储服务保存 CSS
        $this->storage_service->save_template_css($type, $result['css'], $result['stats']);

        return true;
    }

    /**
     * 获取需要编译的模板类型
     */
    private function get_template_types_to_compile()
    {
        // 获取所有已创建的 aether 模板
        $templates = get_posts([
            'post_type' => 'aether_template',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $template_types = [];

        // 遍历所有模板，获取其类型和标题
        /** @var WP_Post[] $templates */
        foreach ($templates as $template) {
            $template_type = get_post_meta($template->ID, '_aether_template_type', true);
            if (!empty($template_type)) {
                $template_types[$template_type] = [
                    'label' => $template->post_title,
                    'id' => $template->ID
                ];
            }
        }

        return $template_types;
    }

    /**
     * 检查是否使用智能 CSS
     *
     * @deprecated 2.0.0 页面级 CSS 编译已取代全局生产模式，请使用 CSS_Injection_Manager 的页面级判断
     * @return bool Always returns false (use page-level CSS checking instead)
     */
    public function should_use_smart_css()
    {
        _deprecated_function(__METHOD__, '2.0.0', 'Aether_CSS_Injection_Manager::has_valid_compiled_css()');
        // 此方法已废弃，页面级 CSS 判断由 CSS_Injection_Manager 处理
        return false;
    }

    /**
     * 获取编译统计信息
     */
    public function get_compilation_stats()
    {
        $stats = get_option('aether_smart_css_stats', [
            'totalPages' => 0,
            'totalTemplates' => 0,
            'compiledPages' => 0,
            'errors' => [],
            'originalSize' => 0,
            'optimizedSize' => 0,
            'cdnSize' => 0,
            'compiledItems' => [],
        ]);

        return $stats;
    }

    /**
     * 获取页面的编译 CSS
     */
    public function get_page_css($post_id = null)
    {
        // 使用存储服务获取完整的 CSS
        return $this->storage_service->get_page_complete_css($post_id);
    }
}
