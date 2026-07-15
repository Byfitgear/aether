<?php
/**
 * AI 设置页面模板
 * 
 * @package aether
 * @var array $settings 当前设置
 */

defined('ABSPATH') || exit;
?>

<!-- aether 设置页面 - 使用独立的容器避免 WordPress 样式冲突 -->
<?php if (!headers_sent()) { nocache_headers(); } ?>
<div class="wrap">
    <div id="aether-settings-root" class="aether-admin-app"></div>
</div>

<script>
    window.aetherSettings = {
        nonce: '<?php echo wp_create_nonce('wp_rest'); ?>',
        apiUrl: '<?php echo esc_url(rest_url('aether/v1/')); ?>',
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        ajaxNonce: '<?php echo wp_create_nonce('aether-editor'); ?>',
        disableTokenAutoVerify: <?php echo (defined('AETHER_DISABLE_TOKEN_AUTO_VERIFY') && AETHER_DISABLE_TOKEN_AUTO_VERIFY) ? 'true' : 'false'; ?>,
        serverSoftware: '<?php echo esc_js($_SERVER['SERVER_SOFTWARE'] ?? ''); ?>',
        settings: <?php echo wp_json_encode($settings); ?>,
        designSystemHtml: <?php echo wp_json_encode($settings['design_system_html'] ?? ''); ?>,
        postTypes: <?php
        $post_types = get_post_types(['public' => true], 'objects');
        $excluded_types = [
            'attachment',
            'aether_template'
        ];

        $filtered_types = [];
        foreach ($post_types as $post_type) {
            if (is_object($post_type) && !in_array($post_type->name, $excluded_types)) {
                $filtered_types[] = [
                    'name' => $post_type->name,
                    'label' => $post_type->label
                ];
            }
        }
        echo wp_json_encode($filtered_types);
        ?>,
        fieldsByType: <?php
        // 获取字段信息
        $fields_by_type = new stdClass();
        $available_types = $settings['post_types'] ?? ['page'];
        
        if (class_exists('Aether_Field_Discovery_API')) {
            $field_api = new Aether_Field_Discovery_API();
            foreach ($available_types as $type) {
                $fields = $field_api->get_fields($type);
                if (!empty($fields)) {
                    $fields_by_type->$type = $fields;
                }
            }
        }
        
        echo wp_json_encode($fields_by_type);
        ?>
    };
</script>
