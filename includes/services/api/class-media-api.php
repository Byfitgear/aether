<?php
/**
 * 媒体 API 服务
 * 
 * 处理媒体库的 REST API 操作
 *
 * @package aether
 * @subpackage Services
 */

defined('ABSPATH') || exit;

/**
 * 媒体 API 类
 */
class Aether_Media_API {
    
    /**
     * 标记附件为 aether 上传
     *
     * @param WP_REST_Request $request REST 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function mark_aether_upload($request) {
        $attachment_id = $request->get_param('attachment_id');

        if (empty($attachment_id) || !is_numeric($attachment_id)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => '无效的附件 ID',
            ], 400);
        }

        // 验证附件存在
        if (!get_post($attachment_id)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => '附件不存在',
            ], 404);
        }

        // 添加标记
        update_post_meta($attachment_id, '_aether_uploaded', 'yes');

        return new WP_REST_Response([
            'success' => true,
        ], 200);
    }

    /**
     * 获取媒体列表
     *
     * @param WP_REST_Request $request REST 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function get_media($request) {
        // 获取参数
        $per_page = $request->get_param('per_page') ?: 20;
        $page = $request->get_param('page') ?: 1;
        $search = $request->get_param('search');
        
        // 构建查询参数
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_mime_type' => 'image',
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        
        if ($search) {
            $args['s'] = $search;
        }
        
        // 执行查询
        $query = new WP_Query($args);
        $media_items = [];
        
        foreach ($query->posts as $attachment) {
            $media_items[] = $this->format_attachment($attachment);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $media_items,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ], 200);
    }
    
    /**
     * 格式化附件数据
     * 
     * @param WP_Post $attachment 附件对象
     * @return array
     */
    private function format_attachment($attachment) {
        $metadata = wp_get_attachment_metadata($attachment->ID);
        
        return [
            'id' => $attachment->ID,
            'title' => $attachment->post_title,
            'filename' => basename(get_attached_file($attachment->ID)),
            'url' => wp_get_attachment_url($attachment->ID),
            'alt' => get_post_meta($attachment->ID, '_wp_attachment_image_alt', true),
            'caption' => $attachment->post_excerpt,
            'description' => $attachment->post_content,
            'sizes' => $this->get_image_sizes($attachment->ID),
            'date' => $attachment->post_date,
            'modified' => $attachment->post_modified,
            'mime_type' => $attachment->post_mime_type,
            'width' => isset($metadata['width']) ? $metadata['width'] : 0,
            'height' => isset($metadata['height']) ? $metadata['height'] : 0,
        ];
    }
    
    /**
     * 获取图片各种尺寸
     * 
     * @param int $attachment_id 附件 ID
     * @return array
     */
    private function get_image_sizes($attachment_id) {
        $sizes = [];
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        // 原始尺寸
        $sizes['full'] = [
            'url' => wp_get_attachment_url($attachment_id),
            'width' => isset($metadata['width']) ? $metadata['width'] : 0,
            'height' => isset($metadata['height']) ? $metadata['height'] : 0,
        ];
        
        // 其他尺寸
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                $sizes[$size] = [
                    'url' => wp_get_attachment_image_url($attachment_id, $size),
                    'width' => $data['width'],
                    'height' => $data['height'],
                ];
            }
        }
        
        return $sizes;
    }
}