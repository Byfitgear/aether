<?php
/**
 * Media API Routes
 * 
 * Handles media-related REST API routes
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Media API Routes Class
 */
class Aether_API_Routes_Media extends Aether_API_Routes_Base {
    
    /**
     * Media API service instance
     */
    private $media_api;

    /**
     * Media upload API service instance
     */
    private $media_upload_api;

    /**
     * Image optimization service instance (lazy encoding)
     */
    private $optimization_service;

    /**
     * Constructor
     */
    public function __construct() {
        $this->media_api = new Aether_Media_API();
        $this->media_upload_api = new Aether_Media_Upload_API();
        $this->optimization_service = new Aether_Image_Optimization_Service();
    }
    
    /**
     * Register media routes
     */
    public function register_routes() {
        // Get media list
        $this->register_route('/media', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->media_api, 'get_media'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'per_page' => [
                    'default' => 20,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 100;
                    }
                ],
                'page' => [
                    'default' => 1,
                    'validate_callback' => [$this, 'validate_positive_numeric']
                ],
                'search' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Mark attachment as aether upload
        $this->register_route('/media/mark-aether-upload', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->media_api, 'mark_aether_upload'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'attachment_id' => [
                    'required' => true,
                    'validate_callback' => [$this, 'validate_positive_numeric']
                ],
            ],
        ]);

        // Upload optimized images (frontend processed)
        // 注意：不要在 args 中定义参数验证，因为这是 multipart/form-data 请求
        // WordPress REST API 会尝试从 JSON body 读取参数导致失败
        // 参数验证在回调函数中进行
        $route_registered = $this->register_route('/media/upload-optimized', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->media_upload_api, 'upload_optimized'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Aether API] 路由注册: /media/upload-optimized - ' . ($route_registered ? '成功' : '失败'));
        }

        // Save optimized files (frontend jsquash processed)
        // 注意：使用 ALLMETHODS 绕过 WordPress 对 POST JSON body 的验证
        $save_route_registered = $this->register_route('/media/save-optimized', [
            'methods' => WP_REST_Server::ALLMETHODS, // 允许所有方法，绕过 JSON 验证
            'callback' => [$this->media_upload_api, 'save_optimized'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'show_in_index' => false, // Not displayed in REST API index
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Aether API] 路由注册: /media/save-optimized - ' . ($save_route_registered ? '成功' : '失败'));
        }

        // Get pending optimization images (lazy encoding)
        $this->register_route('/media/pending-optimization', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this->optimization_service, 'get_pending_images'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'limit' => [
                    'default' => 10,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 50;
                    }
                ],
            ],
        ]);

        // Acquire lock for optimization
        $this->register_route('/media/(?P<id>\d+)/acquire-lock', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->optimization_service, 'acquire_lock'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => [$this, 'validate_positive_numeric']
                ],
            ],
        ]);

        // Release lock for optimization
        $this->register_route('/media/(?P<id>\d+)/release-lock', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->optimization_service, 'release_lock'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => [$this, 'validate_positive_numeric']
                ],
            ],
        ]);

        // Mark optimization as failed
        $this->register_route('/media/(?P<id>\d+)/mark-failed', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this->optimization_service, 'mark_failed'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => [$this, 'validate_positive_numeric']
                ],
                'error' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }
}