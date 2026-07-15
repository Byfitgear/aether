<?php
/**
 * 编辑器页面模板
 * 
 * @package aether
 * @var WP_Post $post 当前编辑的文章
 * @var int $post_id 文章 ID
 * @var array $ai_settings AI 设置
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($post->post_title); ?> - aether Editor</title>
    <?php wp_head(); ?>
</head>
<body>
    <!-- React 应用挂载点 -->
    <div id="aether-editor-root"></div>
    
    <script>
        window.aetherEditor = {
            postId: <?php echo $post_id; ?>,
            nonce: '<?php echo wp_create_nonce('wp_rest'); ?>',
            apiUrl: '<?php echo esc_url(rest_url('aether/v1/')); ?>',
            restUrl: '<?php echo esc_url(rest_url('aether/v1/')); ?>',
            content: <?php echo wp_json_encode($post->post_content); ?>,
            aiSettings: <?php echo wp_json_encode($ai_settings); ?>,
            previewUrl: '<?php echo esc_url(get_permalink($post_id)); ?>',
            themeStylesheetUrl: '<?php echo esc_url(get_stylesheet_uri()); ?>'
        };
        
        // Also set aetherAjax for compatibility
        window.aetherAjax = {
            nonce: window.aetherEditor.nonce,
            restUrl: window.aetherEditor.restUrl
        };
    </script>
    
    <?php wp_footer(); ?>
</body>
</html>