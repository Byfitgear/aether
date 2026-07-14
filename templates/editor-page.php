<?php
defined('ABSPATH') || exit;
$post = $post ?? null;
$post_id = $post_id ?? 0;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html__('WordExpress 编辑器', 'wordexpress'); ?></title>
    <?php wp_head(); ?>
</head>
<body class="wordexpress-editor-page">
    <div id="wordexpress-app"></div>
    <?php wp_footer(); ?>
</body>
</html>
