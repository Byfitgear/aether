<?php
/**
 * 图片尺寸配置类
 *
 * 定义图片优化系统使用的所有尺寸参数和质量设置
 *
 * @package aether
 * @subpackage Services\Image
 */

defined('ABSPATH') || exit;

/**
 * 图片尺寸配置类
 */
class Aether_Image_Sizes {
    /**
     * aether 不再注册额外图片尺寸。
     *
     * 不变量：优化系统只处理 WordPress 已生成的默认尺寸和原图，
     * 不再额外插入 aether 自定义尺寸，避免扩大生成面和增加选图复杂度。
     */
    public const SIZES = [];

    /**
     * 最大宽度限制（超过此宽度将预缩放）
     */
    public const MAX_WIDTH = 2560;

    /**
     * 图片质量（0-100）
     */
    public const QUALITY = 90;

    /**
     * 优化图片存储目录名
     */
    public const OPTIMIZED_DIR = 'aether-optimized';

    /**
     * 获取所有尺寸配置
     *
     * @return array 尺寸数组
     */
    public static function get_sizes(): array {
        return self::SIZES;
    }

    /**
     * 验证并过滤尺寸配置
     *
     * @return array 有效的尺寸数组
     */
    public static function validate_sizes(): array {
        return array_filter(self::SIZES, function($size) {
            return is_int($size) && $size > 0 && $size <= self::MAX_WIDTH;
        });
    }

    /**
     * 获取图片质量配置
     *
     * @return int 质量值（0-100）
     */
    public static function get_quality(): int {
        return self::QUALITY;
    }

    /**
     * 获取最大宽度限制
     *
     * @return int 最大宽度（像素）
     */
    public static function get_max_width(): int {
        return self::MAX_WIDTH;
    }

    /**
     * 获取优化图片存储目录名
     *
     * @return string 目录名
     */
    public static function get_optimized_dir(): string {
        return self::OPTIMIZED_DIR;
    }
}
