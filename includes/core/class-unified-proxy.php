<?php
if (!defined('ABSPATH')) { exit; }

class WordExpress_Unified_Proxy
{
    private static $instance = null;
    private $proxies = [];
    private $global_middleware = [];

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $config_file = WORDEXPRESS_PATH . 'includes/config/proxy-config.php';
        if (file_exists($config_file)) {
            $config = require $config_file;
            $this->proxies = $this->process_config($config);
        }
        $this->proxies = apply_filters('wordexpress_proxy_config', $this->proxies);
    }

    private function process_config($config)
    {
        $global = $config['_global'] ?? [];
        unset($config['_global']);
        $processed = [];
        foreach ($config as $name => $cfg) {
            if (!isset($cfg['base_url']) && isset($global['base_urls'])) {
                $env = defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production';
                $base_url = $global['base_urls'];
                $cfg['base_url'] = is_array($base_url) ? ($base_url[$env] ?? $base_url['production']) : $base_url;
            }
            $processed[$name] = $cfg;
        }
        return $processed;
    }

    public function request($proxy_name, $endpoint, $args = [])
    {
        if (!isset($this->proxies[$proxy_name])) {
            return new WP_Error('invalid_proxy', __('Invalid proxy service', 'wordexpress'));
        }
        $proxy_config = $this->proxies[$proxy_name];
        $endpoint_config = $proxy_config['endpoints'][$endpoint] ?? null;
        if (!$endpoint_config) {
            return new WP_Error('invalid_endpoint', __('Invalid endpoint', 'wordexpress'));
        }

        $base_url = $proxy_config['base_url'];
        $path = str_replace('{id}', urlencode($args['path_params']['id'] ?? ''), $endpoint_config['path']);
        $url = rtrim($base_url, '/') . '/' . ltrim($path, '/');

        $request = [
            'method' => $endpoint_config['method'] ?? 'GET',
            'headers' => $endpoint_config['headers'] ?? [],
            'timeout' => $endpoint_config['timeout'] ?? 30,
        ];
        if (isset($args['data'])) {
            $request['body'] = json_encode($args['data']);
            $request['headers']['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request($url, $request);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        return [
            'success' => $code >= 200 && $code < 300,
            'code' => $code,
            'data' => $data ?: $body,
        ];
    }
}
