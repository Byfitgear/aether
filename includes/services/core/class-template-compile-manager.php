<?php
/**
 * 模板编译管理器
 *
 * 处理全局模板变更时的整站重编译逻辑
 * 支持智能判断：header/footer → 整站重编译，动态模板 → 单模板编译
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Template_Compile_Manager
{

    // 定义常量（避免魔法数字）
    const RECOMPILE_THROTTLE_SECONDS = 300;    // 5 分钟防抖
    const BATCH_SIZE = 50;                     // 每批处理 50 页
    const BATCH_SLEEP_SECONDS = 1;             // 每批休息 1 秒
    const PROGRESS_UPDATE_INTERVAL = 10;       // 每 10 页更新进度
    const ZOMBIE_TASK_TIMEOUT = 3600;          // 1 小时僵尸任务超时
    const COMPILE_TIMEOUT = 10;                // 单页编译超时 10 秒

    /**
     * 初始化钩子
     */
    public static function init()
    {
        add_action('aether_template_saved', [__CLASS__, 'on_template_saved'], 10, 1);
        add_action('aether_recompile_all_pages', [__CLASS__, 'batch_recompile_all_pages'], 10, 1);
    }

    /** @var array|null 最近一次编译结果（供 API 读取） */
    private static $last_compile_result = null;

    /**
     * 模板保存时的处理（智能按需编译）
     *
     * 编译策略：
     * - header/footer → 整站编译（所有页面都用）
     * - single → 只编译 post 类型文章
     * - single_page → 只编译 page 类型页面
     * - single_{cpt} → 只编译该 CPT 类型
     * - archive/category/tag/author/search/404/home → 不触发 post 编译（动态页面用 CDN）
     *
     * @param array $data 模板数据
     */
    public static function on_template_saved($data)
    {
        $type = $data['type'] ?? '';

        // 清除哈希缓存（header/footer 变更时）- 无论是否 dev_mode 都要清除
        if (class_exists('Aether_CSS_Hash_Service')) {
            $hash_service = Aether_CSS_Hash_Service::get_instance();
            if (in_array($type, ['header', 'footer'], true)) {
                $hash_service->clear_header_footer_hash_cache();
            }
        }

        // 检查是否开启了自动速度优化
        $speed_enabled = (bool) get_option('aether_speed_optimization_enabled', false);
        if (!$speed_enabled) {
            self::$last_compile_result = [
                'type' => 'template',
                'template_type' => $type,
                'success' => true,
                'skipped' => true,
                'reason' => 'speed_optimization_disabled'
            ];
            return;
        }

        // 根据模板类型决定编译范围
        $compile_scope = self::get_compile_scope($type);

        if ($compile_scope === 'all') {
            // header/footer：不在后端编译，让前端自动触发整站编译
            // 这样可以复用旧版极致压缩的逻辑（前端编译 → 后端编译 → CDN 兜底）
            $result = [
                'type' => 'global_template',
                'template_type' => $type,
                'success' => true,
                'message' => 'Global template saved, frontend will trigger site-wide compilation'
            ];
        } elseif ($compile_scope !== 'none') {
            // single/single_page/single_{cpt}：编译模板 CSS + 对应类型的文章
            $template_id = $data['id'] ?? 0;
            $content = $data['content'] ?? '';

            // 1. 先编译模板自身的 CSS（用于没有被 Aether 编辑过的文章）
            self::compile_template_css($type, $template_id, $content);

            // 2. 再编译对应类型中被 Aether 编辑过的文章
            $result = self::sync_compile_by_post_type($compile_scope, [
                'trigger' => $type . '_template_updated',
                'user_id' => get_current_user_id()
            ]);
        } else {
            // archive/category/tag 等动态页面：只编译模板自身的 CSS
            $template_id = $data['id'] ?? 0;
            $content = $data['content'] ?? '';
            $result = self::compile_template_css($type, $template_id, $content);
        }

        self::$last_compile_result = $result;

        error_log(sprintf(
            'aether: Template %s updated, scope=%s, compiled %d/%d pages',
            $type,
            $compile_scope,
            $result['completed'] ?? 0,
            $result['total'] ?? 0
        ));
    }

    /**
     * 根据模板类型确定编译范围
     *
     * 模板类型命名规范：
     * - header/footer：布局部件
     * - single_post, single_page, single_{cpt}：单页模板
     * - archive_post, archive_{cpt}：归档模板
     * - taxonomy_category, taxonomy_post_tag, taxonomy_{custom}：分类法模板
     * - 404, search, front_page, home, author：核心模板
     *
     * @param string $type 模板类型
     * @return string 'all' | '{post_type}' | 'none'
     */
    private static function get_compile_scope($type)
    {
        // header/footer 影响所有页面
        if (in_array($type, ['header', 'footer'], true)) {
            return 'all';
        }

        // single_{post_type}：只影响对应的文章类型
        // 例如 single_post → 'post', single_page → 'page', single_movie → 'movie'
        if (strpos($type, 'single_') === 0) {
            $post_type = substr($type, 7); // 去掉 'single_' 前缀
            if (post_type_exists($post_type)) {
                return $post_type;
            }
        }

        // archive/taxonomy/core 等动态页面
        // 这些页面没有固定的 post_id，内容是动态的，只编译模板自身的 CSS
        return 'none';
    }

    /**
     * 按文章类型编译（用于 single 模板变更）
     *
     * @param string $post_type 文章类型
     * @param array $args 参数
     * @return array 编译结果
     */
    public static function sync_compile_by_post_type($post_type, $args = [])
    {
        global $wpdb;

        $start_time = microtime(true);

        // 获取该类型中 aether 编辑过的文章
        $pages = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key = '_aether_edited'
            AND pm.meta_value = '1'
        ", $post_type));

        $total = count($pages);
        $completed = 0;
        $failed = 0;

        if ($total === 0) {
            return [
                'type' => 'post_type',
                'post_type' => $post_type,
                'total' => 0,
                'completed' => 0,
                'failed' => 0,
                'duration' => 0,
                'success' => true
            ];
        }

        // 编译服务
        $compile_service = Aether_CSS_Compile_Strategy_Service::get_instance();
        $storage = Aether_CSS_Storage_Service::get_instance();

        // 获取当前的哈希值
        $hash_service = Aether_CSS_Hash_Service::get_instance();
        $header_footer_hash = $hash_service->get_header_footer_hash();
        $design_system_hash = $hash_service->get_design_system_hash();

        foreach ($pages as $post_id) {
            try {
                $post = get_post($post_id);
                if (!$post) {
                    $failed++;
                    continue;
                }

                $content = $post->post_content;
                $result = $compile_service->compile_single_page($post_id, $content, [
                    'timeout' => self::COMPILE_TIMEOUT
                ]);

                if (!is_wp_error($result) && !empty($result['css'])) {
                    $storage->save_page_css($post_id, $result['css']);
                    update_post_meta($post_id, '_aether_has_compiled_css', '1');
                    update_post_meta($post_id, '_aether_css_compiled_at', current_time('timestamp'));
                    update_post_meta($post_id, '_aether_css_compile_source', 'backend');
                    update_post_meta($post_id, '_aether_css_header_footer_hash', $header_footer_hash);
                    update_post_meta($post_id, '_aether_css_design_system_hash', $design_system_hash);
                    $completed++;
                } else {
                    $failed++;
                }
            } catch (Exception $e) {
                $failed++;
                error_log('aether: Compile failed for post ' . $post_id . ': ' . $e->getMessage());
            }
        }

        $duration = round(microtime(true) - $start_time, 2);

        return [
            'type' => 'post_type',
            'post_type' => $post_type,
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'duration' => $duration,
            'success' => $failed === 0
        ];
    }

    /**
     * 获取最近一次编译结果
     *
     * @return array|null
     */
    public static function get_last_compile_result()
    {
        return self::$last_compile_result;
    }

    /**
     * 编译模板自身的 CSS（用于 archive/category 等动态页面模板）
     *
     * @param string $type 模板类型
     * @param int $template_id 模板 post ID
     * @param string $content 模板内容
     * @return array 编译结果
     */
    public static function compile_template_css($type, $template_id, $content)
    {
        $start_time = microtime(true);
        $debug_enabled = defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;

        if ($debug_enabled) {
            error_log(sprintf('[Aether Compile] Starting template CSS compile for type=%s, template_id=%d, content_length=%d', $type, $template_id, strlen($content)));
        }

        if (empty($content)) {
            if ($debug_enabled) {
                error_log(sprintf('[Aether Compile] Skipped: empty content for type=%s', $type));
            }
            return [
                'type' => 'template',
                'template_type' => $type,
                'total' => 0,
                'completed' => 0,
                'failed' => 0,
                'duration' => 0,
                'success' => true,
                'scope' => 'template_only',
                'reason' => 'empty_content'
            ];
        }

        try {
            // 编译服务
            $compile_service = Aether_CSS_Compile_Strategy_Service::get_instance();
            $storage = Aether_CSS_Storage_Service::get_instance();

            // 编译模板 CSS（包含 header + 模板内容 + footer）
            $result = $compile_service->compile_single_page($template_id, $content, [
                'timeout' => self::COMPILE_TIMEOUT,
                'is_template' => true
            ]);

            if (!is_wp_error($result) && !empty($result['css'])) {
                // 保存模板 CSS
                $storage->save_template_css($type, $result['css'], [
                    'optimized_size' => strlen($result['css'])
                ]);

                // 保存 header/footer 哈希（模板也包含 header/footer）
                if (class_exists('Aether_CSS_Hash_Service')) {
                    $hash_service = Aether_CSS_Hash_Service::get_instance();
                    update_option('aether_template_css_' . $type . '_header_footer_hash', $hash_service->get_header_footer_hash(), false);
                }

                $duration = round(microtime(true) - $start_time, 2);

                if ($debug_enabled) {
                    error_log(sprintf('[Aether Compile] Success: template CSS compiled for type=%s, css_size=%d bytes, duration=%ss', $type, strlen($result['css']), $duration));
                }

                return [
                    'type' => 'template',
                    'template_type' => $type,
                    'total' => 1,
                    'completed' => 1,
                    'failed' => 0,
                    'duration' => $duration,
                    'success' => true,
                    'scope' => 'template_only',
                    'css_size' => strlen($result['css'])
                ];
            } else {
                $error_message = is_wp_error($result) ? $result->get_error_message() : 'Empty CSS result';
                error_log('aether: Template CSS compile failed for ' . $type . ': ' . $error_message);

                if ($debug_enabled) {
                    error_log(sprintf('[Aether Compile] Failed: type=%s, error=%s', $type, $error_message));
                }

                return [
                    'type' => 'template',
                    'template_type' => $type,
                    'total' => 1,
                    'completed' => 0,
                    'failed' => 1,
                    'duration' => round(microtime(true) - $start_time, 2),
                    'success' => false,
                    'scope' => 'template_only',
                    'error' => $error_message
                ];
            }
        } catch (Exception $e) {
            error_log('aether: Template CSS compile exception for ' . $type . ': ' . $e->getMessage());

            return [
                'type' => 'template',
                'template_type' => $type,
                'total' => 1,
                'completed' => 0,
                'failed' => 1,
                'duration' => round(microtime(true) - $start_time, 2),
                'success' => false,
                'scope' => 'template_only',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 重新编译所有模板 CSS（header/footer 变更时调用）
     *
     * @return array 编译结果
     */
    public static function recompile_all_template_css()
    {
        global $wpdb;

        // 获取所有 aether_template 类型的模板（排除 header/footer）
        $templates = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as template_type
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'aether_template'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_aether_template_type'
            AND pm.meta_value NOT IN ('header', 'footer')
        ");

        $total = count($templates);
        $completed = 0;
        $failed = 0;

        foreach ($templates as $template) {
            $type = $template->template_type;
            $content = get_post_meta($template->ID, Aether_Templates::CONTENT_META_KEY, true);

            if (empty($content)) {
                continue;
            }

            $result = self::compile_template_css($type, $template->ID, $content);
            if ($result['success']) {
                $completed++;
            } else {
                $failed++;
            }
        }

        error_log(sprintf(
            'aether: Recompiled %d/%d template CSS (failed: %d)',
            $completed,
            $total,
            $failed
        ));

        return [
            'type' => 'template_css_all',
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed
        ];
    }

    /**
     * 同步编译所有页面（不使用 cron）
     *
     * @param array $args 参数
     * @return array 编译结果
     */
    public static function sync_compile_all_pages($args = [])
    {
        global $wpdb;

        $start_time = microtime(true);

        // 获取所有 aether 编辑过的页面
        $pages = $wpdb->get_col("
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_aether_edited'
            AND meta_value = '1'
        ");

        $total = count($pages);
        $completed = 0;
        $failed = 0;

        if ($total === 0) {
            return [
                'type' => 'site_wide',
                'total' => 0,
                'completed' => 0,
                'failed' => 0,
                'duration' => 0,
                'success' => true
            ];
        }

        // 编译服务
        $compile_service = Aether_CSS_Compile_Strategy_Service::get_instance();
        $storage = Aether_CSS_Storage_Service::get_instance();

        // 获取当前的哈希值
        $hash_service = Aether_CSS_Hash_Service::get_instance();
        $header_footer_hash = $hash_service->get_header_footer_hash();
        $design_system_hash = $hash_service->get_design_system_hash();

        foreach ($pages as $post_id) {
            try {
                $post = get_post($post_id);
                if (!$post) {
                    $failed++;
                    continue;
                }

                $content = $post->post_type === 'aether_template'
                    ? get_post_meta($post_id, Aether_Templates::CONTENT_META_KEY, true)
                    : $post->post_content;

                if (empty($content)) {
                    $failed++;
                    continue;
                }

                // 编译
                $result = $compile_service->compile_single_page($post_id, $content, [
                    'timeout' => self::COMPILE_TIMEOUT
                ]);

                if (!is_wp_error($result) && !empty($result['css'])) {
                    // 保存编译结果
                    $storage->save_page_css($post_id, $result['css'], $result['stats'] ?? []);

                    update_post_meta($post_id, '_aether_has_compiled_css', '1');
                    update_post_meta($post_id, '_aether_css_compiled_at', current_time('timestamp'));
                    update_post_meta($post_id, '_aether_css_header_footer_hash', $header_footer_hash);
                    update_post_meta($post_id, '_aether_css_design_system_hash', $design_system_hash);

                    $completed++;
                } else {
                    $failed++;
                }
            } catch (Exception $e) {
                error_log(sprintf(
                    'aether: Sync compile failed for post %d: %s',
                    $post_id,
                    $e->getMessage()
                ));
                $failed++;
            }
        }

        $duration = round(microtime(true) - $start_time, 2);

        return [
            'type' => 'site_wide',
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'duration' => $duration,
            'success' => $failed === 0
        ];
    }

    /**
     * 重新编译动态模板的 CSS
     *
     * @param string $type 模板类型
     * @param string $content 模板内容
     * @return bool
     */
    private static function recompile_template_css($type, $content)
    {
        try {
            // 编译服务
            $compile_service = Aether_CSS_Compile_Strategy_Service::get_instance();

            // 动态模板需要包含 header/footer（因为前台会完整渲染）
            $result = $compile_service->compile_single_page(0, $content, [
                'excludeHeaderFooter' => false,  // 包含 header/footer
                'timeout' => self::COMPILE_TIMEOUT
            ]);

            if (!is_wp_error($result) && !empty($result['css'])) {
                // 保存模板 CSS
                $storage = Aether_CSS_Storage_Service::get_instance();
                $storage->save_template_css($type, $result['css'], $result['stats'] ?? []);

                return true;
            }
        } catch (Exception $e) {
            error_log("aether: Template {$type} compile failed: " . $e->getMessage());
        }

        return false;
    }

    /**
     * 异步整站重编译（分批处理，防止 OOM）
     *
     * @param array $args 任务参数
     */
    public static function batch_recompile_all_pages($args)
    {
        global $wpdb;

        // 强制刷新对象缓存，确保读取到最新的字体 CSS 和设计系统配置
        // 这对于异步 Cron 任务至关重要，因为它在独立的 PHP 进程中运行
        wp_cache_delete('aether_font_css', 'options');
        wp_cache_delete('aether_font_formats', 'options');
        wp_cache_delete('aether_font_settings', 'options');
        wp_cache_delete('aether_design_system_html', 'options');
        wp_cache_delete('alloptions', 'options');

        // 获取当前批次的偏移量
        $offset = (int) get_option('aether_recompile_offset', 0);

        // 分批获取页面 ID（防止一次性加载所有 ID 导致 OOM）
        $pages = $wpdb->get_col($wpdb->prepare(
            "
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_aether_edited'
            AND meta_value = '1'
            LIMIT %d OFFSET %d
        ",
            self::BATCH_SIZE,
            $offset
        ));

        // 如果是第一批，初始化进度
        if ($offset === 0) {
            $total = $wpdb->get_var("
                SELECT COUNT(DISTINCT post_id)
                FROM {$wpdb->postmeta}
                WHERE meta_key = '_aether_edited'
                AND meta_value = '1'
            ");

            update_option('aether_recompile_progress', [
                'total' => (int) $total,
                'completed' => 0,
                'failed' => 0,
                'started_at' => current_time('mysql'),
                'status' => 'running',
                'trigger' => $args['trigger'] ?? 'unknown',
                'user_id' => $args['user_id'] ?? 0
            ]);
        }

        // 获取当前进度
        $progress = get_option('aether_recompile_progress');
        $completed = $progress['completed'] ?? 0;
        $failed = $progress['failed'] ?? 0;

        // 批量编译
        $compile_service = Aether_CSS_Compile_Strategy_Service::get_instance();
        $storage = Aether_CSS_Storage_Service::get_instance();

        // 获取当前的哈希值（用于标记所有重编译页面）
        $hash_service = Aether_CSS_Hash_Service::get_instance();
        $header_footer_hash = $hash_service->get_header_footer_hash();
        $design_system_hash = $hash_service->get_design_system_hash();

        foreach ($pages as $post_id) {
            try {
                // 获取内容
                $post = get_post($post_id);
                if (!$post) {
                    $failed++;
                    continue;
                }

                $content = $post->post_type === 'aether_template'
                    ? get_post_meta($post_id, Aether_Templates::CONTENT_META_KEY, true)
                    : $post->post_content;

                if (empty($content)) {
                    $failed++;
                    continue;
                }

                // 编译
                $result = $compile_service->compile_single_page($post_id, $content, [
                    'timeout' => self::COMPILE_TIMEOUT
                ]);

                if (!is_wp_error($result) && !empty($result['css'])) {
                    // 保存编译结果
                    $storage->save_page_css($post_id, $result['css'], $result['stats'] ?? []);

                    update_post_meta($post_id, '_aether_has_compiled_css', '1');
                    update_post_meta($post_id, '_aether_css_compiled_at', current_time('timestamp'));
                    update_post_meta($post_id, '_aether_css_header_footer_hash', $header_footer_hash);
                    update_post_meta($post_id, '_aether_css_design_system_hash', $design_system_hash);

                    $completed++;
                } else {
                    $failed++;
                }
            } catch (Exception $e) {
                error_log(sprintf(
                    'aether: Recompile failed for post %d (user %d): %s',
                    $post_id,
                    $args['user_id'] ?? 0,
                    $e->getMessage()
                ));
                $failed++;
            }

            // 每 N 页更新一次进度
            if (($completed + $failed) % self::PROGRESS_UPDATE_INTERVAL === 0) {
                update_option('aether_recompile_progress', array_merge($progress, [
                    'completed' => $completed,
                    'failed' => $failed,
                ]));
            }
        }

        // 更新偏移量
        $new_offset = $offset + self::BATCH_SIZE;
        update_option('aether_recompile_offset', $new_offset);

        // 更新最终进度
        update_option('aether_recompile_progress', array_merge($progress, [
            'completed' => $completed,
            'failed' => $failed,
        ]));

        // 如果还有剩余页面，调度下一批
        if (count($pages) === self::BATCH_SIZE) {
            wp_schedule_single_event(time() + self::BATCH_SLEEP_SECONDS, 'aether_recompile_all_pages', [$args]);
        } else {
            // 所有批次完成
            update_option('aether_recompile_progress', array_merge($progress, [
                'completed' => $completed,
                'failed' => $failed,
                'completed_at' => current_time('mysql'),
                'status' => 'completed'
            ]));

            // 清理偏移量
            delete_option('aether_recompile_offset');

            // 释放防抖锁
            delete_transient('aether_recompile_schedule_lock');

            error_log(sprintf(
                'aether: Site-wide recompile completed. Total: %d, Success: %d, Failed: %d, Duration: %s',
                $progress['total'] ?? 0,
                $completed,
                $failed,
                human_time_diff(strtotime($progress['started_at'] ?? 'now'))
            ));

            // 检查防抖期内是否有待处理的重编译请求
            if (get_option('aether_recompile_pending')) {
                delete_option('aether_recompile_pending');

                // 清除旧的哈希缓存，确保使用最新的内容哈希
                $hash_service = Aether_CSS_Hash_Service::get_instance();
                $hash_service->clear_all_hash_cache();

                error_log('aether: Found pending recompile request, scheduling new task');

                // 调度新的重编译任务（延迟 5 秒，避免立即执行）
                wp_schedule_single_event(time() + 5, 'aether_recompile_all_pages', [
                    [
                        'trigger' => 'pending_after_completion',
                        'user_id' => $args['user_id'] ?? 0
                    ]
                ]);
            }
        }
    }

    /**
     * 获取重编译进度
     *
     * @return array|null
     */
    public static function get_recompile_progress()
    {
        return get_option('aether_recompile_progress');
    }

    /**
     * 检查是否正在重编译
     *
     * @return bool
     */
    public static function is_recompiling()
    {
        $progress = self::get_recompile_progress();
        return $progress && isset($progress['status']) && $progress['status'] === 'running';
    }

    /**
     * 取消正在进行的重编译
     *
     * @return bool
     */
    public static function cancel_recompile()
    {
        // 取消调度的事件
        $timestamp = wp_next_scheduled('aether_recompile_all_pages');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'aether_recompile_all_pages');
        }

        // 清理状态
        delete_option('aether_recompile_progress');
        delete_option('aether_recompile_offset');
        delete_option('aether_recompile_pending');
        delete_transient('aether_recompile_schedule_lock');

        error_log('aether: Recompile cancelled by user');

        return true;
    }

    /**
     * 调度整站重编译（供外部调用）
     *
     * @param array $args 任务参数
     * @return bool
     */
    public static function schedule_recompile_all($args = [])
    {
        // 检测僵尸任务
        $progress = get_option('aether_recompile_progress');
        if ($progress && isset($progress['status']) && $progress['status'] === 'running') {
            $started_at = strtotime($progress['started_at']);
            if (time() - $started_at > self::ZOMBIE_TASK_TIMEOUT) {
                // 僵尸任务，重置状态
                delete_option('aether_recompile_progress');
                delete_transient('aether_recompile_schedule_lock');
            } else {
                // 正常任务在运行，标记待处理
                update_option('aether_recompile_pending', true);
                error_log('aether: Recompile already running, marked as pending');
                return false;
            }
        }

        // 取消之前的事件
        $timestamp = wp_next_scheduled('aether_recompile_all_pages');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'aether_recompile_all_pages');
        }

        // 调度新任务
        wp_schedule_single_event(time(), 'aether_recompile_all_pages', [$args]);

        error_log(sprintf(
            'aether: Scheduled site-wide recompile (trigger: %s, user: %d)',
            $args['trigger'] ?? 'unknown',
            $args['user_id'] ?? 0
        ));

        return true;
    }
}
