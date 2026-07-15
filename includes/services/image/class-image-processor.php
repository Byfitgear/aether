<?php
/**
 * 图片处理核心类
 *
 * 负责图片的缩放、格式转换和文件保存
 *
 * @package aether
 * @subpackage Services\Image
 */

defined('ABSPATH') || exit;

/**
 * 图片处理核心类
 */
class Aether_Image_Processor {
    /**
     * 环境检测实例
     *
     * @var Aether_Image_Environment
     */
    private $environment;

    /**
     * Constructor
     *
     * @param Aether_Image_Environment $environment 环境检测实例
     */
    public function __construct(Aether_Image_Environment $environment) {
        $this->environment = $environment;
    }

    /**
     * 检测源文件格式
     *
     * @param string $file_path 文件路径
     * @return string 格式标识（'jpeg', 'png', 'webp', 'gif', 'unknown'）
     */
    public function detect_source_format(string $file_path): string {
        $mime_type = wp_get_image_mime($file_path);

        $mime_map = [
            'image/jpeg' => 'jpeg',
            'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];

        return $mime_map[$mime_type] ?? 'unknown';
    }

    /**
     * 预缩放处理（如果原图宽度超过最大限制）
     *
     * @param string $source_file 源文件路径
     * @return array 处理结果
     * @throws Exception 处理失败时抛出异常
     */
    public function pre_resize_if_needed(string $source_file): array {
        $metadata = @getimagesize($source_file);

        if ($metadata === false) {
            throw new Exception('无法读取图片信息');
        }

        $original_width = $metadata[0];
        $original_height = $metadata[1];
        $mime_type = $metadata['mime'];

        $max_width = Aether_Image_Sizes::get_max_width();

        // 不需要缩放
        if ($original_width <= $max_width) {
            return [
                'file' => $source_file,
                'was_resized' => false,
                'original_dimensions' => [
                    'width' => $original_width,
                    'height' => $original_height,
                ],
            ];
        }

        // Detect environment
        $env = $this->environment->detect_environment();
        if ($env['recommendation'] === 'none') {
            throw new Exception('无法缩放图片：服务器不支持 Imagick 或 GD 扩展');
        }

        // 计算目标尺寸（保持宽高比）
        $target_width = $max_width;
        $target_height = (int) round($original_height * ($max_width / $original_width));

        // 创建临时文件
        $temp_file = wp_tempnam($source_file);

        // 使用源文件的 MIME 类型（保持透明度）
        $output_mime = $mime_type;

        // 执行缩放
        if ($env['recommendation'] === 'imagick') {
            $this->resize_with_imagick($source_file, $temp_file, $target_width, $target_height, 100, $output_mime);
        } elseif ($env['recommendation'] === 'gd') {
            $this->resize_with_gd($source_file, $temp_file, $target_width, $target_height, 100, $output_mime);
        } else {
            throw new Exception('无法缩放图片：环境检测失败');
        }

        return [
            'file' => $temp_file,
            'was_resized' => true,
            'original_dimensions' => [
                'width' => $original_width,
                'height' => $original_height,
            ],
            'resized_dimensions' => [
                'width' => $target_width,
                'height' => $target_height,
            ],
            'output_mime' => $output_mime,
        ];
    }

    /**
     * 生成多尺寸版本
     *
     * @param string $source_file 源文件路径
     * @param string $date_path 日期路径（如 '2024/12'）
     * @param string $format 目标格式（'original', 'webp'）
     * @return array 生成结果
     */
    public function generate_sizes(string $source_file, string $date_path, string $format): array {
        $metadata = @getimagesize($source_file);

        if ($metadata === false) {
            return [
                'success' => false,
                'error' => '无法读取图片信息',
                'files' => [],
            ];
        }

        $original_width = $metadata[0];
        $original_height = $metadata[1];
        $source_mime = $metadata['mime'];

        // 确定输出 MIME 类型
        $output_mime = $this->get_output_mime($format, $source_mime);

        // 获取需要生成的尺寸
        $required_sizes = Aether_Image_Sizes::get_required_sizes($original_width);

        if (empty($required_sizes)) {
            return [
                'success' => false,
                'error' => '原图尺寸过小，无需生成多尺寸版本',
                'files' => [],
            ];
        }

        $quality = Aether_Image_Sizes::get_quality();
        $generated_files = [];
        $errors = [];

        foreach ($required_sizes as $target_width) {
            try {
                // 计算目标高度（保持宽高比）
                $target_height = (int) round($original_height * ($target_width / $original_width));

                // 生成输出文件路径
                $output_path = $this->generate_output_path($source_file, $date_path, $target_width, $target_height, $format);

                // 确保目录存在
                $output_dir = dirname($output_path);
                if (!file_exists($output_dir)) {
                    wp_mkdir_p($output_dir);
                }

                // 执行缩放
                $env = $this->environment->detect_environment();

                if ($env['recommendation'] === 'imagick') {
                    $this->resize_with_imagick($source_file, $output_path, $target_width, $target_height, $quality, $output_mime);
                } elseif ($env['recommendation'] === 'gd') {
                    $this->resize_with_gd($source_file, $output_path, $target_width, $target_height, $quality, $output_mime);
                } else {
                    throw new Exception('无可用的图片处理引擎');
                }

                $generated_files[] = [
                    'width' => $target_width,
                    'height' => $target_height,
                    'path' => $output_path,
                    'url' => $this->path_to_url($output_path),
                    'size' => filesize($output_path),
                ];
            } catch (Exception $e) {
                $errors[] = sprintf('生成 %dpx 版本失败：%s', $target_width, $e->getMessage());
            }
        }

        return [
            'success' => !empty($generated_files),
            'files' => $generated_files,
            'errors' => $errors,
        ];
    }

    /**
     * 使用 Imagick 缩放图片
     *
     * @param string $source_file 源文件路径
     * @param string $output_file 输出文件路径
     * @param int $width 目标宽度
     * @param int $height 目标高度
     * @param int $quality 质量（0-100）
     * @param string $output_mime 输出 MIME 类型
     * @throws Exception 处理失败时抛出异常
     */
    private function resize_with_imagick(string $source_file, string $output_file, int $width, int $height, int $quality, string $output_mime): void {
        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            throw new Exception('Imagick 扩展不可用');
        }

        $imagick = new Imagick();

        try {
            $imagick->readImage($source_file);

            // 设置图片格式
            $format = $this->mime_to_imagick_format($output_mime);
            $imagick->setImageFormat($format);

            // 缩放图片（使用 Lanczos 滤镜获得最佳质量）
            $imagick->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);

            // 设置压缩质量
            $imagick->setImageCompressionQuality($quality);

            // 去除 EXIF 数据（减小文件大小）
            $imagick->stripImage();

            // 写入文件
            $imagick->writeImage($output_file);
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    /**
     * 使用 GD 缩放图片
     *
     * @param string $source_file 源文件路径
     * @param string $output_file 输出文件路径
     * @param int $width 目标宽度
     * @param int $height 目标高度
     * @param int $quality 质量（0-100）
     * @param string $output_mime 输出 MIME 类型
     * @throws Exception 处理失败时抛出异常
     */
    private function resize_with_gd(string $source_file, string $output_file, int $width, int $height, int $quality, string $output_mime): void {
        if (!extension_loaded('gd') || !function_exists('gd_info')) {
            throw new Exception('GD 扩展不可用');
        }

        // 读取源图片
        $source_image = $this->gd_load_image($source_file);

        if ($source_image === false) {
            throw new Exception('无法读取源图片');
        }

        // 创建目标图片
        $target_image = imagecreatetruecolor($width, $height);

        if ($target_image === false) {
            imagedestroy($source_image);
            throw new Exception('无法创建目标图片');
        }

        // 保持透明度（针对 PNG/WebP）
        if (in_array($output_mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($target_image, false);
            imagesavealpha($target_image, true);

            $transparent = imagecolorallocatealpha($target_image, 0, 0, 0, 127);
            imagefilledrectangle($target_image, 0, 0, $width, $height, $transparent);
        }

        // 执行缩放（使用双线性插值）
        imagecopyresampled(
            $target_image,
            $source_image,
            0, 0, 0, 0,
            $width, $height,
            imagesx($source_image),
            imagesy($source_image)
        );

        // 保存图片
        $this->gd_save_image($target_image, $output_file, $quality, $output_mime);

        // 释放资源
        imagedestroy($source_image);
        imagedestroy($target_image);
    }

    /**
     * 使用 GD 加载图片
     *
     * @param string $file_path 文件路径
     * @return resource|false 图片资源或失败时返回 false
     */
    private function gd_load_image(string $file_path) {
        $mime_type = wp_get_image_mime($file_path);

        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                return imagecreatefromjpeg($file_path);
            case 'image/png':
                return imagecreatefrompng($file_path);
            case 'image/webp':
                return function_exists('imagecreatefromwebp') ? imagecreatefromwebp($file_path) : false;
            case 'image/gif':
                return imagecreatefromgif($file_path);
            default:
                return false;
        }
    }

    /**
     * 使用 GD 保存图片
     *
     * @param resource $image 图片资源
     * @param string $file_path 输出文件路径
     * @param int $quality 质量（0-100）
     * @param string $output_mime 输出 MIME 类型
     * @throws Exception 保存失败时抛出异常
     */
    private function gd_save_image($image, string $file_path, int $quality, string $output_mime): void {
        $success = false;

        switch ($output_mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $success = imagejpeg($image, $file_path, $quality);
                break;
            case 'image/png':
                // PNG quality: 0-9 (0=无压缩, 9=最大压缩)
                $png_quality = (int) round((100 - $quality) / 11);
                $success = imagepng($image, $file_path, $png_quality);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    $success = imagewebp($image, $file_path, $quality);
                }
                break;
            default:
                throw new Exception('不支持的输出格式：' . $output_mime);
        }

        if (!$success) {
            throw new Exception('保存图片失败');
        }
    }

    /**
     * 根据格式和源 MIME 确定输出 MIME 类型
     *
     * @param string $format 目标格式
     * @param string $source_mime 源 MIME 类型
     * @return string 输出 MIME 类型
     */
    private function get_output_mime(string $format, string $source_mime): string {
        switch ($format) {
            case 'webp':
                return 'image/webp';
            case 'original':
            default:
                return $source_mime;
        }
    }

    /**
     * MIME 类型转换为 Imagick 格式
     *
     * @param string $mime_type MIME 类型
     * @return string Imagick 格式
     */
    private function mime_to_imagick_format(string $mime_type): string {
        $map = [
            'image/jpeg' => 'JPEG',
            'image/jpg' => 'JPEG',
            'image/png' => 'PNG',
            'image/webp' => 'WEBP',
            'image/gif' => 'GIF',
        ];

        return $map[$mime_type] ?? 'JPEG';
    }

    /**
     * 生成输出文件路径
     *
     * @param string $source_file 源文件路径
     * @param string $date_path 日期路径
     * @param int $width 宽度
     * @param int $height 高度
     * @param string $format 格式
     * @return string 输出文件路径
     */
    private function generate_output_path(string $source_file, string $date_path, int $width, int $height, string $format): string {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        $optimized_dir = Aether_Image_Sizes::get_optimized_dir();
        $filename = basename($source_file);
        $filename_parts = pathinfo($filename);

        // 根据格式确定扩展名
        $extension = $this->format_to_extension($format, $filename_parts['extension']);

        // 构建文件名：original-name-320x180.webp（WordPress 格式）
        $output_filename = sprintf(
            '%s-%dx%d.%s',
            $filename_parts['filename'],
            $width,
            $height,
            $extension
        );

        // 构建完整路径
        return sprintf(
            '%s/%s/%s/%s',
            $base_dir,
            $optimized_dir,
            $date_path,
            $output_filename
        );
    }

    /**
     * 格式转换为文件扩展名
     *
     * @param string $format 格式
     * @param string $original_ext 原始扩展名
     * @return string 扩展名
     */
    private function format_to_extension(string $format, string $original_ext): string {
        switch ($format) {
            case 'webp':
                return 'webp';
            case 'original':
            default:
                return $original_ext;
        }
    }

    /**
     * 将文件路径转换为 URL
     *
     * @param string $file_path 文件路径
     * @return string URL
     */
    private function path_to_url(string $file_path): string {
        $upload_dir = wp_upload_dir();
        return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
    }

    /**
     * 生成单个指定尺寸的图片
     *
     * @param string $source_file 源文件路径
     * @param int $target_width 目标宽度
     * @param int $target_height 目标高度
     * @param string $format 目标格式
     * @param string $date_path 日期路径
     * @return array 生成结果
     * @throws Exception 处理失败时抛出异常
     */
    public function generate_single_size(
        string $source_file,
        int $target_width,
        int $target_height,
        string $format,
        string $date_path
    ): array {
        $metadata = @getimagesize($source_file);
        if ($metadata === false) {
            throw new Exception('无法读取图片信息');
        }

        $source_mime = $metadata['mime'];
        $output_mime = $this->get_output_mime($format, $source_mime);

        // 生成输出路径
        $output_path = $this->generate_output_path(
            $source_file,
            $date_path,
            $target_width,
            $target_height,
            $format
        );

        // 确保目录存在
        $output_dir = dirname($output_path);
        if (!file_exists($output_dir)) {
            wp_mkdir_p($output_dir);
        }

        // 执行缩放
        $env = $this->environment->detect_environment();
        $quality = Aether_Image_Sizes::get_quality();

        if ($env['recommendation'] === 'imagick') {
            $this->resize_with_imagick($source_file, $output_path, $target_width, $target_height, $quality, $output_mime);
        } elseif ($env['recommendation'] === 'gd') {
            $this->resize_with_gd($source_file, $output_path, $target_width, $target_height, $quality, $output_mime);
        } else {
            throw new Exception('无可用的图片处理引擎');
        }

        return [
            'width' => $target_width,
            'height' => $target_height,
            'path' => $output_path,
            'url' => $this->path_to_url($output_path),
            'size' => filesize($output_path),
        ];
    }
}
