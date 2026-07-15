<?php
/**
 * Template renderer for Aether dynamic templates
 *
 * @package Aether
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get template content from global
$template_content = $GLOBALS['aether_template_content'] ?? '';

// Get header if not a partial template
get_header();

// Render the template using PHP execution
if ( ! empty( $template_content ) ) {
	$template_service = Aether_Template_Service::getInstance();
	$rendered_content = $template_service->render( $template_content );
	echo $rendered_content;
} else {
	// Fallback content
	echo '<div class="aether-template-error">';
	echo '<p>' . esc_html__( '模板内容为空', 'aether' ) . '</p>';
	echo '</div>';
}

// Get footer if not a partial template
get_footer();