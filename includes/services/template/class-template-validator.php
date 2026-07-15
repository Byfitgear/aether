<?php
/**
 * Template Validator Service
 *
 * @package Aether
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aether_Template_Validator class
 */
class Aether_Template_Validator {

	/**
	 * Validate template data before saving
	 *
	 * @param array $data Template data
	 * @return array|WP_Error Validated data or error
	 */
	public static function validate_template_data( $data ) {
		// Check required fields
		if ( empty( $data['type'] ) ) {
			return new WP_Error( 'missing_type', __( '请选择模板类型', 'aether' ) );
		}

		// Always use template type as title
		$title = self::get_template_type_label( $data['type'] );

		// Sanitize data
		$validated = array(
			'id'      => isset( $data['id'] ) ? intval( $data['id'] ) : 0,
			'title'   => $title,
			'type'    => sanitize_key( $data['type'] ),
			'content' => isset( $data['content'] ) ? $data['content'] : '', // No sanitization for template code
		);

		return $validated;
	}

	/**
	 * Get template type label
	 *
	 * @param string $type Template type
	 * @return string Type label
	 */
	private static function get_template_type_label( $type ) {
		// Get template type label
		$template_types_manager = Aether_Dynamic_Template_Types::getInstance();
		$all_types = $template_types_manager->get_template_types();
		
		foreach ( $all_types as $group ) {
			if ( isset( $group['types'][ $type ] ) ) {
				return $group['types'][ $type ];
			}
		}
		
		return $type;
	}

	/**
	 * Check if template type is valid
	 *
	 * @param string $type Template type
	 * @return bool
	 */
	public static function is_valid_template_type( $type ) {
		$template_types_manager = Aether_Dynamic_Template_Types::getInstance();
		$all_types = $template_types_manager->get_template_types();
		
		foreach ( $all_types as $group ) {
			if ( isset( $group['types'][ $type ] ) ) {
				return true;
			}
		}
		
		return false;
	}
}