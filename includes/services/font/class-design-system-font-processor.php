<?php
/**
 * Design System Font Processor
 * 
 * 自动处理设计系统中的字体
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Design_System_Font_Processor
{
    /**
     * 处理设计系统中的字体
     * 
     * @param string $html 设计系统 HTML
     * @return array|bool 处理结果，返回数组包含处理详情，失败返回 false
     */
    public static function process_fonts_from_design_system($html)
    {
        try {
            // 1. 首先清理旧的字体缓存
            Aether_Font_Manager_Service::clear_font_cache();
            
            if (empty($html)) {
                return ['fonts_count' => 0, 'fonts_downloaded' => 0]; // 如果 HTML 为空，只清理缓存即可
            }
            
            // 2. 提取字体信息
            $font_imports = Aether_Design_System_Extractor::extract_font_imports($html);
            
            if (empty($font_imports)) {
                return ['fonts_count' => 0, 'fonts_downloaded' => 0]; // 没有字体需要处理
            }
            
            // 3. Download all fonts并生成 CSS
            $download_result = self::download_and_generate_css($font_imports);
            
            return [
                'fonts_count' => count($font_imports),
                'fonts_downloaded' => $download_result['downloaded'],
                'fonts_list' => array_map(function($font) {
                    return $font['family'];
                }, $font_imports)
            ];
        } catch (Exception $e) {
            error_log('Aether Font Processor Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Download font并生成 CSS
     * 
     * @param array $font_imports 提取的字体信息
     * @return array 下载结果统计
     */
    private static function download_and_generate_css($font_imports)
    {
        $downloader = new Aether_Font_Downloader_Service();
        $css_generator = new Aether_Font_CSS_Generator_Service();
        $font_formats = [];
        $css = '';
        $downloaded_count = 0;
        
        // Download all fonts
        foreach ($font_imports as $font_info) {
            $family = $font_info['family'];
            $weights = $font_info['weights'];
            
            foreach ($weights as $weight) {
                $result = $downloader->download_google_font($family, $weight);
                
                if ($result['success']) {
                    $safe_family = sanitize_file_name(strtolower(str_replace(' ', '-', $family)));
                    $font_key = $safe_family . '-' . $weight;
                    $font_formats[$font_key] = $result['format'];
                    
                    // 如果是新下载的（不是已存在的），增加计数
                    if (!isset($result['exists']) || !$result['exists']) {
                        $downloaded_count++;
                    }
                }
            }
        }
        
        // 生成 @font-face CSS
        foreach ($font_imports as $font_info) {
            $family = $font_info['family'];
            $weights = $font_info['weights'];
            
            foreach ($weights as $weight) {
                $css .= $css_generator->generate_font_face($family, $weight, $font_formats);
            }
        }
        
        // Minify CSS
        if (!empty($css)) {
            // Use minify method from CSS generator
            // 由于 minify_css 是 private 方法，我们需要使用反射或者直接实现 minify
            $css = self::minify_css($css);
        }
        
        // 保存生成的 CSS
        update_option('aether_font_css', $css, false);
        update_option('aether_font_formats', $font_formats, false);

        // 清理对象缓存，确保配置立即生效
        wp_cache_delete('aether_font_css', 'options');
        wp_cache_delete('aether_font_formats', 'options');
        wp_cache_delete('alloptions', 'options');
        
        return ['downloaded' => $downloaded_count];
    }
    
    /**
     * 压缩 CSS
     * 
     * @param string $css CSS 内容
     * @return string 压缩后的 CSS
     */
    private static function minify_css($css) {
        // 移除注释
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // 移除多余的空白字符
        $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $css);
        
        // 移除多个空格
        $css = preg_replace('/\s+/', ' ', $css);
        
        // 移除分号前的空格
        $css = str_replace(' ;', ';', $css);
        
        // 移除冒号后的空格（但保留 url() 中的空格）
        $css = preg_replace('/:\s+(?!url)/', ':', $css);
        
        // 移除花括号周围的空格
        $css = str_replace([' {', '{ ', ' }', '} '], ['{', '{', '}', '}'], $css);
        
        // 移除逗号后的空格
        $css = str_replace(', ', ',', $css);
        
        // 移除最后一个分号前的空格
        $css = str_replace(';}', '}', $css);
        
        return trim($css);
    }
}
