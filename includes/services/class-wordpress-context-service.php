<?php
/**
 * WordPress Context Service
 * 
 * Collects comprehensive WordPress context data for AI assistant
 *
 * @package Aether
 * @subpackage Services
 */

defined('ABSPATH') || exit;

/**
 * WordPress Context Service Class
 */
class Aether_WordPress_Context_Service extends Aether_Base {
    
    /**
     * Default options for context collection
     */
    private $default_options = [
        'include_posts' => true,
        'include_fields' => true,
        'include_terms' => true,
        'include_menu_items' => true,
        'include_users' => false, // Privacy consideration
        'include_plugins' => true,
        'posts_per_type' => 20,
        'minimal' => false,
        // 优化字段用于AI：减少噪声字段
        'optimize_for_ai' => true,
        // 限制每个类型最多返回的自定义字段数量
        'custom_field_limit' => 100,
    ];
    
    /**
     * Initialize the service
     */
    protected function init() {
        // Service initialization if needed
    }
    
    /**
     * Get complete WordPress context
     * 
     * @param array $options Context collection options
     * @return array WordPress context data
     */
    public function get_context($options = []) {
        $options = wp_parse_args($options, $this->default_options);

        // Use minimal context if requested
        if ($options['minimal']) {
            return $this->get_minimal_context();
        }

        // Get editing context first to determine template_type
        $editing_context = $this->get_editing_context();

        // Get all fields (already includes ACF fields via Aether_Field_Discovery_API)
        $all_fields = $options['include_fields'] ? $this->get_fields($options) : [];

        // Filter fields by template_type
        $filtered_result = null;
        if (!empty($editing_context['template_type']) && class_exists('Aether_Template_Field_Filter_Service')) {
            $filtered_result = Aether_Template_Field_Filter_Service::filter_by_template_type(
                $editing_context['template_type'],
                $all_fields
            );
        }

        $context = [
            'site' => $this->get_site_info(),
            'post_types' => $this->get_post_types($options),
            'posts' => $options['include_posts'] ? $this->get_posts($options) : [],
            'fields' => $filtered_result ? $filtered_result['fields'] : $all_fields,
            'taxonomies' => $this->get_taxonomies(),
            'terms' => $options['include_terms'] ? $this->get_terms() : [],
            'menus' => $this->get_menus($options['include_menu_items']),
            'menu_locations' => $this->get_menu_locations(),
            'current_user' => $this->get_current_user(),
            'user_roles' => $this->get_user_roles(),
            'theme' => $this->get_theme_info(),
            'plugins' => $options['include_plugins'] ? $this->get_plugins() : [],
            'editing_context' => $editing_context,
            'generated_at' => current_time('c'),
            'api_version' => '1.0.0',
        ];

        // Add debug information in development mode
        if (defined('AETHER_DEV_MODE') && AETHER_DEV_MODE && $filtered_result) {
            $context['_debug'] = [
                'template_type' => $editing_context['template_type'],
                'template_context' => $filtered_result['template_context'] ?? null,
                'relevant_post_types' => $filtered_result['relevant_post_types'],
                'is_fallback' => $filtered_result['is_fallback'] ?? false,
                'filtering_enabled' => true,
                'fields' => [
                    'original_post_types' => array_keys($all_fields),
                    'filtered_post_types' => array_keys($filtered_result['fields']),
                ],
            ];
        }

        return $context;
    }
    
    /**
     * Get minimal context for performance
     * 
     * @return array Minimal context data
     */
    public function get_minimal_context() {
        return [
            'site_url' => get_site_url(),
            'current_post_id' => get_the_ID(),
            'current_post_type' => get_post_type(),
            'post_types' => array_keys($this->get_post_types([])),
            'taxonomies' => array_keys($this->get_taxonomies()),
            'menus' => array_map(function($menu) {
                return [
                    'id' => $menu->term_id,
                    'name' => $menu->name,
                ];
            }, wp_get_nav_menus()),
            'current_user_role' => $this->get_current_user_role(),
        ];
    }
    
    /**
     * Get site information
     * 
     * @return array Site info
     */
    private function get_site_info() {
        return [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => get_site_url(),
            'home_url' => get_home_url(),
            'admin_url' => admin_url(),
            'language' => get_bloginfo('language'),
            'timezone' => wp_timezone_string(),
            'date_format' => get_option('date_format'),
            'time_format' => get_option('time_format'),
        ];
    }
    
    /**
     * Get post types
     * 
     * @param array $options Options
     * @return array Post types keyed by name
     */
    private function get_post_types($options) {
        $post_types = [];
        $all_types = get_post_types(['public' => true], 'objects');
        
        // Exclude system and plugin post types
        $excluded = [
            'attachment',
            'aether_template',
        ];
        
        foreach ($all_types as $type_name => $type_obj) {
            if (in_array($type_name, $excluded)) {
                continue;
            }
            
            if (!empty($options['post_type_filter']) && !in_array($type_name, $options['post_type_filter'])) {
                continue;
            }
            
            $post_types[$type_name] = [
                'name' => $type_name,
                'label' => $type_obj->labels->name,
                'singular_label' => $type_obj->labels->singular_name,
                'description' => $type_obj->description,
                'public' => $type_obj->public,
                'hierarchical' => $type_obj->hierarchical,
                'supports' => isset($type_obj->supports) ? array_values((array) $type_obj->supports) : [],
                'has_archive' => $type_obj->has_archive,
                'rewrite' => $type_obj->rewrite,
                'menu_icon' => $type_obj->menu_icon,
                'menu_position' => $type_obj->menu_position,
                'show_in_rest' => $type_obj->show_in_rest,
                'rest_base' => $type_obj->rest_base,
                'capability_type' => $type_obj->capability_type,
            ];
        }
        
        return $post_types;
    }
    
    /**
     * Get posts grouped by post type
     * 
     * @param array $options Options
     * @return array Posts grouped by type
     */
    private function get_posts($options) {
        $posts_by_type = [];
        $post_types = array_keys($this->get_post_types($options));
        
        foreach ($post_types as $post_type) {
            $query_args = [
                'post_type' => $post_type,
                'posts_per_page' => $options['posts_per_type'],
                'post_status' => ['publish', 'draft', 'private'],
                'orderby' => 'modified',
                'order' => 'DESC',
            ];
            
            $posts = get_posts($query_args);
            $posts_by_type[$post_type] = [];
            
            foreach ($posts as $post) {
                $posts_by_type[$post_type][] = [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'slug' => $post->post_name,
                    'status' => $post->post_status,
                    'type' => $post->post_type,
                    'url' => get_permalink($post),
                    'parent_id' => $post->post_parent ?: null,
                    'menu_order' => $post->menu_order,
                    'created_at' => get_the_date('c', $post),
                    'modified_at' => get_the_modified_date('c', $post),
                    'author_id' => $post->post_author,
                    'excerpt' => $post->post_excerpt,
                    'featured_image_id' => get_post_thumbnail_id($post),
                    'template' => get_page_template_slug($post),
                ];
            }
        }
        
        return $posts_by_type;
    }
    
    /**
     * Get fields for post types
     * 
     * @param array $options Options
     * @return array Fields grouped by post type
     */
    private function get_fields($options) {
        $fields_by_type = [];
        $post_types = array_keys($this->get_post_types($options));
        
        // Use existing field discovery if available
        if (class_exists('Aether_Field_Discovery_API')) {
            $field_api = new Aether_Field_Discovery_API();
            foreach ($post_types as $post_type) {
                $fields = $field_api->get_fields($post_type, [
                    'include_system' => false,
                    'optimize_for_ai' => (bool)($options['optimize_for_ai'] ?? false),
                    'custom_field_limit' => intval($options['custom_field_limit'] ?? 100),
                ]);
                if (!empty($fields)) {
                    $fields_by_type[$post_type] = $this->format_fields($fields);
                }
            }
        }
        
        // Add core fields for all post types
        foreach ($post_types as $post_type) {
            if (!isset($fields_by_type[$post_type])) {
                $fields_by_type[$post_type] = [];
            }
            
            // Add standard WordPress fields
            $core_fields = $this->get_core_fields($post_type);
            $merged = array_merge($core_fields, $fields_by_type[$post_type]);

            // Deduplicate overlapping fields between core and builtin/custom sources
            $fields_by_type[$post_type] = $this->deduplicate_fields($merged);
        }
        
        return $fields_by_type;
    }

    /**
     * Remove duplicate fields that represent the same concept
     * across different sources (e.g. core vs builtin).
     *
     * Rules:
     * - If core 'post_title' exists, drop builtin 'title'
     * - If core 'post_content' exists, drop builtin 'content'
     * - If core 'post_excerpt' exists, drop builtin 'excerpt'
     * - If core 'featured_image' exists, drop builtin 'image'
     *
     * @param array $fields
     * @return array
     */
    private function deduplicate_fields($fields) {
        // Track which core fields are present
        $has_core = [
            'post_title' => false,
            'post_content' => false,
            'post_excerpt' => false,
            'featured_image' => false,
        ];

        foreach ($fields as $field) {
            if (($field['source'] ?? '') === 'core' && isset($has_core[$field['name'] ?? ''])) {
                $has_core[$field['name']] = true;
            }
        }

        // Map of core field => builtin duplicates
        $duplicate_map = [
            'post_title' => ['title'],
            'post_content' => ['content'],
            'post_excerpt' => ['excerpt'],
            'featured_image' => ['image'],
        ];

        $filtered = [];
        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            $source = $field['source'] ?? '';

            $is_duplicate = false;
            if ($source === 'builtin') {
                foreach ($duplicate_map as $core_name => $dups) {
                    if ($has_core[$core_name] && in_array($name, $dups, true)) {
                        $is_duplicate = true;
                        break;
                    }
                }
            }

            if (!$is_duplicate) {
                $filtered[] = $field;
            }
        }

        return $filtered;
    }
    
    /**
     * Format fields to match new structure
     *
     * @param array $fields Raw fields
     * @return array Formatted fields
     */
    private function format_fields($fields) {
        $formatted = [];

        foreach ($fields as $field) {
            $formatted_field = [
                'name' => $field['name'] ?? '',
                'label' => $field['label'] ?? $field['name'] ?? '',
                'type' => $field['type'] ?? 'text',
                'source' => $field['source'] ?? 'custom',
                'description' => $field['description'] ?? '',
                'required' => $field['required'] ?? false,
                'default_value' => $field['default'] ?? null,
                'choices' => $field['choices'] ?? null,
                'multiple' => $field['multiple'] ?? false,
                'placeholder' => $field['placeholder'] ?? '',
                'instructions' => $field['instructions'] ?? '',
            ];

            // 保留 sub_fields（用于 repeater/group 等嵌套字段）
            if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                $formatted_field['sub_fields'] = $field['sub_fields'];
            }

            // 保留 layouts（用于 flexible_content 字段）
            if (!empty($field['layouts']) && is_array($field['layouts'])) {
                $formatted_field['layouts'] = $field['layouts'];
            }

            // 保留 location_rules（ACF 字段的显示条件）
            if (!empty($field['location_rules']) && is_array($field['location_rules'])) {
                $formatted_field['location_rules'] = $field['location_rules'];
            }

            $formatted[] = $formatted_field;
        }

        return $formatted;
    }
    
    /**
     * Get core WordPress fields for a post type
     * 
     * @param string $post_type Post type
     * @return array Core fields
     */
    private function get_core_fields($post_type) {
        $post_type_obj = get_post_type_object($post_type);
        $fields = [];
        
        if ($post_type_obj) {
            $supports = get_all_post_type_supports($post_type);
            
            if ($supports['title'] ?? false) {
                $fields[] = [
                    'name' => 'post_title',
                    'label' => 'Title',
                    'type' => 'text',
                    'source' => 'core',
                    'required' => true,
                ];
            }
            
            if ($supports['editor'] ?? false) {
                $fields[] = [
                    'name' => 'post_content',
                    'label' => 'Content',
                    'type' => 'wysiwyg',
                    'source' => 'core',
                ];
            }
            
            if ($supports['excerpt'] ?? false) {
                $fields[] = [
                    'name' => 'post_excerpt',
                    'label' => 'Excerpt',
                    'type' => 'textarea',
                    'source' => 'core',
                ];
            }
            
            if ($supports['thumbnail'] ?? false) {
                $fields[] = [
                    'name' => 'featured_image',
                    'label' => 'Featured Image',
                    'type' => 'image',
                    'source' => 'core',
                ];
            }
        }
        
        return $fields;
    }
    
    /**
     * Get taxonomies
     *
     * @return array Taxonomies keyed by name
     */
    private function get_taxonomies() {
        $taxonomies = [];
        $all_taxonomies = get_taxonomies(['public' => true], 'objects');

        foreach ($all_taxonomies as $tax_name => $tax_obj) {
            $taxonomies[$tax_name] = [
                'name' => $tax_name,
                'label' => $tax_obj->labels->name,
                'singular_label' => $tax_obj->labels->singular_name,
                'description' => $tax_obj->description,
                'public' => $tax_obj->public,
                'hierarchical' => $tax_obj->hierarchical,
                'show_in_rest' => $tax_obj->show_in_rest,
                'rest_base' => $tax_obj->rest_base,
                'rewrite' => $tax_obj->rewrite,
                'object_types' => $tax_obj->object_type,
            ];
        }

        return $taxonomies;
    }
    
    /**
     * Get terms grouped by taxonomy
     * 
     * @return array Terms grouped by taxonomy
     */
    private function get_terms() {
        $terms_by_taxonomy = [];
        $taxonomies = array_keys($this->get_taxonomies());
        
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'number' => 100, // Limit for performance
            ]);
            
            if (!is_wp_error($terms)) {
                $terms_by_taxonomy[$taxonomy] = [];
                
                foreach ($terms as $term) {
                    $terms_by_taxonomy[$taxonomy][] = [
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'taxonomy' => $term->taxonomy,
                        'description' => $term->description,
                        'parent_id' => $term->parent ?: null,
                        'count' => $term->count,
                    ];
                }
            }
        }
        
        return $terms_by_taxonomy;
    }
    
    /**
     * Get menus
     * 
     * @param bool $include_items Include menu items
     * @return array Menus
     */
    private function get_menus($include_items = false) {
        $menus = [];
        $nav_menus = wp_get_nav_menus();
        
        foreach ($nav_menus as $menu) {
            $menu_data = [
                'id' => $menu->term_id,
                'name' => $menu->name,
                'slug' => $menu->slug,
                'description' => $menu->description,
                'locations' => [],
            ];
            
            // Get menu locations
            $locations = get_nav_menu_locations();
            foreach ($locations as $location => $menu_id) {
                if ($menu_id == $menu->term_id) {
                    $menu_data['locations'][] = $location;
                }
            }
            
            // Get menu items if requested
            if ($include_items) {
                $menu_items = wp_get_nav_menu_items($menu->term_id);
                $menu_data['items'] = [];
                
                if ($menu_items) {
                    foreach ($menu_items as $item) {
                        $menu_data['items'][] = [
                            'id' => $item->ID,
                            'title' => $item->title,
                            'url' => $item->url,
                            'type' => $item->type,
                            'object_type' => $item->object,
                            'object_id' => $item->object_id,
                            'parent_id' => $item->menu_item_parent ?: null,
                            'menu_order' => $item->menu_order,
                            'target' => $item->target ?: '_self',
                            'classes' => $item->classes,
                            'description' => $item->description,
                        ];
                    }
                }
            }
            
            $menus[] = $menu_data;
        }
        
        return $menus;
    }
    
    /**
     * Get menu locations
     * 
     * @return array Menu locations
     */
    private function get_menu_locations() {
        $locations = get_nav_menu_locations();
        $registered_locations = get_registered_nav_menus();
        $result = [];
        
        foreach ($registered_locations as $location => $_) {
            $menu_id = $locations[$location] ?? 0;
            if ($menu_id) {
                $menu = wp_get_nav_menu_object($menu_id);
                $result[$location] = $menu ? $menu->slug : '';
            } else {
                $result[$location] = '';
            }
        }
        
        return $result;
    }
    
    /**
     * Get current user information
     * 
     * @return array User info
     */
    private function get_current_user() {
        $current_user = wp_get_current_user();
        
        if (!$current_user->exists()) {
            return null;
        }
        
        return [
            'id' => $current_user->ID,
            'username' => $current_user->user_login,
            'email' => $current_user->user_email,
            'display_name' => $current_user->display_name,
            'roles' => $current_user->roles,
            'avatar_url' => get_avatar_url($current_user->ID),
        ];
    }
    
    /**
     * Get current user role
     * 
     * @return string Primary role
     */
    private function get_current_user_role() {
        $current_user = wp_get_current_user();
        return $current_user->roles[0] ?? 'guest';
    }
    
    /**
     * Get user roles
     * 
     * @return array User roles
     */
    private function get_user_roles() {
        global $wp_roles;
        $roles = [];
        
        foreach ($wp_roles->roles as $role_name => $role_info) {
            $roles[$role_name] = [
                'name' => $role_name,
                'display_name' => $role_info['name'],
                'capabilities' => array_keys(array_filter($role_info['capabilities'])),
            ];
        }
        
        return $roles;
    }
    
    /**
     * Get theme information
     * 
     * @return array Theme info
     */
    private function get_theme_info() {
        $theme = wp_get_theme();
        
        return [
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'template' => $theme->get_template(),
            'stylesheet' => $theme->get_stylesheet(),
            'description' => $theme->get('Description'),
            'author' => $theme->get('Author'),
            'screenshot' => $theme->get_screenshot(),
        ];
    }
    
    /**
     * Get active plugins
     * 
     * @return array Active plugins
     */
    private function get_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $plugins = [];
        
        foreach ($active_plugins as $plugin_file) {
            if (isset($all_plugins[$plugin_file])) {
                $plugin_data = $all_plugins[$plugin_file];
                $plugins[] = [
                    'name' => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                    'active' => true,
                    'description' => $plugin_data['Description'],
                ];
            }
        }
        
        return $plugins;
    }
    
    /**
     * Get editing context
     * 
     * @return array Editing context
     */
    private function get_editing_context() {
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 
                   (isset($_GET['template_id']) ? intval($_GET['template_id']) : 0);
        
        $context = [
            'post_id' => $post_id,
            'post_type' => null,
            'template_type' => null,
            'is_template' => false,
            'user' => $this->get_current_user(),
            'can_edit' => false,
            'can_publish' => false,
            'can_delete' => false,
        ];
        
        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $context['post_type'] = $post->post_type;
                $context['is_template'] = ($post->post_type === 'aether_template');
                
                if ($context['is_template']) {
                    $context['template_type'] = get_post_meta($post_id, '_aether_template_type', true);
                }
                
                $context['can_edit'] = current_user_can('edit_post', $post_id);
                $context['can_publish'] = current_user_can('publish_posts');
                $context['can_delete'] = current_user_can('delete_post', $post_id);
            }
        }
        
        return $context;
    }

}
