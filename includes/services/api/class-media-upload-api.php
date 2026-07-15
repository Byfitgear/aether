<?php
/**
 * 媒体优化上传 API 服务
 *
 * 处理前端批量生成的多格式多尺寸图片上传
 *
 * @package aether
 * @subpackage Services
 */

defined('ABSPATH') || exit;

/**
 * 媒体优化上传 API 类
 */
class Aether_Media_Upload_API {

    /**
     * 注册 AJAX hooks
     */
    public function __construct() {
        // 注册 admin-ajax.php 处理器（支持文件上传）
        add_action('wp_ajax_aether_save_optimized', [$this, 'ajax_save_optimized']);
    }

    /**
     * 处理优化图片上传
     *
     * @param WP_REST_Request $request REST 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function upload_optimized($request) {
        // Debug 日志
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Aether Upload] === 开始处理图片上传 ===');
            error_log('[Aether Upload] Request method: ' . $request->get_method());
            error_log('[Aether Upload] Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
            error_log('[Aether Upload] Query params: ' . print_r($request->get_query_params(), true));
            error_log('[Aether Upload] Body params: ' . print_r($request->get_body_params(), true));
            error_log('[Aether Upload] File params: ' . print_r(array_keys($request->get_file_params()), true));
        }

        // 1. 验证原图文件
        $files = $request->get_file_params();

        if (!isset($files['original'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Aether Upload] 错误：缺少原图文件');
                error_log('[Aether Upload] 可用的文件键：' . print_r(array_keys($files), true));
            }
            return new WP_REST_Response([
                'success' => false,
                'error' => '缺少原图文件',
            ], 400);
        }

        $original_file = $files['original'];

        // 从 URL query 参数读取 source_format（不是从 body）
        $source_format = isset($_GET['source_format']) ? sanitize_text_field($_GET['source_format']) : '';

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Aether Upload] 原图文件名: ' . $original_file['name']);
            error_log('[Aether Upload] 原图类型: ' . $original_file['type']);
            error_log('[Aether Upload] 原图大小: ' . $original_file['size']);
            error_log('[Aether Upload] 源格式: ' . $source_format);
        }

        // 验证 source_format 参数
        if (!in_array($source_format, ['jpeg', 'png', 'webp'], true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Aether Upload] 错误：无效的源格式 - ' . $source_format);
            }
            return new WP_REST_Response([
                'success' => false,
                'error' => '无效的源格式参数',
            ], 400);
        }

        // 2. 验证文件类型
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($original_file['type'], $allowed_types, true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => '不支持的文件类型',
            ], 400);
        }

        // 3. 使用 WordPress 标准函数上传原图
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload_overrides = ['test_form' => false];
        $uploaded_file = wp_handle_upload($original_file, $upload_overrides);

        if (isset($uploaded_file['error'])) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $uploaded_file['error'],
            ], 500);
        }

        // 4. 创建附件
        $attachment_data = [
            'post_mime_type' => $uploaded_file['type'],
            'post_title' => sanitize_file_name(pathinfo($original_file['name'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment_data, $uploaded_file['file']);

        if (is_wp_error($attachment_id)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $attachment_id->get_error_message(),
            ], 500);
        }

        // 5. 生成附件元数据（WordPress 标准缩略图）
        $attach_data = wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        // 6. 标记为 aether 上传
        update_post_meta($attachment_id, '_aether_uploaded', 'yes');

        // 7. 保存前端生成的文件
        $saved_files = [
            'original' => [],
            'webp' => [],
        ];

        try {
            // 保存 WebP 文件
            if (isset($files['webp_files'])) {
                $saved_files['webp'] = $this->save_processed_files(
                    $files['webp_files'],
                    $attachment_id
                );
            }

            // 8. 后端生成原格式多尺寸（注意：后端已在 wp_generate_attachment_metadata 中处理）
            // 由于我们已经在 class-image-optimizer.php 的 optimize_on_upload hook 中处理
            // 这里不需要额外调用

            // 9. 保存优化信息到元数据
            $optimization_data = [
                'frontend_processed' => true,
                'source_format' => $source_format,
                'webp_count' => count($saved_files['webp']),
                'saved_files' => $saved_files,
                'timestamp' => time(),
            ];

            update_post_meta($attachment_id, '_aether_frontend_optimized', $optimization_data);

        } catch (Exception $e) {
            error_log('aether: 保存前端生成文件失败 - ' . $e->getMessage());

            return new WP_REST_Response([
                'success' => false,
                'error' => '保存优化文件失败：' . $e->getMessage(),
            ], 500);
        }

        // 10. 返回结果
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'media_id' => $attachment_id,
                'media_url' => wp_get_attachment_url($attachment_id),
                'files_saved' => $saved_files,
            ],
        ], 200);
    }

    /**
     * 保存前端处理的文件到 aether-optimized 目录
     *
     * @param array $files 文件数组
     * @param int $attachment_id 附件 ID
     * @return array 保存的文件信息
     * @throws Exception
     */
    private function save_processed_files(array $files, int $attachment_id): array {
        $upload_dir = wp_upload_dir();
        $date_path = date('Y/m');

        // aether-optimized 目录
        $optimized_base = $upload_dir['basedir'] . '/' . Aether_Image_Sizes::get_optimized_dir();
        $optimized_dir = $optimized_base . '/' . $date_path;
        $optimized_url_base = $upload_dir['baseurl'] . '/' . Aether_Image_Sizes::get_optimized_dir() . '/' . $date_path;

        // 创建目录
        if (!file_exists($optimized_dir)) {
            wp_mkdir_p($optimized_dir);
        }

        $saved = [];

        foreach ($files as $file) {
            if (!isset($file['tmp_name']) || !isset($file['name'])) {
                continue;
            }

            // 验证文件
            if (!is_uploaded_file($file['tmp_name'])) {
                throw new Exception('无效的上传文件');
            }

            // 目标路径
            $filename = sanitize_file_name($file['name']);
            $target_path = $optimized_dir . '/' . $filename;

            // 移动文件
            if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                throw new Exception('文件保存失败：' . $filename);
            }

            // 记录文件信息
            $saved[] = [
                'filename' => $filename,
                'path' => $target_path,
                'url' => $optimized_url_base . '/' . $filename,
                'size' => filesize($target_path),
            ];
        }

        return $saved;
    }

    /**
     * 保存前端 jsquash 处理后的文件
     *
     * @param WP_REST_Request $request REST 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function save_optimized($request) {
        // CRITICAL: 第一行就记录日志，确认回调被执行
        error_log('[Aether Save] ========== 回调函数被调用 ==========');

        // Debug 日志
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Aether Save] === 开始保存优化文件 ===');
            error_log('[Aether Save] Request method: ' . $request->get_method());
            error_log('[Aether Save] Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'NOT SET'));
            error_log('[Aether Save] Query params: ' . print_r($request->get_query_params(), true));
            error_log('[Aether Save] POST data: ' . print_r($_POST, true));
            error_log('[Aether Save] FILES data: ' . print_r(array_keys($_FILES), true));
            error_log('[Aether Save] File params: ' . print_r(array_keys($request->get_file_params()), true));
        }

        // 1. 验证附件 ID
        $files = $request->get_file_params();
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;

        if (!$attachment_id || !get_post($attachment_id)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => '无效的附件 ID',
            ], 400);
        }

        // 2. 获取源格式
        $source_format = isset($_GET['source_format']) ? sanitize_text_field($_GET['source_format']) : '';

        if (!in_array($source_format, ['jpeg', 'png', 'webp'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => '无效的源格式参数',
            ], 400);
        }

        // 3. 获取附件信息
        $attachment_path = get_attached_file($attachment_id);
        if (!$attachment_path) {
            return new WP_REST_Response([
                'success' => false,
                'error' => '无法获取附件路径',
            ], 404);
        }

        $upload_dir = wp_upload_dir();
        $attachment_dir = dirname($attachment_path);

        // 提取日期路径（YYYY/MM）
        $date_pattern = '/(\d{4}\/\d{2})/';
        preg_match($date_pattern, $attachment_path, $matches);
        $date_path = $matches[1] ?? date('Y/m');

        $files_updated = [
            'compressed' => 0,
            'webp' => 0,
        ];

        try {
            // 4. 覆盖压缩后的原格式文件
            if (isset($files['compressed_files']) && is_array($files['compressed_files'])) {
                foreach ($files['compressed_files'] as $file) {
                    if (!isset($file['tmp_name']) || !isset($file['name'])) {
                        continue;
                    }

                    if (!is_uploaded_file($file['tmp_name'])) {
                        throw new Exception('无效的上传文件');
                    }

                    // 目标路径：覆盖 WordPress 生成的文件
                    $filename = sanitize_file_name($file['name']);
                    $target_path = $attachment_dir . '/' . $filename;

                    // 检查文件是否存在
                    if (!file_exists($target_path)) {
                        error_log("[Aether Save] 警告：目标文件不存在，跳过覆盖 - {$target_path}");
                        continue;
                    }

                    // 移动文件（覆盖）
                    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                        throw new Exception('文件覆盖失败：' . $filename);
                    }

                    $files_updated['compressed']++;
                    error_log("[Aether Save] 覆盖文件: {$filename} (" . filesize($target_path) . ' bytes)');
                }
            }

            // 5. 保存 WebP 文件到 aether-optimized/
            if (isset($files['webp_files']) && is_array($files['webp_files'])) {
                $saved_webp = $this->save_optimized_files(
                    $files['webp_files'],
                    $date_path
                );
                $files_updated['webp'] = count($saved_webp);
            }

            // 6. 更新附件元数据
            $optimization_data = [
                'frontend_optimized' => true,
                'source_format' => $source_format,
                'files_updated' => $files_updated,
                'timestamp' => time(),
            ];

            update_post_meta($attachment_id, '_aether_frontend_optimized', $optimization_data);

            // 7. 更新优化状态（关键：防止重复处理）
            // 修复: 即使文件都被跳过,也要标记为 completed,避免死循环
            // 只要前端请求了某个格式,就认为已尝试优化,不再重复处理
            if (isset($files['compressed_files'])) {
                update_post_meta($attachment_id, '_aether_compression_status', 'completed');
                error_log("[Aether Save] 标记压缩状态: completed (处理了 {$files_updated['compressed']} 个文件)");
            }

            if (isset($files['webp_files'])) {
                update_post_meta($attachment_id, '_aether_webp_status', 'completed');
                error_log("[Aether Save] 标记 WebP 状态: completed (处理了 {$files_updated['webp']} 个文件)");
                $this->refresh_dependent_pages_after_webp_update($attachment_id);
            }

            error_log('[Aether Save] 完成，文件统计: ' . print_r($files_updated, true));

        } catch (Exception $e) {
            error_log('aether: 保存优化文件失败 - ' . $e->getMessage());

            return new WP_REST_Response([
                'success' => false,
                'error' => '保存优化文件失败：' . $e->getMessage(),
            ], 500);
        }

        // 8. 返回结果
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'attachment_id' => $attachment_id,
                'files_updated' => $files_updated,
            ],
        ], 200);
    }

    /**
     * AJAX 处理器：保存优化文件（使用 admin-ajax.php）
     * 这个方法直接处理 multipart/form-data，绕过 REST API 的 JSON 验证
     */
    public function ajax_save_optimized() {
        // CRITICAL: 第一行就记录日志
        error_log('[Aether AJAX] ========== AJAX 回调函数被调用 ==========');

        // 检查权限
        if (!current_user_can('edit_posts')) {
            error_log('[Aether AJAX] 权限检查失败');
            wp_send_json_error(['message' => '权限不足'], 403);
            return;
        }

        error_log('[Aether AJAX] 权限检查通过');
        error_log('[Aether AJAX] POST: ' . print_r($_POST, true));
        error_log('[Aether AJAX] FILES: ' . print_r(array_keys($_FILES), true));

        // 1. 验证附件 ID
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;

        if (!$attachment_id || !get_post($attachment_id)) {
            error_log('[Aether AJAX] 无效的附件 ID: ' . $attachment_id);
            wp_send_json_error(['message' => '无效的附件 ID'], 400);
            return;
        }

        // 2. 获取源格式
        $source_format = isset($_GET['source_format']) ? sanitize_text_field($_GET['source_format']) : '';

        if (!in_array($source_format, ['jpeg', 'png', 'webp'], true)) {
            error_log('[Aether AJAX] 无效的源格式: ' . $source_format);
            wp_send_json_error(['message' => '无效的源格式参数'], 400);
            return;
        }

        // 3. 获取附件信息
        $attachment_path = get_attached_file($attachment_id);
        if (!$attachment_path) {
            error_log('[Aether AJAX] 无法获取附件路径');
            wp_send_json_error(['message' => '无法获取附件路径'], 404);
            return;
        }

        $upload_dir = wp_upload_dir();
        $attachment_dir = dirname($attachment_path);

        // 提取日期路径（YYYY/MM）
        $date_pattern = '/(\d{4}\/\d{2})/';
        preg_match($date_pattern, $attachment_path, $matches);
        $date_path = $matches[1] ?? date('Y/m');

        $files_updated = [
            'compressed' => 0,
            'webp' => 0,
        ];

        try {
            // 4. 覆盖压缩后的原格式文件
            if (isset($_FILES['compressed_files']) && is_array($_FILES['compressed_files']['name'])) {
                $count = count($_FILES['compressed_files']['name']);
                error_log("[Aether AJAX] 处理 {$count} 个压缩文件");

                for ($i = 0; $i < $count; $i++) {
                    if ($_FILES['compressed_files']['error'][$i] !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $filename = sanitize_file_name($_FILES['compressed_files']['name'][$i]);
                    $tmp_name = $_FILES['compressed_files']['tmp_name'][$i];
                    $target_path = $attachment_dir . '/' . $filename;

                    if (!file_exists($target_path)) {
                        error_log("[Aether AJAX] 警告：目标文件不存在，跳过 - {$target_path}");
                        continue;
                    }

                    // 对比文件大小：如果压缩后没有变小，跳过覆盖
                    $original_size = filesize($target_path);
                    $compressed_size = filesize($tmp_name);

                    if ($compressed_size >= $original_size) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("[Aether AJAX] 跳过覆盖（压缩后没变小）: {$filename} - 原始: {$original_size} bytes, 压缩后: {$compressed_size} bytes");
                        }
                        continue;
                    }

                    if (!move_uploaded_file($tmp_name, $target_path)) {
                        throw new Exception('文件覆盖失败：' . $filename);
                    }

                    $saved_bytes = $original_size - $compressed_size;
                    $files_updated['compressed']++;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[Aether AJAX] 覆盖: {$filename} - 节省: {$saved_bytes} bytes ({$original_size} -> {$compressed_size})");
                    }
                }
            }

            // 5. 保存 WebP 文件
            if (isset($_FILES['webp_files']) && is_array($_FILES['webp_files']['name'])) {
                $webp_files = $this->convert_files_array($_FILES['webp_files']);
                $saved_webp = $this->save_optimized_files($webp_files, $date_path);
                $files_updated['webp'] = count($saved_webp);
                error_log("[Aether AJAX] WebP: {$files_updated['webp']} 个");
            }

            // 6. 更新附件元数据
            $optimization_data = [
                'frontend_optimized' => true,
                'source_format' => $source_format,
                'files_updated' => $files_updated,
                'timestamp' => time(),
            ];

            update_post_meta($attachment_id, '_aether_frontend_optimized', $optimization_data);

            // 7. 更新优化状态（关键：防止重复处理）
            // 修复: 即使文件都被跳过,也要标记为 completed,避免死循环
            // 只要前端请求了某个格式,就认为已尝试优化,不再重复处理
            if (isset($_FILES['compressed_files'])) {
                update_post_meta($attachment_id, '_aether_compression_status', 'completed');
                error_log("[Aether AJAX] 标记压缩状态: completed (处理了 {$files_updated['compressed']} 个文件)");
            }

            if (isset($_FILES['webp_files'])) {
                update_post_meta($attachment_id, '_aether_webp_status', 'completed');
                error_log("[Aether AJAX] 标记 WebP 状态: completed (处理了 {$files_updated['webp']} 个文件)");
                $this->refresh_dependent_pages_after_webp_update($attachment_id);
            }

            error_log('[Aether AJAX] 成功完成，文件统计: ' . print_r($files_updated, true));

            wp_send_json_success([
                'attachment_id' => $attachment_id,
                'files_updated' => $files_updated,
            ]);

        } catch (Exception $e) {
            error_log('[Aether AJAX] 错误: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * 转换 $_FILES 数组格式
     * 从 ['name' => [0 => 'file1', 1 => 'file2']] 转换为标准格式
     */
    private function convert_files_array($files_array) {
        $files = [];
        $count = count($files_array['name']);

        for ($i = 0; $i < $count; $i++) {
            if ($files_array['error'][$i] === UPLOAD_ERR_OK) {
                $files[] = [
                    'name' => $files_array['name'][$i],
                    'type' => $files_array['type'][$i],
                    'tmp_name' => $files_array['tmp_name'][$i],
                    'error' => $files_array['error'][$i],
                    'size' => $files_array['size'][$i],
                ];
            }
        }

        return $files;
    }

    /**
     * 保存优化文件到 aether-optimized 目录
     *
     * @param array $files 文件数组
     * @param string $date_path 日期路径（YYYY/MM）
     * @return array 保存的文件信息
     * @throws Exception
     */
    private function save_optimized_files(array $files, string $date_path): array {
        $upload_dir = wp_upload_dir();

        // aether-optimized 目录
        $optimized_base = $upload_dir['basedir'] . '/' . Aether_Image_Sizes::get_optimized_dir();
        $optimized_dir = $optimized_base . '/' . $date_path;
        $optimized_url_base = $upload_dir['baseurl'] . '/' . Aether_Image_Sizes::get_optimized_dir() . '/' . $date_path;

        // 创建目录
        if (!file_exists($optimized_dir)) {
            wp_mkdir_p($optimized_dir);
        }

        $saved = [];

        foreach ($files as $file) {
            if (!isset($file['tmp_name']) || !isset($file['name'])) {
                continue;
            }

            // 验证文件
            if (!is_uploaded_file($file['tmp_name'])) {
                throw new Exception('无效的上传文件');
            }

            // 目标路径
            $filename = sanitize_file_name($file['name']);
            $target_path = $optimized_dir . '/' . $filename;

            // 移动文件
            if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                throw new Exception('文件保存失败：' . $filename);
            }

            // 记录文件信息
            $saved[] = [
                'filename' => $filename,
                'path' => $target_path,
                'url' => $optimized_url_base . '/' . $filename,
                'size' => filesize($target_path),
            ];

            error_log("[Aether Save] 保存优化文件: {$filename} (" . filesize($target_path) . ' bytes)');
        }

        return $saved;
    }

    /**
     * WebP 完成后刷新依赖页面的优化 HTML
     *
     * @param int $attachment_id 附件 ID
     * @return void
     */
    private function refresh_dependent_pages_after_webp_update($attachment_id): void {
        if (!class_exists('Aether_HTML_Optimization_Service')) {
            return;
        }

        $result = Aether_HTML_Optimization_Service::get_instance()->refresh_pages_for_attachment($attachment_id);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Aether Save] WebP 完成后刷新依赖页面 - Attachment #%d, Pages: %s, Count: %d',
                intval($attachment_id),
                implode(',', $result['page_ids'] ?? []),
                intval($result['refreshed_count'] ?? 0)
            ));
        }
    }
}
