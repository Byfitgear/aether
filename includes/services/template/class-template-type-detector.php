<?php
/**
 * Template Type Detector Service
 *
 * @package Aether
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aether_Template_Type_Detector class
 */
class Aether_Template_Type_Detector {

	/**
	 * Detect template type for current page
	 *
	 * @return string|false
	 */
	public static function detect_current_page_type() {
		// 404 page
		if ( is_404() ) {
			return '404';
		}

		// Search page
		if ( is_search() ) {
			return 'search';
		}

        // Front page (static): map to distinct front_page type
        if ( is_front_page() && ! is_home() ) {
            return 'front_page';
        }

		// Blog page (posts index)
		if ( is_home() && ! is_front_page() ) {
			return 'blog';
		}

		// Home page (when set to show latest posts)
		if ( is_home() && is_front_page() ) {
			return 'home';
		}

		// Author archive
		if ( is_author() ) {
			return 'author';
		}

		// Single post/page/CPT
		if ( is_singular() ) {
			$queried_object = get_queried_object();
			if ( $queried_object instanceof WP_Post ) {
				return 'single_' . $queried_object->post_type;
			}

			$queried_object_id = get_queried_object_id();
			if ( $queried_object_id ) {
				$post_type = get_post_type( $queried_object_id );
				if ( $post_type ) {
					return 'single_' . $post_type;
				}
			}

			$post_type = get_post_type();
			return $post_type ? 'single_' . $post_type : false;
		}

		// Post type archive
		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			return 'archive_' . $post_type;
		}

		// Category/Tag/Taxonomy archive
		if ( is_category() || is_tag() || is_tax() ) {
			$queried_object = get_queried_object();
			if ( $queried_object && isset( $queried_object->taxonomy ) ) {
				return 'taxonomy_' . $queried_object->taxonomy;
			}
		}

		// Default blog archive
		if ( is_archive() && ! is_post_type_archive() && ! is_author() && ! is_date() ) {
			return 'archive_post';
		}

		return false;
	}

	/**
	 * Get WordPress template filter for a template type
	 *
	 * @param string $template_type Template type
	 * @param array  $context Parsed template context
	 * @return string|false Filter name
	 */
	public static function get_template_filter( $template_type, $context ) {
		// Core types
		if ( empty( $context['subtype'] ) ) {
			$filters = array(
				'404'    => '404_template',
				'search' => 'search_template',
				'home'   => 'home_template',
				'blog'   => 'home_template', // Blog page uses same filter as home
				'author' => 'author_template',
			);
			return isset( $filters[ $context['type'] ] ) ? $filters[ $context['type'] ] : false;
		}

		// Dynamic types
		switch ( $context['type'] ) {
			case 'single':
				return 'single_template';
			
			case 'archive':
				return 'archive_template';
			
			case 'taxonomy':
				// WordPress uses specific filters for category/tag
				if ( $context['subtype'] === 'category' ) {
					return 'category_template';
				} elseif ( $context['subtype'] === 'post_tag' ) {
					return 'tag_template';
				} else {
					return 'taxonomy_template';
				}
		}

		return false;
	}
}
