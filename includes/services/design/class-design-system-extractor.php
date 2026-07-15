<?php
/**
 * Design System Extractor
 * 
 * 从设计系统 HTML 中提取 Tailwind 配置和样式
 * 
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Design_System_Extractor
{
    /**
     * 从 HTML 中提取 Tailwind 配置
     * 
     * @param string $html HTML 内容
     * @return string|null 提取的配置字符串
     */
    public static function extract_tailwind_config($html)
    {
        if (empty($html)) {
            return null;
        }

        // 查找包含 tailwind.config 的 script 标签
        preg_match_all('/<script[^>]*>([\s\S]*?)<\/script>/i', $html, $script_matches);

        foreach ($script_matches[1] as $script_content) {
            if (strpos($script_content, 'tailwind.config') !== false) {
                // 提取 tailwind.config = { ... } 部分
                if (preg_match('/tailwind\.config\s*=\s*(\{[\s\S]*)/i', $script_content, $config_match)) {
                    $config_start = $config_match[1];
                    $closing_brace_pos = self::find_balanced_closing_brace($config_start);
                    
                    if ($closing_brace_pos !== -1) {
                        $config_str = substr($config_start, 0, $closing_brace_pos + 1);
                        
                        // 验证是否是有效的对象
                        if (substr($config_str, 0, 1) === '{' && substr($config_str, -1) === '}') {
                            return 'tailwind.config = ' . $config_str . ';';
                        }
                    }
                }
            }
        }

        return null;
    }


    /**
     * 从 HTML 中提取 Google 字体导入
     * 
     * @param string $html HTML 内容
     * @return array 字体信息数组
     */
    public static function extract_font_imports($html)
    {
        if (empty($html)) {
            return [];
        }

        $fonts = [];

        // 提取所有 <style> 标签内容
        preg_match_all('/<style[^>]*>([\s\S]*?)<\/style>/i', $html, $style_matches);

        foreach ($style_matches[1] as $style_content) {
            // 查找 @import url() 语句
            preg_match_all('/@import\s+url\s*\(\s*[\'"]?(https:\/\/fonts\.googleapis\.com\/css2?\?[^\'")]+)[\'"]?\s*\)/i', $style_content, $import_matches);
            
            foreach ($import_matches[1] as $font_url) {
                // 解析 Google Fonts URL
                $parsed_fonts = self::parse_google_fonts_url($font_url);
                foreach ($parsed_fonts as $font) {
                    $fonts[] = $font;
                }
            }
        }

        // 提取 <link> 标签中的 Google 字体
        preg_match_all('/<link[^>]*href=[\'"]?(https:\/\/fonts\.googleapis\.com\/css2?\?[^\'">]+)[\'"]?[^>]*>/i', $html, $link_matches);
        
        foreach ($link_matches[1] as $font_url) {
            $parsed_fonts = self::parse_google_fonts_url($font_url);
            foreach ($parsed_fonts as $font) {
                $fonts[] = $font;
            }
        }

        return $fonts;
    }

    /**
     * 从 HTML 中提取自定义 CSS（移除字体 import）
     *
     * @param string $html HTML 内容
     * @return string
     */
    public static function extract_custom_css($html)
    {
        if (empty($html)) {
            return '';
        }

        $css_blocks = [];
        preg_match_all('/<style[^>]*>([\s\S]*?)<\/style>/i', $html, $style_matches);

        foreach ($style_matches[1] as $style_content) {
            $cleaned = preg_replace(
                '/@import\s+url\s*\(\s*[\'"]?https:\/\/fonts\.googleapis\.com\/css2?\?[^\'")]+[\'"]?\s*\)\s*;?/i',
                '',
                $style_content
            );
            $cleaned = trim($cleaned);

            if ($cleaned !== '') {
                $css_blocks[] = $cleaned;
            }
        }

        return trim(implode("\n", $css_blocks));
    }

    /**
     * 提取需要在编译模式额外保留的全局 CSS
     * 包括：非 class 选择器规则和 at-rules（如 @keyframes）
     *
     * @param string $css Custom CSS
     * @return string
     */
    public static function extract_global_custom_css($css)
    {
        if (empty($css) || !is_string($css)) {
            return '';
        }

        $rules = self::parse_css_rules($css);
        if (empty($rules)) {
            return '';
        }

        $global_rules = [];
        foreach ($rules as $rule) {
            if (self::is_global_css_rule($rule)) {
                $global_rules[] = trim($rule['content']);
            }
        }

        return trim(implode("\n", array_filter($global_rules)));
    }

    /**
     * 解析 Google Fonts URL
     * 
     * @param string $url Google Fonts URL
     * @return array 解析后的字体信息
     */
    private static function parse_google_fonts_url($url)
    {
        $fonts = [];
        
        // 解码 URL
        $url = html_entity_decode($url);
        
        // 获取查询字符串
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return $fonts;
        }
        
        // 使用正则表达式匹配所有 family 参数
        // 支持 Google Fonts API v2 的多个 family 参数格式
        preg_match_all('/(?:^|&)family=([^&]+)/', $query, $family_matches);
        
        if (!empty($family_matches[1])) {
            foreach ($family_matches[1] as $family_param) {
                // URL 解码参数值
                $family_param = urldecode($family_param);
                
                // API v2 格式: Orbitron:wght@400;500;600
                // API v1 格式: Orbitron:400,500,600
                if (preg_match('/^([^:]+)(?::(.+))?$/i', $family_param, $matches)) {
                    $family_name = str_replace('+', ' ', $matches[1]);
                    $weights = ['400']; // 默认权重
                    
                    if (isset($matches[2])) {
                        $weight_spec = $matches[2];
                        
                        // 处理 API v2 格式: wght@400;500;600
                        if (preg_match('/^wght@(.+)$/i', $weight_spec, $weight_matches)) {
                            $weights = explode(';', $weight_matches[1]);
                        }
                        // 处理 API v1 格式: 400,500,600
                        elseif (preg_match('/^[\d,]+$/', $weight_spec)) {
                            $weights = explode(',', $weight_spec);
                        }
                    }
                    
                    $fonts[] = [
                        'family' => $family_name,
                        'weights' => $weights,
                        'url' => $url
                    ];
                }
            }
        }
        
        // 如果没有匹配到新格式，尝试旧格式（用 | 分隔的单个 family 参数）
        if (empty($fonts)) {
            parse_str($query, $params);
            if (isset($params['family'])) {
                $families = explode('|', $params['family']);
                
                foreach ($families as $family_spec) {
                    if (preg_match('/^([^:]+)(?::(.+))?$/i', $family_spec, $matches)) {
                        $family_name = str_replace('+', ' ', $matches[1]);
                        $weights = isset($matches[2]) ? explode(',', $matches[2]) : ['400'];
                        
                        $fonts[] = [
                            'family' => $family_name,
                            'weights' => $weights,
                            'url' => $url
                        ];
                    }
                }
            }
        }
        
        return $fonts;
    }

    /**
     * 查找平衡的闭合大括号位置
     * 
     * @param string $str 字符串
     * @return int 位置索引，-1 表示未找到
     */
    private static function find_balanced_closing_brace($str)
    {
        $brace_count = 0;
        $in_string = false;
        $string_char = '';
        $escaped = false;
        $length = strlen($str);

        for ($i = 0; $i < $length; $i++) {
            $char = $str[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            // 处理字符串状态
            if (!$in_string && ($char === '"' || $char === "'" || $char === '`')) {
                $in_string = true;
                $string_char = $char;
            } elseif ($in_string && $char === $string_char) {
                $in_string = false;
                $string_char = '';
            }

            // 处理大括号（仅在非字符串状态下）
            if (!$in_string) {
                if ($char === '{') {
                    $brace_count++;
                } elseif ($char === '}') {
                    $brace_count--;
                    if ($brace_count === 0) {
                        return $i;
                    }
                }
            }
        }

        return -1;
    }

    /**
     * 解析 CSS 顶层规则
     *
     * @param string $css CSS 内容
     * @return array<int, array{type:string, selector:string, content:string}>
     */
    private static function parse_css_rules($css)
    {
        $rules = [];
        $length = strlen($css);
        $current = 0;

        while ($current < $length) {
            $current = self::skip_css_whitespace_and_comments($css, $current);
            if ($current >= $length) {
                break;
            }

            if ($css[$current] === '@') {
                $rule = self::parse_css_at_rule($css, $current);
            } else {
                $rule = self::parse_css_standard_rule($css, $current);
            }

            if (!empty($rule)) {
                $rules[] = $rule;
                $current = $rule['end'];
            } else {
                $current++;
            }
        }

        return $rules;
    }

    /**
     * 跳过 CSS 空白和注释
     *
     * @param string $css CSS 内容
     * @param int $start 开始位置
     * @return int
     */
    private static function skip_css_whitespace_and_comments($css, $start)
    {
        $length = strlen($css);
        $current = $start;

        while ($current < $length) {
            if (preg_match('/\s/', $css[$current])) {
                $current++;
                continue;
            }

            if (
                $css[$current] === '/' &&
                $current + 1 < $length &&
                $css[$current + 1] === '*'
            ) {
                $comment_end = strpos($css, '*/', $current + 2);
                if ($comment_end === false) {
                    return $length;
                }

                $current = $comment_end + 2;
                continue;
            }

            break;
        }

        return $current;
    }

    /**
     * 解析 CSS at-rule
     *
     * @param string $css CSS 内容
     * @param int $start 开始位置
     * @return array<string, mixed>|null
     */
    private static function parse_css_at_rule($css, $start)
    {
        $length = strlen($css);
        $cursor = $start + 1;

        while ($cursor < $length && preg_match('/[\w-]/', $css[$cursor])) {
            $cursor++;
        }

        $rule_name = substr($css, $start, $cursor - $start);
        $semicolon_pos = strpos($css, ';', $cursor);
        $brace_pos = strpos($css, '{', $cursor);

        if ($semicolon_pos !== false && ($brace_pos === false || $semicolon_pos < $brace_pos)) {
            return [
                'type' => 'at-rule',
                'selector' => $rule_name,
                'content' => substr($css, $start, $semicolon_pos - $start + 1),
                'end' => $semicolon_pos + 1,
            ];
        }

        if ($brace_pos === false) {
            return null;
        }

        $brace_end = self::find_matching_css_brace($css, $brace_pos);
        if ($brace_end === -1) {
            return null;
        }

        return [
            'type' => 'at-rule',
            'selector' => $rule_name,
            'content' => substr($css, $start, $brace_end - $start + 1),
            'end' => $brace_end + 1,
        ];
    }

    /**
     * 解析普通 CSS 规则
     *
     * @param string $css CSS 内容
     * @param int $start 开始位置
     * @return array<string, mixed>|null
     */
    private static function parse_css_standard_rule($css, $start)
    {
        $brace_pos = strpos($css, '{', $start);
        if ($brace_pos === false) {
            return null;
        }

        $selector = trim(substr($css, $start, $brace_pos - $start));
        if ($selector === '') {
            return null;
        }

        $brace_end = self::find_matching_css_brace($css, $brace_pos);
        if ($brace_end === -1) {
            return null;
        }

        return [
            'type' => 'rule',
            'selector' => $selector,
            'content' => substr($css, $start, $brace_end - $start + 1),
            'end' => $brace_end + 1,
        ];
    }

    /**
     * 查找 CSS 规则的匹配右花括号
     *
     * @param string $css CSS 内容
     * @param int $start 左花括号位置
     * @return int
     */
    private static function find_matching_css_brace($css, $start)
    {
        $brace_count = 0;
        $in_string = false;
        $string_char = '';
        $escaped = false;
        $length = strlen($css);

        for ($i = $start; $i < $length; $i++) {
            $char = $css[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if (!$in_string && ($char === '"' || $char === "'")) {
                $in_string = true;
                $string_char = $char;
                continue;
            }

            if ($in_string && $char === $string_char) {
                $in_string = false;
                $string_char = '';
                continue;
            }

            if ($in_string) {
                continue;
            }

            if ($char === '{') {
                $brace_count++;
            } elseif ($char === '}') {
                $brace_count--;
                if ($brace_count === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * 判断是否属于全局 CSS 规则
     *
     * @param array $rule CSS 规则
     * @return bool
     */
    private static function is_global_css_rule($rule)
    {
        if (empty($rule['selector'])) {
            return false;
        }

        if ($rule['type'] === 'at-rule') {
            return stripos($rule['selector'], '@import') !== 0;
        }

        $selectors = array_filter(array_map('trim', explode(',', $rule['selector'])));
        if (empty($selectors)) {
            return false;
        }

        foreach ($selectors as $selector) {
            if (strpos($selector, '.') !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 处理设计系统更新
     * 
     * @param array $settings 当前设置
     * @return array 更新后的设置
     */
    public static function process_design_system($settings)
    {
        $design_system_html = $settings['design_system_html'] ?? '';
        
        if (!empty($design_system_html)) {
            // 提取 Tailwind 配置
            $tailwind_config = self::extract_tailwind_config($design_system_html);
            if ($tailwind_config !== null) {
                $settings['extracted_tailwind_config'] = $tailwind_config;
            } else {
                $settings['extracted_tailwind_config'] = '';
            }

            // 提取自定义 CSS
            $custom_css = self::extract_custom_css($design_system_html);
            $settings['extracted_custom_css'] = $custom_css;
            $settings['extracted_global_custom_css'] = self::extract_global_custom_css($custom_css);

            
            // 提取字体信息
            $font_imports = self::extract_font_imports($design_system_html);
            $settings['extracted_font_imports'] = $font_imports;
        } else {
            // 清空提取的内容
            $settings['extracted_tailwind_config'] = '';
            $settings['extracted_custom_css'] = '';
            $settings['extracted_global_custom_css'] = '';
            $settings['extracted_font_imports'] = [];
        }

        return $settings;
    }
}
