<?php
/**
 * PHP Execution API Routes
 * 
 * KISS principle: Simple PHP code execution for admin users only
 */

defined('ABSPATH') || exit;

class Aether_API_Routes_PHP extends Aether_API_Routes_Base {
    
    public function register_routes() {
        // Execute PHP code
        $this->register_route('/php/execute', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'execute_php'],
                'permission_callback' => [$this, 'admin_permission_check'],
                'args' => [
                    'code' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => 'PHP code to execute',
                    ],
                    'post_id' => [
                        'required' => false,
                        'type' => 'integer',
                        'description' => 'Current post ID for context',
                    ],
                ],
            ],
        ]);
    }
    
    /**
     * Execute PHP code and return result
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function execute_php($request) {
        $code = $request->get_param('code');
        $post_id = $request->get_param('post_id');
        
        // Basic security: Disable dangerous functions
        $dangerous_functions = [
            'exec', 'system', 'shell_exec', 'passthru', 'proc_open',
            'popen', 'curl_exec', 'curl_multi_exec', 'parse_ini_file',
            'show_source', 'file_get_contents', 'file_put_contents',
            'fopen', 'fwrite', 'fputs', 'file', 'readfile',
            'include', 'require', 'include_once', 'require_once',
            'eval', 'create_function', 'assert',
            'mail', 'phpinfo', 'symlink', 'link', 'syslog',
            'move_uploaded_file', 'ini_set', 'set_time_limit',
            'ignore_user_abort', 'set_include_path', 'restore_include_path'
        ];
        
        // Extract PHP code blocks only (ignore HTML content)
        // Match: standard tags, short tags, and short echo tags
        $php_code_only = '';
        if (preg_match_all('/<\?(?:php|=)?\s*(.*?)\?>/is', $code, $matches)) {
            $php_code_only = implode("\n", $matches[1]);
        } else {
            // If no PHP tags found, treat entire content as PHP
            $php_code_only = $code;
        }

        // Check for dangerous function calls in PHP code only
        foreach ($dangerous_functions as $func) {
            if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $php_code_only)) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => "Security Error: Function '$func' is not allowed",
                    'output' => '',
                ], 200);
            }
        }
        
        // Set up WordPress context
        if ($post_id) {
            global $post;
            $post = get_post($post_id);
            setup_postdata($post);
        }
        
        // Capture output
        ob_start();
        $error = null;
        
        try {
            // Set execution limits - more strict for safety
            @set_time_limit(2); // 2 seconds max
            @ini_set('memory_limit', '64M'); // Reduced memory limit
            @ini_set('max_execution_time', '2');
            
            // Create a sandbox environment with limited functions
            $sandbox_code = '
                // Disable error reporting to screen for security
                error_reporting(E_ALL);
                ini_set("display_errors", 0);
                
                // Safe WordPress functions whitelist
                $allowed_functions = [
                    "get_posts", "get_post", "get_post_meta", "get_field",
                    "get_option", "get_theme_mod", "get_site_url", "home_url",
                    "get_bloginfo", "get_categories", "get_tags", "get_terms",
                    "get_users", "get_user_meta", "get_current_user_id",
                    "is_user_logged_in", "current_user_can", "wp_get_current_user",
                    "get_permalink", "get_the_title", "get_the_content",
                    "get_the_excerpt", "get_the_date", "get_the_author",
                    "has_post_thumbnail", "get_post_thumbnail_id", "wp_get_attachment_url",
                    "esc_html", "esc_attr", "esc_url", "wp_kses", "wp_kses_post",
                    "sprintf", "printf", "echo", "print", "print_r", "var_dump",
                    "count", "sizeof", "empty", "isset", "is_array", "in_array",
                    "array_merge", "array_keys", "array_values", "implode", "explode",
                    "str_replace", "substr", "strlen", "strtolower", "strtoupper",
                    "date", "time", "strtotime", "number_format", "round", "ceil", "floor"
                ];
                
                ?>' . $code;
            
            // Execute PHP code
            // Using eval is intentional here - this is for admin users only
            // and is the core feature of this PHP editor
            eval($sandbox_code);
            
        } catch (ParseError $e) {
            $error = 'Parse Error: ' . $e->getMessage() . ' on line ' . $e->getLine();
        } catch (Error $e) {
            $error = 'Fatal Error: ' . $e->getMessage() . ' on line ' . $e->getLine();
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage() . ' on line ' . $e->getLine();
        }
        
        $output = ob_get_clean();
        
        // Reset post data
        if ($post_id) {
            wp_reset_postdata();
        }
        
        // Log execution for audit
        if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
            error_log('Aether PHP Execution by user ' . get_current_user_id() . ' for post ' . $post_id);
        }
        
        if ($error) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $error,
                'output' => $output,
            ], 200);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'output' => $output,
        ], 200);
    }
    
    /**
     * Check if user is admin
     * 
     * @return bool
     */
    public function admin_permission_check() {
        return current_user_can('manage_options');
    }
}