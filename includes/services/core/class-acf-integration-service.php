<?php
/**
 * ACF Integration Service
 * 
 * 负责与Advanced Custom Fields集成
 * 
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_ACF_Integration_Service {
    
    /**
     * 获取ACF字段
     *
     * @param string $post_type
     * @return array
     */
    public static function get_acf_fields($post_type) {
        $fields = [];

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $fields;
        }

        // ✅ 不依赖 Screen Context，获取所有字段组
        $all_field_groups = acf_get_field_groups();

        if (!$all_field_groups || !is_array($all_field_groups)) {
            return $fields;
        }

        // ✅ 手动匹配 location rules
        foreach ($all_field_groups as $group) {
            if (!isset($group['key']) || !isset($group['location'])) {
                continue;
            }

            // 检查这个字段组是否匹配当前 post_type
            if (!self::does_group_match_post_type($group, $post_type)) {
                continue;
            }

            $acf_fields = acf_get_fields($group['key']);

            if (!$acf_fields || !is_array($acf_fields)) {
                continue;
            }

            // 获取字段组的 location rules
            $location_rules = $group['location'] ?? [];

            $fields = array_merge($fields, self::format_fields_for_context($acf_fields, $location_rules));
        }

        return $fields;
    }
    
    /**
     * 获取ACF字段名列表
     *
     * @param string $post_type
     * @return array
     */
    public static function get_acf_field_names($post_type) {
        $names = [];

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $names;
        }

        // ✅ 不依赖 Screen Context，获取所有字段组
        $all_field_groups = acf_get_field_groups();

        if (!$all_field_groups || !is_array($all_field_groups)) {
            return $names;
        }

        // ✅ 手动匹配 location rules
        foreach ($all_field_groups as $group) {
            if (!isset($group['key']) || !isset($group['location'])) {
                continue;
            }

            // 检查这个字段组是否匹配当前 post_type
            if (!self::does_group_match_post_type($group, $post_type)) {
                continue;
            }

            $fields = acf_get_fields($group['key']);
            if (!$fields || !is_array($fields)) {
                continue;
            }

            foreach ($fields as $field) {
                $names = array_merge($names, self::get_storage_field_names($field));
                // 注意：不返回 repeater/group 的子字段名
                // 原因：
                // 1. repeater 子字段在数据库中存储为 parent_0_child, parent_1_child 等格式
                // 2. 这些会被 get_custom_fields() 中的正则表达式 /_\d+_/ 自动过滤
                // 3. 返回 parent_child 格式的名字会导致误判（过滤掉不该过滤的自定义字段）
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Format a normal field or expand a clone field into its real target fields.
     *
     * @param array $field ACF field array
     * @param int $depth Current nesting depth
     * @param array $seen_clone_targets Clone target keys already visited
     * @return array List of formatted field data
     */
    private static function format_field_or_clone($field, $depth = 0, $seen_clone_targets = []) {
        if (!is_array($field) || empty($field['type'])) {
            return [];
        }

        if ($field['type'] === 'clone') {
            return self::expand_clone_field($field, $depth, $seen_clone_targets);
        }

        $field_data = self::format_single_field($field, $depth, $seen_clone_targets);
        return $field_data === null ? [] : [$field_data];
    }

    /**
     * Format raw ACF fields for a context and add top-level location metadata.
     *
     * @param array $acf_fields Raw ACF fields
     * @param array $location_rules ACF location rules
     * @return array Formatted fields
     */
    private static function format_fields_for_context($acf_fields, $location_rules) {
        $fields = [];

        if (!$acf_fields || !is_array($acf_fields)) {
            return $fields;
        }

        foreach ($acf_fields as $field) {
            foreach (self::format_field_or_clone($field) as $field_data) {
                $field_data['location_rules'] = $location_rules;
                $fields[] = $field_data;

                if (($field_data['type'] ?? '') === 'image') {
                    $fields[] = [
                        'name' => $field_data['name'] . '_url',
                        'label' => $field_data['label'] . ' URL',
                        'type' => 'url',
                        'source' => 'acf',
                        'description' => $field_data['label'] . ' 的URL地址',
                        'parent_field' => $field_data['name'],
                        'location_rules' => $location_rules,
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * Expand an ACF clone field into the fields it actually exposes.
     *
     * @param array $field Clone field
     * @param int $depth Current nesting depth
     * @param array $seen_clone_targets Clone target keys already visited
     * @return array List of formatted target fields
     */
    private static function expand_clone_field($field, $depth = 0, $seen_clone_targets = []) {
        if ($depth > 5) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[aether] ACF clone nesting too deep: ' . ($field['name'] ?? 'unknown'));
            }
            return [];
        }

        $expanded = [];
        $targets = (array)($field['clone'] ?? []);

        foreach ($targets as $target_key) {
            if (!is_string($target_key) || $target_key === '') {
                continue;
            }

            if (isset($seen_clone_targets[$target_key])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[aether] ACF clone recursion skipped: ' . $target_key);
                }
                continue;
            }

            $next_seen_targets = $seen_clone_targets;
            $next_seen_targets[$target_key] = true;

            foreach (self::get_clone_target_fields($target_key) as $target_field) {
                $target_field = self::apply_clone_prefix($target_field, $field);
                foreach (self::format_field_or_clone($target_field, $depth, $next_seen_targets) as $formatted_field) {
                    $expanded[] = $formatted_field;
                }
            }
        }

        return $expanded;
    }

    /**
     * Resolve a clone target key to raw ACF fields.
     *
     * @param string $target_key Field group key or field key
     * @return array Raw ACF fields
     */
    private static function get_clone_target_fields($target_key) {
        if (strpos($target_key, 'group_') === 0 && function_exists('acf_get_fields')) {
            $fields = acf_get_fields($target_key);
            return is_array($fields) ? $fields : [];
        }

        if (function_exists('acf_get_field')) {
            $field = acf_get_field($target_key);
            return is_array($field) ? [$field] : [];
        }

        return [];
    }

    /**
     * Apply ACF clone prefix settings to the cloned field name/label.
     *
     * @param array $target_field Raw target field
     * @param array $clone_field Clone field config
     * @return array Field with clone prefix settings applied
     */
    private static function apply_clone_prefix($target_field, $clone_field) {
        if (!is_array($target_field)) {
            return $target_field;
        }

        if (!empty($clone_field['prefix_name']) && !empty($clone_field['name']) && !empty($target_field['name'])) {
            $target_field['name'] = $clone_field['name'] . '_' . $target_field['name'];
        }

        if (!empty($clone_field['prefix_label']) && !empty($clone_field['label']) && !empty($target_field['label'])) {
            $target_field['label'] = trim($clone_field['label'] . ' ' . $target_field['label']);
        }

        return $target_field;
    }

    /**
     * Get ACF storage field names, including top-level fields exposed by clone fields.
     *
     * @param array $field ACF field array
     * @param int $depth Current nesting depth
     * @param array $seen_clone_targets Clone target keys already visited
     * @return array Field names
     */
    private static function get_storage_field_names($field, $depth = 0, $seen_clone_targets = []) {
        if (!is_array($field) || empty($field['name']) || empty($field['type'])) {
            return [];
        }

        $names = [$field['name']];

        if ($field['type'] !== 'clone' || $depth > 5) {
            return $names;
        }

        $targets = (array)($field['clone'] ?? []);
        foreach ($targets as $target_key) {
            if (!is_string($target_key) || $target_key === '' || isset($seen_clone_targets[$target_key])) {
                continue;
            }

            $next_seen_targets = $seen_clone_targets;
            $next_seen_targets[$target_key] = true;

            foreach (self::get_clone_target_fields($target_key) as $target_field) {
                $target_field = self::apply_clone_prefix($target_field, $field);
                $names = array_merge($names, self::get_storage_field_names($target_field, $depth + 1, $next_seen_targets));
            }
        }

        return array_values(array_unique($names));
    }
    
    /**
     * 格式化单个字段（统一处理顶层和子字段）
     *
     * @param array $field ACF 字段数组
     * @param int $depth 当前递归深度（防止栈溢出）
     * @return array 格式化后的字段数据
     */
    private static function format_single_field($field, $depth = 0, $seen_clone_targets = []) {
        // 防止无限递归（最多 5 层嵌套）
        if ($depth > 5) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[aether] ACF field nesting too deep: ' . ($field['name'] ?? 'unknown'));
            }
            return null;
        }

        // 验证必需字段
        if (!is_array($field) || empty($field['name']) || empty($field['type'])) {
            return null;
        }

        // 基础字段数据
        $field_data = [
            'name' => $field['name'],
            'label' => $field['label'] ?? $field['name'],
            'type' => $field['type'],
            'source' => 'acf',
            'description' => $field['instructions'] ?? $field['label'] ?? '',
            'instructions' => $field['instructions'] ?? '',
            'required' => (bool)($field['required'] ?? false),
            'placeholder' => $field['placeholder'] ?? '',
        ];

        // 选项型字段的 choices/multiple/default
        if (in_array($field['type'], ['select', 'checkbox', 'radio'], true)) {
            if (isset($field['choices'])) {
                $field_data['choices'] = $field['choices'];
            }
            if ($field['type'] === 'select') {
                $field_data['multiple'] = (bool)($field['multiple'] ?? false);
            } elseif ($field['type'] === 'checkbox') {
                $field_data['multiple'] = true;
            } else {
                $field_data['multiple'] = false;
            }
            if (array_key_exists('default_value', $field)) {
                $field_data['default'] = $field['default_value'];
            }
        }

        // 递归处理嵌套字段（repeater, group）
        if (in_array($field['type'], ['repeater', 'group'], true) && !empty($field['sub_fields']) && is_array($field['sub_fields'])) {
            $sub_fields = [];
            foreach ($field['sub_fields'] as $sub_field) {
                foreach (self::format_field_or_clone($sub_field, $depth + 1, $seen_clone_targets) as $formatted_sub) {
                    $sub_fields[] = $formatted_sub;
                }
            }
            if (!empty($sub_fields)) {
                $field_data['sub_fields'] = $sub_fields;
            }
        }

        // 处理 flexible_content 字段
        if ($field['type'] === 'flexible_content' && !empty($field['layouts']) && is_array($field['layouts'])) {
            $layouts = [];
            foreach ($field['layouts'] as $layout) {
                if (!is_array($layout) || empty($layout['name'])) {
                    continue;
                }

                $layout_data = [
                    'name' => $layout['name'],
                    'label' => $layout['label'] ?? $layout['name'],
                ];

                // 处理 layout 的 sub_fields
                if (!empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                    $sub_fields = [];
                    foreach ($layout['sub_fields'] as $sub_field) {
                        foreach (self::format_field_or_clone($sub_field, $depth + 1, $seen_clone_targets) as $formatted_sub) {
                            $sub_fields[] = $formatted_sub;
                        }
                    }
                    if (!empty($sub_fields)) {
                        $layout_data['sub_fields'] = $sub_fields;
                    }
                }

                $layouts[] = $layout_data;
            }
            if (!empty($layouts)) {
                $field_data['layouts'] = $layouts;
            }
        }

        return $field_data;
    }

    /**
     * 检查字段组是否匹配给定的 post_type
     *
     * 实现手动 location rules 匹配，不依赖 WordPress screen context
     * Location rules 结构：OR of ANDs - [[rule1 AND rule2], [rule3 AND rule4]]
     *
     * 支持的规则类型：
     * - post_type: 文章类型
     * - post_taxonomy: 文章所属分类法（如 product_cat:electronics）
     * - post_category: 文章分类（category taxonomy 的快捷方式）
     * - post_format: 文章格式
     * - post_status: 文章状态（通常只考虑 publish）
     * - post_template: 文章模板
     * - page_template: 页面模板（仅对 page 类型有效）
     * - page_type: 页面类型（front_page, posts_page, top_level, parent, child）
     * - page_parent: 页面父级
     * - page: 具体页面
     * - post: 具体文章
     * - 动态 taxonomy 名称（如 category, post_tag, product_cat）
     *
     * @param array $group ACF 字段组
     * @param string $post_type 要匹配的 post type
     * @return bool
     */
    private static function does_group_match_post_type($group, $post_type) {
        if (empty($group['location']) || !is_array($group['location'])) {
            return false;
        }

        // Location rules 是 OR of ANDs 结构
        foreach ($group['location'] as $rule_group) {
            if (!is_array($rule_group)) {
                continue;
            }

            $result = self::evaluate_rule_group_for_post_type($rule_group, $post_type);
            if ($result === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * 评估一组规则是否匹配给定的 post_type
     *
     * @param array $rule_group 规则组（AND 关系）
     * @param string $post_type 要匹配的 post type
     * @return bool|null true=匹配, false=不匹配, null=无法判断（没有相关规则）
     */
    private static function evaluate_rule_group_for_post_type($rule_group, $post_type) {
        $has_relevant_rule = false;
        $all_match = true;

        // 与当前 post 上下文无关的规则类型（用于 user、comment、widget 等）
        $irrelevant_params = [
            'user_role',
            'user_form',
            'current_user',
            'current_user_role',
            'comment',
            'widget',
            'nav_menu',
            'nav_menu_item',
            'attachment',
            'block',
            'options_page',
        ];

        foreach ($rule_group as $rule) {
            if (!is_array($rule) || !isset($rule['param']) || !isset($rule['operator']) || !isset($rule['value'])) {
                continue;
            }

            $param = $rule['param'];
            $operator = $rule['operator'];
            $value = $rule['value'];

            // 跳过与 post 上下文无关的规则
            if (in_array($param, $irrelevant_params, true)) {
                // 如果这组规则只有不相关的规则，不应该匹配任何 post_type
                continue;
            }

            // 1. post_type 规则 - 最直接的匹配
            if ($param === 'post_type') {
                $has_relevant_rule = true;
                $matches = ($value === $post_type || $value === 'all');

                if ($operator === '!=') {
                    $matches = !$matches;
                }

                if (!$matches) {
                    $all_match = false;
                    break;
                }
            }
            // 2. post_taxonomy 规则 - 检查 post_type 是否关联到该 taxonomy
            elseif ($param === 'post_taxonomy') {
                $has_relevant_rule = true;
                $matches = self::match_post_taxonomy_rule($value, $post_type);

                if ($operator === '!=') {
                    $matches = !$matches;
                }

                if (!$matches) {
                    $all_match = false;
                    break;
                }
            }
            // 3. post_category 规则 - category taxonomy 的快捷方式
            elseif ($param === 'post_category') {
                $has_relevant_rule = true;
                $taxonomies = get_object_taxonomies($post_type);
                $matches = in_array('category', $taxonomies, true);

                if ($operator === '!=') {
                    $matches = !$matches;
                }

                if (!$matches) {
                    $all_match = false;
                    break;
                }
            }
            // 4. post_format 规则 - 只有 post 类型支持 post formats
            elseif ($param === 'post_format') {
                $has_relevant_rule = true;
                // 检查该 post_type 是否支持 post-formats
                $matches = post_type_supports($post_type, 'post-formats');

                if ($operator === '!=') {
                    $matches = !$matches;
                }

                if (!$matches) {
                    $all_match = false;
                    break;
                }
            }
            // 5. post_status 规则 - 对于获取字段，我们只关心 publish 状态
            elseif ($param === 'post_status') {
                $has_relevant_rule = true;
                // 包容性策略：只要 post_type 匹配就行，状态规则不影响
                // 因为我们是获取字段定义，不是检查具体文章
                $matches = true;
            }
            // 6. post_template / post 规则 - 用于具体文章
            elseif ($param === 'post_template' || $param === 'post') {
                $has_relevant_rule = true;
                // 包容性策略：只要是这个 post_type 的模板，就应该包含
                // 具体的文章/模板筛选在运行时由 ACF 处理
                $matches = true;
            }
            // 7. page_template / page_type / page_parent / page 规则 - 仅对 page 有效
            elseif (in_array($param, ['page_template', 'page_type', 'page_parent', 'page'], true)) {
                $has_relevant_rule = true;
                // 包容性策略：只要 post_type 是 page 就应该包含
                // 忽略 != 操作符，具体的模板/类型筛选在运行时由 ACF 处理
                $matches = ($post_type === 'page');

                if (!$matches) {
                    $all_match = false;
                    break;
                }
            }
            // 8. taxonomy 规则 - 用于 taxonomy term 编辑页面，不是 post
            elseif ($param === 'taxonomy') {
                // taxonomy 规则用于 term 编辑页面，不匹配任何 post_type
                // 但我们不标记为 relevant，让其他规则决定
                continue;
            }
            // 9. 动态 taxonomy 名称作为 param（如 category, post_tag, product_cat）
            elseif (taxonomy_exists($param)) {
                $has_relevant_rule = true;
                $taxonomies = get_object_taxonomies($post_type);
                $matches = in_array($param, $taxonomies, true);

                if ($operator === '!=') {
                    $matches = !$matches;
                }

                if (!$matches) {
                    $all_match = false;
                    break;
                }
            }
            // 10. 未知规则类型 - 记录日志，保守处理
            else {
                // 未知规则类型，不标记为 relevant，让其他规则决定
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[aether] Unknown ACF location rule param: %s (operator: %s, value: %s)',
                        $param,
                        $operator,
                        is_string($value) ? $value : wp_json_encode($value)
                    ));
                }
                continue;
            }
        }

        // 如果没有相关规则，返回 null 表示无法判断
        if (!$has_relevant_rule) {
            return null;
        }

        return $all_match;
    }

    /**
     * 匹配 post_taxonomy 规则
     *
     * @param string $value 规则值（如 "product_cat:electronics" 或 "product_cat"）
     * @param string $post_type 要匹配的 post type
     * @return bool
     */
    private static function match_post_taxonomy_rule($value, $post_type) {
        // 解析 taxonomy:term 格式
        if (strpos($value, ':') !== false) {
            list($taxonomy, $term_slug) = explode(':', $value, 2);
        } else {
            $taxonomy = $value;
        }

        // 检查该 taxonomy 是否属于当前 post_type
        $taxonomies = get_object_taxonomies($post_type);
        return in_array($taxonomy, $taxonomies, true);
    }

    /**
     * 获取 Taxonomy Term 的 ACF 字段
     *
     * 用于 taxonomy term 编辑页面的字段组（location rule: taxonomy == xxx）
     *
     * @param string $taxonomy Taxonomy 名称
     * @return array
     */
    public static function get_taxonomy_acf_fields($taxonomy) {
        $fields = [];

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $fields;
        }

        $all_field_groups = acf_get_field_groups();

        if (!$all_field_groups || !is_array($all_field_groups)) {
            return $fields;
        }

        foreach ($all_field_groups as $group) {
            if (!isset($group['key']) || !isset($group['location'])) {
                continue;
            }

            // 检查这个字段组是否匹配当前 taxonomy
            if (!self::does_group_match_taxonomy($group, $taxonomy)) {
                continue;
            }

            $acf_fields = acf_get_fields($group['key']);

            if (!$acf_fields || !is_array($acf_fields)) {
                continue;
            }

            // 获取字段组的 location rules
            $location_rules = $group['location'] ?? [];

            $fields = array_merge($fields, self::format_fields_for_context($acf_fields, $location_rules));
        }

        return $fields;
    }

    /**
     * 检查字段组是否匹配给定的 taxonomy
     *
     * @param array $group ACF 字段组
     * @param string $taxonomy 要匹配的 taxonomy
     * @return bool
     */
    private static function does_group_match_taxonomy($group, $taxonomy) {
        if (empty($group['location']) || !is_array($group['location'])) {
            return false;
        }

        // Location rules 是 OR of ANDs 结构
        foreach ($group['location'] as $rule_group) {
            if (!is_array($rule_group)) {
                continue;
            }

            $all_match = true;
            $has_taxonomy_rule = false;

            foreach ($rule_group as $rule) {
                if (!is_array($rule) || !isset($rule['param'])) {
                    continue;
                }

                $param = $rule['param'];
                $operator = $rule['operator'] ?? '==';
                $value = $rule['value'] ?? '';

                // 匹配 taxonomy 规则
                if ($param === 'taxonomy') {
                    $has_taxonomy_rule = true;

                    // 处理 "taxonomy:term" 或 "taxonomy" 格式
                    $rule_taxonomy = $value;
                    if (strpos($value, ':') !== false) {
                        list($rule_taxonomy, $term) = explode(':', $value, 2);
                    }

                    $matches = ($rule_taxonomy === $taxonomy || $value === 'all');

                    if ($operator === '!=') {
                        $matches = !$matches;
                    }

                    if (!$matches) {
                        $all_match = false;
                        break;
                    }
                }
            }

            // 如果这组规则有 taxonomy 规则且全部匹配
            if ($has_taxonomy_rule && $all_match) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取 User/Author 的 ACF 字段.
     *
     * 用于 author 动态模板。ACF 将 user meta 字段挂在 user_form/user_role
     * location 下，模板里通过 get_field($field, 'user_' . $author_id) 访问。
     *
     * @return array
     */
    public static function get_user_acf_fields() {
        $fields = [];

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $fields;
        }

        $all_field_groups = acf_get_field_groups();

        if (!$all_field_groups || !is_array($all_field_groups)) {
            return $fields;
        }

        foreach ($all_field_groups as $group) {
            if (!isset($group['key']) || !isset($group['location'])) {
                continue;
            }

            if (!self::does_group_match_user($group)) {
                continue;
            }

            $fields = array_merge(
                $fields,
                self::format_fields_for_context(acf_get_fields($group['key']), $group['location'] ?? [])
            );
        }

        return $fields;
    }

    /**
     * 获取真实 ACF Options Page 的字段.
     *
     * @return array
     */
    public static function get_options_acf_fields() {
        $fields = [];

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return $fields;
        }

        $option_page_slugs = self::get_registered_options_page_slugs();
        if (empty($option_page_slugs)) {
            return $fields;
        }

        $all_field_groups = acf_get_field_groups();

        if (!$all_field_groups || !is_array($all_field_groups)) {
            return $fields;
        }

        foreach ($all_field_groups as $group) {
            if (!isset($group['key']) || !isset($group['location'])) {
                continue;
            }

            if (!self::does_group_match_options_page($group, $option_page_slugs)) {
                continue;
            }

            $fields = array_merge(
                $fields,
                self::format_fields_for_context(acf_get_fields($group['key']), $group['location'] ?? [])
            );
        }

        return $fields;
    }

    /**
     * Check whether a field group targets user meta.
     *
     * @param array $group ACF field group
     * @return bool
     */
    private static function does_group_match_user($group) {
        if (empty($group['location']) || !is_array($group['location'])) {
            return false;
        }

        foreach ($group['location'] as $rule_group) {
            if (!is_array($rule_group)) {
                continue;
            }

            $has_user_rule = false;
            $has_conflicting_object_rule = false;

            foreach ($rule_group as $rule) {
                if (!is_array($rule) || empty($rule['param'])) {
                    continue;
                }

                $param = $rule['param'];

                if (in_array($param, ['user_form', 'user_role'], true)) {
                    $has_user_rule = true;
                    continue;
                }

                if (in_array($param, ['current_user', 'current_user_role'], true)) {
                    continue;
                }

                $has_conflicting_object_rule = true;
                break;
            }

            if ($has_user_rule && !$has_conflicting_object_rule) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a field group targets a registered ACF options page.
     *
     * @param array $group ACF field group
     * @param array $option_page_slugs Registered option page slugs
     * @return bool
     */
    private static function does_group_match_options_page($group, $option_page_slugs) {
        if (empty($group['location']) || !is_array($group['location'])) {
            return false;
        }

        foreach ($group['location'] as $rule_group) {
            if (!is_array($rule_group)) {
                continue;
            }

            foreach ($option_page_slugs as $option_page_slug) {
                if (self::does_options_page_rule_group_match_slug($rule_group, $option_page_slug)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check a single ACF location rule group against one options page slug.
     *
     * @param array $rule_group ACF AND rule group
     * @param string $option_page_slug Registered options page slug
     * @return bool
     */
    private static function does_options_page_rule_group_match_slug($rule_group, $option_page_slug) {
        $has_options_rule = false;

        foreach ($rule_group as $rule) {
            if (!is_array($rule) || empty($rule['param']) || !isset($rule['operator']) || !isset($rule['value'])) {
                continue;
            }

            $param = $rule['param'];
            $operator = $rule['operator'];
            $value = $rule['value'];

            if ($param === 'options_page') {
                $has_options_rule = true;
                $rule_matches_slug = ($value === 'all' || $value === $option_page_slug);
                $matches = $operator === '!=' ? !$rule_matches_slug : $rule_matches_slug;

                if (!$matches) {
                    return false;
                }

                continue;
            }

            if (in_array($param, ['current_user', 'current_user_role'], true)) {
                continue;
            }

            return false;
        }

        return $has_options_rule;
    }

    /**
     * Get registered ACF options page slugs.
     *
     * @return array
     */
    private static function get_registered_options_page_slugs() {
        if (!function_exists('acf_get_options_pages')) {
            return [];
        }

        $pages = acf_get_options_pages();
        if (!$pages || !is_array($pages)) {
            return [];
        }

        $slugs = [];
        foreach ($pages as $key => $page) {
            if (is_array($page) && !empty($page['menu_slug'])) {
                $slugs[] = $page['menu_slug'];
            } elseif (is_string($key)) {
                $slugs[] = $key;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * 检查ACF是否已激活
     *
     * @return bool
     */
    public static function is_active() {
        return function_exists('acf_get_field_groups');
    }
}
