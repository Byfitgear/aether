<?php
/**
 * Footer template for themes that support custom footer
 * 
 * Include this file in your theme's footer.php like:
 * <?php get_template_part('footer', 'aether'); ?>
 * 
 * Or call the function directly:
 * <?php if (function_exists('aether_custom_footer')) aether_custom_footer(); ?>
 *
 * @package Aether
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Output custom footer if function exists
if ( function_exists( 'aether_custom_footer' ) ) {
	aether_custom_footer();
}