<?php
/**
 * Image Cleanup Service
 *
 * 删除图片时同步清理优化文件（WebP）
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Image Cleanup Service Class
 */
class Aether_Image_Cleanup_Service {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // 优先级 5：确保在其他插件可能清空 metadata 之前执行
        add_action('delete_attachment', [$this, 'cleanup_optimized_files'], 5, 1);
    }

    /**
     * 清理优化后的文件
     *
     * @param int $attachment_id Attachment ID
     */
    public function cleanup_optimized_files($attachment_id) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $upload_dir = wp_upload_dir();
        $optimized_dir = $upload_dir['basedir'] . '/aether-optimized';

        $file_path = get_attached_file($attachment_id);
        if (!$file_path) {
            return; // 静默跳过
        }

        // 获取子目录（如 2026/01）
        $subdir = dirname(str_replace($upload_dir['basedir'] . '/', '', $file_path));

        // 收集所有需要删除的 WebP 文件路径
        $webp_files = $this->get_webp_files($attachment_id, $metadata, $subdir);

        // 删除所有 WebP 文件
        foreach ($webp_files as $webp_path) {
            $full_path = "{$optimized_dir}/{$webp_path}";
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
            // 文件不存在时静默跳过
        }

        // 清理空目录（可选）
        $this->cleanup_empty_directory("{$optimized_dir}/{$subdir}");
    }

    /**
     * 获取所有需要删除的 WebP 文件路径
     *
     * @param int $attachment_id Attachment ID
     * @param array|false $metadata Attachment metadata
     * @param string $subdir 子目录路径
     * @return array WebP 文件相对路径列表
     */
    private function get_webp_files($attachment_id, $metadata, $subdir) {
        $files = [];

        // 1. 主图的 WebP
        $main_file = get_attached_file($attachment_id);
        if ($main_file) {
            $main_name = pathinfo(basename($main_file), PATHINFO_FILENAME);
            $files[] = "{$subdir}/{$main_name}.webp";
        }

        // 2. 所有缩略图的 WebP
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $info) {
                if (!empty($info['file'])) {
                    $size_name = pathinfo($info['file'], PATHINFO_FILENAME);
                    $files[] = "{$subdir}/{$size_name}.webp";
                }
            }
        }

        // 3. 原图的 WebP（如果有 scaled 版本）
        $original = wp_get_original_image_path($attachment_id);
        if ($original && file_exists($original)) {
            $orig_name = pathinfo(basename($original), PATHINFO_FILENAME);
            $files[] = "{$subdir}/{$orig_name}.webp";
        }

        return array_unique($files);
    }

    /**
     * 清理空目录
     *
     * @param string $dir 目录路径
     */
    private function cleanup_empty_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        // 检查目录是否为空（只有 . 和 ..）
        $files = @scandir($dir);
        if ($files && count($files) === 2) {
            @rmdir($dir);

            // 递归清理父目录
            $parent = dirname($dir);
            $upload_dir = wp_upload_dir();
            $optimized_base = $upload_dir['basedir'] . '/aether-optimized';

            // 不要删除 aether-optimized 根目录
            if ($parent !== $optimized_base && strpos($parent, $optimized_base) === 0) {
                $this->cleanup_empty_directory($parent);
            }
        }
    }
}
