<?php
/**
 * Post Type Discovery Service
 * 
 * 负责发现和管理WordPress文章类型
 * 
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Post_Type_Discovery_Service {
    
    /**
     * 系统保留的文章类型
     */
    private static $system_post_types = [
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
        'acf-field',
        'acf-field-group',
        'aether_template'
    ];
    
    /**
     * 获取文章类型列表
     * 
     * @param bool $include_system 是否包含系统文章类型
     * @return array
     */
    public static function get_post_types($include_system = false) {
        $post_types = get_post_types(['public' => true], 'objects');
        $result = [];
        
        /** @var WP_Post_Type $post_type */
        foreach ($post_types as $post_type) {
            // 过滤系统文章类型
            if (!$include_system && in_array($post_type->name, self::$system_post_types)) {
                continue;
            }
            
            $result[] = [
                'name' => $post_type->name,
                'label' => $post_type->label,
                'description' => $post_type->description ?: $post_type->label,
                'supports' => get_all_post_type_supports($post_type->name),
                'taxonomies' => self::get_post_type_taxonomies($post_type->name)
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取文章类型的分类法
     * 
     * @param string $post_type
     * @return array
     */
    public static function get_post_type_taxonomies($post_type) {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $result = [];
        
        /** @var WP_Taxonomy $taxonomy */
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->public && !in_array($taxonomy->name, ['post_format', 'link_category'])) {
                $result[] = [
                    'name' => $taxonomy->name,
                    'label' => $taxonomy->label,
                    'hierarchical' => $taxonomy->hierarchical,
                    'description' => $taxonomy->description
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * 检查文章类型是否为系统类型
     * 
     * @param string $post_type
     * @return bool
     */
    public static function is_system_post_type($post_type) {
        return in_array($post_type, self::$system_post_types);
    }
    
    /**
     * 获取系统文章类型列表
     * 
     * @return array
     */
    public static function get_system_post_types() {
        return self::$system_post_types;
    }
}