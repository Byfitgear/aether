<?php
/**
 * Font CSS Generator Service
 * 
 * 生成字体相关的 CSS 代码
 *
 * @package aether
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Font_CSS_Generator_Service {
    
    /**
     * 字体 URL 基础路径
     */
    private $fonts_url;
    
    /**
     * 字体回退栈定义
     */
    private $font_stacks = [
        'sans-serif' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', sans-serif",
        'serif' => "Georgia, Cambria, 'Times New Roman', Times, serif",
        'monospace' => "'SF Mono', Monaco, 'Inconsolata', 'Fira Mono', 'Droid Sans Mono', 'Source Code Pro', Consolas, 'Courier New', monospace"
    ];
    
    /**
     * 字重后缀映射
     */
    private $weight_suffixes = [
        '300' => ['Light', '-Light'],
        '400' => ['Regular', '-Regular', ''],
        '500' => ['Medium', '-Medium'],
        '600' => ['SemiBold', '-SemiBold', 'Semi Bold', '-Semi-Bold'],
        '700' => ['Bold', '-Bold'],
        '900' => ['Black', '-Black', 'Heavy', '-Heavy']
    ];
    
    /**
     * 格式映射
     */
    private $format_map = [
        'woff2' => 'woff2',
        'woff' => 'woff',
        'ttf' => 'truetype',
        'otf' => 'opentype'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        // 使用相对路径
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace(home_url(), '', $upload_dir['baseurl']);
        $this->fonts_url = $relative_path . '/aether-fonts/';
    }
    
    /**
     * 生成字体 CSS
     * 
     * @param array $settings 字体设置
     * @param array $font_formats 字体格式信息
     * @param array $fonts_list 可用字体列表
     * @param bool $minify 是否压缩 CSS
     * @return string 生成的 CSS
     */
    public function generate($settings, $font_formats = [], $fonts_list = [], $minify = true) {
        $css = '';
        
        // 生成 @font-face 声明
        $fonts_to_declare = $this->get_fonts_to_declare($settings);
        
        foreach ($fonts_to_declare as $family => $weight) {
            $css .= $this->generate_font_face($family, $weight, $font_formats);
        }
        
        // 生成应用字体的 CSS
        $css .= $this->generate_font_applications($settings, $fonts_list);
        
        // 如果需要压缩
        if ($minify && !empty($css)) {
            $css = $this->minify_css($css);
        }
        
        return $css;
    }
    
    /**
     * 获取需要声明的字体
     * 
     * @param array $settings 字体设置
     * @return array
     */
    private function get_fonts_to_declare($settings) {
        $fonts = [];
        
        if (!empty($settings['heading_font'])) {
            $fonts[$settings['heading_font']] = $settings['heading_weight'];
        }
        
        if (!empty($settings['body_font'])) {
            $fonts[$settings['body_font']] = $settings['body_weight'];
        }
        
        if (!empty($settings['code_font'])) {
            $fonts[$settings['code_font']] = $settings['code_weight'];
        }
        
        return $fonts;
    }
    
    /**
     * 生成 @font-face 声明
     * 
     * @param string $family 字体族
     * @param string $weight 字重
     * @param array $font_formats 字体格式信息
     * @return string
     */
    public function generate_font_face($family, $weight, $font_formats) {
        // 统一使用小写处理
        $font_filename = sanitize_file_name(strtolower(str_replace(' ', '-', $family)));
        $safe_family = sanitize_file_name(strtolower(str_replace(' ', '-', $family)));
        
        // 检查实际存在的文件
        $upload_dir = wp_upload_dir();
        $font_dir = $upload_dir['basedir'] . '/aether-fonts/' . $font_filename . '/';
        $formats = ['woff2', 'woff', 'ttf', 'otf'];
        $extension = 'woff2'; // 默认
        $found = false;
        
        // 查找实际存在的文件
        foreach ($formats as $ext) {
            $file_path = $font_dir . $safe_family . '-' . $weight . '.' . $ext;
            if (file_exists($file_path)) {
                $extension = $ext;
                $found = true;
                break;
            }
        }
        
        // 如果没找到文件，返回空字符串
        if (!$found) {
            return '';
        }
        
        $font_file = $this->fonts_url . $font_filename . '/' . $safe_family . '-' . $weight . '.' . $extension;
        $format = isset($this->format_map[$extension]) ? $this->format_map[$extension] : 'woff2';
        
        // 生成本地字体名称变体
        $local_names = [
            str_replace(' ', '', $family),  // 无空格版本
            $family                          // 原始版本
        ];
        
        $css = "@font-face {\n";
        $css .= "    font-family: '" . $family . "';\n";
        $css .= "    font-style: normal;\n";
        $css .= "    font-weight: " . $weight . ";\n";
        $css .= "    font-display: swap;\n";
        $css .= "    src: ";
        
        // 添加本地字体回退
        $src_parts = [];
        if (isset($this->weight_suffixes[$weight])) {
            foreach ($local_names as $local_name) {
                foreach ($this->weight_suffixes[$weight] as $suffix) {
                    $src_parts[] = "local('" . $local_name . $suffix . "')";
                }
            }
        }
        
        // 添加 URL
        $src_parts[] = "url('" . $font_file . "') format('" . $format . "')";
        
        $css .= implode(",\n         ", $src_parts) . ";\n";
        $css .= "}\n\n";
        
        return $css;
    }
    
    /**
     * 生成字体应用 CSS
     * 
     * @param array $settings 字体设置
     * @param array $fonts_list 可用字体列表
     * @return string
     */
    private function generate_font_applications($settings, $fonts_list) {
        $css = '';
        
        // 获取字体类别
        $font_categories = $this->get_font_categories($fonts_list);
        
        // 标题字体
        if (!empty($settings['heading_font'])) {
            $category = $font_categories[$settings['heading_font']] ?? 'serif';
            $fallback = $this->font_stacks[$category] ?? $this->font_stacks['serif'];
            
            $css .= "h1, h2, h3, h4, h5, h6, .heading {\n";
            $css .= "    font-family: '" . $settings['heading_font'] . "', " . $fallback . ";\n";
            $css .= "    font-weight: " . $settings['heading_weight'] . ";\n";
            $css .= "}\n\n";
        }
        
        // 正文字体
        if (!empty($settings['body_font'])) {
            $category = $font_categories[$settings['body_font']] ?? 'sans-serif';
            $fallback = $this->font_stacks[$category] ?? $this->font_stacks['sans-serif'];
            
            $css .= "body, p, .body-text {\n";
            $css .= "    font-family: '" . $settings['body_font'] . "', " . $fallback . ";\n";
            $css .= "    font-weight: " . $settings['body_weight'] . ";\n";
            $css .= "}\n\n";
        }
        
        // Code font
        if (!empty($settings['code_font'])) {
            $css .= "code, pre, .code-text, .wp-block-code {\n";
            $css .= "    font-family: '" . $settings['code_font'] . "', " . $this->font_stacks['monospace'] . ";\n";
            $css .= "    font-weight: " . $settings['code_weight'] . ";\n";
            $css .= "}\n\n";
        }
        
        return $css;
    }
    
    /**
     * 从字体列表中提取类别信息
     * 
     * @param array $fonts_list 字体列表
     * @return array
     */
    private function get_font_categories($fonts_list) {
        $categories = [];
        
        foreach ($fonts_list as $font_data) {
            if (isset($font_data['family']) && isset($font_data['category'])) {
                $categories[$font_data['family']] = $font_data['category'];
            }
        }
        
        return $categories;
    }
    
    /**
     * 压缩 CSS
     * 
     * @param string $css CSS 内容
     * @return string 压缩后的 CSS
     */
    private function minify_css($css) {
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