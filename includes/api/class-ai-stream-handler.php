<?php
/**
 * AI Stream Handler
 * 
 * Handles streaming responses for AI assistant
 *
 * @package aether
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_AI_Stream_Handler {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Response buffer for error handling
     */
    private $response_buffer = '';
    
    /**
     * Response HTTP code
     */
    private $response_http_code = 0;
    
    /**
     * Empty response detected flag
     */
    private $empty_response_detected = false;
    
    /**
     * Get instance
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
        add_action('wp_ajax_aether_ai_assistant', [$this, 'handle_stream_request']);
    }
    
    /**
     * Handle stream request
     */
    public function handle_stream_request() {
        // Get nonce from header or POST data
        // Headers are transformed: X-WP-Nonce becomes HTTP_X_WP_NONCE
        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? $_POST['_wpnonce'] ?? '';
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'aether-editor')) {
            wp_die('Invalid nonce', 403);
        }
        
        // Check permission
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized', 403);
        }
        
        // Get request data
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            wp_die('Invalid request data', 400);
        }
        
        // Disable output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set longer execution time for streaming
        set_time_limit(600); // 10 minutes
        
        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable Nginx buffering
        
        // Stream the response
        $this->stream_ai_response($data);
        
        exit;
    }
    
    /**
     * Stream AI response
     */
    private function stream_ai_response($request_data) {
        // Check if proxy_service and proxy_endpoint are provided
        $proxy_service = $request_data['proxy_service'] ?? 'ai';
        $proxy_endpoint = $request_data['proxy_endpoint'] ?? 'assistant';
        
        // Add design system HTML and additional context to the request if available (only for assistant endpoint)
        if ($proxy_endpoint === 'assistant') {
            $settings = Aether_Settings_Service::get_all();
            
            // Add design system HTML
            if (!empty($request_data['request']['design_system_html'])) {
                // The design system HTML was passed from frontend
                // It will be used by the AI to maintain design consistency
            } else {
                // If not passed from frontend, get it from settings
                if (!empty($settings['design_system_html'])) {
                    $request_data['request']['design_system_html'] = $settings['design_system_html'];
                }
            }
            
            // Add additional context
            if (!empty($settings['additional_context'])) {
                $request_data['request']['additional_context'] = $settings['additional_context'];
            }
        }
        
        // Use the unified proxy to get proper configuration
        $proxy = Aether_Unified_Proxy::get_instance();
        
        // Load and process config to get auth settings
        $config_file = AETHER_PATH . 'includes/config/proxy-config.php';
        $config = require $config_file;
        $processed_config = $this->process_config_with_inheritance($config);
        
        $proxy_config = $processed_config[$proxy_service] ?? null;
        $endpoint_config = $proxy_config['endpoints'][$proxy_endpoint] ?? null;
        
        if (!$endpoint_config) {
            $this->send_sse_error("Endpoint '{$proxy_endpoint}' not configured for service '{$proxy_service}'");
            return;
        }
        
        // Get base URL
        $base_url = $proxy_config['base_url'];
        if (is_array($base_url)) {
            $is_dev = defined('AETHER_DEV_MODE') && AETHER_DEV_MODE;
            $env = $is_dev ? 'development' : 'production';
            $base_url = $base_url[$env] ?? $base_url['production'];
        }
        
        $url = rtrim($base_url, '/') . $endpoint_config['path'];
        
        
        // Prepare headers with authentication
        $headers = [
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ];
        
        // Apply authentication using the auth middleware logic
        $auth_config = $endpoint_config['auth'] ?? $proxy_config['_global']['default_auth'] ?? null;
        if ($auth_config) {
            $token = $this->get_auth_token($proxy_config['_global']['default_token_source'] ?? 'settings:aether_settings[api_token]');
            if ($token) {
                $headers[] = 'Authorization: Bearer ' . $token;
            } else {
            }
        } else {
        }
        
        // Store response data
        $this->response_buffer = '';
        $this->response_http_code = 0;
        $this->empty_response_detected = false;
        
        
        // Extract and transform data based on endpoint
        if ($proxy_endpoint === 'design') {
            // For design endpoint, transform the data structure
            $api_data = [
                'type' => 'design_system',
                'config' => $request_data['config'] ?? [],
                'request' => $request_data['data'] ?? []
            ];
        } else {
            // For other endpoints, use the original structure
            $api_data = $request_data;
        }
        
        // Use cURL for streaming support
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($api_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, [$this, 'stream_callback']);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, [$this, 'header_callback']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10 minutes timeout for SSE
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, apply_filters('aether_proxy_verify_ssl', true));
        
        // Execute request
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        
        if ($result === false) {
            $this->send_sse_error("Connection failed: {$error}");
            return; // Don't send success signal
        } elseif ($http_code >= 400) {
            $error_message = "Server error: HTTP {$http_code}";
            
            // Try to parse error message from buffered response
            if ($this->response_buffer) {
                $error_data = json_decode($this->response_buffer, true);
                if (isset($error_data['error']['message'])) {
                    $error_message = $error_data['error']['message'];
                } elseif (isset($error_data['message'])) {
                    $error_message = $error_data['message'];
                } elseif (isset($error_data['error'])) {
                    $error_message = is_string($error_data['error']) ? $error_data['error'] : $error_message;
                }
            }
            
            // Check for specific error codes
            if ($http_code === 401) {
                $error_message = "认证失败：API 密钥无效或已过期";
            } elseif ($http_code === 403) {
                $error_message = "权限不足：请检查 API 配置";
            } elseif ($http_code === 429) {
                $error_message = "请求过于频繁，请稍后再试";
            }
            
            $this->send_sse_error($error_message);
            return; // Don't send success signal
        } elseif ($this->empty_response_detected) {
            // Error already sent in stream_callback
            return; // Don't send success signal
        }
        
        // Only send done signal if request was successful and has content
        echo "data: [DONE]\n\n";
        flush();
    }
    
    /**
     * Stream callback for cURL
     */
    private function stream_callback($ch, $data) {
        // Get HTTP code
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        
        // If error response, buffer it for error message extraction
        if ($http_code >= 400) {
            $this->response_buffer .= $data;
            // Don't forward error responses
            return strlen($data);
        }
        
        // Buffer data to check for empty content BEFORE forwarding
        $this->response_buffer .= $data;
        
        // Check for empty content in done message
        if (strpos($data, '"done":true') !== false) {
            // Parse all SSE messages in the current chunk
            preg_match_all('/data:\s*({[^}]+})/s', $data, $matches);
            foreach ($matches[1] as $json_str) {
                $json_data = json_decode($json_str, true);
                if (isset($json_data['done']) && $json_data['done'] === true && 
                    isset($json_data['content']) && $json_data['content'] === '') {
                    // Empty response detected, don't forward this data
                    $this->empty_response_detected = true;
                    // Send error message immediately
                    $this->send_sse_error("认证失败：服务器返回了空响应，请检查 API 密钥是否正确");
                    // Return length to continue but don't forward
                    return strlen($data);
                }
            }
        }
        
        // Only forward if not empty response
        if (!$this->empty_response_detected) {
            echo $data;
            flush();
        }
        
        // Return the length of data to continue
        return strlen($data);
    }
    
    /**
     * Header callback for cURL
     */
    private function header_callback($ch, $header) {
        // This is called for each header line
        return strlen($header);
    }
    
    /**
     * Send SSE error
     */
    private function send_sse_error($message) {
        echo "data: " . json_encode(['error' => $message]) . "\n\n";
        // Don't send [DONE] for errors - let the client handle the error
        flush();
    }
    
    /**
     * Get proxy configuration
     */
    public function get_proxy_config($proxy_name) {
        $config_file = AETHER_PATH . 'includes/config/proxy-config.php';
        if (file_exists($config_file)) {
            $proxies = require $config_file;
            return $proxies[$proxy_name] ?? null;
        }
        return null;
    }
    
    /**
     * Process configuration with inheritance
     */
    private function process_config_with_inheritance($config) {
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
                }
            }
            
            // Store global config reference
            $service_config['_global'] = $global;
            $processed[$service_name] = $service_config;
        }
        
        return $processed;
    }
    
    /**
     * Get authentication token
     */
    private function get_auth_token($source) {
        if (!$source) {
            return null;
        }
        
        list($type, $key) = explode(':', $source, 2);
        
        switch ($type) {
            case 'settings':
                // Check if it's an array access pattern
                if (preg_match('/^(.+)\[(.+)\]$/', $key, $matches)) {
                    $option_name = $matches[1];
                    $array_key = $matches[2];
                    $settings = get_option($option_name, []);
                    return isset($settings[$array_key]) ? $settings[$array_key] : null;
                }
                return get_option($key);
                
            case 'env':
                if (defined($key)) {
                    return constant($key);
                }
                return getenv($key);
                
            default:
                return apply_filters('aether_proxy_get_token', null, $type, $key);
        }
    }
}

// Initialize
Aether_AI_Stream_Handler::get_instance();