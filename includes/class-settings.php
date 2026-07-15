<?php
/**
 * aether 设置页面类
 * 
 * 处理设置页面的UI和AJAX请求
 *
 * @package aether
 */

defined('ABSPATH') || exit;

// Dependent classes loaded via autoloader

/**
 * 设置类
 */
class Aether_Settings extends Aether_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX 处理
        add_action('wp_ajax_aether_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_aether_reset_settings', [$this, 'ajax_reset_settings']);
    }
    
    /**
     * 渲染设置页面
     */
    public function render_settings_page() {
        $this->load_template('settings-page', [
            'settings' => Aether_Settings_Service::get_all(),
            'post_types' => get_post_types(['public' => true], 'objects')
        ]);
    }
    
    /**
     * 加载资源
     * 
     * @param string $hook 当前页面钩子
     */
    public function enqueue_assets($hook) {
        if ('toplevel_page_aether' !== $hook) {
            return;
        }
        
        // Use Vite loader
        $vite_loader = Aether_Vite_Loader::getInstance();
        $vite_loader->enqueue('settings');
    }
    
    /**
     * AJAX 保存设置
     */
    public function ajax_save_settings() {
        check_ajax_referer('aether-settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'aether')]);
        }
        
        $settings = $_POST['settings'] ?? [];
        $result = Aether_Settings_Service::save($settings);
        
        if ($result === true) {
            wp_send_json_success([
                'message' => __('设置已保存', 'aether'),
                'settings' => Aether_Settings_Service::get_all()
            ]);
        } elseif ($result === 'unchanged') {
            wp_send_json_success([
                'message' => __('设置未发生变化', 'aether'),
                'unchanged' => true
            ]);
        } else {
            wp_send_json_error(['message' => __('保存失败，请重试', 'aether')]);
        }
    }
    
    /**
     * AJAX 重置设置
     */
    public function ajax_reset_settings() {
        check_ajax_referer('aether-settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'aether')]);
        }
        
        if (Aether_Settings_Service::reset()) {
            wp_send_json_success([
                'message' => __('设置已重置为默认值', 'aether'),
                'settings' => Aether_Settings_Service::get_all()
            ]);
        } else {
            wp_send_json_error(['message' => __('重置失败，请重试', 'aether')]);
        }
    }
}