<?php
defined('ABSPATH') || exit;

class Aether_API_Routes_Settings extends Aether_API_Routes_Base
{
    public function register_routes()
    {
        $this->register_route('/settings', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_settings'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
        ]);
        $this->register_route('/settings', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'save_settings'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
        ]);
    }

    public function get_settings($request)
    {
        return $this->success_response(Aether_Settings_Service::get_all());
    }

    public function save_settings($request)
    {
        $settings = $request->get_json_params();
        $result = Aether_Settings_Service::save($settings);
        if ($result === true) {
            return $this->success_response(Aether_Settings_Service::get_all(), __('设置已保存', 'aether'));
        } elseif ($result === 'unchanged') {
            return $this->success_response([], __('设置未发生变化', 'aether'));
        }
        return $this->error_response(__('保存失败', 'aether'), 500);
    }
}
