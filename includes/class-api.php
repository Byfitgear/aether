<?php
/**
 * aether REST API 核心类
 * 
 * 注册和管理 REST API 路由 - 重构版本
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * API 类 - 重构版本
 */
class Aether_API extends Aether_Base
{

    /**
     * API 命名空间
     */
    const NAMESPACE = 'aether/v1';

    /**
     * Route modules
     */
    private $route_modules = [];

    /**
     * 初始化
     */
    protected function init()
    {
        // 初始化路由模块
        $this->init_route_modules();

        // 注册 REST API 路由
        add_action('rest_api_init', [$this, 'register_routes']);

        // 禁止缓存插件缓存 aether API 响应（双重保障）
        add_filter('rest_post_dispatch', [$this, 'add_no_cache_headers'], 10, 3);
        add_action('rest_api_init', [$this, 'maybe_set_nocache']);
    }

    /**
     * 初始化路由模块
     */
    private function init_route_modules()
    {
        // Initialize unified proxy routes
        Aether_Unified_Proxy_Routes::get_instance();
        
        // Initialize AI stream handler for AJAX streaming
        Aether_AI_Stream_Handler::get_instance();

        $this->route_modules = [
            'content' => new Aether_API_Routes_Content(),
            'media' => new Aether_API_Routes_Media(),
            'settings' => new Aether_API_Routes_Settings(),
            'templates' => new Aether_API_Routes_Templates(),
            'preview' => new Aether_API_Routes_Preview(),
            'css' => new Aether_API_Routes_CSS(),
            'fonts' => new Aether_API_Routes_Fonts(),
            'ai' => new Aether_API_Routes_AI(),
            'fields' => new Aether_API_Routes_Fields(),
            'php' => new Aether_API_Routes_PHP(),
            'htmx' => new Aether_API_Routes_HTMX(),
            'submissions' => new Aether_API_Routes_Submissions(),
            'auth' => new Aether_API_Routes_Auth(),
            'notifications' => new Aether_API_Routes_Notifications(),
            'optimization' => new Aether_API_Routes_Optimization(),
        ];
    }

    /**
     * 在 REST API 初始化时，尽早通知缓存插件不要缓存 aether 请求
     *
     * 这比 rest_post_dispatch 更早执行，能防止 LiteSpeed Cache 等插件
     * 在 PHP 处理请求之前就返回缓存响应。
     */
    public function maybe_set_nocache() {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, 'aether/v1') === false) {
            return;
        }

        // LiteSpeed Cache 插件 API（最可靠的方式）
        if (function_exists('litespeed_control_set_nocache')) {
            litespeed_control_set_nocache();
        } elseif (has_action('litespeed_control_set_nocache')) {
            do_action('litespeed_control_set_nocache');
        }

        // 直接发送 header（防止 Web Server 级别的缓存）
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache');
        }
    }

    /**
     * 为 aether REST API 响应添加全面的 no-cache 头
     *
     * @param WP_REST_Response $response
     * @param WP_REST_Server   $server
     * @param WP_REST_Request  $request
     * @return WP_REST_Response
     */
    public function add_no_cache_headers($response, $server, $request) {
        $route = $request->get_route();

        if (strpos($route, '/' . self::NAMESPACE . '/') === 0) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            $response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        }

        return $response;
    }

    /**
     * 注册 REST API 路由
     */
    public function register_routes()
    {
        // 注册所有模块的路由
        foreach ($this->route_modules as $module) {
            $module->register_routes();
        }
    }
}
