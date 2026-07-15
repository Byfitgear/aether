<?php
/**
 * PHP 代码保护管理器
 *
 * @package Aether
 * @since 1.1.15
 */

defined('ABSPATH') || exit;

class Aether_PHP_Protection_Manager {

    private static $instance = null;
    private $protected_posts = [];
    private $is_protecting = false;
    private $initialized = false;  // 防止重复初始化

    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 延迟初始化，避免在构造函数中做太多事情
        add_action('plugins_loaded', [$this, 'init_once'], 1);
    }

    /**
     * 初始化（只执行一次）
     */
    public function init_once() {
        // 防止重复初始化
        if ($this->initialized) {
            $this->log('init_once called but already initialized, skipping', 'warning');
            return;
        }

        $this->initialized = true;
        $this->log('PHP Protection Manager initializing...');

        // 加载子组件
        $this->load_components();
        $this->log('Components loaded');

        // 设置保护
        $this->setup_protection();
        $this->log('Protection setup complete, protected posts: ' . count($this->protected_posts));

        // 延迟设置
        add_action('init', [$this, 'late_setup'], PHP_INT_MAX);
    }

    private function load_components() {
        // 只加载核心的 Bypass Interceptor
        // 新方案：直接在保存时用数据库内容替换，无需备份、恢复、显示保护等复杂机制
        $component_files = [
            'class-php-bypass-interceptor.php',  // 核心保护机制
        ];

        foreach ($component_files as $file) {
            $path = AETHER_PATH . 'includes/services/core/' . $file;
            if (file_exists($path)) {
                require_once $path;
            } else {
                $this->log('Component file not found: ' . $file, 'error');
            }
        }

        // 实例化核心组件
        if (class_exists('Aether_PHP_Bypass_Interceptor')) {
            Aether_PHP_Bypass_Interceptor::getInstance();
            $this->log('Bypass Interceptor loaded');
        }
    }

    public function setup_protection() {
        // 扫描需要保护的内容
        $this->scan_protected_posts();

        if (!empty($this->protected_posts)) {
            $this->is_protecting = true;
        }
    }

    public function late_setup() {
        // 清理和优化
    }

    private function scan_protected_posts() {
        global $wpdb;

        // 从缓存获取
        $cached = wp_cache_get('aether_protected_posts', 'aether');
        if (false !== $cached) {
            $this->protected_posts = $cached;
            return;
        }

        // 安全地查询包含 PHP 代码的 aether 编辑内容
        try {
            $posts = $wpdb->get_col($wpdb->prepare("
                SELECT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE pm.meta_key = %s
                AND pm.meta_value = %s
                AND (p.post_content LIKE %s OR p.post_content LIKE %s)
            ", '_aether_edited', '1', '%<?php%', '%<?=%'));

            if (is_array($posts)) {
                $this->protected_posts = array_map('intval', $posts);
            } else {
                $this->protected_posts = [];
            }

            // 缓存1小时
            wp_cache_set('aether_protected_posts', $this->protected_posts, 'aether', 3600);
        } catch (Exception $e) {
            $this->log('Error scanning protected posts: ' . $e->getMessage(), 'error');
            $this->protected_posts = [];
        }
    }

    public function is_protected_post($post_id) {
        return in_array($post_id, $this->protected_posts);
    }

    public function is_aether_edited($post_id) {
        return get_post_meta($post_id, '_aether_edited', true) === '1';
    }

    public function contains_php($content) {
        return strpos($content, '<?php') !== false || strpos($content, '<?=') !== false;
    }

    public function has_encoded_php($content) {
        $patterns = [
            '&lt;?php',
            '&lt;?=',
            '<!--?php',
            '?-->',
            '=&gt;',
            '&amp;&amp;',
            '<br ?',          // wpautop 破坏 PHP 代码
            '<br/>',          // PHP 代码中的 br 标签
            '<br />',         // PHP 代码中的 br 标签
        ];

        foreach ($patterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    public function decode_php_content($content) {
        // 第一阶段：递归 HTML 实体解码
        $max_iterations = 5;
        for ($i = 0; $i < $max_iterations; $i++) {
            $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $content) {
                break;
            }
            $content = $decoded;
        }

        // 第二阶段：特定模式替换
        $replacements = [
            '<!--?php' => '<?php',
            '?-->' => '?>',
            '<br ?>' => '',      // wpautop 插入的奇怪标签
            '<br ?->' => '?>',   // 另一种变体
            '=&gt;' => '=>',
            '&gt;=' => '>=',
            '&lt;=' => '<=',
            '&amp;&amp;' => '&&',
            '&amp;' => '&',
            '&quot;' => '"',
            '&#39;' => "'",
        ];

        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        // 额外清理：移除 PHP 代码前后的 br 标签
        $content = preg_replace('/<br\s*\/?>\s*<\?php/', '<?php', $content);
        $content = preg_replace('/\?>\s*<br\s*\/?>/', '?>', $content);

        // 第三阶段：清理 PHP 代码块中的 HTML 标签（保留原有格式）
        $content = preg_replace_callback(
            '/<\?php(.*?)\?>/s',
            function($matches) {
                $php_code = $matches[1];

                // 只移除 HTML 标签，保留原有空白和换行
                $php_code = preg_replace('/<br\s*\/?>/i', '', $php_code);
                $php_code = preg_replace('/<\/?p>/i', '', $php_code);
                $php_code = preg_replace('/<\/?div>/i', '', $php_code);
                $php_code = preg_replace('/<\/?span>/i', '', $php_code);

                // 不要压缩空白，保留原有格式
                return '<?php' . $php_code . '?>';
            },
            $content
        );

        // 第四阶段：清理短 PHP 标签
        $content = preg_replace_callback(
            '/<\?=(.*?)\?>/s',
            function($matches) {
                $php_code = $matches[1];

                // 移除 HTML 标签
                $php_code = preg_replace('/<br\s*\/?>/', '', $php_code);
                $php_code = preg_replace('/<\/?[^>]+>/', '', $php_code);

                // 清理空白
                $php_code = trim($php_code);

                return '<?=' . $php_code . '?>';
            },
            $content
        );

        return $content;
    }

    public function clear_cache($post_id) {
        wp_cache_delete('aether_protected_posts', 'aether');
        $this->scan_protected_posts();
    }

    public function log($message, $type = 'info') {
        // 需要开启 AETHER_DEBUG 才输出日志
        if (!defined('AETHER_DEBUG') || !AETHER_DEBUG) {
            return;
        }

        error_log('[Aether PHP Protection Manager] ' . strtoupper($type) . ': ' . $message);
    }
}