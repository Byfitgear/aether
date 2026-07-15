<?php
/**
 * WordPress 对象缓存辅助类
 *
 * 用于解决 WordPress 对象缓存（Redis、Memcached）导致的配置更新不及时问题
 *
 * @package zeroy
 * @subpackage Core
 * @since 1.1.11
 */

if (!defined('ABSPATH')) {
    exit;
}

class ZeroY_Cache_Helper
{
    /**
     * 在更新选项后清理相关缓存
     *
     * @param string|array $option_names 选项名称（字符串或数组）
     * @return void
     */
    public static function clear_option_cache($option_names)
    {
        // 确保输入是数组
        if (!is_array($option_names)) {
            $option_names = [$option_names];
        }

        // 清理每个选项的缓存
        foreach ($option_names as $option_name) {
            wp_cache_delete($option_name, 'options');
        }

        // 清理 alloptions 缓存（WordPress 会缓存所有选项）
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * 安全更新选项并清理缓存
     *
     * @param string $option_name 选项名称
     * @param mixed $value 选项值
     * @param bool $autoload 是否自动加载
     * @return bool 更新结果
     */
    public static function update_option_with_cache_clear($option_name, $value, $autoload = null)
    {
        $result = update_option($option_name, $value, $autoload);

        if ($result) {
            self::clear_option_cache($option_name);
        }

        return $result;
    }

    /**
     * 批量更新选项并清理缓存
     *
     * @param array $options 选项数组，格式为 ['option_name' => 'value']
     * @return array 更新结果数组，格式为 ['option_name' => bool]
     */
    public static function update_options_with_cache_clear($options)
    {
        $results = [];
        $updated_options = [];

        // 逐个更新选项
        foreach ($options as $option_name => $value) {
            $result = update_option($option_name, $value);
            $results[$option_name] = $result;

            if ($result) {
                $updated_options[] = $option_name;
            }
        }

        // 统一清理缓存
        if (!empty($updated_options)) {
            self::clear_option_cache($updated_options);
        }

        return $results;
    }

    /**
     * 清理 zeroy 插件相关的所有缓存
     *
     * @return void
     */
    public static function clear_zeroy_cache()
    {
        $zeroy_options = [
            'zeroy_settings',
            'zeroy_font_settings',
            'zeroy_font_css',
            'zeroy_font_formats',
            'zeroy_use_smart_css',
            'zeroy_smart_css_compiled_at',
            'zeroy_smart_css_stats',
        ];

        // 清理模板 CSS 缓存（动态生成的选项名）
        global $wpdb;
        $template_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'zeroy_template_css_%'"
        );

        if ($template_options) {
            $zeroy_options = array_merge($zeroy_options, $template_options);
        }

        self::clear_option_cache($zeroy_options);
    }

    /**
     * 检查是否启用了对象缓存
     *
     * @return bool
     */
    public static function is_object_cache_enabled()
    {
        return wp_using_ext_object_cache();
    }

    /**
     * 获取缓存信息（用于调试）
     *
     * @return array
     */
    public static function get_cache_info()
    {
        return [
            'using_object_cache' => wp_using_ext_object_cache(),
            'cache_type' => self::get_cache_type(),
        ];
    }

    /**
     * 检测缓存类型
     *
     * @return string
     */
    private static function get_cache_type()
    {
        if (class_exists('Redis')) {
            return 'Redis';
        }

        if (class_exists('Memcached')) {
            return 'Memcached';
        }

        if (function_exists('apcu_enabled') && apcu_enabled()) {
            return 'APCu';
        }

        return wp_using_ext_object_cache() ? 'Unknown Object Cache' : 'No Object Cache';
    }

    /**
     * 清理单个页面的缓存（精准清理）
     *
     * 注意：单页清理不使用防抖机制，每次保存都会清理
     * 只有全站清理（clear_page_cache）才有防抖
     *
     * @param int $post_id 文章 ID
     * @return bool 是否执行了清理
     */
    public static function clear_post_cache($post_id)
    {
        if (!$post_id) {
            return false;
        }

        $success = false;

        try {
            // WP Rocket - 单页清理
            if (function_exists('rocket_clean_post')) {
                try {
                    rocket_clean_post($post_id);
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] WP Rocket clear_post_cache failed: ' . $e->getMessage());
                }
            }

            // LiteSpeed Cache - 单页清理
            if (class_exists('LiteSpeed\\Purge')) {
                try {
                    do_action('litespeed_purge_post', $post_id);
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] LiteSpeed Cache clear_post_cache failed: ' . $e->getMessage());
                }
            }

            // W3 Total Cache - 单页清理
            if (function_exists('w3tc_flush_post')) {
                try {
                    w3tc_flush_post($post_id);
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] W3 Total Cache clear_post_cache failed: ' . $e->getMessage());
                }
            }

            // WP Super Cache - 单页清理
            if (function_exists('wp_cache_post_change')) {
                try {
                    wp_cache_post_change($post_id);
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] WP Super Cache clear_post_cache failed: ' . $e->getMessage());
                }
            }

            // WP Fastest Cache - 单页清理
            if (class_exists('WpFastestCache') && method_exists('WpFastestCache', 'singleDeleteCache')) {
                try {
                    $wpfc = new WpFastestCache();
                    $wpfc->singleDeleteCache(false, $post_id);
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] WP Fastest Cache clear_post_cache failed: ' . $e->getMessage());
                }
            }

            // 通用钩子
            do_action('zeroy_clear_post_cache', $post_id);
        } catch (Exception $e) {
            error_log('[zeroy] clear_post_cache unexpected error: ' . $e->getMessage());
        }

        return $success;
    }

    /**
     * 清理全站页面缓存（设置/设计系统保存用）
     *
     * @param bool $force 是否强制清理（忽略防抖动）
     * @return bool 是否执行了清理
     */
    public static function clear_page_cache($force = false)
    {
        // 防抖动：5秒内只清理一次
        $transient_key = 'zeroy_page_cache_cleared';
        if (!$force && get_transient($transient_key)) {
            return false;
        }

        $success = false;

        try {
            // WP Rocket
            if (function_exists('rocket_clean_domain')) {
                try {
                    rocket_clean_domain();
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] WP Rocket clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // LiteSpeed Cache
            if (class_exists('LiteSpeed\\Purge')) {
                try {
                    do_action('litespeed_purge_all');
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] LiteSpeed Cache clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // W3 Total Cache
            if (function_exists('w3tc_flush_all')) {
                try {
                    w3tc_flush_all();
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] W3 Total Cache clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // WP Super Cache
            if (function_exists('wp_cache_clear_cache')) {
                try {
                    wp_cache_clear_cache();
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] WP Super Cache clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // WP Fastest Cache
            if (function_exists('wpfc_clear_all_cache')) {
                try {
                    wpfc_clear_all_cache();
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] WP Fastest Cache clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // Autoptimize
            if (class_exists('autoptimizeCache')) {
                try {
                    autoptimizeCache::clearall();
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] Autoptimize clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // Hummingbird
            if (class_exists('WP_Hummingbird')) {
                try {
                    do_action('wphb_clear_page_cache');
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] Hummingbird clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // SG Optimizer (SiteGround)
            if (function_exists('sg_cachepress_purge_cache')) {
                try {
                    sg_cachepress_purge_cache();
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] SG Optimizer clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // WP Engine
            if (class_exists('WpeCommon')) {
                try {
                    if (method_exists('WpeCommon', 'purge_memcached')) {
                        WpeCommon::purge_memcached();
                    }
                    if (method_exists('WpeCommon', 'clear_maxcdn_cache')) {
                        WpeCommon::clear_maxcdn_cache();
                    }
                    if (method_exists('WpeCommon', 'purge_varnish_cache')) {
                        WpeCommon::purge_varnish_cache();
                    }
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] WP Engine clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // Kinsta
            global $kinsta_cache;
            if (class_exists('Kinsta\\Cache') && isset($kinsta_cache)) {
                try {
                    if (property_exists($kinsta_cache, 'kinsta_cache_purge') &&
                        method_exists($kinsta_cache->kinsta_cache_purge, 'purge_complete_caches')) {
                        $kinsta_cache->kinsta_cache_purge->purge_complete_caches();
                        $success = true;
                    }
                } catch (Exception $e) {
                    error_log('[zeroy] Kinsta clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // Flywheel
            if (class_exists('FlywheelNginxCompat')) {
                try {
                    // Flywheel 使用 Varnish，通过 HTTP PURGE 方法清理
                    if (function_exists('flywheel_purge_all')) {
                        flywheel_purge_all();
                        $success = true;
                    }
                } catch (Exception $e) {
                    error_log('[zeroy] Flywheel clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // Pagely
            if (class_exists('PagelyCachePurge')) {
                try {
                    $pagely = new PagelyCachePurge();
                    if (method_exists($pagely, 'purgeAll')) {
                        $pagely->purgeAll();
                        $success = true;
                    }
                } catch (Exception $e) {
                    error_log('[zeroy] Pagely clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // Pressable
            if (class_exists('Pressable_Automated_Cache_Purge')) {
                try {
                    do_action('pressable_purge_all_caches');
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] Pressable clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // NitroPack
            if (function_exists('nitropack_sdk_purge')) {
                try {
                    nitropack_sdk_purge();
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] NitroPack clear_page_cache failed: ' . $e->getMessage());
                }
            } elseif (has_action('nitropack_integration_purge_all')) {
                try {
                    do_action('nitropack_integration_purge_all');
                    $success = true;
                } catch (Exception $e) {
                    error_log('[zeroy] NitroPack clear_page_cache failed: ' . $e->getMessage());
                }
            }

            // 通用钩子
            do_action('zeroy_clear_page_cache');

            // 记录日志
            if ($success) {
                $plugins = self::get_active_cache_plugins();
                error_log(sprintf(
                    '[zeroy] Page cache cleared via: %s',
                    implode(', ', $plugins) ?: 'None (no cache plugin detected)'
                ));
            } else {
                error_log('[zeroy] Warning: No cache plugins detected, page cache not cleared');
            }
        } finally {
            // 只有成功执行后才设置防抖标记
            if ($success) {
                set_transient($transient_key, true, 5);
            }
        }

        return $success;
    }

    /**
     * 清理 OPcache（PHP 字节码缓存）
     *
     * ⚠️ 注意：清理 OPcache 会导致所有 PHP 代码重新编译，影响性能
     * 只在以下场景使用：
     * 1. 插件/主题更新后
     * 2. 修改了 PHP 代码
     * 3. 调试需要
     *
     * @return bool 是否成功清理
     */
    public static function clear_opcache()
    {
        $success = false;

        if (function_exists('opcache_reset')) {
            // 完全重置 OPcache
            $success = opcache_reset();
            if ($success) {
                error_log('[zeroy] OPcache cleared successfully');
            } else {
                error_log('[zeroy] Warning: Failed to clear OPcache');
            }
        }

        return $success;
    }

    /**
     * 清理所有缓存（对象缓存 + 页面缓存）
     *
     * ⚠️ 不包括 OPcache，因为：
     * - 保存设置不会修改 PHP 代码
     * - 清理 OPcache 影响性能
     * - 如需清理 OPcache，请手动调用 clear_opcache()
     *
     * @param bool $force 是否强制清理页面缓存
     * @return void
     */
    public static function clear_all_caches($force = false)
    {
        self::clear_zeroy_cache();      // 对象缓存
        self::clear_page_cache($force); // 页面缓存
        // 不清理 OPcache - 需要时手动调用 clear_opcache()
    }

    /**
     * 获取检测到的活跃缓存插件
     *
     * @return array
     */
    public static function get_active_cache_plugins()
    {
        $plugins = [];

        // 缓存插件
        if (function_exists('rocket_clean_domain')) {
            $plugins[] = 'WP Rocket';
        }
        if (class_exists('LiteSpeed\\Purge')) {
            $plugins[] = 'LiteSpeed Cache';
        }
        if (function_exists('w3tc_flush_all')) {
            $plugins[] = 'W3 Total Cache';
        }
        if (function_exists('wp_cache_clear_cache')) {
            $plugins[] = 'WP Super Cache';
        }
        if (function_exists('wpfc_clear_all_cache')) {
            $plugins[] = 'WP Fastest Cache';
        }
        if (class_exists('autoptimizeCache')) {
            $plugins[] = 'Autoptimize';
        }
        if (class_exists('WP_Hummingbird')) {
            $plugins[] = 'Hummingbird';
        }
        if (function_exists('sg_cachepress_purge_cache')) {
            $plugins[] = 'SG Optimizer';
        }

        // 高端托管商内置缓存
        if (class_exists('WpeCommon')) {
            $plugins[] = 'WP Engine';
        }
        global $kinsta_cache;
        if (class_exists('Kinsta\\Cache') && isset($kinsta_cache)) {
            $plugins[] = 'Kinsta';
        }
        if (class_exists('FlywheelNginxCompat')) {
            $plugins[] = 'Flywheel';
        }
        if (class_exists('PagelyCachePurge')) {
            $plugins[] = 'Pagely';
        }
        if (class_exists('Pressable_Automated_Cache_Purge')) {
            $plugins[] = 'Pressable';
        }
        if (function_exists('nitropack_sdk_purge') || has_action('nitropack_integration_purge_all')) {
            $plugins[] = 'NitroPack';
        }

        return $plugins;
    }
}