<?php
/**
 * 图片优化协调器类
 *
 * 整合所有图片优化组件，提供统一的优化接口
 *
 * @package aether
 * @subpackage Services\Image
 */

defined('ABSPATH') || exit;

/**
 * 图片优化协调器类
 */
class Aether_Image_Optimizer {
    /**
     * 单例实例
     *
     * @var Aether_Image_Optimizer|null
     */
    private static $instance = null;

    /**
     * 环境检测实例
     *
     * @var Aether_Image_Environment
     */
    private $environment;

    /**
     * 图片处理实例
     *
     * @var Aether_Image_Processor
     */
    private $processor;

    /**
     * Get singleton instance
     *
     * @return Aether_Image_Optimizer
     */
    public static function get_instance(): Aether_Image_Optimizer {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor（私有，单例模式）
     */
    private function __construct() {
        // 初始化组件
        $this->environment = new Aether_Image_Environment();
        $this->processor = new Aether_Image_Processor($this->environment);

        // 注册 WordPress hooks
        $this->register_hooks();
    }

    /**
     * 注册 WordPress hooks
     */
    private function register_hooks(): void {
        // 禁用后端自动优化（改为前端 jsquash 处理）
        // 前端 jsquash 压缩效果远超 PHP Imagick/GD，且能压缩原格式文件
        // add_filter('wp_generate_attachment_metadata', [$this, 'optimize_on_upload'], 10, 2);

        // 删除附件时清理优化文件
        add_action('delete_attachment', [$this, 'delete_optimized_files']);

        // 文件名规范化：特殊字符 → -，避免文件名/URL 不一致
        // Priority 5 ensures execution before WordPress default processing (priority 10)
        // 这样特殊字符先被我们转成 -，而不是被 WordPress 直接移除
        add_filter('sanitize_file_name', [$this, 'normalize_filename'], 5);
    }

    /**
     * 规范化文件名
     *
     * 目的：避免特殊字符导致的文件名/URL 不一致问题
     *
     * 统一规则（与前端 TypeScript sanitizeFileName 一致）：
     * 1. 特殊字符（空格、括号、方括号等）→ -
     * 2. 连续 - → 单个 -
     * 3. 去掉首尾 -
     * 4. 空名 → image
     *
     * @param string $filename 文件名
     * @return string 规范化后的文件名
     */
    public function normalize_filename(string $filename): string {
        // 分离文件名和扩展名
        $pathinfo = pathinfo($filename);
        $name = $pathinfo['filename'] ?? '';
        $ext = isset($pathinfo['extension']) ? '.' . strtolower($pathinfo['extension']) : '';

        if (empty($name)) {
            return $filename;
        }

        $original_name = $name;

        // 1. 特殊字符 → -
        // 匹配：空格、括号、方括号、尖括号、花括号、管道、反斜杠、脱字符、反引号、波浪号、感叹号、@、#、$、%、&、*
        $name = preg_replace('/[\s()\[\]<>{}|\\\\^`~!@#$%&*]+/', '-', $name);

        // 2. 连续 - → 单个 -
        $name = preg_replace('/-+/', '-', $name);

        // 3. 去掉首尾 -
        $name = trim($name, '-');

        // 4. 空名 → image
        if (empty($name)) {
            $name = 'image';
        }

        // 5. 如果有修改，记录日志（debug 模式）
        if ($name !== $original_name && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[aether] 文件名规范化: "%s" → "%s"',
                $original_name . $ext,
                $name . $ext
            ));
        }

        return $name . $ext;
    }

    /**
     * WordPress 上传图片时自动优化
     *
     * @param array $metadata 附件元数据
     * @param int $attachment_id 附件 ID
     * @return array 修改后的元数据
     */
    public function optimize_on_upload(array $metadata, int $attachment_id): array {
        // Only process image types的附件
        if (!wp_attachment_is_image($attachment_id)) {
            return $metadata;
        }

        try {
            // 1. 检测环境
            $env = $this->environment->detect_environment();
            if ($env['recommendation'] === 'none') {
                error_log('aether: 无法优化图片 (ID: ' . $attachment_id . ') - 服务器不支持 Imagick 或 GD 扩展');
                return $metadata;
            }

            // 2. 获取源文件和格式
            $source_file = get_attached_file($attachment_id);
            if (!file_exists($source_file)) {
                return $metadata;
            }

            $source_format = $this->processor->detect_source_format($source_file);

            // 3. 检测 GIF 动图（跳过优化）
            if ($source_format === 'gif') {
                $is_animated = $this->is_animated_gif($source_file);
                if ($is_animated) {
                    error_log('aether: 跳过 GIF 动图优化 (ID: ' . $attachment_id . ')');
                    $metadata['aether_optimized'] = [
                        'version' => '1.0',
                        'timestamp' => time(),
                        'skipped' => true,
                        'reason' => 'GIF 动图',
                    ];
                    return $metadata;
                }
            }

            // 4. 读取需要优化的所有尺寸（原图/2560px + WordPress 生成的所有缩略图）
            $wp_sizes = [];
            $original_width = $metadata['width'] ?? 0;
            $original_height = $metadata['height'] ?? 0;
            $max_width = Aether_Image_Sizes::get_max_width();

            // 首先添加原图尺寸（或限制到 2560px）
            if ($original_width > 0 && $original_height > 0) {
                if ($original_width > $max_width) {
                    // 原图超过 2560px，优化版本限制到 2560px
                    $target_height = (int) round($original_height * ($max_width / $original_width));
                    $wp_sizes[] = [
                        'width' => $max_width,
                        'height' => $target_height,
                        'file' => basename($source_file),
                    ];
                    error_log(sprintf(
                        'aether: 原图过大 (ID: %d, 原尺寸: %dx%d)，优化版本限制到 %dpx',
                        $attachment_id,
                        $original_width,
                        $original_height,
                        $max_width
                    ));
                } else {
                    // 原图 <= 2560px，直接使用原图尺寸
                    $wp_sizes[] = [
                        'width' => $original_width,
                        'height' => $original_height,
                        'file' => basename($source_file),
                    ];
                }
            }

            // 然后添加所有 WordPress 生成的缩略图尺寸
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size_name => $size_data) {
                    $wp_sizes[] = [
                        'width' => $size_data['width'],
                        'height' => $size_data['height'],
                        'file' => $size_data['file'],
                    ];
                }
            }

            if (empty($wp_sizes)) {
                error_log('aether: 没有可用的尺寸信息 (ID: ' . $attachment_id . ')');
                return $metadata;
            }

            // 5. 根据源格式决定生成哪些优化格式
            $date_path = date('Y/m');
            $generated_files = [
                'webp' => [],
            ];

            foreach ($wp_sizes as $size) {
                try {
                    switch ($source_format) {
                        case 'jpeg':
                        case 'png':
                            // JPEG/PNG → 生成 WebP
                            if ($this->environment->supports_webp_write()) {
                                $result = $this->processor->generate_single_size(
                                    $source_file,
                                    $size['width'],
                                    $size['height'],
                                    'webp',
                                    $date_path
                                );
                                $generated_files['webp'][] = $result;
                            }
                            break;

                        case 'webp':
                            // WebP → 不需要额外处理
                            break;
                    }
                } catch (Exception $e) {
                    error_log(sprintf(
                        'aether: 生成 %dx%d 优化版本失败 - %s',
                        $size['width'],
                        $size['height'],
                        $e->getMessage()
                    ));
                }
            }

            // 6. 保存优化信息到元数据
            $metadata['aether_optimized'] = [
                'version' => '1.0',
                'timestamp' => time(),
                'source_format' => $source_format,
                'webp' => $generated_files['webp'],
                'total_files' => count($generated_files['webp']),
            ];

            error_log(sprintf(
                'aether: 图片优化成功 (ID: %d, 格式: %s, 生成: %d WebP)',
                $attachment_id,
                $source_format,
                count($generated_files['webp'])
            ));

        } catch (Exception $e) {
            error_log('aether: 图片优化失败 (ID: ' . $attachment_id . ') - ' . $e->getMessage());

            $metadata['aether_optimized'] = [
                'version' => '1.0',
                'timestamp' => time(),
                'error' => $e->getMessage(),
            ];
        }

        return $metadata;
    }

    /**
     * 检测 GIF 是否为动图
     *
     * @param string $file_path GIF 文件路径
     * @return bool 是否为动图
     */
    private function is_animated_gif(string $file_path): bool {
        if (!file_exists($file_path)) {
            return false;
        }

        $file_content = @file_get_contents($file_path);

        if ($file_content === false) {
            return false;
        }

        // 搜索 GIF89a 标志和多个图像块
        // 动图会包含多个 0x00 0x21 0xF9 序列（图形控制扩展）
        $frame_count = preg_match_all('#\x00\x21\xF9\x04#', $file_content, $matches);

        return $frame_count > 1;
    }

    /**
     * 获取附件的优化信息
     *
     * @param int $attachment_id 附件 ID
     * @return array|null 优化信息或 null
     */
    public function get_optimization_info(int $attachment_id): ?array {
        $metadata = wp_get_attachment_metadata($attachment_id);

        return $metadata['aether_optimized'] ?? null;
    }

    /**
     * 检查附件是否已优化
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否已优化
     */
    public function is_optimized(int $attachment_id): bool {
        $info = $this->get_optimization_info($attachment_id);

        return $info !== null && isset($info['timestamp']);
    }

    /**
     * 手动触发图片优化
     *
     * @param int $attachment_id 附件 ID
     * @return array 优化结果
     */
    public function optimize_manually(int $attachment_id): array {
        // 获取当前元数据
        $metadata = wp_get_attachment_metadata($attachment_id);

        // 调用优化方法
        $updated_metadata = $this->optimize_on_upload($metadata, $attachment_id);

        // 更新附件元数据
        wp_update_attachment_metadata($attachment_id, $updated_metadata);

        // 返回优化信息
        return $updated_metadata['aether_optimized'] ?? ['success' => false, 'error' => '优化失败'];
    }

    /**
     * 获取环境信息
     *
     * @return array 环境信息
     */
    public function get_environment_info(): array {
        return $this->environment->detect_environment();
    }

    /**
     * 删除附件时清理优化文件
     *
     * @param int $attachment_id 附件 ID
     */
    public function delete_optimized_files($attachment_id): void {
        // 1. 验证附件类型（只处理图片）
        if (!wp_attachment_is_image($attachment_id)) {
            return;
        }

        // 2. 获取附件路径和日期
        $attachment_path = get_attached_file($attachment_id);
        if (!$attachment_path) {
            return;
        }

        // 3. 提取日期路径（YYYY/MM）
        $date_pattern = '/(\d{4}\/\d{2})/';
        preg_match($date_pattern, $attachment_path, $matches);
        $date_path = $matches[1] ?? date('Y/m');

        // 4. 构建优化目录路径
        $upload_dir = wp_upload_dir();
        $optimized_dir = $upload_dir['basedir'] . '/' . Aether_Image_Sizes::get_optimized_dir() . '/' . $date_path;

        if (!file_exists($optimized_dir)) {
            return;
        }

        // 5. 读取优化元数据
        $metadata = get_post_meta($attachment_id, '_aether_frontend_optimized', true);

        $files_deleted = 0;

        // 6. 方式 A：从元数据删除（精确）
        if ($metadata && is_array($metadata)) {
            $files_deleted += $this->delete_files_from_metadata(
                $metadata,
                $optimized_dir
            );
        }

        // 7. 方式 B：根据文件名推测删除（补充）
        $files_deleted += $this->delete_files_by_guess(
            $attachment_id,
            $attachment_path,
            $optimized_dir
        );

        // 8. 日志记录
        if ($files_deleted > 0) {
            error_log(sprintf(
                'aether: 删除附件 %d 的优化文件，共删除 %d 个文件',
                $attachment_id,
                $files_deleted
            ));
        }

        // 9. 可选：删除空目录
        $this->cleanup_empty_directory($optimized_dir);
    }

    /**
     * 从元数据删除文件（精确删除）
     *
     * @param array $metadata 优化元数据
     * @param string $optimized_dir 优化目录路径
     * @return int 删除的文件数量
     */
    private function delete_files_from_metadata(array $metadata, string $optimized_dir): int {
        $files_deleted = 0;

        // 检查元数据结构
        if (!isset($metadata['files_updated'])) {
            return 0;
        }

        $files_updated = $metadata['files_updated'];

        // 删除 WebP 文件
        if (isset($files_updated['webp']) && $files_updated['webp'] > 0) {
            // 尝试从旧版元数据结构读取文件列表
            // 注意：当前 save_optimized 没有保存具体文件名列表，这里需要改进
            // 暂时跳过，依赖 delete_files_by_guess
        }

        return $files_deleted;
    }

    /**
     * 根据文件名推测删除（补充删除）
     *
     * @param int $attachment_id 附件 ID
     * @param string $attachment_path 附件路径
     * @param string $optimized_dir 优化目录路径
     * @return int 删除的文件数量
     */
    private function delete_files_by_guess(int $attachment_id, string $attachment_path, string $optimized_dir): int {
        $files_deleted = 0;

        // 获取原图文件名（去除扩展名）
        $path_info = pathinfo($attachment_path);
        $basename = $path_info['filename'];

        // 获取 WordPress 元数据
        $wp_metadata = wp_get_attachment_metadata($attachment_id);
        if (!$wp_metadata) {
            return 0;
        }

        // 收集所有需要删除的文件名
        $filenames_to_delete = [];

        // 1. 原图尺寸
        if (isset($wp_metadata['width']) && isset($wp_metadata['height'])) {
            $width = $wp_metadata['width'];
            $height = $wp_metadata['height'];

            // 如果原图超过 2560px，WordPress 会缩放，优化文件应该是 2560px 版本
            $max_width = Aether_Image_Sizes::get_max_width();
            if ($width > $max_width) {
                $target_height = (int) round($height * ($max_width / $width));
                $filenames_to_delete[] = "{$basename}-{$max_width}x{$target_height}";
            } else {
                $filenames_to_delete[] = "{$basename}-{$width}x{$height}";
            }
        }

        // 2. 所有缩略图尺寸
        if (isset($wp_metadata['sizes']) && is_array($wp_metadata['sizes'])) {
            foreach ($wp_metadata['sizes'] as $size_data) {
                $width = $size_data['width'];
                $height = $size_data['height'];
                $filenames_to_delete[] = "{$basename}-{$width}x{$height}";
            }
        }

        // 3. 尝试删除 WebP 文件
        foreach ($filenames_to_delete as $filename_base) {
            // 删除 WebP
            $webp_file = $optimized_dir . '/' . $filename_base . '.webp';
            if (file_exists($webp_file)) {
                if (@unlink($webp_file)) {
                    $files_deleted++;
                } else {
                    error_log("aether: 无法删除文件 - {$webp_file}");
                }
            }
        }

        return $files_deleted;
    }

    /**
     * 清理空目录
     *
     * @param string $dir 目录路径
     */
    private function cleanup_empty_directory(string $dir): void {
        if (!file_exists($dir) || !is_dir($dir)) {
            return;
        }

        // 检查目录是否为空
        $files = @scandir($dir);
        if ($files === false) {
            return;
        }

        // 过滤 . 和 ..
        $files = array_diff($files, ['.', '..']);

        // 如果目录为空，删除
        if (empty($files)) {
            @rmdir($dir);
            error_log("aether: 删除空目录 - {$dir}");
        }
    }
}
