<?php
/**
 * WP-CLI 缓存管理命令
 *
 * 提供命令行接口用于管理 aether 缓存
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class Aether_Cache_CLI_Command {
    /**
     * 清理缓存
     *
     * ## 选项
     *
     * [--all]
     * : 清理全站页面缓存和对象缓存
     *
     * [--post-id=<id>]
     * : 清理指定文章的缓存
     *
     * [--force]
     * : 强制清理（忽略防抖动）
     *
     * [--opcache]
     * : 同时清理 OPcache（PHP 字节码缓存）
     *
     * ## 示例
     *
     *   wp aether clear-cache --all
     *   wp aether clear-cache --post-id=123
     *   wp aether clear-cache --all --force
     *   wp aether clear-cache --all --opcache
     *
     * @param array $args 位置参数
     * @param array $assoc_args 关联参数
     */
    public function clear_cache($args, $assoc_args) {
        if (isset($assoc_args['all'])) {
            $force = isset($assoc_args['force']);

            WP_CLI::log('清理对象缓存...');
            Aether_Cache_Helper::clear_aether_cache();

            WP_CLI::log('清理页面缓存...');
            $result = Aether_Cache_Helper::clear_page_cache($force);

            // 清理 OPcache（如果指定）
            if (isset($assoc_args['opcache'])) {
                WP_CLI::log('清理 OPcache...');
                $opcache_result = Aether_Cache_Helper::clear_opcache();
                if ($opcache_result) {
                    WP_CLI::success('OPcache 已清理');
                } else {
                    WP_CLI::warning('OPcache 未启用或清理失败');
                }
            }

            if ($result) {
                $plugins = Aether_Cache_Helper::get_active_cache_plugins();
                WP_CLI::success(
                    sprintf(
                        '缓存已清理。检测到的缓存插件: %s',
                        implode(', ', $plugins) ?: '无'
                    )
                );
            } else {
                WP_CLI::warning('对象缓存已清理，但未检测到页面缓存插件');
            }
        } elseif (isset($assoc_args['post-id'])) {
            $post_id = (int) $assoc_args['post-id'];

            // 验证文章是否存在
            $post = get_post($post_id);
            if (!$post) {
                WP_CLI::error("文章 ID $post_id 不存在");
                return;
            }

            WP_CLI::log("清理文章 $post_id 的缓存...");
            clean_post_cache($post_id);
            $result = Aether_Cache_Helper::clear_post_cache($post_id);

            if ($result) {
                WP_CLI::success("文章 $post_id ({$post->post_title}) 的缓存已清理");
            } else {
                WP_CLI::warning("文章 $post_id ({$post->post_title}) 的对象缓存已清理，但未检测到页面缓存插件");
            }
        } else {
            WP_CLI::error('请指定 --all 或 --post-id=<id>');
        }
    }

    /**
     * 显示缓存状态
     *
     * ## 示例
     *
     *   wp aether cache-status
     */
    public function cache_status() {
        $plugins = Aether_Cache_Helper::get_active_cache_plugins();
        $object_cache = Aether_Cache_Helper::is_object_cache_enabled();

        WP_CLI::log('');
        WP_CLI::log('=== aether 缓存状态 ===');
        WP_CLI::log('');

        // 对象缓存
        WP_CLI::log('对象缓存: ' . ($object_cache ? '已启用' : '已禁用'));
        if ($object_cache) {
            $cache_info = Aether_Cache_Helper::get_cache_info();
            WP_CLI::log('  类型: ' . $cache_info['cache_type']);
        }

        WP_CLI::log('');

        // OPcache
        if (function_exists('opcache_get_status')) {
            $opcache_status = opcache_get_status(false);
            if ($opcache_status && $opcache_status['opcache_enabled']) {
                WP_CLI::log('OPcache: 已启用');
                WP_CLI::log('  内存使用: ' . round($opcache_status['memory_usage']['used_memory'] / 1024 / 1024, 2) . ' MB');
                WP_CLI::log('  命中率: ' . round($opcache_status['opcache_statistics']['opcache_hit_rate'], 2) . '%');
            } else {
                WP_CLI::log('OPcache: 已禁用');
            }
        } else {
            WP_CLI::log('OPcache: 未安装');
        }

        WP_CLI::log('');

        // 页面缓存
        if (empty($plugins)) {
            WP_CLI::warning('未检测到页面缓存插件');
        } else {
            WP_CLI::log('页面缓存插件:');
            foreach ($plugins as $plugin) {
                WP_CLI::log('  • ' . $plugin);
            }
        }

        WP_CLI::log('');
    }
}

// 注册 WP-CLI 命令
WP_CLI::add_command('aether clear-cache', ['Aether_Cache_CLI_Command', 'clear_cache']);
WP_CLI::add_command('aether cache-status', ['Aether_Cache_CLI_Command', 'cache_status']);
