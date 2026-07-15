<?php
/**
 * Meta Field Discovery Service
 * 
 * 负责发现和管理WordPress元数据字段
 * 
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Meta_Field_Discovery_Service
{

    /**
     * 系统保留的元数据键
     */
    private static $system_meta_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_page_template',
        '_thumbnail_id',
        '_menu_item',
        '_wp_attached_file',
        '_wp_attachment_metadata',
        '_pingme',
        '_encloseme',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_old_date',
        '_wp_old_password',
        '_default_password_nag',
        '_wp_attachment_image_alt'
    ];

    /**
     * 获取内置字段
     * 
     * @param string $post_type
     * @param bool $optimize_for_ai
     * @return array
     */
    public static function get_builtin_fields($post_type, $optimize_for_ai = false)
    {
        $fields = [];

        // 基础字段
        $fields[] = [
            'name' => 'title',
            'label' => '标题',
            'type' => 'text',
            'source' => 'builtin',
            'description' => '文章标题'
        ];

        // 非AI优化模式下包含更多字段
        if (!$optimize_for_ai) {
            $fields = array_merge($fields, [
                [
                    'name' => 'content',
                    'label' => '内容',
                    'type' => 'wysiwyg',
                    'source' => 'builtin',
                    'description' => '文章主要内容'
                ],
                [
                    'name' => 'excerpt',
                    'label' => '摘要',
                    'type' => 'textarea',
                    'source' => 'builtin',
                    'description' => '文章摘要'
                ],
                [
                    'name' => 'url',
                    'label' => '链接',
                    'type' => 'url',
                    'source' => 'builtin',
                    'description' => '文章永久链接'
                ],
                [
                    'name' => 'publish_date',
                    'label' => '发布日期',
                    'type' => 'date',
                    'source' => 'builtin',
                    'description' => '文章发布日期'
                ],
                [
                    'name' => 'modify_date',
                    'label' => '修改日期',
                    'type' => 'date',
                    'source' => 'builtin',
                    'description' => '文章最后修改日期'
                ],
                [
                    'name' => 'author',
                    'label' => '作者',
                    'type' => 'user',
                    'source' => 'builtin',
                    'description' => '文章作者'
                ],
                [
                    'name' => 'author_display_name',
                    'label' => '作者显示名',
                    'type' => 'text',
                    'source' => 'builtin',
                    'description' => '作者的显示名称'
                ]
            ]);
        }

        // 特色图片
        if (post_type_supports($post_type, 'thumbnail')) {
            $fields[] = [
                'name' => 'image',
                'label' => '特色图片',
                'type' => 'image',
                'source' => 'builtin',
                'description' => '文章特色图片'
            ];

            if (!$optimize_for_ai) {
                $fields[] = [
                    'name' => 'image_url',
                    'label' => '特色图片URL',
                    'type' => 'url',
                    'source' => 'builtin',
                    'description' => '特色图片的URL地址'
                ];
            }
        }

        return $fields;
    }

    /**
     * 获取自定义字段
     * 
     * @param string $post_type
     * @param array $options
     * @param array $acf_field_names ACF字段名列表，用于排除
     * @return array
     */
    public static function get_custom_fields($post_type, $options = [], $acf_field_names = [])
    {
        global $wpdb;
        $fields = [];

        $defaults = [
            'include_system' => false,
            'optimize_for_ai' => false,
            'custom_field_limit' => 100
        ];
        $options = array_merge($defaults, $options);

        // 构建查询
        $query = "
            SELECT DISTINCT pm.meta_key, COUNT(*) as count
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key NOT LIKE '\_%'
            AND pm.meta_key NOT LIKE 'field_%'
        ";

        // AI优化模式下只获取高频字段
        if ($options['optimize_for_ai']) {
            $query .= " GROUP BY pm.meta_key HAVING count > 1 ORDER BY count DESC";
        } else {
            $query .= " GROUP BY pm.meta_key";
        }

        $query .= " LIMIT %d";

        $meta_keys = $wpdb->get_results($wpdb->prepare(
            $query,
            $post_type,
            $options['custom_field_limit']
        ));

        foreach ($meta_keys as $meta) {
            $meta_key = $meta->meta_key;

            // 跳过系统字段
            if (!$options['include_system']) {
                $skip = false;
                foreach (self::$system_meta_keys as $system_key) {
                    if ($meta_key !== null && is_string($meta_key) && strpos($meta_key, $system_key) === 0) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip)
                    continue;
            }

            // 跳过 ACF Repeater/Group 的行实例化字段（如 faq_0_question）
            // 这类键在数据库中为每一行生成，属于中间产物，不适合作为统一字段展示
            // 规则：任意位置包含 _<数字>_ 的 meta_key
            if (is_string($meta_key) && preg_match('/_\d+_/u', $meta_key)) {
                // 但如果该键刚好是已注册的ACF字段名（极少见），仍保留
                if (!in_array($meta_key, $acf_field_names, true)) {
                    continue;
                }
            }

            // 跳过已经在ACF中定义的字段
            if (in_array($meta_key, $acf_field_names)) {
                continue;
            }

            // 推断字段类型
            $type = self::infer_field_type($meta_key);

            $field_data = [
                'name' => $meta_key,
                'label' => ucwords(str_replace(['_', '-'], ' ', (string) $meta_key)),
                'type' => $type,
                'source' => 'custom_field',
                'description' => '自定义字段'
            ];

            // AI优化模式下包含使用频率
            if ($options['optimize_for_ai'] && isset($meta->count)) {
                $field_data['usage_count'] = $meta->count;
            }

            $fields[] = $field_data;
        }

        return $fields;
    }

    /**
     * 推断字段类型
     * 
     * @param string $field_name
     * @return string
     */
    public static function infer_field_type($field_name)
    {
        $field_name_lower = strtolower($field_name);

        // 根据字段名推断类型
        $type_patterns = [
            'number' => ['price', 'cost', 'amount', 'quantity', 'count', 'total'],
            'date' => ['date', 'time', 'datetime', 'created', 'updated', 'modified'],
            'email' => ['email', 'mail'],
            'url' => ['url', 'link', 'website', 'site'],
            'image' => ['image', 'photo', 'picture', 'img', 'thumbnail', 'avatar'],
            'boolean' => ['is_', 'has_', 'enable', 'disable', 'active', 'featured'],
            'textarea' => ['description', 'summary', 'bio', 'notes']
        ];

        foreach ($type_patterns as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if ($field_name_lower !== null && is_string($field_name_lower) && strpos($field_name_lower, $pattern) !== false) {
                    return $type;
                }
            }
        }

        return 'text';
    }

    /**
     * 检查元数据键是否为系统键
     * 
     * @param string $meta_key
     * @return bool
     */
    public static function is_system_meta_key($meta_key)
    {
        foreach (self::$system_meta_keys as $system_key) {
            if ($meta_key !== null && is_string($meta_key) && strpos($meta_key, $system_key) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取系统元数据键列表
     * 
     * @return array
     */
    public static function get_system_meta_keys()
    {
        return self::$system_meta_keys;
    }
}
