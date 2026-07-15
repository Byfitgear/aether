<?php
/**
 * PHP 保存绕过拦截器 - 保护 aether 编辑的内容不被 WordPress 修改
 *
 * 核心策略：
 * 如果页面被 aether 编辑过，在 WordPress 后台的任何保存操作都不应该修改 post_content
 * 用户可以在 WordPress 后台修改标题、slug、meta 等，但内容只能在 aether 编辑器中修改
 *
 * @package Aether
 * @since 1.1.15
 */

defined('ABSPATH') || exit;

class Aether_PHP_Bypass_Interceptor {

    private static $instance = null;
    private $manager;

    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
    }

    private function get_manager() {
        if (!$this->manager) {
            $this->manager = Aether_PHP_Protection_Manager::getInstance();
        }
        return $this->manager;
    }

    private function register_hooks() {
        // 在 WordPress 保存数据之前，拦截并保护 post_content
        add_filter('wp_insert_post_data', [$this, 'protect_aether_content'], 1, 2);
    }

    /**
     * 保护 aether 编辑的内容
     *
     * 如果页面被 aether 编辑过，直接用数据库中的原始内容替换 $data['post_content']
     * 这样 WordPress 无论如何处理都不会破坏 aether 保存的代码
     */
    public function protect_aether_content($data, $postarr) {
        $post_id = isset($postarr['ID']) ? $postarr['ID'] : 0;

        // 必须是更新操作（不是新建）
        if (!$post_id) {
            return $data;
        }

        // 检查是否是 aether 编辑的内容
        $is_aether = $this->get_manager()->is_aether_edited($post_id);

        if (!$is_aether) {
            return $data;
        }

        $this->log("Post {$post_id} is aether-edited, protecting content...");

        // 检测保存来源
        $save_source = $this->detect_save_source();
        $this->log("Save source detected: {$save_source}");

        // 如果是 aether 编辑器保存的，允许内容更新
        if ($this->is_aether_editor_save()) {
            $this->log("Save from aether editor, allowing content update");
            return $data;
        }

        // Read current post_content from database (original content saved by aether)
        global $wpdb;
        $current_content = $wpdb->get_var($wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d",
            $post_id
        ));

        if (null === $current_content) {
            $this->log("Could not read current content from database for post {$post_id}", 'warning');
            return $data;
        }

        // 用数据库中的原始内容替换 WordPress 准备保存的内容
        $original_length = strlen($data['post_content']);
        $protected_length = strlen($current_content);

        $data['post_content'] = $current_content;

        $this->log("Protected post_content for post {$post_id}");
        $this->log("Original length from WP: {$original_length}, Protected length from DB: {$protected_length}");
        $this->log("WordPress can update title, slug, meta, but NOT content");

        return $data;
    }

    /**
     * 检测保存来源
     */
    private function detect_save_source() {
        // 检查是否是 REST API 请求
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'REST_API (Gutenberg)';
        }

        // 检查是否是 WordPress 后台
        if (is_admin()) {
            // 检查是否是快速编辑
            if (isset($_POST['action']) && $_POST['action'] === 'inline-save') {
                return 'Quick Edit';
            }

            // 检查是否是 Classic Editor
            if (isset($_POST['classic-editor'])) {
                return 'Classic Editor';
            }

            return 'WordPress Admin';
        }

        // 检查是否是前端编辑
        if (!is_admin() && isset($_POST['action'])) {
            return 'Frontend (' . $_POST['action'] . ')';
        }

        return 'Unknown';
    }

    /**
     * 检测是否是 aether 编辑器保存的
     */
    private function is_aether_editor_save() {
        // 1. 检查 REST API 请求 (aether 编辑器主要保存方式)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            // aether REST API: /wp-json/aether/v1/content/{id}
            if (strpos($request_uri, '/aether/v1/content/') !== false) {
                return true;
            }
        }

        // 2. 检查 AJAX action (进入编辑器前保存草稿)
        if (isset($_POST['action']) && $_POST['action'] === 'aether_save_draft') {
            return true;
        }

        // 3. 检查 aether 特定的请求标记
        if (isset($_POST['aether_editor']) || isset($_GET['aether_editor'])) {
            return true;
        }

        // 4. 检查 X-WP-Nonce header (REST API 请求会带此 header)
        $nonce_header = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
        if (!empty($nonce_header)) {
            // 如果有 nonce header 且请求路径包含 aether，很可能是 aether REST API
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($request_uri, '/aether/') !== false) {
                return true;
            }
        }

        return false;
    }

    private function log($message, $type = 'info') {
        // 只在 debug 模式下记录日志到 WordPress debug.log
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('[Aether Bypass Interceptor] ' . strtoupper($type) . ': ' . $message);
    }
}
