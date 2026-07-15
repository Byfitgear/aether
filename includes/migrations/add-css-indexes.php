<?php
/**
 * 数据库迁移：添加 CSS 编译相关的索引
 *
 * 执行时机：插件激活或版本升级时
 * 目的：优化统计查询和整站重编译的性能
 *
 * @package aether
 * @subpackage Migrations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 添加 CSS 编译相关的数据库索引
 *
 * @return array 迁移结果
 */
function aether_migration_add_css_indexes()
{
    global $wpdb;

    // Check if this migration has been executed
    $migration_key = 'aether_migration_css_indexes_v1';
    if (get_option($migration_key)) {
        return [
            'success' => true,
            'message' => '索引迁移已执行过，跳过',
            'skipped' => true
        ];
    }

    $indexes_added = [];
    $errors = [];

    // Check if index already exists
    $existing_indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta}", ARRAY_A);
    $existing_index_names = array_column($existing_indexes, 'Key_name');

    // Index definition
    $indexes_to_add = [
        [
            'name' => 'idx_aether_edited',
            'sql' => "CREATE INDEX idx_aether_edited ON {$wpdb->postmeta} (meta_key, meta_value(1)) COMMENT 'aether: 快速查找已编辑的页面'"
        ],
        [
            'name' => 'idx_aether_has_compiled_css',
            'sql' => "CREATE INDEX idx_aether_has_compiled_css ON {$wpdb->postmeta} (meta_key, meta_value(1)) COMMENT 'aether: 快速查找已编译的页面'"
        ],
    ];

    foreach ($indexes_to_add as $index) {
        if (in_array($index['name'], $existing_index_names)) {
            continue; // Index already exists
        }

        $result = $wpdb->query($index['sql']);

        if ($result === false) {
            $errors[] = [
                'index' => $index['name'],
                'error' => $wpdb->last_error
            ];
            error_log(sprintf(
                'aether: Failed to create index %s: %s',
                $index['name'],
                $wpdb->last_error
            ));
        } else {
            $indexes_added[] = $index['name'];
        }
    }

    // Mark migration as complete
    if (empty($errors)) {
        update_option($migration_key, current_time('mysql'));
    }

    $message = sprintf(
        '添加了 %d 个索引',
        count($indexes_added)
    );

    if (!empty($errors)) {
        $message .= sprintf('，%d 个失败', count($errors));
    }

    error_log(sprintf(
        'aether: CSS indexes migration completed. Added: %s, Errors: %d',
        implode(', ', $indexes_added) ?: 'none',
        count($errors)
    ));

    return [
        'success' => empty($errors),
        'message' => $message,
        'indexes_added' => $indexes_added,
        'errors' => $errors
    ];
}

/**
 * 删除 CSS 编译相关的数据库索引（回滚用）
 *
 * @return array 结果
 */
function aether_migration_remove_css_indexes()
{
    global $wpdb;

    $indexes_removed = [];
    $errors = [];

    $indexes_to_remove = [
        'idx_aether_edited',
        'idx_aether_has_compiled_css',
    ];

    foreach ($indexes_to_remove as $index_name) {
        $result = $wpdb->query("DROP INDEX IF EXISTS {$index_name} ON {$wpdb->postmeta}");

        if ($result === false) {
            $errors[] = [
                'index' => $index_name,
                'error' => $wpdb->last_error
            ];
        } else {
            $indexes_removed[] = $index_name;
        }
    }

    // Clear migration marker
    delete_option('aether_migration_css_indexes_v1');

    return [
        'success' => empty($errors),
        'message' => sprintf('删除了 %d 个索引', count($indexes_removed)),
        'indexes_removed' => $indexes_removed,
        'errors' => $errors
    ];
}

/**
 * 在插件激活时执行索引迁移
 */
add_action('aether_activated', function () {
    aether_migration_add_css_indexes();
});
