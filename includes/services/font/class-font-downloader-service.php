<?php
/**
 * Font Downloader Service
 * 
 * 处理字体文件的下载和存储
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Font_Downloader_Service
{

    /**
     * 字体目录路径
     */
    private $fonts_dir;

    /**
     * Constructor
     */
    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->fonts_dir = $upload_dir['basedir'] . '/aether-fonts/';

        // 确保字体目录存在
        if (!file_exists($this->fonts_dir)) {
            wp_mkdir_p($this->fonts_dir);
        }
    }

    /**
     * 下载谷歌字体
     * 
     * @param string $family 字体族名称
     * @param string $weight 字体字重
     * @return array 包含成功状态和文件信息
     */
    public function download_google_font($family, $weight = '400')
    {
        $font_dir = $this->fonts_dir . sanitize_file_name(strtolower(str_replace(' ', '-', $family))) . '/';

        if (!file_exists($font_dir)) {
            wp_mkdir_p($font_dir);
        }

        // 检查文件是否已存在（支持多种格式）
        $safe_family = sanitize_file_name(strtolower(str_replace(' ', '-', $family)));
        $formats = ['woff2', 'woff', 'ttf'];

        foreach ($formats as $ext) {
            $filename = $font_dir . $safe_family . '-' . $weight . '.' . $ext;
            if (file_exists($filename)) {
                // 文件已存在，返回现有文件信息
                return [
                    'success' => true,
                    'filename' => $filename,
                    'format' => $ext,
                    'exists' => true
                ];
            }
        }

        // 构建谷歌字体 API URL
        $api_url = 'https://fonts.googleapis.com/css2?family=' . urlencode($family) . ':wght@' . $weight;

        // 获取 CSS
        $response = wp_remote_get($api_url, [
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => 'Failed to fetch Google Fonts CSS: ' . $response->get_error_message()
            ];
        }

        $css = wp_remote_retrieve_body($response);

        // 解析 CSS 获取字体文件 URL
        preg_match_all('/url\((https:\/\/[^)]+)\)/', $css, $matches);

        if (empty($matches[1])) {
            return [
                'success' => false,
                'error' => 'No font URLs found in CSS'
            ];
        }

        // Download font文件，优先 woff2，其次 woff，最后 ttf
        $preferred_formats = ['.woff2', '.woff', '.ttf'];

        foreach ($preferred_formats as $format) {
            foreach ($matches[1] as $font_url) {
                if (strpos($font_url, $format) !== false) {
                    $font_response = wp_remote_get($font_url, ['timeout' => 60]);

                    if (!is_wp_error($font_response)) {
                        $font_data = wp_remote_retrieve_body($font_response);

                        if (!empty($font_data)) {
                            // 保持原始格式的扩展名
                            $extension = str_replace('.', '', $format);
                            $filename = $font_dir . $safe_family . '-' . $weight . '.' . $extension;

                            $result = file_put_contents($filename, $font_data);

                            if ($result !== false) {
                                // 触发字体下载 hook
                                do_action('aether_font_downloaded', [
                                    'family' => $family,
                                    'weight' => $weight,
                                    'filename' => $filename,
                                    'format' => $extension,
                                    'size' => $result
                                ]);

                                return [
                                    'success' => true,
                                    'filename' => $filename,
                                    'format' => $extension,
                                    'size' => $result,
                                    'exists' => false
                                ];
                            }
                        }
                    }
                }
            }
        }

        return [
            'success' => false,
            'error' => 'Failed to download font file'
        ];
    }

    /**
     * 清空字体缓存
     * 
     * @return bool
     */
    public function clear_cache()
    {
        if (!file_exists($this->fonts_dir)) {
            return true;
        }

        // 递归删除字体目录
        $this->recursive_rmdir($this->fonts_dir);

        // 重新创建目录
        wp_mkdir_p($this->fonts_dir);

        return true;
    }

    /**
     * 递归删除目录
     * 
     * @param string $dir 目录路径
     */
    private function recursive_rmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursive_rmdir($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }
}