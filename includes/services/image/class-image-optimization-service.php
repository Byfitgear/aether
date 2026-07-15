<?php
/**
 * Image Optimization Service (Lazy Encoding)
 *
 * 后台图片优化服务 - 支持懒编码架构
 *
 * 核心功能：
 * - 扫描媒体库，找出需要优化的图片
 * - 提供锁机制，防止多开页面重复处理
 * - 追踪优化状态（压缩、WebP）
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Image Optimization Service Class
 */
class Aether_Image_Optimization_Service {

    /**
     * Meta key for ready status (WP has finished generating all sizes)
     */
    const META_READY_FOR_OPTIMIZATION = '_aether_ready_for_optimization';

    /**
     * Meta key for compression status
     */
    const META_COMPRESSION_STATUS = '_aether_compression_status';

    /**
     * Meta key for WebP status
     */
    const META_WEBP_STATUS = '_aether_webp_status';

    /**
     * Meta key for optimization lock
     */
    const META_OPTIMIZATION_LOCK = '_aether_optimization_lock';

    /**
     * Meta key for optimization error
     */
    const META_OPTIMIZATION_ERROR = '_aether_optimization_error';

    /**
     * Lock timeout (5 minutes)
     */
    const LOCK_TIMEOUT = 300;

    /**
     * Get pending optimization images
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function get_pending_images($request) {
        $limit = $request->get_param('limit') ?? 10;

        global $wpdb;

        // 查询所有需要优化的图片
        // 条件：
        // 1. 是图片附件
        // 2. WP 已完成尺寸生成（ready = 1）或旧图片（sizes >= 3 视为 ready）
        // 3. 压缩/WebP 状态为 pending 或 NULL
        // 4. 没有被锁定（或锁已过期）
        $query = $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.guid, p.post_mime_type, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_ready ON p.ID = pm_ready.post_id
                AND pm_ready.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} pm_lock ON p.ID = pm_lock.post_id
                AND pm_lock.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} pm_comp ON p.ID = pm_comp.post_id
                AND pm_comp.meta_key = %s
            LEFT JOIN {$wpdb->postmeta} pm_webp ON p.ID = pm_webp.post_id
                AND pm_webp.meta_key = %s
            WHERE p.post_type = 'attachment'
                AND p.post_mime_type LIKE 'image/%%'
                AND (
                    pm_ready.meta_value = '1'
                    OR pm_ready.meta_value IS NULL
                )
                AND (
                    pm_comp.meta_value IS NULL
                    OR pm_comp.meta_value = 'pending'
                    OR pm_webp.meta_value IS NULL
                    OR pm_webp.meta_value = 'pending'
                )
                AND (
                    pm_lock.meta_value IS NULL
                    OR pm_lock.meta_value < %d
                )
            ORDER BY p.post_date DESC
            LIMIT %d",
            self::META_READY_FOR_OPTIMIZATION,
            self::META_OPTIMIZATION_LOCK,
            self::META_COMPRESSION_STATUS,
            self::META_WEBP_STATUS,
            time(),
            $limit
        );

        $results = $wpdb->get_results($query);

        if (empty($results)) {
            return rest_ensure_response([]);
        }

        // 构建返回数据
        $pending_images = [];

        foreach ($results as $row) {
            $attachment_id = $row->ID;
            $source_url = $row->guid;
            $mime_type = $row->post_mime_type;

            // 检测源格式
            $source_format = $this->detect_source_format($mime_type);

            // 获取所有尺寸信息
            $sizes = $this->get_attachment_sizes($attachment_id);

            if (empty($sizes)) {
                continue;
            }

            // 检查需要哪些优化
            $compression_status = get_post_meta($attachment_id, self::META_COMPRESSION_STATUS, true);
            $webp_status = get_post_meta($attachment_id, self::META_WEBP_STATUS, true);

            // WebP 格式不需要压缩（前端 jsquash 只支持 jpeg/png）
            $needs_compression = ($source_format !== 'webp') && (empty($compression_status) || $compression_status === 'pending');

            // 检查图片优化开关是否开启
            $optimization_enabled = true;
            if (class_exists('Aether_HTML_Optimization_Service')) {
                $optimization_enabled = Aether_HTML_Optimization_Service::get_instance()->is_optimization_enabled();
            }

            // 如果开关关闭，不需要 WebP（即使未生成）
            // 如果源格式已经是目标格式，跳过转换
            $needs_webp = $optimization_enabled && ($source_format !== 'webp') && (empty($webp_status) || $webp_status === 'pending');

            // 如果都不需要优化，跳过
            if (!$needs_compression && !$needs_webp) {
                continue;
            }

            $pending_images[] = [
                'id' => $attachment_id,
                'url' => $source_url,
                'title' => get_the_title($attachment_id),
                'sourceFormat' => $source_format,
                'sizes' => $sizes,
                'needsCompression' => $needs_compression,
                'needsWebP' => $needs_webp,
                'uploadedAt' => $row->post_date,
            ];
        }

        return rest_ensure_response($pending_images);
    }

    /**
     * Acquire lock for optimization
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function acquire_lock($request) {
        global $wpdb;

        $attachment_id = $request->get_param('id');
        $session_id = $request->get_param('session_id');

        if (!$attachment_id) {
            return new WP_Error('missing_id', '缺少图片 ID', ['status' => 400]);
        }

        if (empty($session_id)) {
            return new WP_Error('missing_session', '缺少 Session ID', ['status' => 400]);
        }

        $lock_expiry = time() + self::LOCK_TIMEOUT;

        // JSON 格式的锁数据
        $lock_value = wp_json_encode([
            'session' => $session_id,
            'expiry' => $lock_expiry,
            'timestamp' => time(),
        ]);

        // 检查是否已有锁记录
        $meta_id = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta}
            WHERE post_id = %d AND meta_key = %s",
            $attachment_id,
            self::META_OPTIMIZATION_LOCK
        ));

        if ($meta_id) {
            // 尝试原子更新（只有过期的锁才能被覆盖）
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta}
                SET meta_value = CASE
                    WHEN JSON_EXTRACT(meta_value, '$.expiry') < %d THEN %s
                    ELSE meta_value
                END
                WHERE meta_id = %d",
                time(),
                $lock_value,
                $meta_id
            ));

            // 读取实际的锁持有者
            $actual_lock = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                WHERE meta_id = %d",
                $meta_id
            ));

            $actual_data = json_decode($actual_lock, true);

            return rest_ensure_response([
                'success' => isset($actual_data['session']) && $actual_data['session'] === $session_id,
                'lock_holder' => $actual_data['session'] ?? 'unknown',
            ]);
        } else {
            // 没有锁，尝试插入
            $wpdb->insert(
                $wpdb->postmeta,
                [
                    'post_id' => $attachment_id,
                    'meta_key' => self::META_OPTIMIZATION_LOCK,
                    'meta_value' => $lock_value,
                ],
                ['%d', '%s', '%s']
            );

            // 可能有并发插入，读取实际的锁持有者
            $actual_lock = get_post_meta($attachment_id, self::META_OPTIMIZATION_LOCK, true);
            $actual_data = json_decode($actual_lock, true);

            return rest_ensure_response([
                'success' => isset($actual_data['session']) && $actual_data['session'] === $session_id,
            ]);
        }
    }

    /**
     * Release lock for optimization
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function release_lock($request) {
        $attachment_id = $request->get_param('id');

        if (!$attachment_id) {
            return new WP_Error('missing_id', '缺少图片 ID', ['status' => 400]);
        }

        // 删除锁
        delete_post_meta($attachment_id, self::META_OPTIMIZATION_LOCK);

        return rest_ensure_response([
            'success' => true,
        ]);
    }

    /**
     * Mark optimization as failed
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response object or error
     */
    public function mark_failed($request) {
        $attachment_id = $request->get_param('id');
        $error_message = $request->get_param('error') ?? '未知错误';

        if (!$attachment_id) {
            return new WP_Error('missing_id', '缺少图片 ID', ['status' => 400]);
        }

        // 记录错误信息
        update_post_meta($attachment_id, self::META_OPTIMIZATION_ERROR, $error_message);

        // 标记状态为 failed
        update_post_meta($attachment_id, self::META_COMPRESSION_STATUS, 'failed');
        update_post_meta($attachment_id, self::META_WEBP_STATUS, 'failed');

        // 释放锁
        delete_post_meta($attachment_id, self::META_OPTIMIZATION_LOCK);

        return rest_ensure_response([
            'success' => true,
        ]);
    }

    /**
     * Detect source format from MIME type
     *
     * @param string $mime_type MIME type
     * @return string Source format (jpeg|png|webp)
     */
    private function detect_source_format($mime_type) {
        if (strpos($mime_type, 'jpeg') !== false || strpos($mime_type, 'jpg') !== false) {
            return 'jpeg';
        }
        if (strpos($mime_type, 'png') !== false) {
            return 'png';
        }
        if (strpos($mime_type, 'webp') !== false) {
            return 'webp';
        }
        return 'jpeg';
    }

    /**
     * Get all sizes for an attachment
     *
     * @param int $attachment_id Attachment ID
     * @return array Array of sizes with width, height, file, url
     */
    private function get_attachment_sizes($attachment_id) {
        $sizes = [];

        // 获取附件元数据
        $metadata = wp_get_attachment_metadata($attachment_id);

        if (empty($metadata)) {
            return $sizes;
        }

        // 获取上传目录信息
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];
        $base_dir = $upload_dir['basedir'];

        // 获取文件路径（可能是 scaled 版本）
        $file = $metadata['file'] ?? '';
        if (empty($file)) {
            return $sizes;
        }

        $dir = dirname($file);

        // 1. 添加主图（可能是 scaled 版本）
        $sizes[] = [
            'width' => $metadata['width'] ?? 0,
            'height' => $metadata['height'] ?? 0,
            'file' => basename($file),
            'url' => $base_url . '/' . $file,
        ];

        // 2. 添加所有缩略图
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                // 停止处理历史遗留的 aether 自定义尺寸，只保留 WP 已有尺寸。
                if (strpos((string) $size_name, 'aether_') === 0) {
                    continue;
                }

                if (!empty($size_data['file'])) {
                    $sizes[] = [
                        'width' => $size_data['width'] ?? 0,
                        'height' => $size_data['height'] ?? 0,
                        'file' => $size_data['file'],
                        'url' => $base_url . '/' . $dir . '/' . $size_data['file'],
                    ];
                }
            }
        }

        // 3. 添加原图（如果存在且与主图不同）
        // WP 5.3+ 大图会生成 scaled 版本，原图单独保留
        $original_path = wp_get_original_image_path($attachment_id);
        $main_file_path = $base_dir . '/' . $file;

        if ($original_path && $original_path !== $main_file_path && file_exists($original_path)) {
            // 获取原图尺寸
            $original_size = @getimagesize($original_path);
            $original_width = $original_size ? $original_size[0] : 0;
            $original_height = $original_size ? $original_size[1] : 0;

            // 原图文件名保留原始名（不是 -scaled 版本）
            $original_filename = basename($original_path);
            $original_url = $base_url . '/' . $dir . '/' . $original_filename;

            $sizes[] = [
                'width' => $original_width,
                'height' => $original_height,
                'file' => $original_filename,
                'url' => $original_url,
                'is_original' => true, // 标记这是原图
            ];
        }

        return $sizes;
    }
}
