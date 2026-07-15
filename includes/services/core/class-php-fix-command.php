<?php
/**
 * WP-CLI 命令：修复被破坏的 PHP 代码
 *
 * @package Aether
 * @since 1.1.15
 */

defined('ABSPATH') || exit;

if (!class_exists('WP_CLI')) {
    return;
}

/**
 * 修复 aether 页面中被破坏的 PHP 代码
 */
class Aether_PHP_Fix_Command {

    /**
     * 扫描并修复所有被破坏的 PHP 代码
     *
     * ## 示例
     *
     *     wp aether fix-php
     *     wp aether fix-php --dry-run
     *     wp aether fix-php --post-id=275
     *
     * ## 选项
     *
     * [--dry-run]
     * : 只检查，不修复
     *
     * [--post-id=<post-id>]
     * : 只修复指定的文章 ID
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function __invoke($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $post_id = isset($assoc_args['post-id']) ? intval($assoc_args['post-id']) : 0;

        if (!class_exists('Aether_PHP_Protection_Manager')) {
            WP_CLI::error('PHP Protection Manager 未加载。请确保插件正常运行。');
            return;
        }

        $manager = Aether_PHP_Protection_Manager::getInstance();

        if ($post_id) {
            // 修复单个文章
            $this->fix_single_post($post_id, $manager, $dry_run);
        } else {
            // 扫描并修复所有文章
            $this->fix_all_posts($manager, $dry_run);
        }
    }

    /**
     * 修复单个文章
     */
    private function fix_single_post($post_id, $manager, $dry_run) {
        $post = get_post($post_id);

        if (!$post) {
            WP_CLI::error("文章 ID {$post_id} 不存在。");
            return;
        }

        if (!$manager->is_aether_edited($post_id)) {
            WP_CLI::warning("文章 ID {$post_id} 不是 aether 编辑的内容。");
            return;
        }

        WP_CLI::line("检查文章 ID: {$post_id} - {$post->post_title}");

        if ($manager->has_encoded_php($post->post_content)) {
            WP_CLI::warning("  发现被编码的 PHP 代码");

            if (!$dry_run) {
                $restored = $manager->decode_php_content($post->post_content);

                global $wpdb;
                $result = $wpdb->update(
                    $wpdb->posts,
                    ['post_content' => $restored],
                    ['ID' => $post_id],
                    ['%s'],
                    ['%d']
                );

                if ($result !== false) {
                    clean_post_cache($post_id);
                    WP_CLI::success("  已修复文章 ID {$post_id}");
                } else {
                    WP_CLI::error("  修复失败");
                }
            } else {
                WP_CLI::line("  [模拟模式] 将修复此文章");
            }
        } else {
            WP_CLI::success("  PHP 代码正常，无需修复");
        }
    }

    /**
     * 修复所有文章
     */
    private function fix_all_posts($manager, $dry_run) {
        global $wpdb;

        WP_CLI::line('扫描所有 aether 编辑的内容...');

        // 查找所有可能被破坏的内容
        $posts = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_content
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_aether_edited'
            AND pm.meta_value = '1'
            AND (
                p.post_content LIKE '%&lt;?php%'
                OR p.post_content LIKE '%<!--?php%'
                OR p.post_content LIKE '%=&gt;%'
            )
        ");

        if (empty($posts)) {
            WP_CLI::success('未发现需要修复的内容。');
            return;
        }

        WP_CLI::line(sprintf('找到 %d 个可能需要修复的文章', count($posts)));

        $fixed_count = 0;
        $progress = \WP_CLI\Utils\make_progress_bar('修复进度', count($posts));

        foreach ($posts as $post) {
            if ($manager->has_encoded_php($post->post_content)) {
                WP_CLI::line('');
                WP_CLI::line("文章 ID: {$post->ID} - {$post->post_title}");

                if (!$dry_run) {
                    $restored = $manager->decode_php_content($post->post_content);

                    $result = $wpdb->update(
                        $wpdb->posts,
                        ['post_content' => $restored],
                        ['ID' => $post->ID],
                        ['%s'],
                        ['%d']
                    );

                    if ($result !== false) {
                        clean_post_cache($post->ID);
                        WP_CLI::success("  已修复");
                        $fixed_count++;
                    } else {
                        WP_CLI::error("  修复失败");
                    }
                } else {
                    WP_CLI::line("  [模拟模式] 将修复此文章");
                    $fixed_count++;
                }
            }

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::line('');
        if ($dry_run) {
            WP_CLI::success("扫描完成。发现 {$fixed_count} 个需要修复的文章（模拟模式）");
            WP_CLI::line('运行 wp aether fix-php 进行实际修复');
        } else {
            WP_CLI::success("修复完成。共修复了 {$fixed_count} 个文章");
        }
    }
}

// 注册 WP-CLI 命令
WP_CLI::add_command('aether fix-php', 'Aether_PHP_Fix_Command');