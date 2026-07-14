<?php
if (!defined('ABSPATH')) { exit; }

class WordExpress_Unified_Proxy_Routes
{
    private static $instance = null;
    private $proxy;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->proxy = WordExpress_Unified_Proxy::get_instance();
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        $config = $this->proxy->get_proxy_config();
        foreach ($config as $name => $cfg) {
            if (isset($cfg['endpoints'])) {
                foreach ($cfg['endpoints'] as $ep_name => $ep_cfg) {
                    $route = sprintf('proxy/%s/%s', $name, $ep_name);
                    if (strpos($ep_cfg['path'], '{') !== false) {
                        preg_match_all('/\{([^}]+)\}/', $ep_cfg['path'], $matches);
                        foreach ($matches[1] as $param) {
                            $route .= '/(?P<' . $param . '>[^/]+)';
                        }
                    }
                    register_rest_route('wordexpress/v1', $route, [
                        'methods' => $ep_cfg['method'] ?? 'GET',
                        'callback' => [$this, 'handle_proxy_request'],
                        'permission_callback' => [WordExpress_Permission_Service::class, 'check_rest_permission'],
                    ]);
                }
            }
        }
    }

    public function handle_proxy_request($request)
    {
        $proxy_name = $request->get_param('_proxy');
        $endpoint_name = $request->get_param('_endpoint');
        $args = [
            'params' => $request->get_query_params(),
            'data' => $request->get_json_params() ?? $request->get_body_params(),
            'path_params' => [],
        ];
        unset($args['params']['_proxy'], $args['params']['_endpoint']);
        $route_params = $request->get_url_params();
        foreach ($route_params as $key => $value) {
            if (!in_array($key, ['_proxy', '_endpoint'])) {
                $args['path_params'][$key] = $value;
            }
        }
        $response = $this->proxy->request($proxy_name, $endpoint_name, $args);
        if (is_wp_error($response)) {
            return new WP_REST_Response(['success' => false, 'error' => $response->get_error_message()], 400);
        }
        return new WP_REST_Response($response['data'], $response['code']);
    }
}
