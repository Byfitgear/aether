<?php
defined('ABSPATH') || exit;

class WordExpress_Settings extends WordExpress_Base
{
    protected function init()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wordexpress_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_wordexpress_reset_settings', [$this, 'ajax_reset_settings']);
    }

    public static function render_settings_page()
    {
        $settings = WordExpress_Settings_Service::get_all();
        $post_types = get_post_types(['public' => true], 'objects');
        include WORDEXPRESS_PATH . 'templates/settings-page.php';
    }

    public function enqueue_assets($hook)
    {
        if ('toplevel_page_wordexpress' !== $hook && 'wordexpress_page_wordexpress-editor' !== $hook) {
            return;
        }
        wp_enqueue_style(
            'wordexpress-settings-css',
            WORDEXPRESS_URL . 'assets/settings.css',
            [],
            WORDEXPRESS_VERSION
        );
        wp_enqueue_script(
            'wordexpress-settings-js',
            WORDEXPRESS_URL . 'assets/settings.js',
            ['jquery'],
            WORDEXPRESS_VERSION,
            true
        );
        wp_localize_script('wordexpress-settings-js', 'wordexpressSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wordexpress-settings'),
            'version' => WORDEXPRESS_VERSION,
        ]);
    }

    public function ajax_save_settings()
    {
        check_ajax_referer('wordexpress-settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'wordexpress')]);
        }
        $settings = $_POST['settings'] ?? [];
        $result = WordExpress_Settings_Service::save($settings);
        if ($result === true) {
            wp_send_json_success([
                'message' => __('设置已保存', 'wordexpress'),
                'settings' => WordExpress_Settings_Service::get_all(),
            ]);
        } elseif ($result === 'unchanged') {
            wp_send_json_success([
                'message' => __('设置未发生变化', 'wordexpress'),
                'unchanged' => true,
            ]);
        } else {
            wp_send_json_error(['message' => __('保存失败，请重试', 'wordexpress')]);
        }
    }

    public function ajax_reset_settings()
    {
        check_ajax_referer('wordexpress-settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'wordexpress')]);
        }
        if (WordExpress_Settings_Service::reset()) {
            wp_send_json_success([
                'message' => __('设置已重置为默认值', 'wordexpress'),
                'settings' => WordExpress_Settings_Service::get_all(),
            ]);
        } else {
            wp_send_json_error(['message' => __('重置失败，请重试', 'wordexpress')]);
        }
    }
}
