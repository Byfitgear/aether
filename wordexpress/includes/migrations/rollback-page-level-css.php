<?php
/**
 * 紧急回滚：禁用页面级 CSS，全站使用 CDN
 *
 * 使用方法：
 * 1. 在 wp-config.php 中添加：define('ZEROY_FORCE_CDN_MODE', true);
 * 2. 或者在 WordPress 管理后台运行此函数：zeroy_emergency_rollback();
 *
 * @package zeroy
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
function zeroy_emergency_rollback()
{
    global $wpdb;

    $result = [
        'cleared_meta' => 0,
        'cleared_options' => 0,
        'cancelled_tasks' => 0
    ];

    try {
        // 1. 清除所有编译 CSS 标记（强制使用 CDN）
        $result['cleared_meta'] = $wpdb->query("
            DELETE FROM {$wpdb->postmeta}
            WHERE meta_key = '_zeroy_has_compiled_css'
        ");

        // 2. 清除存储层
        if (class_exists('ZeroY_CSS_Storage_Service')) {
            $storage = ZeroY_CSS_Storage_Service::get_instance();
            $storage->clear_all_css();
        }

        // 3. 停止所有重编译任务
        $task_options = [
            'zeroy_recompile_progress',
            'zeroy_recompile_offset',
            'zeroy_recompile_pending'
        ];

        foreach ($task_options as $option) {
            if (delete_option($option)) {
                $result['cleared_options']++;
            }
        }

        delete_transient('zeroy_recompile_schedule_lock');

        // 4. 取消所有调度任务
        $next = wp_next_scheduled('zeroy_recompile_all_pages');
        while ($next) {
            wp_unschedule_event($next, 'zeroy_recompile_all_pages');
            $result['cancelled_tasks']++;
            $next = wp_next_scheduled('zeroy_recompile_all_pages');
        }

        wp_clear_scheduled_hook('zeroy_recompile_all_pages');

        // 5. 清除缓存
        wp_cache_flush();

        error_log(sprintf(
            'zeroy: Emergency rollback completed. Cleared %d meta, %d options, cancelled %d tasks',
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
        error_log('zeroy: Emergency rollback failed: ' . $e->getMessage());

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
function zeroy_reenable_page_level_css()
{
    // 清除回滚状态
    delete_option('zeroy_emergency_rollback_at');

    // 触发整站重编译
    wp_schedule_single_event(time(), 'zeroy_recompile_all_pages', [
        [
            'trigger' => 'reenable_after_rollback',
            'user_id' => get_current_user_id()
        ]
    ]);

    error_log('zeroy: Page-level CSS re-enabled, scheduled full recompile');

    return [
        'success' => true,
        'message' => '页面级 CSS 已重新启用，整站重编译已调度'
    ];
}
