<?php
/**
 * Template Field Filter Service
 *
 * 根据模板类型筛选相关的字段（包括 ACF 字段）
 *
 * 设计原则：
 * 1. ACF 字段已通过 Aether_ACF_Integration_Service 直接合并到 fields 中
 * 2. 此服务仅负责根据 template_type 过滤 post_types
 * 3. 采用包容性策略 - 宁可多包含，不遗漏
 *
 * @since 1.1.25
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Template_Field_Filter_Service {

    /**
     * 根据模板类型筛选字段
     *
     * @param string $template_type 模板类型（如 single_product, taxonomy_product_cat）
     * @param array $all_fields 所有字段（按 post_type 分组，已包含 ACF 字段）
     * @return array {
     *     @type array $fields 筛选后的字段（按 post_type/taxonomy 分组）
     *     @type array $relevant_post_types 相关的 post types
     *     @type array $template_context 解析后的模板上下文
     *     @type bool $is_fallback 是否使用了降级策略
     * }
     */
    public static function filter_by_template_type($template_type, $all_fields) {
        // 1. 解析模板类型
        $context = self::parse_template_type($template_type);

        // 2. 获取相关的 post_types
        $relevant_post_types = self::get_relevant_post_types($context);
        $is_fallback = false;

        // 3. 降级处理：如果无法确定相关 post_types，返回所有数据
        if (empty($relevant_post_types) && !self::is_no_field_template($context) && !self::is_context_only_template($context)) {
            $is_fallback = true;
            $relevant_post_types = array_keys($all_fields);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[aether] Template field filter fallback: unknown template_type "%s", returning all fields',
                    $template_type
                ));
            }
        }

        // 4. 过滤字段 - 只保留相关 post_type 的字段
        $filtered_fields = [];
        foreach ($relevant_post_types as $post_type) {
            if (isset($all_fields[$post_type])) {
                $filtered_fields[$post_type] = $all_fields[$post_type];
            }
        }

        // 5. 对于 taxonomy 模板，额外获取 taxonomy term 的 ACF 字段
        if ($context['type'] === 'taxonomy' && !empty($context['taxonomy'])) {
            $taxonomy = $context['taxonomy'];
            $taxonomy_fields = self::get_taxonomy_term_fields($taxonomy);

            if (!empty($taxonomy_fields)) {
                // 使用 "taxonomy:{name}" 格式作为 key，区分于 post_type
                $filtered_fields['taxonomy:' . $taxonomy] = $taxonomy_fields;
            }
        }

        // 6. Author templates can use user meta fields.
        if ($context['type'] === 'author') {
            $user_fields = self::get_author_user_fields();
            if (!empty($user_fields)) {
                $filtered_fields['user:author'] = $user_fields;
            }
        }

        // 7. ACF options fields are global and can be used by any template.
        $options_fields = self::get_options_fields();
        if (!empty($options_fields)) {
            $filtered_fields['acf:options'] = $options_fields;
        }

        return [
            'fields' => $filtered_fields,
            'relevant_post_types' => $relevant_post_types,
            'template_context' => $context,
            'is_fallback' => $is_fallback,
        ];
    }

    /**
     * 获取 taxonomy term 的字段（ACF 字段）
     *
     * @param string $taxonomy Taxonomy 名称
     * @return array 格式化后的字段数组
     */
    private static function get_taxonomy_term_fields($taxonomy) {
        // 获取 taxonomy term 的 ACF 字段
        $acf_fields = (class_exists('Aether_ACF_Integration_Service') && Aether_ACF_Integration_Service::is_active())
            ? Aether_ACF_Integration_Service::get_taxonomy_acf_fields($taxonomy)
            : [];

        // 添加 taxonomy term 的核心字段
        $core_fields = [
            [
                'name' => 'term_name',
                'label' => 'Term Name',
                'type' => 'text',
                'source' => 'core',
                'description' => 'The name of the taxonomy term',
            ],
            [
                'name' => 'term_slug',
                'label' => 'Term Slug',
                'type' => 'text',
                'source' => 'core',
                'description' => 'The slug of the taxonomy term',
            ],
            [
                'name' => 'term_description',
                'label' => 'Term Description',
                'type' => 'textarea',
                'source' => 'core',
                'description' => 'The description of the taxonomy term',
            ],
        ];

        return array_merge($core_fields, $acf_fields);
    }

    /**
     * 获取 author 模板可用的 User 字段.
     *
     * @return array
     */
    private static function get_author_user_fields() {
        $acf_fields = (class_exists('Aether_ACF_Integration_Service') && Aether_ACF_Integration_Service::is_active())
            ? Aether_ACF_Integration_Service::get_user_acf_fields()
            : [];

        $core_fields = [
            [
                'name' => 'author_id',
                'label' => 'Author ID',
                'type' => 'number',
                'source' => 'core',
                'description' => 'The queried author user ID',
            ],
            [
                'name' => 'author_display_name',
                'label' => 'Author Display Name',
                'type' => 'text',
                'source' => 'core',
                'description' => 'The public display name for the queried author',
            ],
            [
                'name' => 'author_description',
                'label' => 'Author Description',
                'type' => 'textarea',
                'source' => 'core',
                'description' => 'The author bio/description',
            ],
            [
                'name' => 'author_url',
                'label' => 'Author URL',
                'type' => 'url',
                'source' => 'core',
                'description' => 'The author website URL',
            ],
        ];

        return array_merge($core_fields, $acf_fields);
    }

    /**
     * 获取全局 ACF Options Page 字段.
     *
     * @return array
     */
    private static function get_options_fields() {
        return (class_exists('Aether_ACF_Integration_Service') && Aether_ACF_Integration_Service::is_active())
            ? Aether_ACF_Integration_Service::get_options_acf_fields()
            : [];
    }

    /**
     * 检查是否为不需要字段的模板类型
     *
     * @param array $context 模板上下文
     * @return bool
     */
    private static function is_no_field_template($context) {
        return $context['type'] === '404';
    }

    /**
     * 模板本身没有 post loop 对象，但仍可能使用全局 ACF options.
     *
     * @param array $context 模板上下文
     * @return bool
     */
    private static function is_context_only_template($context) {
        return in_array($context['type'] ?? '', ['template_part'], true);
    }

    /**
     * 解析模板类型
     *
     * @param string $template_type 模板类型
     * @return array {
     *     @type string $type 类型：single, archive, taxonomy, 或核心模板类型
     *     @type string|null $post_type Post type（对于 single/archive）
     *     @type string|null $taxonomy Taxonomy 名称（对于 taxonomy）
     *     @type array|null $related_post_types 相关的 post types（对于 taxonomy）
     *     @type bool|null $is_core 是否为核心模板类型
     * }
     */
    private static function parse_template_type($template_type) {
        // single_* pattern (如 single_product, single_post, single_page)
        if (preg_match('/^single_(.+)$/', $template_type, $matches)) {
            $post_type = $matches[1];
            return [
                'type' => 'single',
                'post_type' => $post_type,
                'is_page' => ($post_type === 'page'),
            ];
        }

        // archive_* pattern (如 archive_product, archive_post)
        if (preg_match('/^archive_(.+)$/', $template_type, $matches)) {
            return [
                'type' => 'archive',
                'post_type' => $matches[1]
            ];
        }

        // taxonomy_* pattern (如 taxonomy_product_cat, taxonomy_category)
        if (preg_match('/^taxonomy_(.+)$/', $template_type, $matches)) {
            $taxonomy = $matches[1];
            $tax_obj = get_taxonomy($taxonomy);

            return [
                'type' => 'taxonomy',
                'taxonomy' => $taxonomy,
                'related_post_types' => $tax_obj ? (array) $tax_obj->object_type : []
            ];
        }

        // Core templates (404, search, home, blog, author, date, etc.)
        $core_templates = [
            '404' => ['type' => '404', 'is_core' => true],
            'search' => ['type' => 'search', 'is_core' => true],
            'home' => ['type' => 'home', 'is_core' => true],
            'blog' => ['type' => 'blog', 'is_core' => true],
            'front_page' => ['type' => 'front_page', 'is_core' => true],
            'author' => ['type' => 'author', 'is_core' => true],
            'date' => ['type' => 'date', 'is_core' => true],
            'index' => ['type' => 'index', 'is_core' => true],
            'header' => ['type' => 'template_part', 'part' => 'header', 'is_core' => true],
            'footer' => ['type' => 'template_part', 'part' => 'footer', 'is_core' => true],
        ];

        if (isset($core_templates[$template_type])) {
            return $core_templates[$template_type];
        }

        // 未知模板类型
        return [
            'type' => $template_type,
            'is_unknown' => true
        ];
    }

    /**
     * 获取相关的 post_types
     *
     * @param array $context 模板上下文
     * @return array Post types 列表
     */
    private static function get_relevant_post_types($context) {
        $type = $context['type'] ?? '';

        switch ($type) {
            case 'single':
            case 'archive':
                // 单页和归档 - 返回对应的 post_type
                $post_type = $context['post_type'] ?? '';
                return $post_type ? [$post_type] : [];

            case 'taxonomy':
                // Taxonomy 归档 - 返回关联的 post_types
                return $context['related_post_types'] ?? [];

            case 'author':
                return self::get_public_content_post_types(false);

            case 'search':
                return self::get_public_content_post_types(true);

            case 'index':
                return self::get_public_content_post_types(false);

            case 'home':
            case 'blog':
            case 'date':
                // 通用页面 - 返回 post
                return ['post'];

            case 'front_page':
                // 首页可能是 page 或 post
                return ['page', 'post'];

            case '404':
                // 404 不需要字段
                return [];

            default:
                // 未知类型 - 返回空数组，触发降级
                return [];
        }
    }

    /**
     * 获取动态列表模板可能循环的公开内容类型.
     *
     * @param bool $search_only 是否仅返回可搜索类型
     * @return array
     */
    private static function get_public_content_post_types($search_only = false) {
        $args = ['public' => true];
        if ($search_only) {
            $args['exclude_from_search'] = false;
        }

        $post_types = get_post_types($args, 'names');
        if (!$post_types || !is_array($post_types)) {
            return ['post'];
        }

        $excluded = apply_filters('aether_field_context_excluded_post_types', [
            'attachment',
            'aether_template',
        ]);

        $post_types = array_values(array_filter($post_types, function($post_type) use ($excluded) {
            return !in_array($post_type, $excluded, true);
        }));

        return $post_types ?: ['post'];
    }
}
