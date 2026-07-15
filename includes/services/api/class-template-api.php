<?php

/**
 * Template API Service
 * 
 * Handles all template-related business logic
 * 
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Template_API
{

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {}

    /**
     * Get all template types with existing status
     * 
     * @return array
     */
    public function get_template_types()
    {
        $template_types_manager = Aether_Dynamic_Template_Types::getInstance();
        $all_types = $template_types_manager->get_template_types();

        // Get existing templates
        $existing_types = $this->get_existing_template_types();

        // Load default templates provider to check which types have defaults
        require_once AETHER_PATH . 'includes/services/template/class-default-templates-provider.php';
        $default_provider = Aether_Default_Templates_Provider::getInstance();

        // Format types for frontend
        $formatted_types = [];
        foreach ($all_types as $group_key => $group) {
            if (!empty($group['types'])) {
                foreach ($group['types'] as $type_key => $type_label) {
                    // Skip excluded template types (e.g., homepage and page single)
                    if (in_array($type_key, $this->get_excluded_template_types(), true)) {
                        continue;
                    }
                    // Check if default template exists for this type
                    $has_default = !empty($default_provider->get_default_template($type_key));

                    $formatted_types[] = [
                        'value' => $type_key,
                        'label' => $type_label,
                        'group' => $group['label'],
                        'exists' => in_array($type_key, $existing_types),
                        'has_default' => $has_default,
                        'simple_type' => $this->map_template_type($type_key),
                    ];
                }
            }
        }

        return [
            'types' => $formatted_types,
            'existing' => $existing_types,
        ];
    }

    /**
     * Get all templates
     * 
     * @param array $args Query arguments
     * @return array
     */
    public function get_templates($args = [])
    {
        $defaults = [
            'post_type' => 'aether_template',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $templates = get_posts($args);

        $formatted_templates = [];
        foreach ($templates as $template) {
            if ($template instanceof WP_Post) {
                $formatted_templates[] = $this->format_template($template);
            }
        }

        return [
            'templates' => $formatted_templates,
            'total' => count($formatted_templates),
        ];
    }

    /**
     * Get single template
     * 
     * @param int $id Template ID
     * @return array|WP_Error
     */
    public function get_template($id)
    {
        $template = get_post($id);

        if (!$template || $template->post_type !== 'aether_template') {
            return new WP_Error('template_not_found', __('模板不存在', 'aether'), ['status' => 404]);
        }

        return $this->format_template($template);
    }

    /**
     * Create template
     * 
     * @param array $data Template data
     * @return array|WP_Error
     */
    public function create_template($data)
    {
        $type = $data['type'] ?? '';
        $full_type = $data['full_type'] ?? '';
        $content = $data['content'] ?? '';

        // Validate required fields
        if (empty($full_type)) {
            return new WP_Error('missing_fields', __('缺少必要字段', 'aether'), ['status' => 400]);
        }

        // Note: We only hide these types from the creation UI,
        // but do not hard-block API creation to allow power users/admins
        // to create them if truly needed.

        // Check if template already exists
        if ($this->template_exists($full_type)) {
            return new WP_Error('template_exists', __('该类型的模板已存在', 'aether'), ['status' => 409]);
        }

        // Generate title
        $title = $this->generate_template_title($full_type, $type);

        // Prepare post data
        $post_data = [
            'post_title' => $title,
            'post_content' => '',
            'post_type' => 'aether_template',
            'post_status' => 'publish',
            'post_name' => sanitize_title($full_type),
            'meta_input' => [
                '_aether_template_type' => $full_type,
                Aether_Templates::CONTENT_META_KEY => Aether_Templates::prepare_content_for_meta($content),
            ],
        ];

        // Handle extracted CSS
        if (!empty($data['extractedCss'])) {
            $post_data['meta_input']['_aether_template_extracted_css'] = base64_encode($data['extractedCss']);
            $post_data['meta_input']['_aether_css_encoded'] = '1';
        }

        // Additional meta fields
        foreach (['post_type', 'taxonomy', 'term'] as $field) {
            if (!empty($data[$field])) {
                $post_data['meta_input'][$field] = $data[$field];
            }
        }

        // Create template
        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return new WP_Error('create_failed', __('创建模板失败', 'aether'), ['status' => 500]);
        }

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[Template API] 准备触发 aether_template_saved hook (create)，Template #%d, content 长度: %d',
                $post_id,
                strlen($content)
            ));
        }

        // Trigger hook
        do_action('aether_template_saved', [
            'id' => $post_id,
            'type' => $full_type,
            'content' => $content,
            'action' => 'create'
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[Template API] aether_template_saved hook 已触发 (create)，Template #%d',
                $post_id
            ));
        }

        return $this->get_template($post_id);
    }

    /**
     * Update template
     * 
     * @param int $id Template ID
     * @param array $data Update data
     * @return array|WP_Error
     */
    public function update_template($id, $data)
    {
        $template = get_post($id);

        if (!$template || $template->post_type !== 'aether_template') {
            return new WP_Error('template_not_found', __('模板不存在', 'aether'), ['status' => 404]);
        }

        $post_data = ['ID' => $id];

        // Update title if provided
        if (isset($data['title'])) {
            $post_data['post_title'] = $data['title'];
        }

        // Update slug if provided
        if (isset($data['slug'])) {
            $post_data['post_name'] = $data['slug'];
        }

        // Update post
        if (count($post_data) > 1) {
            $result = wp_update_post($post_data);
            if (is_wp_error($result)) {
                return new WP_Error('update_failed', __('更新模板失败', 'aether'), ['status' => 500]);
            }
        }

        // Update content in meta
        if (isset($data['content'])) {
            Aether_Templates::update_content_meta($id, $data['content']);

            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log(sprintf(
                    '[Template API] Template #%d content 已更新 (长度: %d)',
                    $id,
                    strlen($data['content'])
                ));
            }
        }

        // Update extracted CSS
        if (isset($data['extractedCss'])) {
            update_post_meta($id, '_aether_template_extracted_css', base64_encode($data['extractedCss']));
            update_post_meta($id, '_aether_css_encoded', '1');
        }

        // Trigger hook - only pass content if it was actually provided
        $hook_data = [
            'id' => $id,
            'type' => get_post_meta($id, '_aether_template_type', true),
            'action' => 'update'
        ];

        // Only include content if it was provided in the update
        if (isset($data['content'])) {
            $hook_data['content'] = $data['content'];
        }

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[Template API] 准备触发 aether_template_saved hook，Template #%d, content %s',
                $id,
                isset($hook_data['content']) ? '已提供' : '未提供'
            ));
        }

        do_action('aether_template_saved', $hook_data);

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[Template API] aether_template_saved hook 已触发，Template #%d',
                $id
            ));
        }

        return $this->get_template($id);
    }

    /**
     * Delete template
     * 
     * @param int $id Template ID
     * @return bool|WP_Error
     */
    public function delete_template($id)
    {
        $template = get_post($id);

        if (!$template || $template->post_type !== 'aether_template') {
            return new WP_Error('template_not_found', __('模板不存在', 'aether'), ['status' => 404]);
        }

        $result = wp_delete_post($id, true);

        if (!$result) {
            return new WP_Error('delete_failed', __('删除模板失败', 'aether'), ['status' => 500]);
        }

        return true;
    }

    /**
     * Get template preview URL
     * 
     * @param int $id Template ID
     * @return array|WP_Error
     */
    public function get_template_preview_url($id)
    {
        $template = get_post($id);

        if (!$template || $template->post_type !== 'aether_template') {
            return new WP_Error('template_not_found', __('模板不存在', 'aether'), ['status' => 404]);
        }

        $template_type = get_post_meta($id, '_aether_template_type', true);

        // Use Dynamic Template Types Manager to get the preview URL
        $template_types_manager = Aether_Dynamic_Template_Types::getInstance();
        $preview_data = $template_types_manager->get_preview_url($template_type);

        return [
            'url' => $preview_data['url'] ?? '',
            'message' => $preview_data['message'] ?? __('无法生成预览URL', 'aether'),
            'available' => $preview_data['available'] ?? false
        ];
    }

    /**
     * Check if template exists for given type
     * 
     * @param string $type Template type
     * @return bool
     */
    private function template_exists($type)
    {
        $existing = get_posts([
            'post_type' => 'aether_template',
            'meta_query' => [
                [
                    'key' => '_aether_template_type',
                    'value' => $type,
                ],
            ],
            'posts_per_page' => 1,
        ]);

        return !empty($existing);
    }

    /**
     * Get existing template types
     * 
     * @return array
     */
    private function get_existing_template_types()
    {
        $templates = get_posts([
            'post_type' => 'aether_template',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $types = [];
        foreach ($templates as $template) {
            if ($template instanceof WP_Post) {
                $type = get_post_meta($template->ID, '_aether_template_type', true);
                if ($type) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * Generate template title based on type
     * 
     * @param string $full_type Full template type
     * @param string $type Simple type
     * @return string
     */
    private function generate_template_title($full_type, $type)
    {
        $template_types_manager = Aether_Dynamic_Template_Types::getInstance();
        $all_types = $template_types_manager->get_template_types();

        foreach ($all_types as $group) {
            if (isset($group['types'][$full_type])) {
                return $group['types'][$full_type];
            }
        }

        return ucfirst(str_replace('_', ' ', (string) $type));
    }

    /**
     * Map complex template type to simple type
     * 
     * @param string $type_key Template type key
     * @return string
     */
    private function map_template_type($type_key)
    {
        // Core types
        if (in_array($type_key, ['404', 'search', 'home', 'blog', 'author', 'header', 'footer'])) {
            return $type_key;
        }

        // Post type singles
        if ($type_key !== null && strpos($type_key, 'single_') === 0) {
            return 'single';
        }

        // Post type archives
        if ($type_key !== null && strpos($type_key, 'archive_') === 0) {
            return 'archive';
        }

        // Taxonomies
        if ($type_key !== null && strpos($type_key, 'taxonomy_') === 0) {
            $taxonomy = substr($type_key, 9);
            if ($taxonomy === 'category') {
                return 'category';
            }
            if ($taxonomy === 'post_tag') {
                return 'tag';
            }
            return 'archive';
        }

        return $type_key;
    }

    /**
     * Get excluded template types that users cannot create
     *
     * @return array
     */
    private function get_excluded_template_types()
    {
        // Exclude homepage templates and page single template
        // - 'front_page': static homepage set to a Page
        // - 'home': homepage showing latest posts
        // - 'single_page': single template for the Page post type
        $excluded = ['front_page', 'home', 'single_page'];

        // Allow customization via filter if needed
        return apply_filters('aether_excluded_template_types', $excluded);
    }

    /**
     * Get default template content
     * 
     * @param string $type Template type
     * @return array|WP_Error
     */
    public function get_default_template($type)
    {
        // Load default templates provider
        require_once AETHER_PATH . 'includes/services/template/class-default-templates-provider.php';
        $default_provider = Aether_Default_Templates_Provider::getInstance();

        $content = $default_provider->get_default_template($type);

        if (empty($content)) {
            return new WP_Error('no_default_template', __('该类型没有默认模板', 'aether'), ['status' => 404]);
        }

        // Get template types for label
        $template_types = Aether_Dynamic_Template_Types::getInstance();
        $all_types = $template_types->get_template_types();

        $label = $type;
        foreach ($all_types as $group) {
            if (isset($group['types'][$type])) {
                $label = $group['types'][$type];
                break;
            }
        }

        return [
            'id' => 0,
            'title' => sprintf(__('默认模板 - %s', 'aether'), $label),
            'slug' => '',
            'content' => $content,
            'type' => $this->map_template_type($type),
            'full_type' => $type,
            'is_default' => true,
            'created_at' => '',
            'updated_at' => '',
        ];
    }

    /**
     * Format template for response
     * 
     * @param WP_Post $template
     * @return array
     */
    private function format_template($template)
    {
        $template_type = get_post_meta($template->ID, '_aether_template_type', true);
        $content = get_post_meta($template->ID, Aether_Templates::CONTENT_META_KEY, true);

        // Extract simple type from full type
        $simple_type = 'single';
        if ($template_type) {
            $simple_type = $this->map_template_type($template_type);
        }

        return [
            'id' => $template->ID,
            'title' => $template->post_title,
            'slug' => $template->post_name,
            'content' => $content ?: '',
            'type' => $simple_type,
            'full_type' => $template_type ?: '',
            'created_at' => $template->post_date,
            'updated_at' => $template->post_modified,
        ];
    }
}
