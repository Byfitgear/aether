<?php
defined('ABSPATH') || exit;

abstract class WordExpress_API_Routes_Base
{
    const NAMESPACE = 'wordexpress/v1';

    abstract public function register_routes();

    protected function get_namespace()
    {
        return self::NAMESPACE;
    }

    protected function register_route($route, $args)
    {
        return register_rest_route($this->get_namespace(), $route, $args);
    }

    protected function success_response($data = null, $message = '', $status = 200)
    {
        $response = ['success' => true];
        if ($data !== null) $response['data'] = $data;
        if (!empty($message)) $response['message'] = $message;
        return new WP_REST_Response($response, $status);
    }

    protected function error_response($message, $status = 400, $data = null)
    {
        $response = ['success' => false, 'message' => $message];
        if ($data !== null) $response['data'] = $data;
        return new WP_REST_Response($response, $status);
    }

    public function validate_numeric($param)
    {
        return is_numeric($param);
    }

    public function check_permission()
    {
        return current_user_can('edit_posts');
    }
}
