<?php
/**
 * 紧急回滚：禁用页面级 CSS，全站使用 CDN
 *
 * 使用方法：
 * 1. 在 wp-config.php 中添加：define('AETHER_FORCE_CDN_MODE', true);
 * 2. 或者在 WordPress 管理后台运行此函数：aether_emergency_rollback();
 *
 * @package aether
 * @subpackage Migrations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 紧急回滚到 CDN 模式
 *
 * @return array 回滚结果
 */
function aether_emergency_rollback()
{
    global $wpdb;

    $result = [
        'cleared_meta' => 0,
        'cleared_options' => 0,
        'cancelled_tasks' => 0
    ];

    try {
        // 1. Clear all compiled CSS markers (force use CDN)
        $result['cleared_meta'] = $wpdb->query("
            DELETE FROM {$wpdb->postmeta}
            WHERE meta_key = '_aether_has_compiled_css'
        ");

        // 2. Clear storage layer
        if (class_exists('Aether_CSS_Storage_Service')) {
            $storage = Aether_CSS_Storage_Service::get_instance();
            $storage->clear_all_css();
        }

        // 3. Stop all recompilation tasks
        $task_options = [
            'aether_recompile_progress',
            'aether_recompile_offset',
            'aether_recompile_pending'
        ];

        foreach ($task_options as $option) {
            if (delete_option($option)) {
                $result['cleared_options']++;
            }
        }

        delete_transient('aether_recompile_schedule_lock');

        // 4. Cancel all scheduled tasks
        $next = wp_next_scheduled('aether_recompile_all_pages');
        while ($next) {
            wp_unschedule_event($next, 'aether_recompile_all_pages');
            $result['cancelled_tasks']++;
            $next = wp_next_scheduled('aether_recompile_all_pages');
        }

        wp_clear_scheduled_hook('aether_recompile_all_pages');

        // 5. Clear cache
        wp_cache_flush();

        error_log(sprintf(
            'aether: Emergency rollback completed. Cleared %d meta, %d options, cancelled %d tasks',
            $result['cleared_meta'],
            $result['cleared_options'],
            $result['cancelled_tasks']
        ));

        return [
            'success' => true,
            'message' => '已回滚到 CDN 模式，所有页面将使用 Tailwind CDN',
            'result' => $result
        ];
    } catch (Exception $e) {
        error_log('aether: Emergency rollback failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => '回滚失败：' . $e->getMessage(),
            'result' => $result
        ];
    }
}

/**
 * 重新启用页面级 CSS 编译
 *
 * @return array 结果
 */
function aether_reenable_page_level_css()
{
    // Clear rollback status
    delete_option('aether_emergency_rollback_at');

    // Trigger full site recompilation
    wp_schedule_single_event(time(), 'aether_recompile_all_pages', [
        [
            'trigger' => 'reenable_after_rollback',
            'user_id' => get_current_user_id()
        ]
    ]);

    error_log('aether: Page-level CSS re-enabled, scheduled full recompile');

    return [
        'success' => true,
        'message' => '页面级 CSS 已重新启用，整站重编译已调度'
    ];
}
