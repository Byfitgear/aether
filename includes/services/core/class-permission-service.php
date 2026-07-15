<?php
/**
 * 权限服务
 * 
 * 处理用户权限检查
 *
 * @package aether
 * @subpackage Services
 */

defined('ABSPATH') || exit;

/**
 * 权限服务类
 */
class Aether_Permission_Service {
    
    /**
     * 检查用户是否可以使用编辑器
     * 
     * @return bool
     */
    public static function can_use_editor() {
        return current_user_can('edit_posts');
    }
    
    /**
     * 检查用户是否可以编辑特定文章
     * 
     * @param int $post_id 文章 ID
     * @return bool
     */
    public static function can_edit_post($post_id) {
        return current_user_can('edit_post', $post_id);
    }
    
    /**
     * 检查用户是否可以管理设置
     * 
     * @return bool
     */
    public static function can_manage_settings() {
        return current_user_can('manage_options');
    }

    /**
     * Check whether the current user may save executable PHP content.
     *
     * @return bool
     */
    public static function can_save_php_content() {
        return current_user_can('manage_options');
    }

    /**
     * Validate executable PHP content save permission.
     *
     * @param string $content Content to save.
     * @return true|WP_Error
     */
    public static function check_php_content_save_permission($content) {
        if (
            class_exists('Aether_PHP_Runtime_Cache_Policy') &&
            Aether_PHP_Runtime_Cache_Policy::content_contains_php($content) &&
            !self::can_save_php_content()
        ) {
            return new WP_Error(
                'forbidden_php_content',
                __('您没有权限保存 PHP 代码。', 'aether'),
                ['status' => 403]
            );
        }

        return true;
    }
    
    /**
     * 验证 REST API 权限
     *
     * @return bool|WP_Error
     */
    public static function check_rest_permission() {
        $debug = defined('AETHER_DEBUG') && AETHER_DEBUG;

        if ($debug) {
            error_log('[Aether Permission] 检查 REST API 权限');
            error_log('[Aether Permission] 当前用户 ID: ' . get_current_user_id());
            error_log('[Aether Permission] can_use_editor: ' . (self::can_use_editor() ? 'true' : 'false'));
        }

        if (!self::can_use_editor()) {
            if ($debug) {
                error_log('[Aether Permission] 权限检查失败 - 用户无 edit_posts 权限');
            }
            return new WP_Error(
                'rest_forbidden',
                __('您没有权限执行此操作。', 'aether'),
                ['status' => 403]
            );
        }

        if ($debug) {
            error_log('[Aether Permission] 权限检查通过');
        }
        return true;
    }
    
    /**
     * 验证文章编辑权限
     * 
     * @param WP_REST_Request $request REST 请求对象
     * @return bool|WP_Error
     */
    public static function check_post_permission($request) {
        $post_id = $request->get_param('id');
        
        if (!$post_id || !self::can_edit_post($post_id)) {
            return new WP_Error(
                'rest_forbidden',
                __('您没有权限编辑此文章。', 'aether'),
                ['status' => 403]
            );
        }
        
        return true;
    }
    
    /**
     * 验证管理权限
     * 
     * @param WP_REST_Request $request REST 请求对象
     * @return bool|WP_Error
     */
    public static function check_manage_permission($request) {
        if (!self::can_manage_settings()) {
            return new WP_Error(
                'rest_forbidden',
                __('您没有权限管理设置。', 'aether'),
                ['status' => 403]
            );
        }
        
        return true;
    }
}
