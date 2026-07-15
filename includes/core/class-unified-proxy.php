<?php
/**
 * Unified Proxy System
 *
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Unified proxy class for handling all external API requests
 */
class Aether_Unified_Proxy {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Registered proxies configuration
     */
    private $proxies = [];
    
    /**
     * Global middleware stack
     */
    private $global_middleware = [];
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_config();
        $this->register_default_middleware();
    }
    
    /**
     * Load proxy configuration
     */
    private function load_config() {
        $config_file = AETHER_PATH . 'includes/config/proxy-config.php';
        if (file_exists($config_file)) {
            $config = require $config_file;
            $this->proxies = $this->process_config($config);
        }
        
        // Allow filtering of proxy configuration
        $this->proxies = apply_filters('aether_proxy_config', $this->proxies);
    }
    
    /**
     * Process configuration with inheritance
     */
    private function process_config($config) {
        $global = $config['_global'] ?? [];
        unset($config['_global']);
        
        $processed = [];
        
        foreach ($config as $service_name => $service_config) {
            // Apply global base URLs if not set
            if (!isset($service_config['base_url']) && isset($global['base_urls'])) {
                $service_config['base_url'] = $global['base_urls'];
            }
            
            // Process endpoints with inheritance
            if (isset($service_config['endpoints'])) {
                foreach ($service_config['endpoints'] as $endpoint_name => &$endpoint_config) {
                    // Set endpoint name if not explicitly set
                    if (!isset($endpoint_config['name'])) {
                        $endpoint_config['name'] = $endpoint_name;
                    }
                    
                    // Apply service defaults
                    if (isset($service_config['defaults'])) {
                        $endpoint_config = array_merge($service_config['defaults'], $endpoint_config);
                    }
                    
                    // Apply global defaults
                    if (!isset($endpoint_config['auth']) && isset($global['default_auth'])) {
                        $endpoint_config['auth'] = $global['default_auth'];
                    }
                    if (!isset($endpoint_config['headers']) && isset($global['default_headers'])) {
                        $endpoint_config['headers'] = $global['default_headers'];
                    }
                    if (!isset($endpoint_config['timeout']) && isset($global['default_timeout'])) {
                        $endpoint_config['timeout'] = $global['default_timeout'];
                    }
                    
                    // Deep merge cache settings if both exist
                    if (isset($service_config['defaults']['cache']) && isset($endpoint_config['cache'])) {
                        $endpoint_config['cache'] = array_merge(
                            $service_config['defaults']['cache'],
                            $endpoint_config['cache']
                        );
                    }
                }
            }
            
            // Store global config reference for middleware
            $service_config['_global'] = $global;
            
            $processed[$service_name] = $service_config;
        }
        
        return $processed;
    }
    
    /**
     * Register default middleware
     */
    private function register_default_middleware() {
        // Auth middleware - always first
        $this->add_global_middleware(new Aether_Proxy_Auth_Middleware());
        
        // Cache middleware
        $this->add_global_middleware(new Aether_Proxy_Cache_Middleware());
        
        // Log middleware
        if (defined('AETHER_DEBUG') && constant('AETHER_DEBUG') && !defined('AETHER_DISABLE_PROXY_LOG')) {
            $this->add_global_middleware(new Aether_Proxy_Log_Middleware());
        }
    }
    
    /**
     * Add global middleware
     */
    public function add_global_middleware($middleware) {
        $this->global_middleware[] = $middleware;
    }
    
    /**
     * Make a proxy request
     *
     * @param string $proxy_name Proxy service name
     * @param string $endpoint Endpoint name
     * @param array $args Request arguments
     * @return array|WP_Error
     */
    public function request($proxy_name, $endpoint, $args = []) {
        if (!isset($this->proxies[$proxy_name])) {
            return new WP_Error('invalid_proxy', __('Invalid proxy service', 'aether'));
        }
        
        if (!isset($this->proxies[$proxy_name]['endpoints'][$endpoint])) {
            return new WP_Error('invalid_endpoint', __('Invalid endpoint', 'aether'));
        }
        
        $proxy_config = $this->proxies[$proxy_name];
        $endpoint_config = $proxy_config['endpoints'][$endpoint];
        
        // Build request
        $request = $this->build_request($proxy_config, $endpoint_config, $args);
        
        // Execute middleware chain
        $middleware_chain = $this->build_middleware_chain($endpoint_config);
        
        $response = $this->execute_middleware($request, $middleware_chain);
        
        return $response;
    }
    
    /**
     * 从 URL 参数获取环境配置
     *
     * @return string 'development', 'staging', 或 'production'
     */
    private function get_environment_from_url() {
        // 优先从当前请求的 referer 中读取
        $referer = wp_get_referer();
        if ($referer && strpos($referer, 'dev=') !== false) {
            if (strpos($referer, 'dev=1') !== false) {
                return 'development';
            }
            if (strpos($referer, 'dev=2') !== false) {
                return 'staging';
            }
        }

        // 其次从当前请求 URL 中读取
        if (isset($_GET['dev'])) {
            if ($_GET['dev'] === '1') {
                return 'development';
            }
            if ($_GET['dev'] === '2') {
                return 'staging';
            }
        }

        // 最后检查 AETHER_DEV_MODE 常量（向后兼容）
        if (defined('AETHER_DEV_MODE') && constant('AETHER_DEV_MODE')) {
            return 'development';
        }

        return 'production';
    }

    /**
     * Build request array
     */
    private function build_request($proxy_config, $endpoint_config, $args) {
        // Determine base URL
        $base_url = $proxy_config['base_url'];

        // Handle dynamic base URL from settings
        if (is_string($base_url) && strpos($base_url, 'settings:') === 0) {
            $setting_key = substr($base_url, 9);
            $base_url = get_option($setting_key, '');
        } elseif (is_array($base_url)) {
            // 从 URL 参数读取环境配置 (dev=1 本地, dev=2 staging, 默认 production)
            $env = $this->get_environment_from_url();
            $base_url = $base_url[$env] ?? $base_url['production'];

        }

        // Build full URL
        $path = $endpoint_config['path'];

        // Replace path parameters
        if (isset($args['path_params'])) {
            foreach ($args['path_params'] as $key => $value) {
                $path = str_replace('{' . $key . '}', urlencode($value), $path);
            }
        }

        $url = rtrim($base_url, '/') . '/' . ltrim($path, '/');


        // Add query parameters for GET requests
        if (isset($args['params']) && $endpoint_config['method'] === 'GET') {
            $url = add_query_arg($args['params'], $url);
        }

        // Build request array
        $request = [
            'url' => $url,
            'method' => $endpoint_config['method'],
            'headers' => $endpoint_config['headers'] ?? [],
            'timeout' => $endpoint_config['timeout'] ?? 30,
            'proxy_config' => $proxy_config,
            'endpoint_config' => $endpoint_config,
            'global_config' => $proxy_config['_global'] ?? [],
            'args' => $args,
            'environment' => $env ?? 'production' // 记录使用的环境
        ];
        
        // Add body for non-GET requests
        if ($endpoint_config['method'] !== 'GET' && isset($args['data'])) {
            $request['body'] = json_encode($args['data']);
            $request['headers']['Content-Type'] = 'application/json';
            
        }
        
        return $request;
    }
    
    /**
     * Build middleware chain for endpoint
     */
    private function build_middleware_chain($endpoint_config) {
        $middleware = $this->global_middleware;
        
        // Add endpoint-specific middleware
        if (isset($endpoint_config['middleware'])) {
            foreach ($endpoint_config['middleware'] as $mw_class) {
                if (class_exists($mw_class)) {
                    $middleware[] = new $mw_class();
                }
            }
        }
        
        return $middleware;
    }
    
    /**
     * Execute middleware chain
     */
    private function execute_middleware($request, $middleware) {
        $next = function($request) {
            return $this->send_request($request);
        };
        
        // Build middleware chain in reverse order
        foreach (array_reverse($middleware) as $mw) {
            $next = function($request) use ($mw, $next) {
                return $mw->process($request, $next);
            };
        }
        
        return $next($request);
    }
    
    /**
     * Send actual HTTP request
     */
    private function send_request($request) {
        $args = [
            'method' => $request['method'],
            'headers' => $request['headers'],
            'timeout' => $request['timeout'],
            'sslverify' => apply_filters('aether_proxy_verify_ssl', true)
        ];
        
        if (isset($request['body'])) {
            $args['body'] = $request['body'];
        }
        
        
        // Regular request
        $response = wp_remote_request($request['url'], $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Try to decode JSON
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Check if response is HTML
            if (stripos($body, '<!doctype') !== false || stripos($body, '<html') !== false) {
                return [
                    'success' => false,
                    'code' => $code,
                    'data' => [
                        'error' => 'Service returned HTML instead of JSON. The service may be unavailable or the URL may be incorrect.',
                        'message' => '服务返回了网页而不是预期的数据，请检查 API 端点配置是否正确'
                    ],
                    'headers' => wp_remote_retrieve_headers($response)
                ];
            }
            // For other non-JSON responses, return a generic error
            return [
                'success' => false,
                'code' => $code,
                'data' => [
                    'error' => 'Invalid JSON response',
                    'message' => '服务返回的数据格式不正确'
                ],
                'headers' => wp_remote_retrieve_headers($response)
            ];
        }
        
        return [
            'success' => $code >= 200 && $code < 300,
            'code' => $code,
            'data' => $data,
            'headers' => wp_remote_retrieve_headers($response)
        ];
    }
    
    
    /**
     * Get proxy configuration
     */
    public function get_proxy_config($proxy_name = null) {
        if ($proxy_name) {
            return $this->proxies[$proxy_name] ?? null;
        }
        return $this->proxies;
    }
}