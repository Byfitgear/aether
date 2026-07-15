<?php
/**
 * 图片处理环境检测类
 *
 * 检测服务器图片处理能力（Imagick/GD）和格式支持
 *
 * @package aether
 * @subpackage Services\Image
 */

defined('ABSPATH') || exit;

/**
 * 图片处理环境检测类
 */
class Aether_Image_Environment {
    /**
     * 环境检测缓存
     *
     * @var array|null
     */
    private $environment_cache = null;

    /**
     * 检测服务器图片处理环境
     *
     * @return array 环境信息数组
     */
    public function detect_environment(): array {
        // 使用缓存避免重复检测
        if ($this->environment_cache !== null) {
            return $this->environment_cache;
        }

        $result = [
            'imagick' => $this->detect_imagick(),
            'gd' => $this->detect_gd(),
            'recommendation' => 'none',
        ];

        // Imagick recommended (better performance, more format support)
        if ($result['imagick']['available']) {
            $result['recommendation'] = 'imagick';
        } elseif ($result['gd']['available']) {
            $result['recommendation'] = 'gd';
        }

        $this->environment_cache = $result;
        return $result;
    }

    /**
     * 检测 Imagick 扩展
     *
     * @return array Imagick 支持信息
     */
    private function detect_imagick(): array {
        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            return ['available' => false];
        }

        $formats = Imagick::queryFormats();
        $version = Imagick::getVersion();

        return [
            'available' => true,
            'version' => $version['versionString'] ?? 'unknown',
            'formats' => $formats,
            'webp' => in_array('WEBP', $formats, true),
            'jpeg' => in_array('JPEG', $formats, true),
            'png' => in_array('PNG', $formats, true),
            'gif' => in_array('GIF', $formats, true),
        ];
    }

    /**
     * 检测 GD 扩展
     *
     * @return array GD 支持信息
     */
    private function detect_gd(): array {
        if (!extension_loaded('gd') || !function_exists('gd_info')) {
            return ['available' => false];
        }

        $info = gd_info();

        return [
            'available' => true,
            'version' => $info['GD Version'] ?? 'unknown',
            'webp' => !empty($info['WebP Support']),
            'jpeg' => !empty($info['JPEG Support']) || !empty($info['JPG Support']),
            'png' => !empty($info['PNG Support']),
            'gif' => !empty($info['GIF Read Support']),
        ];
    }

    /**
     * 检测 WebP 写入支持
     *
     * @return bool 是否支持 WebP 写入
     */
    public function supports_webp_write(): bool {
        $env = $this->detect_environment();

        if ($env['recommendation'] === 'imagick' && isset($env['imagick']['webp'])) {
            // Imagick: 检测是否支持 WebP 编码
            return $env['imagick']['webp'] && in_array('WEBP', Imagick::queryFormats('WEBP'), true);
        }

        if ($env['recommendation'] === 'gd' && isset($env['gd']['webp'])) {
            // GD: 检测是否支持 WebP 写入
            return $env['gd']['webp'] && function_exists('imagewebp');
        }

        return false;
    }

    /**
     * 检测 WebP 读取支持（向后兼容）
     *
     * @return bool 是否支持 WebP 读取
     */
    public function supports_webp(): bool {
        return $this->supports_webp_write(); // 通常读写一致
    }

    /**
     * 获取推荐的图片处理引擎
     *
     * @return string 'imagick', 'gd', 或 'none'
     */
    public function get_recommendation(): string {
        $env = $this->detect_environment();
        return $env['recommendation'];
    }

    /**
     * 检查是否有可用的图片处理引擎
     *
     * @return bool 是否有可用引擎
     */
    public function has_processor(): bool {
        return $this->get_recommendation() !== 'none';
    }

    /**
     * 获取环境信息的可读描述
     *
     * @return string 环境描述
     */
    public function get_description(): string {
        $env = $this->detect_environment();
        $recommendation = $env['recommendation'];

        if ($recommendation === 'none') {
            return '无可用图片处理引擎（需要 Imagick 或 GD 扩展）';
        }

        $engine = $env[$recommendation];
        $formats = [];

        if (!empty($engine['jpeg'])) {
            $formats[] = 'JPEG';
        }
        if (!empty($engine['png'])) {
            $formats[] = 'PNG';
        }
        if (!empty($engine['webp'])) {
            $formats[] = 'WebP';
        }
        if (!empty($engine['gif'])) {
            $formats[] = 'GIF';
        }

        return sprintf(
            '使用 %s（版本：%s）支持格式：%s',
            strtoupper($recommendation),
            $engine['version'] ?? 'unknown',
            implode(', ', $formats)
        );
    }
}
