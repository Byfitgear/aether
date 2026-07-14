<?php
defined('ABSPATH') || exit;

class Aether_Settings extends Aether_Base
{
    protected function init()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_aether_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_aether_reset_settings', [$this, 'ajax_reset_settings']);
    }

    public static function render_settings_page()
    {
        $settings = Aether_Settings_Service::get_all();
        $post_types = get_post_types(['public' => true], 'objects');
        include AETHER_PATH . 'templates/settings-page.php';
    }

    public function enqueue_assets($hook)
    {
        if ('toplevel_page_aether' !== $hook && 'aether_page_aether-editor' !== $hook) {
            return;
        }
        wp_enqueue_style(
            'aether-settings-css',
            AETHER_URL . 'assets/settings.css',
            [],
            AETHER_VERSION
        );
        wp_enqueue_script(
            'aether-settings-js',
            AETHER_URL . 'assets/settings.js',
            ['jquery'],
            AETHER_VERSION,
            true
        );
        wp_localize_script('aether-settings-js', 'aetherSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('aether-settings'),
            'version' => AETHER_VERSION,
        ]);
    }

    public function ajax_save_settings()
    {
        check_ajax_referer('aether-settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'aether')]);
        }
        $settings = $_POST['settings'] ?? [];
        $allowed_keys = array_keys(Aether_Settings_Service::get_defaults());
        $sanitized = [];
        foreach ($settings as $key => $value) {
            if (in_array($key, $allowed_keys, true)) {
                if (is_array($value)) {
                    $sanitized[$key] = array_map(function($v) { return is_string($v) ? sanitize_text_field($v) : (bool)$v; }, $value);
                } else {
                    $sanitized[$key] = is_string($value) ? sanitize_text_field($value) : (bool)$value;
                }
            }
        }
        $settings = $sanitized;
        $result = Aether_Settings_Service::save($settings);
        if ($result === true) {
            wp_send_json_success([
                'message' => __('设置已保存', 'aether'),
                'settings' => Aether_Settings_Service::get_all(),
            ]);
        } elseif ($result === 'unchanged') {
            wp_send_json_success([
                'message' => __('设置未发生变化', 'aether'),
                'unchanged' => true,
            ]);
        } else {
            wp_send_json_error(['message' => __('保存失败，请重试', 'aether')]);
        }
    }

    public function ajax_reset_settings()
    {
        check_ajax_referer('aether-settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'aether')]);
        }
        if (Aether_Settings_Service::reset()) {
            wp_send_json_success([
                'message' => __('设置已重置为默认值', 'aether'),
                'settings' => Aether_Settings_Service::get_all(),
            ]);
        } else {
            wp_send_json_error(['message' => __('重置失败，请重试', 'aether')]);
        }
    }
}
