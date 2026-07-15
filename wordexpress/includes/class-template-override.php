<?php
/**
 * Template Override Handler
 * 
 * 使用 WordPress 原生模板系统实现动态模板替换
 * 不再使用正则表达式和输出缓冲
 *
 * @package ZeroY
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class ZeroY_Template_Override
 */
class ZeroY_Template_Override extends ZeroY_Base
{

	/**
	 * Template path
	 */
	private $template_path;

	/**
	 * Initialize the class
	 */
	protected function init()
	{
		$this->template_path = ZEROY_PATH . 'templates/';

		// 立即注册模板标签，不要等到 init action
		$this->register_template_tags();

		// 使用 WordPress 原生的模板覆盖系统
		add_filter('template_include', array($this, 'override_template'), 999);

		// 为特定的模板部分提供覆盖
		add_action('get_header', array($this, 'maybe_override_header'), 1);
		add_action('get_footer', array($this, 'maybe_override_footer'), 1);
		add_action('get_sidebar', array($this, 'maybe_override_sidebar'), 1);
	}

	/**
	 * Override template file
	 */
	public function override_template($template)
	{
		$templates = ZeroY_Templates::getInstance();

		// 检查是否有活动的动态模板
		if (!$this->should_override_template()) {
			return $template;
		}

		// 根据当前页面类型选择合适的模板
		$custom_template = $this->get_custom_template_path();

		if ($custom_template && file_exists($custom_template)) {
			return $custom_template;
		}

		return $template;
	}

	/**
	 * Get current page template
	 */
	private function get_current_page_template()
	{
		// 获取当前页面模板
		if (is_page()) {
			global $post;
			if ($post) {
				$template = get_page_template_slug($post->ID);
				if ($template) {
					return $template;
				}
			}
		}
		return '';
	}

	/**
	 * Check if current page template should exclude header/footer
	 */
	public function should_exclude_header_footer()
	{
		$current_template = $this->get_current_page_template();
		
		// 从设置中获取排除的模板
		$excluded_templates = ZeroY_Settings_Service::get('excluded_templates', array(
			'page-landing.php',
			'template-landing.php',
			'landing-page.php',
			'page-blank.php',
			'template-blank.php',
			'blank-page.php'
		));
		
		// 允许通过过滤器自定义排除的模板
		$excluded_templates = apply_filters('zeroy_excluded_header_footer_templates', $excluded_templates);
		
		return in_array($current_template, $excluded_templates);
	}

	/**
	 * Check if we should override the template
	 */
	private function should_override_template()
	{
		$templates = ZeroY_Templates::getInstance();

		// 检查是否有任何活动的动态模板
		$has_header = $templates->get_active_template('header');
		$has_footer = $templates->get_active_template('footer');
		$has_content_template = false;

		// 检查当前页面类型的内容模板
		$template_types = ZeroY_Dynamic_Template_Types::getInstance();
		$current_type = $template_types->get_current_page_template_type();

		if ($current_type) {
			$content_template = $templates->get_active_template($current_type);
			$has_content_template = !empty($content_template);
		}

		// 如果当前页面模板应该排除页眉页脚，始终覆盖模板
		// 这样我们的通用模板可以正确处理页眉页脚的排除
		if ($this->should_exclude_header_footer()) {
			return true;
		}

		return $has_header || $has_footer || $has_content_template;
	}

	/**
	 * Get custom template path based on current page
	 */
	private function get_custom_template_path()
	{
		// 使用通用模板，让 WordPress 处理具体的内容逻辑
		return $this->template_path . 'zeroy-universal-template.php';
	}

	/**
	 * Maybe override header
	 */
	public function maybe_override_header($name)
	{
		// 如果当前页面模板应该排除页眉页脚，不覆盖
		if ($this->should_exclude_header_footer()) {
			return;
		}

		$templates = ZeroY_Templates::getInstance();
		$header_template = $templates->get_active_template('header');

		if ($header_template && !empty($header_template['content'])) {
			// 阻止主题的 header.php 被包含
			$this->prevent_theme_template_include('header', $name);

			// 输出我们的自定义头部
			$this->render_custom_header($header_template);

			// 阻止后续的 get_header 调用
			return false;
		}
	}

	/**
	 * Maybe override footer
	 */
	public function maybe_override_footer($name)
	{
		// 如果当前页面模板应该排除页眉页脚，不覆盖
		if ($this->should_exclude_header_footer()) {
			return;
		}

		$templates = ZeroY_Templates::getInstance();
		$footer_template = $templates->get_active_template('footer');

		if ($footer_template && !empty($footer_template['content'])) {
			// 阻止主题的 footer.php 被包含
			$this->prevent_theme_template_include('footer', $name);

			// 输出我们的自定义页脚
			$this->render_custom_footer($footer_template);

			// 阻止后续的 get_footer 调用
			return false;
		}
	}

	/**
	 * Maybe override sidebar
	 */
	public function maybe_override_sidebar($name)
	{
		$templates = ZeroY_Templates::getInstance();
		$sidebar_template = $templates->get_active_template('sidebar');

		if ($sidebar_template && !empty($sidebar_template['content'])) {
			// 阻止主题的 sidebar.php 被包含
			$this->prevent_theme_template_include('sidebar', $name);

			// 输出我们的自定义侧边栏
			$this->render_custom_sidebar($sidebar_template);

			// 阻止后续的 get_sidebar 调用
			return false;
		}
	}

	/**
	 * Prevent theme template from being included
	 */
	private function prevent_theme_template_include($type, $name)
	{
		// 使用输出缓冲来捕获并丢弃主题模板的输出
		add_action('get_template_part_' . $type, function () {
			ob_start();
		}, 1);

		add_action('get_template_part_' . $type, function () {
			ob_end_clean();
		}, 999);
	}

	/**
	 * Render custom header
	 */
	private function render_custom_header($template)
	{
		$template_service = ZeroY_Template_Service::getInstance();

		// 直接输出用户的头部内容，不添加任何包裹标签
		echo $template_service->render($template['content']);
	}

	/**
	 * Render custom footer
	 */
	private function render_custom_footer($template)
	{
		$template_service = ZeroY_Template_Service::getInstance();

		// 直接输出用户的页脚内容，不添加任何包裹标签
		echo $template_service->render($template['content']);
	}

	/**
	 * Render custom sidebar
	 */
	private function render_custom_sidebar($template)
	{
		$template_service = ZeroY_Template_Service::getInstance();

		// 直接输出用户的侧边栏内容，不添加任何包裹标签
		echo $template_service->render($template['content']);
	}

	/**
	 * Register custom template tags
	 */
	public function register_template_tags()
	{
		// 注册自定义模板标签，供模板文件使用
		if (!function_exists('zeroy_header')) {
			function zeroy_header()
			{
				$templates = ZeroY_Templates::getInstance();
				$template_service = ZeroY_Template_Service::getInstance();
				$header_template = $templates->get_active_template('header');

				if ($header_template && !empty($header_template['content'])) {
					echo $template_service->render($header_template['content']);
				} else {
					// 回退到主题的 header
					get_header();
				}
			}
		}

		if (!function_exists('zeroy_footer')) {
			function zeroy_footer()
			{
				$templates = ZeroY_Templates::getInstance();
				$template_service = ZeroY_Template_Service::getInstance();
				$footer_template = $templates->get_active_template('footer');

				if ($footer_template && !empty($footer_template['content'])) {
					echo $template_service->render($footer_template['content']);
				} else {
					// 回退到主题的 footer
					get_footer();
				}
			}
		}

		if (!function_exists('zeroy_content')) {
			function zeroy_content()
			{
				$templates = ZeroY_Templates::getInstance();
				$template_service = ZeroY_Template_Service::getInstance();
				$template_types = ZeroY_Dynamic_Template_Types::getInstance();

				$current_type = $template_types->get_current_page_template_type();

				if ($current_type) {
					$content_template = $templates->get_active_template($current_type);
					if ($content_template && !empty($content_template['content'])) {
						echo $template_service->render($content_template['content']);
						return;
					}
				}

				// 回退到默认的内容循环
				if (have_posts()) {
					while (have_posts()) {
						the_post();
						the_content();
					}
				}
			}
		}

		if (!function_exists('zeroy_should_exclude_header_footer')) {
			function zeroy_should_exclude_header_footer()
			{
				$template_override = ZeroY_Template_Override::getInstance();
				return $template_override->should_exclude_header_footer();
			}
		}
	}
}