<?php
/**
 * Font Manager Service
 * 
 * 管理字体下载、缓存和生成 CSS
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Font_Manager_Service
{

    /**
     * 字体下载服务
     */
    private $downloader;

    /**
     * CSS 生成服务
     */
    private $css_generator;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->downloader = new Aether_Font_Downloader_Service();
        $this->css_generator = new Aether_Font_CSS_Generator_Service();
    }

    /**
     * 获取谷歌字体列表
     * 
     * @return array
     */
    public static function get_google_fonts_list()
    {
        // 返回精选的常用字体列表
        return [
            'Roboto' => [
                'family' => 'Roboto',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '500', '700'],
            ],
            'Open Sans' => [
                'family' => 'Open Sans',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '600', '700'],
            ],
            'Lato' => [
                'family' => 'Lato',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '700', '900'],
            ],
            'Montserrat' => [
                'family' => 'Montserrat',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '500', '600', '700'],
            ],
            'Noto Sans SC' => [
                'family' => 'Noto Sans SC',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '500', '700'],
            ],
            'Plus Jakarta Sans' => [
                'family' => 'Plus Jakarta Sans',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '500', '600', '700'],
            ],
            'Inter' => [
                'family' => 'Inter',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '500', '600', '700'],
            ],
            'Raleway' => [
                'family' => 'Raleway',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '500', '600', '700'],
            ],
            'Poppins' => [
                'family' => 'Poppins',
                'category' => 'sans-serif',
                'variants' => ['300', '400', '500', '600', '700'],
            ],
            'Playfair Display' => [
                'family' => 'Playfair Display',
                'category' => 'serif',
                'variants' => ['400', '700', '900'],
            ],
            'Merriweather' => [
                'family' => 'Merriweather',
                'category' => 'serif',
                'variants' => ['300', '400', '700', '900'],
            ],
            'Lora' => [
                'family' => 'Lora',
                'category' => 'serif',
                'variants' => ['400', '500', '600', '700'],
            ],
            'Crimson Text' => [
                'family' => 'Crimson Text',
                'category' => 'serif',
                'variants' => ['400', '600', '700'],
            ],
            'Fira Code' => [
                'family' => 'Fira Code',
                'category' => 'monospace',
                'variants' => ['300', '400', '500', '600', '700'],
            ],
            'JetBrains Mono' => [
                'family' => 'JetBrains Mono',
                'category' => 'monospace',
                'variants' => ['400', '500', '600', '700'],
            ],
            'Source Code Pro' => [
                'family' => 'Source Code Pro',
                'category' => 'monospace',
                'variants' => ['300', '400', '500', '600', '700'],
            ],
        ];
    }

    /**
     * 获取已保存的字体配置
     * 
     * @return array
     */
    public static function get_font_settings()
    {
        $default = [
            'heading_font' => '',
            'heading_weight' => '700',
            'body_font' => '',
            'body_weight' => '400',
            'code_font' => '',
            'code_weight' => '400',
        ];

        return get_option('aether_font_settings', $default);
    }

    /**
     * 更新字体设置并下载字体
     * 
     * @param array $settings 字体设置
     * @return bool
     */
    public static function update_font_settings($settings)
    {
        return self::save_font_settings($settings);
    }

    /**
     * 保存字体配置
     * 
     * @param array $settings 字体设置
     * @return bool
     */
    public static function save_font_settings($settings)
    {
        update_option('aether_font_settings', $settings);

        // 清理对象缓存，确保配置立即生效
        wp_cache_delete('aether_font_settings', 'options');
        wp_cache_delete('alloptions', 'options');

        // 下载并缓存选中的字体
        $instance = new self();
        $fonts_to_download = [];

        if (!empty($settings['heading_font'])) {
            $fonts_to_download[] = [
                'family' => $settings['heading_font'],
                'weight' => $settings['heading_weight'],
            ];
        }

        if (!empty($settings['body_font'])) {
            $fonts_to_download[] = [
                'family' => $settings['body_font'],
                'weight' => $settings['body_weight'],
            ];
        }

        if (!empty($settings['code_font'])) {
            $fonts_to_download[] = [
                'family' => $settings['code_font'],
                'weight' => $settings['code_weight'],
            ];
        }

        // 下载字体
        $font_formats = get_option('aether_font_formats', []);
        $download_errors = [];

        foreach ($fonts_to_download as $font) {
            $result = $instance->downloader->download_google_font($font['family'], $font['weight']);

            if ($result['success']) {
                if (!$result['exists']) {
                    // 保存格式信息
                    $safe_family = sanitize_file_name(strtolower(str_replace(' ', '-', $font['family'])));
                    $font_key = $safe_family . '-' . $font['weight'];
                    $font_formats[$font_key] = $result['format'];
                }
            } else {
                // 记录下载失败的字体
                $download_errors[] = $font['family'] . ' (' . $font['weight'] . '): ' . ($result['error'] ?? 'Unknown error');
            }
        }

        // 如果有下载失败，记录错误
        if (!empty($download_errors)) {
            error_log('Aether Font Download Errors: ' . implode('; ', $download_errors));
        }

        update_option('aether_font_formats', $font_formats, false);

        // 生成 CSS（默认启用 minify）
        $fonts_list = self::get_google_fonts_list();
        $css = $instance->css_generator->generate($settings, $font_formats, $fonts_list, true);
        update_option('aether_font_css', $css, false);

        // 清理对象缓存，确保配置立即生效
        wp_cache_delete('aether_font_formats', 'options');
        wp_cache_delete('aether_font_css', 'options');
        wp_cache_delete('alloptions', 'options');

        // 触发字体设置更新 hook，缓存清理由 Production Mode Manager 统一处理
        do_action('aether_font_settings_updated', [
            'settings' => $settings,
            'fonts_downloaded' => $fonts_to_download
        ]);

        return true;
    }

    /**
     * 获取生成的字体 CSS
     * 
     * @return string
     */
    public static function get_font_css()
    {
        return get_option('aether_font_css', '');
    }

    /**
     * 清空字体缓存
     * 
     * @return bool
     */
    public static function clear_font_cache()
    {
        $instance = new self();

        // 清理下载的字体文件
        $instance->downloader->clear_cache();

        // 清理数据库中的选项
        delete_option('aether_font_settings');
        delete_option('aether_font_css');
        delete_option('aether_font_formats');

        return true;
    }
}
