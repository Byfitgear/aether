<?php
/**
 * Optimization API Routes
 *
 * 优化引擎相关 API 路由
 *
 * 端点：
 * - POST /wp-json/aether/v1/optimization/try-become-leader
 *
 * @package aether
 */

defined('ABSPATH') || exit;

class Aether_API_Routes_Optimization extends Aether_API_Routes_Base {

    /**
     * Global leader service
     *
     * @var Aether_Global_Leader_Service
     */
    private $global_leader_service;

    /**
     * Constructor
     */
    public function __construct() {
        $this->global_leader_service = new Aether_Global_Leader_Service();
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // Try to become global leader
        $leader_route_registered = $this->register_route('/optimization/try-become-leader', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->global_leader_service, 'try_become_leader'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'session_id' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => '客户端 Session ID',
                ],
            ],
        ]);

        if (!$leader_route_registered) {
            error_log('[aether] ❌ 优化引擎 API 路由注册失败: /optimization/try-become-leader');
        } else {
            error_log('[aether] ✅ 优化引擎 API 路由注册成功: /optimization/try-become-leader');
        }
    }
}
