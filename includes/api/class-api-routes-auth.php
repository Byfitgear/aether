<?php
defined('ABSPATH') || exit;

class WordExpress_API_Routes_Auth extends WordExpress_API_Routes_Base
{
    public function register_routes()
    {
        $this->register_route('/auth/nonce', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_rest_nonce'],
            'permission_callback' => [WordExpress_Permission_Service::class, 'check_rest_permission'],
        ]);
    }

    public function get_rest_nonce($request)
    {
        return $this->success_response(['nonce' => wp_create_nonce('wp_rest')]);
    }
}
