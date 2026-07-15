<?php
/**
 * 数据库迁移：添加 CSS 编译相关的索引
 *
 * 执行时机：插件激活或版本升级时
 * 目的：优化统计查询和整站重编译的性能
 *
 * @package zeroy
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
function zeroy_migration_add_css_indexes()
{
    global $wpdb;

    // 检查是否已执行过此迁移
    $migration_key = 'zeroy_migration_css_indexes_v1';
    if (get_option($migration_key)) {
        return [
            'success' => true,
            'message' => '索引迁移已执行过，跳过',
            'skipped' => true
        ];
    }

    $indexes_added = [];
    $errors = [];

    // 检查索引是否已存在
    $existing_indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->postmeta}", ARRAY_A);
    $existing_index_names = array_column($existing_indexes, 'Key_name');

    // 索引定义
    $indexes_to_add = [
        [
            'name' => 'idx_zeroy_edited',
            'sql' => "CREATE INDEX idx_zeroy_edited ON {$wpdb->postmeta} (meta_key, meta_value(1)) COMMENT 'zeroy: 快速查找已编辑的页面'"
        ],
        [
            'name' => 'idx_zeroy_has_compiled_css',
            'sql' => "CREATE INDEX idx_zeroy_has_compiled_css ON {$wpdb->postmeta} (meta_key, meta_value(1)) COMMENT 'zeroy: 快速查找已编译的页面'"
        ],
    ];

    foreach ($indexes_to_add as $index) {
        if (in_array($index['name'], $existing_index_names)) {
            continue; // 索引已存在
        }

        $result = $wpdb->query($index['sql']);

        if ($result === false) {
            $errors[] = [
                'index' => $index['name'],
                'error' => $wpdb->last_error
            ];
            error_log(sprintf(
                'zeroy: Failed to create index %s: %s',
                $index['name'],
                $wpdb->last_error
            ));
        } else {
            $indexes_added[] = $index['name'];
        }
    }

    // 标记迁移完成
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
        'zeroy: CSS indexes migration completed. Added: %s, Errors: %d',
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
function zeroy_migration_remove_css_indexes()
{
    global $wpdb;

    $indexes_removed = [];
    $errors = [];

    $indexes_to_remove = [
        'idx_zeroy_edited',
        'idx_zeroy_has_compiled_css',
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

    // 清除迁移标记
    delete_option('zeroy_migration_css_indexes_v1');

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
add_action('zeroy_activated', function () {
    zeroy_migration_add_css_indexes();
});
