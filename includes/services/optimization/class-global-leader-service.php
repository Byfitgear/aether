<?php
/**
 * Global Leader Service
 *
 * 全局 Leader 选举服务（跨浏览器/用户/设备）
 *
 * 技术方案：
 * - 使用 WordPress Transients API 存储全局 Leader
 * - 心跳超时：15 秒（Leader 必须定期刷新）
 * - 自动故障转移：15 秒无心跳后其他客户端可接管
 *
 * 使用场景：
 * - 多浏览器（Chrome + Firefox）
 * - 多用户（不同账号同时编辑）
 * - 多设备（台式机 + 笔记本）
 *
 * @package aether
 */

defined('ABSPATH') || exit;

class Aether_Global_Leader_Service {

    /**
     * Transient key for global leader
     */
    const TRANSIENT_KEY = 'aether_optimization_global_leader';

    /**
     * Leader heartbeat timeout (15 seconds)
     */
    const LEADER_TIMEOUT = 15;

    /**
     * Try to become global leader
     *
     * 工作流程：
     * 1. 检查是否已有全局 Leader（读取 Transient）
     * 2. 如果没有 → 成为 Leader，设置 Transient
     * 3. 如果是自己 → 刷新心跳（重置过期时间）
     * 4. 如果是其他人 → 返回失败
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public function try_become_leader($request) {
        $session_id = $request->get_param('session_id');

        if (empty($session_id)) {
            return rest_ensure_response([
                'is_leader' => false,
                'message' => 'Session ID 不能为空',
            ]);
        }

        // 获取当前全局 Leader
        $current_leader = get_transient(self::TRANSIENT_KEY);

        // 情况 1: 没有 Leader，成为 Leader
        if (!$current_leader) {
            set_transient(self::TRANSIENT_KEY, $session_id, self::LEADER_TIMEOUT);

            error_log(sprintf(
                '[aether] 🎖️ Session %s 成为全局 Leader',
                $session_id
            ));

            return rest_ensure_response([
                'is_leader' => true,
                'message' => '成为全局 Leader',
            ]);
        }

        // 情况 2: 自己是 Leader，刷新心跳
        if ($current_leader === $session_id) {
            set_transient(self::TRANSIENT_KEY, $session_id, self::LEADER_TIMEOUT);

            return rest_ensure_response([
                'is_leader' => true,
                'message' => '保持 Leader 状态',
            ]);
        }

        // 情况 3: 其他人是 Leader
        return rest_ensure_response([
            'is_leader' => false,
            'leader' => $current_leader,
            'message' => '已有全局 Leader',
        ]);
    }
}
