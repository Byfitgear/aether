<?php
/**
 * Settings Validator Service
 * 
 * 负责验证插件设置
 * 
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Settings_Validator
{

    /**
     * 默认设置
     */
    private static $defaults = [
        'enabled' => true,
        'base_url' => '',
        'api_key' => '',
        'model' => '',
        'post_types' => ['page'],
        'css_compiler_api_url' => '',
        'production_mode' => false,
        'api_token' => '',
        'excluded_templates' => ['page-landing.php', 'template-landing.php', 'landing-page.php', 'page-blank.php', 'template-blank.php', 'blank-page.php'],
        'additional_context' => '',
        'design_system_html' => '',
        'extracted_tailwind_config' => '',
        'extracted_custom_css' => '',
        'extracted_global_custom_css' => '',
        // Submissions feature group
        'submissions' => [
            // Webhook 通知
            'webhook_enabled' => false,
            'webhook_url' => '',
            'webhook_secret' => '',
            'webhook_secret_key' => '',
            // Server酱配置
            'serverchan_enabled' => false,
            'serverchan_sendkey' => '',
            'thankyou_url' => '',
            // Sales email via wp_mail
            'email_enabled' => false,
            'email_to' => '',
            'email_subject' => '',
        ],
    ];

    /**
     * 获取默认设置
     * 
     * @return array
     */
    public static function get_defaults()
    {
        return self::$defaults;
    }

    /**
     * 验证设置
     *
     * @param array $settings 待验证的设置
     * @return array 验证后的设置
     */
    public static function validate($settings)
    {
        // 获取当前值作为基础，支持部分更新
        $current = get_option('aether_settings', []);
        $current = wp_parse_args($current, self::$defaults);

        $validated = [];

        // 启用状态
        $validated['enabled'] = !empty($settings['enabled']);

        // Base URL - 第三方AI服务端点
        // 如果前端未发送该字段，保留当前值（避免覆盖已有凭据）
        $validated['base_url'] = isset($settings['base_url'])
            ? esc_url_raw($settings['base_url'])
            : ($current['base_url'] ?? '');

        // API Key
        // 如果前端未发送该字段，保留当前值（避免覆盖已有凭据）
        $validated['api_key'] = isset($settings['api_key'])
            ? sanitize_text_field($settings['api_key'])
            : ($current['api_key'] ?? '');

        // 模型
        $validated['model'] = self::validate_model($settings['model'] ?? '');

        // 文章类型
        $validated['post_types'] = self::validate_post_types($settings['post_types'] ?? []);

        // CSS 编译器 API URL
        $validated['css_compiler_api_url'] = esc_url_raw($settings['css_compiler_api_url'] ?? '');

        // 正式上线模式
        $validated['production_mode'] = !empty($settings['production_mode']);

        // API Token
        $validated['api_token'] = sanitize_text_field($settings['api_token'] ?? '');

        // 排除页眉页脚的模板
        $validated['excluded_templates'] = self::validate_excluded_templates($settings['excluded_templates'] ?? []);

        // 补充上下文（最多10000字符）
        $additional_context = $settings['additional_context'] ?? '';
        if (is_string($additional_context)) {
            $validated['additional_context'] = mb_substr(sanitize_textarea_field($additional_context), 0, 10000);
        } else {
            $validated['additional_context'] = '';
        }

        // 设计系统 HTML
        $design_system_html = $settings['design_system_html'] ?? '';
        if (is_string($design_system_html)) {
            // 保留原始 HTML，不进行过滤（因为这是开发者工具）
            $validated['design_system_html'] = $design_system_html;
        } else {
            $validated['design_system_html'] = '';
        }

        // 提取的 Tailwind 配置
        $extracted_tailwind_config = $settings['extracted_tailwind_config'] ?? '';
        if (is_string($extracted_tailwind_config)) {
            $validated['extracted_tailwind_config'] = $extracted_tailwind_config;
        } else {
            $validated['extracted_tailwind_config'] = '';
        }

        // 提取的自定义 CSS
        $extracted_custom_css = $settings['extracted_custom_css'] ?? '';
        if (is_string($extracted_custom_css)) {
            $validated['extracted_custom_css'] = $extracted_custom_css;
        } else {
            $validated['extracted_custom_css'] = '';
        }

        // 提取的全局自定义 CSS
        $extracted_global_custom_css = $settings['extracted_global_custom_css'] ?? '';
        if (is_string($extracted_global_custom_css)) {
            $validated['extracted_global_custom_css'] = $extracted_global_custom_css;
        } else {
            $validated['extracted_global_custom_css'] = '';
        }

        // Submissions settings group
        $validated['submissions'] = self::validate_submissions_settings($settings['submissions'] ?? []);

        
        return $validated;
    }

    private static function validate_submissions_settings($input)
    {
        $defaults = self::$defaults['submissions'];
        if (!is_array($input)) {
            return $defaults;
        }

        $out = [];
        // Webhook 通知
        $out['webhook_enabled'] = !empty($input['webhook_enabled']);
        $out['webhook_url'] = esc_url_raw($input['webhook_url'] ?? '');
        $out['webhook_secret'] = sanitize_text_field($input['webhook_secret'] ?? '');
        $out['webhook_secret_key'] = sanitize_text_field($input['webhook_secret_key'] ?? '');
        // Server酱
        $out['serverchan_enabled'] = !empty($input['serverchan_enabled']);
        $out['serverchan_sendkey'] = sanitize_text_field($input['serverchan_sendkey'] ?? '');
        $out['thankyou_url'] = esc_url_raw($input['thankyou_url'] ?? '');

        // Sales email via wp_mail
        $out['email_enabled'] = !empty($input['email_enabled']);
        // Keep original string for UI; split & validate at send time
        $email_to_raw = $input['email_to'] ?? '';
        if (is_array($email_to_raw)) {
            $email_to_raw = implode(',', array_map('strval', $email_to_raw));
        }
        $out['email_to'] = is_string($email_to_raw) ? trim($email_to_raw) : '';
        $out['email_subject'] = sanitize_text_field($input['email_subject'] ?? '');

        return wp_parse_args($out, $defaults);
    }

    /**
     * 验证模型名称
     * 
     * @param string $model 模型名称
     * @return string 验证后的模型名称
     */
    private static function validate_model($model)
    {
        $model = sanitize_text_field($model);

        // 允许任何模型名称，包括空值
        return $model;
    }

    /**
     * 验证文章类型
     * 
     * @param array $post_types 文章类型数组
     * @return array 验证后的文章类型
     */
    private static function validate_post_types($post_types)
    {
        if (!is_array($post_types)) {
            return [];
        }

        $valid_types = get_post_types(['public' => true]);
        $validated = [];

        foreach ($post_types as $type) {
            if (in_array($type, $valid_types)) {
                $validated[] = $type;
            }
        }

        return $validated;
    }

    /**
     * 验证排除模板
     * 
     * @param array $templates 模板数组
     * @return array 验证后的模板
     */
    private static function validate_excluded_templates($templates)
    {
        if (!is_array($templates)) {
            return [];
        }

        // 过滤并清理每个模板名称
        $validated = array_filter(array_map('sanitize_file_name', $templates), function ($template) {
            // 确保是有效的模板文件名（以.php结尾）
            return !empty($template) && preg_match('/\.php$/i', $template);
        });

        return array_values($validated); // 重置键
    }
}
