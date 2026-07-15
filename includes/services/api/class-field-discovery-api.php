<?php
/**
 * Field Discovery API Service
 * 
 * 提供字段发现的REST API端点
 * 
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Field_Discovery_API {
    
    /**
     * 构造函数
     */
    public function __construct() {
        // 确保依赖的服务已加载
        $this->load_dependencies();
    }
    
    /**
     * 加载依赖的服务
     */
    private function load_dependencies() {
        $services = [
            'core/class-post-type-discovery-service.php',
            'core/class-meta-field-discovery-service.php',
            'core/class-acf-integration-service.php'
        ];
        
        foreach ($services as $service) {
            $file = AETHER_PATH . 'includes/services/' . $service;
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
    
    /**
     * 获取字段列表
     * 
     * @param string $post_type
     * @param array $options
     * @return array
     */
    public function get_fields($post_type, $options = []) {
        $defaults = [
            'include_system' => false,
            'optimize_for_ai' => false,
            'custom_field_limit' => 100
        ];
        $options = array_merge($defaults, $options);
        
        $fields = [];
        
        // 1. 内置字段
        $builtin_fields = Aether_Meta_Field_Discovery_Service::get_builtin_fields($post_type, $options['optimize_for_ai']);
        $fields = array_merge($fields, $builtin_fields);
        
        // 2. ACF字段
        if (Aether_ACF_Integration_Service::is_active()) {
            $acf_fields = Aether_ACF_Integration_Service::get_acf_fields($post_type);
            $fields = array_merge($fields, $acf_fields);
        }
        
        // 3. 自定义字段
        $acf_field_names = Aether_ACF_Integration_Service::get_acf_field_names($post_type);
        $custom_fields = Aether_Meta_Field_Discovery_Service::get_custom_fields($post_type, $options, $acf_field_names);
        $fields = array_merge($fields, $custom_fields);
        
        // 4. 分类法（作为字段返回）
        if (!$options['optimize_for_ai']) {
            $taxonomies = Aether_Post_Type_Discovery_Service::get_post_type_taxonomies($post_type);
            foreach ($taxonomies as $taxonomy) {
                $fields[] = [
                    'name' => $taxonomy['name'],
                    'label' => $taxonomy['label'],
                    'type' => 'taxonomy',
                    'source' => 'taxonomy',
                    'description' => $taxonomy['description'] ?: '分类法',
                    'hierarchical' => $taxonomy['hierarchical']
                ];
            }
        }
        
        return $fields;
    }
    
    /**
     * 获取AI优化的上下文
     * 
     * @return array
     */
    public function get_ai_context() {
        $post_types = Aether_Post_Type_Discovery_Service::get_post_types(false);
        $context = [
            'post_types' => [],
            'fields_by_type' => []
        ];
        
        foreach ($post_types as $post_type) {
            // 简化的文章类型信息
            $type_info = [
                'name' => $post_type['name'],
                'label' => $post_type['label'],
                'description' => $post_type['description']
            ];
            
            // 只包含关键支持特性
            if (isset($post_type['supports']['thumbnail'])) {
                $type_info['has_thumbnail'] = true;
            }
            
            // 包含分类法
            if (!empty($post_type['taxonomies'])) {
                $type_info['taxonomies'] = array_map(function($tax) {
                    return [
                        'name' => $tax['name'],
                        'label' => $tax['label'],
                        'hierarchical' => $tax['hierarchical']
                    ];
                }, $post_type['taxonomies']);
            }
            
            $context['post_types'][] = $type_info;
            
            // 获取AI优化的字段
            $fields = $this->get_fields($post_type['name'], [
                'optimize_for_ai' => true,
                'custom_field_limit' => 20
            ]);
            
            if (!empty($fields)) {
                $context['fields_by_type'][$post_type['name']] = [
                    'post_type_label' => $post_type['label'],
                    'fields' => $fields
                ];
            }
        }
        
        return $context;
    }
    
    /**
     * 获取完整的字段信息
     * 
     * @param string $post_type
     * @return array
     */
    public function get_complete_fields($post_type) {
        return $this->get_fields($post_type, [
            'include_system' => true,
            'optimize_for_ai' => false,
            'custom_field_limit' => 200
        ]);
    }
    
    /**
     * 获取文章类型列表
     * 
     * @param bool $include_system
     * @return array
     */
    public function get_post_types($include_system = false) {
        return Aether_Post_Type_Discovery_Service::get_post_types($include_system);
    }
}