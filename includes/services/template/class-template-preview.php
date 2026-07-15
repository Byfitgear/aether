<?php
/**
 * Template Preview Service
 *
 * @package Aether
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aether_Template_Preview class
 */
class Aether_Template_Preview {

	/**
	 * Get preview URL for a template type
	 *
	 * @param string $template_type Template type
	 * @return array Array with 'url' and 'message' keys
	 */
	public static function get_preview_url( $template_type ) {
		$template_types_manager = Aether_Dynamic_Template_Types::getInstance();
		return $template_types_manager->get_preview_url( $template_type );
	}

	/**
	 * Get first post of a type for preview
	 *
	 * @param string $post_type Post type
	 * @return WP_Post|null
	 */
	public static function get_preview_post( $post_type ) {
		$posts = get_posts( array(
			'post_type'      => $post_type,
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Get first term of a taxonomy for preview
	 *
	 * @param string $taxonomy Taxonomy name
	 * @return WP_Term|null
	 */
	public static function get_preview_term( $taxonomy ) {
		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'number'     => 1,
			'hide_empty' => false,
			'orderby'    => 'count',
			'order'      => 'DESC',
		) );

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			return $terms[0];
		}

		return null;
	}

	/**
	 * Generate 404 preview URL
	 *
	 * @return string
	 */
	public static function generate_404_url() {
		return home_url( '/aether-404-preview-' . wp_generate_password( 8, false ) );
	}
}