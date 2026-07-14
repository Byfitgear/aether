<?php
if (!defined('ABSPATH')) { exit; }

class WordExpress_Settings_Service
{
    const OPTION_NAME = 'wordexpress_settings';

    public static function get_all()
    {
        $settings = get_option(self::OPTION_NAME, []);
        return wp_parse_args($settings, self::get_defaults());
    }

    public static function get($key, $default = null)
    {
        $settings = self::get_all();
        if (isset($settings[$key])) {
            return $settings[$key];
        }
        if ($default !== null) return $default;
        $defaults = self::get_defaults();
        return $defaults[$key] ?? null;
    }

    public static function save($settings)
    {
        $current = self::get_all();
        // Simple validation: ensure enabled is boolean
        if (isset($settings['enabled'])) {
            $settings['enabled'] = (bool) $settings['enabled'];
        }
        if ($settings == $current) {
            return 'unchanged';
        }
        $result = update_option(self::OPTION_NAME, $settings);
        return $result;
    }

    public static function reset()
    {
        $result = update_option(self::OPTION_NAME, self::get_defaults());
        return $result;
    }

    public static function get_defaults()
    {
        return [
            'enabled' => true,
            'api_token' => '',
            'api_key' => '',
            'base_url' => '',
            'post_types' => [],
            'contact_form_title' => '联系我们',
            'contact_form_email' => get_option('admin_email'),
            'contact_form_success_message' => '感谢您的留言，我们会尽快回复您！',
            'contact_form_fields' => [
                ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => '邮箱', 'type' => 'email', 'required' => true],
                ['name' => 'phone', 'label' => '电话', 'type' => 'tel', 'required' => false],
                ['name' => 'subject', 'label' => '主题', 'type' => 'text', 'required' => true],
                ['name' => 'message', 'label' => '留言内容', 'type' => 'textarea', 'required' => true],
            ],
        ];
    }

    public static function is_configured()
    {
        $settings = self::get_all();
        return !empty($settings['api_token']);
    }

    public static function is_enabled_for_post_type($post_type)
    {
        $enabled_types = self::get('post_types', []);
        return in_array($post_type, $enabled_types);
    }

    public static function get_localized_data()
    {
        $post_types = get_post_types(['public' => true], 'objects');
        $result = [];
        foreach ($post_types as $post_type) {
            if (is_object($post_type) && $post_type->name === 'attachment') continue;
            if (is_object($post_type)) {
                $result[$post_type->name] = $post_type->label;
            }
        }
        return [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wordexpress-settings'),
            'settings' => self::get_all(),
            'post_types' => $result,
            'messages' => [
                'save_success' => __('设置已保存', 'wordexpress'),
                'save_error' => __('保存失败，请重试', 'wordexpress'),
                'save_unchanged' => __('设置未发生变化', 'wordexpress'),
                'reset_success' => __('设置已重置为默认值', 'wordexpress'),
                'reset_error' => __('重置失败，请重试', 'wordexpress'),
                'reset_confirm' => __('确定要重置所有设置为默认值吗？', 'wordexpress'),
            ]
        ];
    }

    public static function get_available_post_types()
    {
        $post_types = get_post_types(['public' => true], 'objects');
        $excluded_types = ['attachment', 'wordexpress_template'];
        $available = [];
        foreach ($post_types as $pt) {
            if (is_object($pt) && !in_array($pt->name, $excluded_types)) {
                $available[] = ['name' => $pt->name, 'label' => $pt->label];
            }
        }
        return $available;
    }
}
