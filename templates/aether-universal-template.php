<?php
/**
 * Aether Universal Template
 * 
 * 这是一个通用模板，用于当用户启用了动态模板时完全接管页面渲染
 * 不依赖主题的 header.php 和 footer.php
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// 获取模板实例
$aether_templates = Aether_Templates::getInstance();
$template_service = Aether_Template_Service::getInstance();
$template_types = Aether_Dynamic_Template_Types::getInstance();

// 获取当前页面类型
$current_type = $template_types->get_current_page_template_type();

// 获取各部分的模板
$header_template = $aether_templates->get_active_template('header');
$footer_template = $aether_templates->get_active_template('footer');
$content_template = $current_type ? $aether_templates->get_active_template($current_type) : null;

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
	<?php wp_body_open();

	// 检查是否应该排除页眉页脚
	$should_exclude_header_footer = function_exists('aether_should_exclude_header_footer') ? aether_should_exclude_header_footer() : false;

	// 渲染头部
	if (!$should_exclude_header_footer) {
		if ($header_template && !empty($header_template['content'])) {
			// 直接输出用户的头部内容，不添加任何包裹
			echo $template_service->render($header_template['content']);
		} else {
			// 如果没有自定义头部，尝试包含主题的 header.php
			// But avoid recursive calls
			$header_file = get_template_directory() . '/header.php';
			if (file_exists($header_file)) {
				// 直接包含文件内容，跳过 get_header() 以避免钩子
				include $header_file;
			}
		}
	}
	?>

	<?php
	// 渲染主要内容
	if ($content_template && !empty($content_template['content'])) {
		// 使用动态模板 - 直接输出，不添加任何包裹
		echo $template_service->render($content_template['content']);
	} else {
		// 使用默认的 WordPress 循环
		if (have_posts()) {
			while (have_posts()) {
				the_post();

				// 对于单个页面/文章，只输出内容，不添加额外结构
				if (is_singular()) {
					$post_id = get_the_ID();
					$aether_edited = get_post_meta($post_id, '_aether_edited', true);
					
					if ($aether_edited) {
						// 获取原始内容，不经过过滤器
						global $post;
						$post_content = $post->post_content;
						
						// 检查是否包含 PHP 代码
						if (strpos($post_content, '<?php') !== false || strpos($post_content, '<?=') !== false) {
							// 使用 PHP 处理器执行代码
							if (class_exists('Aether_PHP_Processor')) {
								$php_processor = Aether_PHP_Processor::getInstance();
								// 直接执行 PHP 代码，不经过 the_content 过滤器
								ob_start();
								$temp_file = tempnam(sys_get_temp_dir(), 'aether_tmp_');
								file_put_contents($temp_file, $post_content);
								include $temp_file;
								$output = ob_get_clean();
								if (file_exists($temp_file)) wp_delete_file($temp_file);
								$output = ob_get_clean();
								echo $output;
							} else {
								// 回退到模板服务
								echo $template_service->render($post_content, $post_id);
							}
						} else {
							// 没有 PHP 代码，使用正常的内容过滤器
							the_content();
						}
					} else {
						// 非 aether 编辑的内容，使用正常流程
						the_content();
					}

					wp_link_pages(array(
						'before' => '<div class="page-links">Pages:',
						'after' => '</div>',
					));
				} else {
					// 对于存档页面，提供基本结构
					?>
					<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
						<header class="entry-header">
							<?php the_title('<h2 class="entry-title"><a href="' . esc_url(get_permalink()) . '">', '</a></h2>'); ?>
						</header>

						<div class="entry-summary">
							<?php the_excerpt(); ?>
						</div>

						<footer class="entry-footer">
							<a href="<?php the_permalink(); ?>">Continue reading</a>
						</footer>
					</article>
					<?php
				}
			}

			// 分页
			the_posts_navigation();

		} else {
			// 没有内容时的最小化输出
			?>
			<h1>Nothing Found</h1>
			<p>Sorry, but nothing matched your search criteria.</p>
			<?php get_search_form();
		}
	}
	?>

	<?php
	// 渲染页脚
	if (!$should_exclude_header_footer) {
		if ($footer_template && !empty($footer_template['content'])) {
			// 直接输出用户的页脚内容，不添加任何包裹
			echo $template_service->render($footer_template['content']);
		} else {
			// 如果没有自定义页脚，尝试包含主题的 footer.php
			// But avoid recursive calls
			$footer_file = get_template_directory() . '/footer.php';
			if (file_exists($footer_file)) {
				// 直接包含文件内容，跳过 get_footer() 以避免钩子
				include $footer_file;
			}
		}
	}

	wp_footer();
	?>

</body>

</html>