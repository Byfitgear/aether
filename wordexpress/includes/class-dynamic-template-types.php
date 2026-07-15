<?php

/**
 * Dynamic Template Types Manager
 *
 * @package ZeroY
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// 基类通过自动加载器加载

/**
 * ZeroY_Dynamic_Template_Types class
 * 
 * @method WP_Post_Type[] get_post_types()
 * @method WP_Taxonomy[] get_taxonomies()
 */
class ZeroY_Dynamic_Template_Types extends ZeroY_Base
{

	/**
	 * Initialize the class
	 */
	protected function init()
	{
		// This class doesn't need any initialization hooks
		// It's a utility class that provides methods to get dynamic template types
	}

	/**
	 * Get all available template types dynamically
	 *
	 * @return array
	 */
	public function get_template_types()
	{
		$template_types = array();

        // Core template types
        $template_types['core'] = array(
            'label' => __('核心模板', 'zeroy'),
            'types' => array(
                '404' => __('404页面', 'zeroy'),
                'search' => __('搜索结果页', 'zeroy'),
                // Static site front page (a Page set as homepage)
                'front_page' => __('首页(静态页)', 'zeroy'),
                // Home when showing latest posts
                'home' => __('首页(最新文章)', 'zeroy'),
                'blog' => __('博客首页', 'zeroy'),
                'author' => __('作者页', 'zeroy'),
            )
        );

		// Layout parts
		$template_types['parts'] = array(
			'label' => __('布局部件', 'zeroy'),
			'types' => array(
				'header' => __('页眉', 'zeroy'),
				'footer' => __('页脚', 'zeroy'),
			)
		);

		// Built-in post types
		$template_types['post_types'] = array(
			'label' => __('文章类型', 'zeroy'),
			'types' => array()
		);

		// Get all public post types
		$post_types = get_post_types(array('public' => true), 'objects');

		// Post types to exclude
		$excluded_post_types = apply_filters('zeroy_excluded_post_types', array(
			'attachment',
			'zeroy_template', // Our own template post type
		));

		/** @var WP_Post_Type $post_type */
		foreach ($post_types as $post_type) {
			// Skip excluded post types
			if (in_array($post_type->name, $excluded_post_types)) {
				continue;
			}


			// Single template
			$key = 'single_' . $post_type->name;
			$template_types['post_types']['types'][$key] = sprintf(
				__('%s (单页)', 'zeroy'),
				$post_type->labels->singular_name
			);

			// Archive template (only if has_archive is true)
			if ($post_type->has_archive || $post_type->name === 'post') {
				$key = 'archive_' . $post_type->name;
				$template_types['post_types']['types'][$key] = sprintf(
					__('%s (列表页)', 'zeroy'),
					$post_type->labels->name
				);
			}
		}

		// Taxonomies
		$template_types['taxonomies'] = array(
			'label' => __('分类法', 'zeroy'),
			'types' => array()
		);

		// Get all public taxonomies
		$taxonomies = get_taxonomies(array('public' => true), 'objects');


		// Taxonomies to exclude
		$excluded_taxonomies = apply_filters('zeroy_excluded_taxonomies', array(
			'post_format',
			'nav_menu', // Navigation menus
			'link_category', // Link categories (legacy)
		));


		/** @var WP_Taxonomy $taxonomy */
		foreach ($taxonomies as $taxonomy) {
			$debug_info = array(
				'name' => $taxonomy->name,
				'label' => $taxonomy->labels->singular_name ?? 'N/A',
				'object_types' => $taxonomy->object_type ?? array(),
				'excluded_by_name' => false,
				'excluded_by_object_type' => false,
				'included' => false
			);

			// Skip excluded taxonomies
			if (in_array($taxonomy->name, $excluded_taxonomies)) {
				$debug_info['excluded_by_name'] = true;
				continue;
			}

			// Skip taxonomies that are only for excluded post types
			if (!empty($taxonomy->object_type)) {
				$valid_for_included_types = false;
				$valid_object_types = array();
				$invalid_object_types = array();

				foreach ($taxonomy->object_type as $object_type) {
					if (!in_array($object_type, $excluded_post_types)) {
						$valid_for_included_types = true;
						$valid_object_types[] = $object_type;
					} else {
						$invalid_object_types[] = $object_type;
					}
				}

				$debug_info['valid_object_types'] = $valid_object_types;
				$debug_info['invalid_object_types'] = $invalid_object_types;

				if (!$valid_for_included_types) {
					$debug_info['excluded_by_object_type'] = true;
					continue;
				}
			}

			$key = 'taxonomy_' . $taxonomy->name;
			$template_types['taxonomies']['types'][$key] = sprintf(
				__('%s (分类页)', 'zeroy'),
				$taxonomy->labels->singular_name
			);

			$debug_info['included'] = true;

		}


		/**
		 * Filter template types
		 * 
		 * @param array $template_types Template types array
		 */
		return apply_filters('zeroy_template_types', $template_types);
	}

	/**
	 * Parse template type to get context
	 *
	 * @param string $type Template type
	 * @return array
	 */
	public function parse_template_type($type)
	{
		$parts = explode('_', $type, 2);

		if (count($parts) < 2) {
			// Core types
			return array(
				'type' => $type,
				'subtype' => '',
				'object' => ''
			);
		}

		return array(
			'type' => $parts[0], // 'single', 'archive', 'taxonomy'
			'subtype' => $parts[1], // post type or taxonomy name
			'object' => $parts[1]
		);
	}

	/**
	 * Get template type for current page
	 *
	 * @return string|false
	 */
	public function get_current_page_template_type()
	{
		require_once ZEROY_PATH . 'includes/services/template/class-template-type-detector.php';
		return ZeroY_Template_Type_Detector::detect_current_page_type();
	}

	/**
	 * Check if a template type should apply to current page
	 *
	 * @param string $template_type Template type
	 * @return bool
	 */
	public function should_apply_template($template_type)
	{
		$current_type = $this->get_current_page_template_type();
		return $current_type === $template_type;
	}

	/**
	 * Get WordPress template hierarchy filter for a template type
	 *
	 * @param string $template_type Template type
	 * @return string|false Filter name
	 */
	public function get_template_filter($template_type)
	{
		$context = $this->parse_template_type($template_type);

		// Core types
        if (empty($context['subtype'])) {
            $filters = array(
                '404' => '404_template',
                'search' => 'search_template',
                'front_page' => 'frontpage_template',
                'home' => 'home_template',
                'blog' => 'home_template', // Blog page uses home template filter
                'author' => 'author_template',
            );
            return isset($filters[$context['type']]) ? $filters[$context['type']] : false;
        }

		// Dynamic types
		switch ($context['type']) {
			case 'single':
				return 'single_template';

			case 'archive':
				return 'archive_template';

			case 'taxonomy':
				// WordPress uses specific filters for category/tag
				if ($context['subtype'] === 'category') {
					return 'category_template';
				} elseif ($context['subtype'] === 'post_tag') {
					return 'tag_template';
				} else {
					return 'taxonomy_template';
				}
		}

		return false;
	}

	/**
	 * Get preview URL for a template type
	 *
	 * @param string $template_type Template type
	 * @return array Array with 'url' and 'message' keys
	 */
	public function get_preview_url($template_type)
	{
		require_once ZEROY_PATH . 'includes/services/template/class-template-preview.php';

		$context = $this->parse_template_type($template_type);
		$result = array(
			'url' => '',
			'message' => '',
			'available' => false
		);

		// Handle core types
        if (empty($context['subtype'])) {
            switch ($context['type']) {
                case '404':
                    $result['url'] = ZeroY_Template_Preview::generate_404_url();
                    $result['available'] = true;
                    break;

                case 'search':
                    $result['url'] = home_url('/?s=hello');
                    $result['available'] = true;
                    break;

                case 'front_page':
                    // Static front page
                    $result['url'] = home_url('/');
                    $result['available'] = true;
                    break;

                case 'home':
                    $result['url'] = home_url('/');
                    $result['available'] = true;
                    break;

				case 'blog':
					// Get the page for posts
					$page_for_posts = get_option('page_for_posts');
					if ($page_for_posts) {
						$result['url'] = get_permalink($page_for_posts);
					} else {
						// If no separate blog page, use home
						$result['url'] = home_url('/');
					}
					$result['available'] = true;
					break;

				case 'author':
					$authors = get_users(array(
						'has_published_posts' => true,
						'number' => 1,
						'orderby' => 'post_count',
						'order' => 'DESC'
					));
					if (!empty($authors)) {
						$result['url'] = get_author_posts_url($authors[0]->ID);
						$result['available'] = true;
					} else {
						$result['message'] = __('没有找到有文章的作者', 'zeroy');
					}
					break;

				case 'header':
				case 'footer':
					// Header and footer are visible on the homepage
					$result['url'] = home_url('/');
					$result['available'] = true;
					break;
			}
			return $result;
		}

		// Handle dynamic types
		switch ($context['type']) {
			case 'single':
				$post = ZeroY_Template_Preview::get_preview_post($context['subtype']);
				if ($post) {
					$result['url'] = get_permalink($post);
					$result['available'] = true;
				} else {
					$post_type_obj = get_post_type_object($context['subtype']);
					$result['message'] = sprintf(
						__('还没有发布的%s，请先创建一个', 'zeroy'),
						$post_type_obj ? $post_type_obj->labels->singular_name : $context['subtype']
					);
				}
				break;

			case 'archive':
				$post_type_obj = get_post_type_object($context['subtype']);
				if ($post_type_obj) {
					if ($context['subtype'] === 'post') {
						$result['url'] = get_post_type_archive_link('post') ?: home_url('/');
						$result['available'] = true;
					} elseif ($post_type_obj->has_archive) {
						$result['url'] = get_post_type_archive_link($context['subtype']);
						$result['available'] = true;
					} else {
						$result['message'] = sprintf(
							__('%s 没有启用列表页', 'zeroy'),
							$post_type_obj->labels->name
						);
					}
				}
				break;

			case 'taxonomy':
				$term = ZeroY_Template_Preview::get_preview_term($context['subtype']);
				if ($term) {
					$result['url'] = get_term_link($term);
					$result['available'] = true;
				} else {
					$taxonomy_obj = get_taxonomy($context['subtype']);
					$result['message'] = sprintf(
						__('还没有%s，请先创建一个', 'zeroy'),
						$taxonomy_obj ? $taxonomy_obj->labels->singular_name : $context['subtype']
					);
				}
				break;
		}

		return $result;
	}
}
