<?php
/**
 * 数据迁移：还原 picture 标签为 img 标签
 *
 * 背景：
 * v1.1.28 之前的版本会直接将 post_content 中的 <img> 转换为 <picture>
 * 新版本改为将优化版本存储在 meta 字段，post_content 保持原始 <img>
 * 此迁移脚本用于还原旧版本转换的 picture 标签
 *
 * 检测逻辑：
 * 只处理 zeroy 插件生成的 picture 标签（source srcset 包含 zeroy-optimized 路径）
 * 避免误伤用户手动添加的 picture 标签
 *
 * @package zeroy
 * @subpackage Migrations
 * @since 1.1.29
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 执行 picture 还原迁移
 *
 * @param bool $force 是否强制执行（忽略已执行标记）
 * @return array 迁移结果
 */
function zeroy_migrate_restore_picture_to_img($force = false)
{
    $migration_key = 'zeroy_migration_restore_picture_v1';

    if (!$force && get_option($migration_key)) {
        return [
            'success' => true,
            'message' => '迁移已执行过，跳过',
            'skipped' => true
        ];
    }

    global $wpdb;

    $result = [
        'scanned_pages' => 0,
        'updated_pages' => 0,
        'restored_images' => 0,
        'errors' => []
    ];

    try {
        // 查询所有 Page 类型的文章
        $pages = $wpdb->get_results("
            SELECT ID, post_content, post_title
            FROM {$wpdb->posts}
            WHERE post_type = 'page'
            AND post_status IN ('publish', 'draft', 'pending', 'private')
            AND post_content LIKE '%<picture>%'
            AND post_content LIKE '%zeroy-optimized%'
        ");

        $result['scanned_pages'] = count($pages);

        foreach ($pages as $page) {
            $original_content = $page->post_content;
            $restored_content = zeroy_restore_picture_tags($original_content);

            // 如果内容有变化，更新数据库
            if ($restored_content !== $original_content) {
                $original_count = substr_count($original_content, '<picture>');
                $new_count = substr_count($restored_content, '<picture>');

                // 直接更新数据库，绕过所有 WordPress 钩子
                global $wpdb;
                $updated = $wpdb->update(
                    $wpdb->posts,
                    ['post_content' => $restored_content],
                    ['ID' => $page->ID],
                    ['%s'],
                    ['%d']
                );

                if ($updated !== false) {
                    $result['updated_pages']++;
                    $result['restored_images'] += ($original_count - $new_count);

                    // 清除该页面的优化 HTML 缓存
                    delete_post_meta($page->ID, '_zeroy_optimized_html');
                    delete_post_meta($page->ID, '_zeroy_html_hash');

                    // 清除对象缓存，确保编辑器读取最新内容
                    clean_post_cache($page->ID);

                    error_log(sprintf(
                        'zeroy: 还原 %d 个 picture 标签 → img，页面 #%d "%s"',
                        $original_count - $new_count,
                        $page->ID,
                        $page->post_title
                    ));
                } else {
                    $result['errors'][] = sprintf(
                        'Failed to update page #%d: database update failed',
                        $page->ID
                    );
                }
            }
        }

        // 标记迁移完成
        update_option($migration_key, current_time('mysql'));

        $message = sprintf(
            '迁移完成：扫描 %d 个页面，更新 %d 个页面，还原 %d 个图片标签',
            $result['scanned_pages'],
            $result['updated_pages'],
            $result['restored_images']
        );

        error_log('zeroy: ' . $message);

        return [
            'success' => true,
            'message' => $message,
            'result' => $result
        ];
    } catch (Exception $e) {
        error_log('zeroy: Picture restore migration failed: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => '迁移失败：' . $e->getMessage(),
            'result' => $result
        ];
    }
}

/**
 * 还原 zeroy 生成的 picture 标签为 img 标签
 *
 * 只处理包含 zeroy-optimized 路径的 picture 标签
 * 保留用户手动添加的 picture 标签
 * 还原为最简单的 img 标签（只保留 src/alt/class）
 *
 * @param string $content HTML 内容
 * @return string 还原后的内容
 */
function zeroy_restore_picture_tags($content)
{
    if (empty($content)) {
        return $content;
    }

    // 匹配 picture 标签（非贪婪模式）
    $pattern = '/<picture\b[^>]*>(.*?)<\/picture>/is';

    return preg_replace_callback($pattern, function ($matches) {
        $picture_content = $matches[0];
        $inner_content = $matches[1];

        // 检查是否是 zeroy 生成的（source 包含 zeroy-optimized 路径）
        if (strpos($inner_content, 'zeroy-optimized') === false) {
            // 不是 zeroy 生成的，保持原样
            return $picture_content;
        }

        // 提取 img 标签
        if (preg_match('/<img\s+[^>]*>/i', $inner_content, $img_match)) {
            $img_tag = $img_match[0];

            // 提取核心属性：src, alt, class
            $src = '';
            $alt = '';
            $class = '';

            if (preg_match('/\bsrc="([^"]*)"/i', $img_tag, $m)) {
                $src = $m[1];
            }
            if (preg_match('/\balt="([^"]*)"/i', $img_tag, $m)) {
                $alt = $m[1];
            }
            if (preg_match('/\bclass="([^"]*)"/i', $img_tag, $m)) {
                $class = $m[1];
            }

            // 如果没有 src，保持原样（异常情况）
            if (empty($src)) {
                return $picture_content;
            }

            // 构建最简单的 img 标签
            $simple_img = '<img src="' . esc_attr($src) . '"';
            if (!empty($alt)) {
                $simple_img .= ' alt="' . esc_attr($alt) . '"';
            }
            if (!empty($class)) {
                // 移除我们添加的 h-auto class
                $class = preg_replace('/\s*\bh-auto\b\s*/', ' ', $class);
                $class = trim(preg_replace('/\s+/', ' ', $class));
                if (!empty($class)) {
                    $simple_img .= ' class="' . esc_attr($class) . '"';
                }
            }
            $simple_img .= ' />';

            return $simple_img;
        }

        // 没有找到 img 标签，保持原样（异常情况）
        return $picture_content;
    }, $content);
}

/**
 * 执行自动优化关闭迁移
 *
 * 对于之前开启了速度优化或图片优化的用户，自动执行关闭流程
 * 这样用户升级后不会继续使用可能有问题的优化数据
 *
 * @param bool $force 是否强制执行
 * @return array 迁移结果
 */
function zeroy_migrate_disable_auto_optimization($force = false)
{
    $migration_key = 'zeroy_migration_disable_optimization_v1';

    if (!$force && get_option($migration_key)) {
        return [
            'success' => true,
            'message' => '迁移已执行过，跳过',
            'skipped' => true
        ];
    }

    $result = [
        'speed_optimization_disabled' => false,
        'image_optimization_disabled' => false,
        'css_cleared' => false,
        'html_cleared' => false,
    ];

    try {
        // 1. 迁移旧版 option 名称（zeroy_speed_dev_mode → zeroy_speed_optimization_enabled）
        $old_dev_mode = get_option('zeroy_speed_dev_mode');
        if ($old_dev_mode !== false) {
            // 旧版存在，迁移到新版（反向逻辑：dev_mode=false → enabled=true）
            $was_enabled = ($old_dev_mode === false || $old_dev_mode === '0' || $old_dev_mode === 0);
            // 无论之前是开是关，升级时都关闭速度优化
            update_option('zeroy_speed_optimization_enabled', false);
            delete_option('zeroy_speed_dev_mode');
            $result['speed_optimization_disabled'] = $was_enabled;

            if ($was_enabled) {
                // 之前是开启的，需要清理
                if (class_exists('ZeroY_CSS_Storage_Service')) {
                    ZeroY_CSS_Storage_Service::get_instance()->clear_all_css();
                    $result['css_cleared'] = true;
                }

                if (class_exists('ZeroY_CSS_Hash_Service')) {
                    ZeroY_CSS_Hash_Service::get_instance()->clear_all_hash_cache();
                }

                error_log('zeroy: 自动速度优化已关闭，CSS 缓存已清理');
            }
        }

        // 2. 检查新版速度优化是否开启（用于非迁移场景）
        $speed_enabled = get_option('zeroy_speed_optimization_enabled');
        if ($speed_enabled === true || $speed_enabled === '1' || $speed_enabled === 1) {
            // 速度优化是开启的，需要关闭并清理
            update_option('zeroy_speed_optimization_enabled', false);
            $result['speed_optimization_disabled'] = true;

            if (class_exists('ZeroY_CSS_Storage_Service')) {
                ZeroY_CSS_Storage_Service::get_instance()->clear_all_css();
                $result['css_cleared'] = true;
            }

            if (class_exists('ZeroY_CSS_Hash_Service')) {
                ZeroY_CSS_Hash_Service::get_instance()->clear_all_hash_cache();
            }

            error_log('zeroy: 自动速度优化已关闭，CSS 缓存已清理');
        }

        // 2. 检查图片优化是否开启
        $image_optimization = get_option('zeroy_image_optimization_enabled');
        if ($image_optimization === true || $image_optimization === '1' || $image_optimization === 1) {
            // 图片优化是开启的，需要关闭并清理
            update_option('zeroy_image_optimization_enabled', false);
            $result['image_optimization_disabled'] = true;

            // 清理优化 HTML 缓存
            if (class_exists('ZeroY_HTML_Optimization_Service')) {
                ZeroY_HTML_Optimization_Service::get_instance()->clear_all_optimized_html();
                $result['html_cleared'] = true;
            }

            // 清除 transient 缓存
            delete_transient('zeroy_image_optimization_enabled_cache');

            error_log('zeroy: 图片优化已关闭，优化 HTML 缓存已清理');
        }

        // 标记迁移完成
        update_option($migration_key, current_time('mysql'));

        $message = sprintf(
            '优化迁移完成：速度优化%s，图片优化%s',
            $result['speed_optimization_disabled'] ? '已关闭' : '未变更',
            $result['image_optimization_disabled'] ? '已关闭' : '未变更'
        );

        error_log('zeroy: ' . $message);

        return [
            'success' => true,
            'message' => $message,
            'result' => $result
        ];
    } catch (Exception $e) {
        error_log('zeroy: 优化迁移失败: ' . $e->getMessage());

        return [
            'success' => false,
            'message' => '迁移失败：' . $e->getMessage(),
            'result' => $result
        ];
    }
}

/**
 * 注意：迁移触发逻辑已移至 zeroy.php 的版本检测系统
 *
 * 旧版钩子已删除，统一使用版本号驱动的自动迁移
 * 参见：zeroy.php 中的 admin_init 版本检测逻辑
 */
