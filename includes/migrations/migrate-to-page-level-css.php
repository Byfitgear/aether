<?php
/**
 * 数据迁移：从全局生产模式迁移到页面级 CSS
 *
 * 执行内容：
 * 1. 初始化所有现有页面的哈希值
 * 2. 清理旧的全局生产模式配置
 * 3. 标记所有已编译页面为有效状态
 *
 * @package aether
 * @subpackage Migrations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 执行页面级 CSS 迁移
 *
 * @return array 迁移结果
 */
function aether_migrate_to_page_level_css()
{
    $migration_key = 'aether_migration_page_level_css_v1';
    if (get_option($migration_key)) {
        return [
            'success' => true,
            'message' => '迁移已执行过，跳过',
            'skipped' => true
        ];
    }

    global $wpdb;

    $result = [
        'updated_pages' => 0,
        'cleared_options' => 0,
        'errors' => []
    ];

    try {
        // 1. 获取当前的 header/footer 和设计系统哈希
        if (!class_exists('Aether_CSS_Hash_Service')) {
            require_once AETHER_PATH . 'includes/services/css/class-css-hash-service.php';
        }

        $hash_service = Aether_CSS_Hash_Service::get_instance();
        $header_footer_hash = $hash_service->get_header_footer_hash();
        $design_system_hash = $hash_service->get_design_system_hash();

        // 2. 为所有已编译的页面初始化哈希值
        $compiled_pages = $wpdb->get_col("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_aether_has_compiled_css'
            AND meta_value = '1'
        ");

        foreach ($compiled_pages as $post_id) {
            // 添加哈希值（如果还没有）
            if (!get_post_meta($post_id, '_aether_css_header_footer_hash', true)) {
                update_post_meta($post_id, '_aether_css_header_footer_hash', $header_footer_hash);
            }
            if (!get_post_meta($post_id, '_aether_css_design_system_hash', true)) {
                update_post_meta($post_id, '_aether_css_design_system_hash', $design_system_hash);
            }

            // 删除旧的版本号字段（如果存在）
            delete_post_meta($post_id, '_aether_css_version');

            $result['updated_pages']++;
        }

        // 3. 清理旧的全局生产模式配置
        $old_options = [
            'aether_production_mode',
            'aether_global_template_version',
            'aether_smart_css_enabled',
        ];

        foreach ($old_options as $option) {
            if (delete_option($option)) {
                $result['cleared_options']++;
            }
        }

        // 4. 标记迁移完成
        update_option($migration_key, current_time('mysql'));

        error_log(sprintf(
            'aether: Migrated %d pages to page-level CSS system, cleared %d old options',
            $result['updated_pages'],
            $result['cleared_options']
        ));

        return [
            'success' => true,
            'message' => sprintf(
                '迁移完成：更新了 %d 个页面，清理了 %d 个旧配置',
                $result['updated_pages'],
                $result['cleared_options']
            ),
            'result' => $result
        ];
    } catch (Exception $e) {
        error_log('aether: Migration failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => '迁移失败：' . $e->getMessage(),
            'result' => $result
        ];
    }
}

/**
 * 在插件升级时执行迁移
 */
add_action('upgrader_process_complete', function ($upgrader, $options) {
    if ($options['type'] === 'plugin' && isset($options['plugins'])) {
        foreach ($options['plugins'] as $plugin) {
            if (strpos($plugin, 'aether') !== false) {
                aether_migrate_to_page_level_css();
            }
        }
    }
}, 10, 2);

/**
 * 在插件激活时执行迁移
 */
add_action('aether_activated', function () {
    aether_migrate_to_page_level_css();
});
