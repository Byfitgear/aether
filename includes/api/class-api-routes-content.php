<?php
/**
 * Content API Routes
 * 
 * Handles content-related REST API routes
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Content API Routes Class
 */
class Aether_API_Routes_Content extends Aether_API_Routes_Base {
    
    /**
     * Content API service instance
     */
    private $content_api;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->content_api = new Aether_Content_API();
    }
    
    /**
     * Register content routes
     */
    public function register_routes() {
        $this->register_route('/content/(?P<id>\\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this->content_api, 'get_content'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_post_permission'],
                'args' => [
                    'id' => [
                        'validate_callback' => [$this, 'validate_numeric']
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this->content_api, 'update_content'],
                'permission_callback' => [Aether_Permission_Service::class, 'check_post_permission'],
                'args' => array_merge(
                    [
                        'id' => [
                            'validate_callback' => [$this, 'validate_numeric']
                        ],
                    ],
                    Aether_Content_API::get_content_schema()
                ),
            ],
        ]);

        // 获取 header/footer 模板内容（用于前端编译）
        $this->register_route('/content/header-footer', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_header_footer'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
        ]);

        // 保存 raw 内容（第 1 步）
        $this->register_route('/content/(?P<id>\\d+)/save-raw', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'save_raw'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_post_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => [$this, 'validate_numeric']
                ],
                'content' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        $this->register_route('/content/(?P<id>\\d+)/image-optimization-status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_image_optimization_status'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_post_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => [$this, 'validate_numeric']
                ],
            ],
        ]);

        $this->register_route('/content/(?P<id>\\d+)/refresh-optimized-html', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'refresh_optimized_html'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_post_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => [$this, 'validate_numeric']
                ],
            ],
        ]);

        // 保存编译后的 CSS（第 3 步）
        $this->register_route('/content/(?P<id>\\d+)/save-compiled-css', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'save_compiled_css'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_post_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => [$this, 'validate_numeric']
                ],
                'css' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'source' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['frontend', 'backend'],
                ],
                'header_footer_hash' => [
                    'type' => 'string',
                ],
                'design_system_hash' => [
                    'type' => 'string',
                ],
            ],
        ]);

        // 标记使用 CDN（编译失败时）
        $this->register_route('/content/(?P<id>\\d+)/mark-use-cdn', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'mark_use_cdn'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_post_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => [$this, 'validate_numeric']
                ],
            ],
        ]);
    }

    /**
     * GET /content/header-footer
     * 获取 header 和 footer 模板内容（用于前端编译）
     */
    public function get_header_footer() {
        $compile_service = Aether_CSS_Compile_Strategy_Service::get_instance();
        $templates = $compile_service->get_common_templates();

        $hash_service = Aether_CSS_Hash_Service::get_instance();

        return [
            'header' => $templates['header'] ?? '',
            'footer' => $templates['footer'] ?? '',
            'safelist' => Aether_Safelist_Service::get_html(),
            'header_footer_hash' => $hash_service->get_header_footer_hash(),
            'design_system_hash' => $hash_service->get_design_system_hash()
        ];
    }

    /**
     * 保存 raw 内容（第 1 步）
     * POST /content/{id}/save-raw
     */
    public function save_raw($request) {
        $post_id = $request->get_param('id');
        $content = $request->get_param('content');

        // 验证权限
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', '无权编辑', ['status' => 403]);
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', '文章不存在', ['status' => 404]);
        }

        $php_permission = Aether_Permission_Service::check_php_content_save_permission($content);
        if (is_wp_error($php_permission)) {
            return $php_permission;
        }

        // 根据文章类型保存内容
        if ($post->post_type === 'aether_template') {
            // 模板内容存在 meta 中，但仍需更新 post_modified
            Aether_Templates::update_content_meta($post_id, $content);

            // 更新修改时间（触发缓存清理和 RSS 更新）
            wp_update_post([
                'ID' => $post_id,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', true)
            ]);

            // 获取模板类型
            $template_type = get_post_meta($post_id, '_aether_template_type', true);

            // 触发模板保存钩子（智能按需编译）
            do_action('aether_template_saved', [
                'id' => $post_id,
                'type' => $template_type,
                'content' => $content,
                'action' => 'update',
                'source' => 'content_api'
            ]);

            // 获取哈希值
            $hash_service = Aether_CSS_Hash_Service::get_instance();

            // 获取编译结果
            $compile_result = null;
            if (class_exists('Aether_Template_Compile_Manager')) {
                $compile_result = Aether_Template_Compile_Manager::get_last_compile_result();
            }

            $speed_enabled = (bool) get_option('aether_speed_optimization_enabled', false);

            // 判断是否成功编译（必须检查 completed > 0，而不仅是 total > 0）
            $has_compiled = $compile_result &&
                            !empty($compile_result['completed']) &&
                            $compile_result['completed'] > 0;

            return [
                'success' => true,
                'post_id' => $post_id,
                'header_footer_hash' => $hash_service->get_header_footer_hash(),
                'design_system_hash' => $hash_service->get_design_system_hash(),
                'global_template_changed' => in_array($template_type, ['header', 'footer'], true),
                'compile_result' => $compile_result,
                'speed_optimization_enabled' => $speed_enabled,
                'image_optimization_enabled' => false,
                'message' => $speed_enabled
                    ? ($has_compiled ? '已保存，速度优化完成' : '已保存')
                    : '已保存'
            ];
        } else {
            // 普通文章，使用 wp_update_post 更新内容
            $result = wp_update_post([
                'ID' => $post_id,
                'post_content' => $content
            ], true);

            if (is_wp_error($result)) {
                return new WP_Error(
                    'save_failed',
                    '保存失败: ' . $result->get_error_message(),
                    ['status' => 500]
                );
            }
        }

        // 标记为 aether 编辑
        update_post_meta($post_id, '_aether_edited', '1');
        update_post_meta($post_id, '_aether_last_edited', current_time('mysql'));

        // 触发内容保存钩子（用于图片优化等功能，与速度优化无关）
        do_action('aether_content_saved', [
            'post_id' => $post_id,
            'post_type' => get_post_type($post_id),
            'source' => 'save_raw'
        ]);

        // 计算并返回哈希值
        $hash_service = Aether_CSS_Hash_Service::get_instance();

        return [
            'success' => true,
            'post_id' => $post_id,
            'header_footer_hash' => $hash_service->get_header_footer_hash(),
            'design_system_hash' => $hash_service->get_design_system_hash(),
            'speed_optimization_enabled' => (bool) get_option('aether_speed_optimization_enabled', false),
            'image_optimization_enabled' => class_exists('Aether_HTML_Optimization_Service')
                ? Aether_HTML_Optimization_Service::get_instance()->is_optimization_enabled()
                : false,
        ];
    }

    /**
     * 获取页面图片优化状态
     * GET /content/{id}/image-optimization-status
     */
    public function get_image_optimization_status($request) {
        $post_id = intval($request->get_param('id'));

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', '无权编辑', ['status' => 403]);
        }

        if (!class_exists('Aether_HTML_Optimization_Service')) {
            return [
                'success' => false,
                'reason' => 'html_optimization_service_missing',
                'enabled' => false,
            ];
        }

        return Aether_HTML_Optimization_Service::get_instance()->get_page_image_optimization_status($post_id);
    }

    /**
     * 刷新页面优化 HTML
     * POST /content/{id}/refresh-optimized-html
     */
    public function refresh_optimized_html($request) {
        $post_id = intval($request->get_param('id'));

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', '无权编辑', ['status' => 403]);
        }

        if (!class_exists('Aether_HTML_Optimization_Service')) {
            return new WP_Error('service_unavailable', 'HTML 优化服务不可用', ['status' => 500]);
        }

        return Aether_HTML_Optimization_Service::get_instance()->refresh_page_optimized_html($post_id, true);
    }

    /**
     * 保存编译后的 CSS（第 3 步）
     * POST /content/{id}/save-compiled-css
     */
    public function save_compiled_css($request) {
        $post_id = $request->get_param('id');
        $css = $request->get_param('css');
        $source = $request->get_param('source');
        $header_footer_hash = $request->get_param('header_footer_hash');
        $design_system_hash = $request->get_param('design_system_hash');

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', '无权编辑', ['status' => 403]);
        }

        // 添加字体 CSS（前端编译不包含字体）
        if (class_exists('Aether_Font_Manager_Service')) {
            $font_css = Aether_Font_Manager_Service::get_font_css();
            if (!empty($font_css) && strpos($css, '@font-face') === false) {
                $css = $font_css . "\n" . $css;
            }
        }

        // 保存编译 CSS
        $storage = Aether_CSS_Storage_Service::get_instance();
        $storage->save_page_css($post_id, $css);

        update_post_meta($post_id, '_aether_has_compiled_css', '1');
        update_post_meta($post_id, '_aether_css_compiled_at', current_time('timestamp'));
        update_post_meta($post_id, '_aether_css_compile_source', $source);
        update_post_meta($post_id, '_aether_css_header_footer_hash', $header_footer_hash);
        update_post_meta($post_id, '_aether_css_design_system_hash', $design_system_hash);

        // 清理页面缓存
        $this->clear_page_cache($post_id);

        // 触发钩子
        do_action('aether_content_saved', [
            'post_id' => $post_id,
            'post_type' => get_post_type($post_id),
            'source' => 'content_api',
            'css_optimized' => true
        ]);

        return ['success' => true];
    }

    /**
     * 标记使用 CDN（编译失败时）
     * POST /content/{id}/mark-use-cdn
     */
    public function mark_use_cdn($request) {
        $post_id = $request->get_param('id');

        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('forbidden', '无权编辑', ['status' => 403]);
        }

        // 清除存储层的 CSS
        $storage = Aether_CSS_Storage_Service::get_instance();
        $storage->clear_page_css($post_id);

        // 清除所有编译相关的 meta
        delete_post_meta($post_id, '_aether_has_compiled_css');
        delete_post_meta($post_id, '_aether_css_header_footer_hash');
        delete_post_meta($post_id, '_aether_css_design_system_hash');
        delete_post_meta($post_id, '_aether_css_compile_source');
        delete_post_meta($post_id, '_aether_css_compiled_at');

        // 标记编译失败（用于日志和诊断）
        update_post_meta($post_id, '_aether_compile_failed_at', current_time('mysql'));

        // 清理页面缓存
        $this->clear_page_cache($post_id);

        // 触发钩子
        do_action('aether_content_saved', [
            'post_id' => $post_id,
            'post_type' => get_post_type($post_id),
            'source' => 'content_api',
            'css_optimized' => false
        ]);

        return ['success' => true];
    }

    /**
     * 清理页面缓存
     */
    private function clear_page_cache($post_id) {
        clean_post_cache($post_id);

        // 如果使用了缓存插件，清除其缓存
        if (function_exists('wp_cache_post_change')) {
            wp_cache_post_change($post_id);
        }

        // WP Super Cache
        if (function_exists('wpsc_delete_post_cache')) {
            wpsc_delete_post_cache($post_id);
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($post_id);
        }
    }
}
