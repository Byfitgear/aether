<?php
/**
 * Templates functionality
 *
 * Handles dynamic template post type and template application
 * 
 * @package ZeroY
 * @subpackage Templates
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates class
 * 
 * Manages dynamic templates using PHP execution
 */
class ZeroY_Templates extends ZeroY_Base {

	/**
	 * Post type name
	 *
	 * @var string
	 */
	const POST_TYPE = 'zeroy_template';

	/**
	 * Template content meta key.
	 *
	 * @var string
	 */
	const CONTENT_META_KEY = '_zeroy_template_content';

	/**
	 * Dynamic template types manager
	 *
	 * @var ZeroY_Dynamic_Template_Types
	 */
	private $template_types_manager;

	/**
	 * Initialize the class
	 */
	protected function init() {
		// Initialize template types manager
		$this->template_types_manager = ZeroY_Dynamic_Template_Types::getInstance();
		
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_template' ), 10, 3 );
		
		// Dynamic template application
		add_action( 'init', array( $this, 'register_dynamic_template_filters' ), 20 );
	}

	/**
	 * Register custom post type
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => '动态模板',
			'singular_name'      => '动态模板',
			'menu_name'          => '动态模板',
			'add_new'            => '添加模板',
			'add_new_item'       => '添加新模板',
			'edit_item'          => '编辑模板',
			'new_item'           => '新模板',
			'view_item'          => '查看模板',
			'search_items'       => '搜索模板',
			'not_found'          => '未找到模板',
			'not_found_in_trash' => '回收站中未找到模板',
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false, // We'll create custom UI
			'show_in_menu'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'hierarchical'        => false,
			'supports'            => array( 'title' ),
			'show_in_rest'        => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Prepare template code for WordPress meta writes.
	 *
	 * WordPress unslashes meta values during writes, so code content must be
	 * slashed at the storage boundary to preserve literal backslashes.
	 *
	 * @param string $content Template code content.
	 * @return string
	 */
	public static function prepare_content_for_meta( $content ) {
		return wp_slash( is_string( $content ) ? $content : '' );
	}

	/**
	 * Update template content while preserving code literals exactly.
	 *
	 * @param int    $post_id Template post ID.
	 * @param string $content Template code content.
	 * @return int|bool
	 */
	public static function update_content_meta( $post_id, $content ) {
		return update_post_meta(
			$post_id,
			self::CONTENT_META_KEY,
			self::prepare_content_for_meta( $content )
		);
	}

	/**
	 * Register dynamic template filters
	 */
	public function register_dynamic_template_filters() {
		// Get all available filters
		$filters = array(
			'404_template',
			'search_template',
			'home_template',
			'frontpage_template', // For static front page
			'index_template', // For blog page
			'author_template',
			'single_template',
			'archive_template',
			'category_template',
			'tag_template',
			'taxonomy_template',
		);

		// Register universal filter for each
		foreach ( $filters as $filter ) {
			add_filter( $filter, array( $this, 'filter_template' ), 10, 1 );
		}
	}

	/**
	 * Universal template filter
	 *
	 * @param string $template Original template path
	 * @return string
	 */
	public function filter_template( $template ) {
		// Get current page template type
		$current_type = $this->template_types_manager->get_current_page_template_type();
		
		if ( ! $current_type ) {
			return $template;
		}

		// Check if we have a template for this type
		$active_template = $this->get_active_template( $current_type );
		
		if ( $active_template ) {
			return $this->get_template_file( $current_type, $active_template );
		}

		return $template;
	}

	/**
	 * Get all templates
	 *
	 * @return array
	 */
	public function get_templates() {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$templates = get_posts( $args );
		$result    = array();

		/** @var WP_Post $template */
		foreach ( $templates as $template ) {
			$result[] = array(
				'id'      => $template->ID,
				'title'   => $template->post_title,
				'type'    => get_post_meta( $template->ID, '_zeroy_template_type', true ),
				'content' => get_post_meta( $template->ID, self::CONTENT_META_KEY, true ),
			);
		}

		return $result;
	}

	/**
	 * Get template by ID
	 *
	 * @param int $template_id Template ID
	 * @return array|false
	 */
	public function get_template( $template_id ) {
		$template = get_post( $template_id );

		if ( ! $template || $template->post_type !== self::POST_TYPE ) {
			return false;
		}

		return array(
			'id'      => $template->ID,
			'title'   => $template->post_title,
			'type'    => get_post_meta( $template->ID, '_zeroy_template_type', true ),
			'content' => get_post_meta( $template->ID, self::CONTENT_META_KEY, true ),
		);
	}

	/**
	 * Create or update template
	 *
	 * @param array $data Template data
	 * @return int|WP_Error
	 */
	public function save_template_data( $data ) {
		$post_data = array(
			'post_title'  => sanitize_text_field( $data['title'] ),
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
		);

		if ( ! empty( $data['id'] ) ) {
			$post_data['ID'] = intval( $data['id'] );
			$result = wp_update_post( $post_data );
		} else {
			$result = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Save template meta
		update_post_meta( $result, '_zeroy_template_type', sanitize_key( $data['type'] ) );
		self::update_content_meta( $result, $data['content'] ); // No sanitization for template code

		return $result;
	}

	/**
	 * Delete template
	 *
	 * @param int $template_id Template ID
	 * @return bool
	 */
	public function delete_template( $template_id ) {
		$template = get_post( $template_id );

		if ( ! $template || $template->post_type !== self::POST_TYPE ) {
			return false;
		}

		return wp_delete_post( $template_id, true ) !== false;
	}

	/**
	 * Save template meta on post save
	 *
	 * @param int     $post_id Post ID
	 * @param WP_Post $post    Post object
	 * @param bool    $update  Whether this is an update
	 */
	public function save_template( $post_id, $post, $update ) {
		// Verify nonce and capabilities handled by admin page
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
	}

	/**
	 * Apply template based on current page
	 */
	public function apply_template() {
		// This method can be extended for immediate template application
		// Currently templates are applied through filter hooks
	}

	/**
	 * Get active template for a specific type
	 *
	 * @param string $type Template type
	 * @param bool $use_default Whether to use default template if no custom exists
	 * @return array|false
	 */
	public function get_active_template( $type, $use_default = true ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_zeroy_template_type',
					'value' => $type,
				),
			),
		);

		$templates = get_posts( $args );

		if ( ! empty( $templates ) ) {
			/** @var WP_Post $template */
			$template = $templates[0];

			$content = get_post_meta( $template->ID, self::CONTENT_META_KEY, true );

			return array(
				'id'      => $template->ID,
				'content' => $content,
				'is_default' => false,
			);
		}

		// If no custom template and use_default is true, return default template
		if ( $use_default ) {
			// Load default templates provider
			require_once ZEROY_PATH . 'includes/services/template/class-default-templates-provider.php';
			$default_provider = ZeroY_Default_Templates_Provider::getInstance();
			
			$default_content = $default_provider->get_default_template( $type );
			
			if ( ! empty( $default_content ) ) {
				return array(
					'id'      => 0,
					'content' => $default_content,
					'is_default' => true,
				);
			}
		}

		return false;
	}

	/**
	 * Render template content
	 *
	 * @param string $content Template content
	 * @return string
	 */
	private function render_template( $content ) {
		// Use PHP execution to render
		$template_service = ZeroY_Template_Service::getInstance();
		return $template_service->render( $content );
	}

	/**
	 * Get template file path for rendering
	 *
	 * @param string $type     Template type
	 * @param array  $template Template data
	 * @return string
	 */
	private function get_template_file( $type, $template ) {
		// Store template content in global for rendering
		$GLOBALS['zeroy_template_content'] = $template['content'];
		
		// Return our generic template renderer file
		return ZEROY_PATH . 'templates/template-renderer.php';
	}

	/**
	 * Get available template types
	 *
	 * @return array
	 */
	public function get_template_types() {
		return $this->template_types_manager->get_template_types();
	}

	/**
	 * Get used template types
	 *
	 * @return array
	 */
	public function get_used_template_types() {
		$templates = $this->get_templates();
		$used_types = array();
		
		foreach ( $templates as $template ) {
			$used_types[] = $template['type'];
		}
		
		return $used_types;
	}

	/**
	 * Check if template type is already used
	 *
	 * @param string $type Template type
	 * @param int    $exclude_id Template ID to exclude from check
	 * @return bool
	 */
	public function is_template_type_used( $type, $exclude_id = 0 ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_zeroy_template_type',
					'value' => $type,
				),
			),
		);

		if ( $exclude_id ) {
			$args['post__not_in'] = array( $exclude_id );
		}

		$templates = get_posts( $args );
		return ! empty( $templates );
	}
}
