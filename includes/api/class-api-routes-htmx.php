<?php
/**
 * HTMX API Routes - KISS principle implementation
 */

defined('ABSPATH') || exit;

class Aether_API_Routes_HTMX extends Aether_API_Routes_Base {
    
    public function register_routes() {
        $this->register_route('/htmx/execute/(?P<content_id>\\d+)', [
            [
                'methods' => 'GET,POST,PUT,DELETE',
                'callback' => [$this, 'execute_htmx'],
                'permission_callback' => '__return_true',
                'args' => [
                    'content_id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        // Execute for current page/template context, resolved from hx-current-url
        $this->register_route('/htmx/execute/current', [
            [
                'methods' => 'GET,POST,PUT,DELETE',
                'callback' => [$this, 'execute_htmx_current'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }
    
    /**
     * Execute saved PHP code for HTMX request
     */
    public function execute_htmx($request) {
        $content_id = $request->get_param('content_id');
        $post = get_post($content_id);
        
        // Return empty if no content
        if (!$post) {
            $this->html_response('<div class="htmx-error">Content not found</div>');
        }
        
        // Get content
        $code = $post->post_content;
        if ($post->post_type === 'aether_template') {
            $code = get_post_meta($content_id, Aether_Templates::CONTENT_META_KEY, true) ?: '';
        }
        
        if (empty($code)) {
            $this->html_response('');
        }
        
        // Setup request variables
        $_GET = $request->get_query_params();
        $_POST = $request->get_body_params();
        $_REQUEST = array_merge($_GET, $_POST);
        
        // HTMX specific variables
        $headers = $request->get_headers();
        $HTMX_REQUEST = true;
        $HX_TRIGGER = $headers['hx-trigger'] ?? null;
        $HX_TARGET = $headers['hx-target'] ?? null;
        $HX_PROMPT = $headers['hx-prompt'] ?? null;
        $HX_CURRENT_URL = $headers['hx-current-url'] ?? null;
        
        // Code block isolation mechanism
        $block_id = $_GET['block_id'] ?? null;
        if ($block_id) {
            $HTMX_BLOCK_ID = $block_id;
            // Don't start output buffering here, we'll handle it in execution
        }
        
        // WordPress context
        global $post;
        $original_post = $post;
        $post = get_post($content_id);
        setup_postdata($post);
        
        // Convenience functions for user code
        if (!function_exists('hx_response')) {
            function hx_response($html, $status = 200) {
                // Clean all output buffers if they exist
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                http_response_code($status);
                echo $html;
                exit;
            }
        }
        
        if (!function_exists('hx_redirect')) {
            function hx_redirect($url) {
                header('HX-Redirect: ' . $url);
                exit;
            }
        }
        
        if (!function_exists('hx_refresh')) {
            function hx_refresh() {
                header('HX-Refresh: true');
                exit;
            }
        }
        
        // Execute code using temp file instead of eval()
        $temp_file = null;
        $error = null;
        
        try {
            @set_time_limit(10);
            $temp_file = tempnam(sys_get_temp_dir(), 'aether_htmx_');
            file_put_contents($temp_file, $code);
            
            ob_start();
            include $temp_file;
            $output = ob_get_clean();
            
            if ($temp_file && file_exists($temp_file)) {
                wp_delete_file($temp_file);
                $temp_file = null;
            }
            
        } catch (ParseError $e) {
            $error = $e->getMessage();
            if ($temp_file && file_exists($temp_file)) wp_delete_file($temp_file);
        } catch (Error $e) {
            $error = $e->getMessage();
            if ($temp_file && file_exists($temp_file)) wp_delete_file($temp_file);
        } catch (Exception $e) {
            $error = $e->getMessage();
            if ($temp_file && file_exists($temp_file)) wp_delete_file($temp_file);
        } finally {
            // Reset WordPress context
            $post = $original_post;
            if ($original_post) {
                setup_postdata($original_post);
            }
        }
        
        if ($error) {
            $this->show_error($error, 0);
            exit;
        }
        
        // Handle output
            
            // If block_id was provided but no output, it wasn't handled
            if ($block_id && empty(trim($output))) {
                $this->html_response('<!-- Block not found -->');
            }
            
            // Send the output
            echo $output;
        }
        
        exit;
    }

    /**
     * Execute PHP code for the "current" context resolved from the request headers
     * Tries to map hx-current-url to a post/page ID first; if not found, to an active template ID
     */
    public function execute_htmx_current($request) {
        $content_id = $this->resolve_current_content_id($request);

        if (!$content_id) {
            $this->html_response('<div class="htmx-error">Unable to resolve current content</div>');
        }

        // Reuse the regular executor by injecting the resolved ID
        $request->set_param('content_id', $content_id);
        return $this->execute_htmx($request);
    }

    /**
     * Resolve current content ID from request headers and site context
     * 1) If hx-current-url maps to a singular post/page -> use that ID
     * 2) Else try to detect a matching post type archive and use its active template ID
     * 3) Else try core types (front_page, home/blog, search)
     * Returns 0 if cannot resolve
     */
    private function resolve_current_content_id($request) {
        $headers = $request->get_headers();
        $current_url = '';
        if (isset($headers['hx-current-url'][0])) {
            $current_url = $headers['hx-current-url'][0];
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $current_url = $_SERVER['HTTP_REFERER'];
        }

        $current_url = is_string($current_url) ? $current_url : '';
        if ($current_url) {
            // 1) Map to singular post/page ID if possible
            $pid = url_to_postid($current_url);
            if ($pid) {
                return (int) $pid;
            }
        }

        // 2) Try post type archives by matching archive link prefix
        if ($current_url) {
            $ptypes = get_post_types(['public' => true], 'objects');
            foreach ($ptypes as $ptype => $obj) {
                if ($ptype === 'attachment' || $ptype === 'aether_template') {
                    continue;
                }
                $archive_link = ($obj->has_archive || $ptype === 'post') ? get_post_type_archive_link($ptype) : '';
                if ($archive_link) {
                    if ($this->url_starts_with($current_url, $archive_link)) {
                        // Find active template for this archive type
                        if (class_exists('Aether_Templates') && class_exists('Aether_Dynamic_Template_Types')) {
                            $templates = Aether_Templates::getInstance();
                            $template = $templates->get_active_template('archive_' . $ptype, false);
                            if ($template && !empty($template['id'])) {
                                return (int) $template['id'];
                            }
                        }
                    }
                }
            }
        }

        // 2b) Try taxonomy archives by resolving term slug from URL
        if ($current_url) {
            $taxonomies = get_taxonomies(['public' => true], 'objects');
            $path = $this->strip_query_and_hash($current_url);
            $home_path = parse_url(home_url('/'), PHP_URL_PATH) ?: '/';
            if ($home_path !== '/' && strpos($path, $home_path) === 0) {
                $path = substr($path, strlen($home_path));
            }
            $path = trim($path, '/');
            if ($path !== '') {
                $segments = array_values(array_filter(explode('/', $path), 'strlen'));
                $candidate = end($segments);
                // Handle paging URLs like /category/foo/page/2
                if ($candidate === 'page' || $candidate === 'paged') {
                    array_pop($segments); // remove 'page' or 'paged'
                    $candidate = end($segments) ?: '';
                }
                $candidate = urldecode($candidate);

                if ($candidate !== '') {
                    foreach ($taxonomies as $tax => $tax_obj) {
                        if (in_array($tax, ['nav_menu', 'link_category', 'post_format'], true)) {
                            continue;
                        }
                        $term = get_term_by('slug', $candidate, $tax);
                        if ($term && !is_wp_error($term)) {
                            $term_link = get_term_link($term);
                            if (!is_wp_error($term_link) && $this->url_starts_with($current_url, $term_link)) {
                                if (class_exists('Aether_Templates')) {
                                    $templates = Aether_Templates::getInstance();
                                    $template = $templates->get_active_template('taxonomy_' . $tax, false);
                                    if ($template && !empty($template['id'])) {
                                        return (int) $template['id'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // 3) Core types by URL
        if ($current_url) {
            // search
            $query = parse_url($current_url, PHP_URL_QUERY);
            if (is_string($query) && stripos($query, 's=') !== false) {
                if (class_exists('Aether_Templates')) {
                    $templates = Aether_Templates::getInstance();
                    $template = $templates->get_active_template('search', false);
                    if ($template && !empty($template['id'])) {
                        return (int) $template['id'];
                    }
                }
            }

            // front page or home/blog
            $home = trailingslashit(home_url('/'));
            $url_no_q = $this->strip_query_and_hash($current_url);
            if (rtrim($url_no_q, '/') === rtrim($home, '/')) {
                $show_on_front = get_option('show_on_front');
                if ($show_on_front === 'page') {
                    if (class_exists('Aether_Templates')) {
                        $templates = Aether_Templates::getInstance();
                        $template = $templates->get_active_template('front_page', false);
                        if ($template && !empty($template['id'])) {
                            return (int) $template['id'];
                        }
                    }
                } else {
                    if (class_exists('Aether_Templates')) {
                        $templates = Aether_Templates::getInstance();
                        $template = $templates->get_active_template('home', false);
                        if ($template && !empty($template['id'])) {
                            return (int) $template['id'];
                        }
                    }
                }
            }

            // Blog page distinct URL
            $page_for_posts = (int) get_option('page_for_posts');
            if ($page_for_posts) {
                $blog_url = get_permalink($page_for_posts);
                if ($blog_url && $this->url_starts_with($current_url, $blog_url)) {
                    if (class_exists('Aether_Templates')) {
                        $templates = Aether_Templates::getInstance();
                        $template = $templates->get_active_template('blog', false);
                        if ($template && !empty($template['id'])) {
                            return (int) $template['id'];
                        }
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Helpers
     */
    private function strip_query_and_hash($url) {
        $parts = parse_url($url);
        if (!$parts) return $url;
        $scheme   = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path     = $parts['path'] ?? '';
        return $scheme . $host . $port . $path;
    }

    private function url_starts_with($url, $prefix) {
        $u = rtrim($this->strip_query_and_hash($url), '/');
        $p = rtrim($this->strip_query_and_hash($prefix), '/');
        return stripos($u, $p) === 0;
    }
    
    private function html_response($html) {
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }
    
    private function show_error($message, $line = null) {
        $error = $line ? "Line $line: $message" : $message;
        
        // In production, show simple error
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            $this->html_response('<div class="htmx-error">处理请求时出错</div>');
        }
        
        // In development, show detailed error
        $this->html_response(
            '<div class="htmx-error" style="color: red; padding: 10px; border: 1px solid red;">' .
            '<strong>Error:</strong> ' . esc_html($error) . 
            '</div>'
        );
    }
}
