<?php
/**
 * Front Page Template - Site front page
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Front_Page
 */
class Aether_Template_Front_Page {
    
    /**
     * Get front page template
     */
    public static function get() {
        return '
        <div class="min-h-screen">
            <?php
            // Check if front page shows a static page
            if (get_option("show_on_front") == "page" && get_option("page_on_front")) {
                // Show static page content
                $front_page = get_post(get_option("page_on_front"));
                if ($front_page) {
                    // If edited by Aether, render raw content without extra header wrappers
                    $aether_edited = get_post_meta($front_page->ID, "_aether_edited", true);
                    if ($aether_edited) {
                        $template_service = Aether_Template_Service::getInstance();
                        echo $template_service->render_with_optimization($front_page->post_content, $front_page->ID);
                    } else {
                        // Minimal page output without auto title header to avoid duplicate headings
                        ?>
                        <article>
                            <?php if (has_post_thumbnail($front_page->ID)) : ?>
                                <?php echo get_the_post_thumbnail($front_page->ID, "large", array("class" => "w-full h-auto")); ?>
                            <?php endif; ?>
                            <div class="aether-content-area mb-8">
                                <?php echo apply_filters("the_content", $front_page->post_content); ?>
                            </div>
                        </article>
                        <?php
                    }
                }
            } else {
                // Show latest posts (blog style)
                ?>
                <header class="mb-8 pb-4 border-b border-gray-300">
                    <h1 class="text-3xl font-bold mb-4"><?php bloginfo("name"); ?></h1>
                    
                    <?php
                    $description = get_bloginfo("description");
                    if ($description) {
                        echo \'<p class="text-gray-600">\' . esc_html($description) . \'</p>\';
                    }
                    ?>
                </header>
                
                <?php if (have_posts()) : ?>
                    <div class="space-y-8">
                        <?php while (have_posts()) : the_post(); ?>
                            <article class="pb-8 border-b border-gray-300 last:border-b-0">
                                <header class="mb-4">
                                    <h2 class="text-2xl font-bold mb-2">
                                        <a href="<?php the_permalink(); ?>" class="text-black hover:underline">
                                            <?php the_title(); ?>
                                        </a>
                                    </h2>
                                    
                                    <div class="text-gray-600 text-sm">
                                        <span>发布于: <?php echo get_the_date(); ?></span>
                                        <span> | 作者: <?php the_author(); ?></span>
                                        <span> | 分类: <?php the_category(", "); ?></span>
                                    </div>
                                </header>
                                
                                <?php if (has_post_thumbnail()) : ?>
                                    <div class="mb-4">
                                        <a href="<?php the_permalink(); ?>">
                                            <?php the_post_thumbnail("medium", array("class" => "w-full h-auto")); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="aether-content-area">
                                    <?php the_excerpt(); ?>
                                </div>
                            </article>
                        <?php endwhile; ?>
                    </div>
                    
                    <?php
                    // Pagination
                    the_posts_pagination(array(
                        "mid_size" => 2,
                        "prev_text" => "« 上一页",
                        "next_text" => "下一页 »",
                        "before_page_number" => "<span class=\"sr-only\">页码 </span>",
                        "class" => "mt-12"
                    ));
                    ?>
                <?php else : ?>
                    <div class="text-center py-16">
                        <h2 class="text-2xl font-bold mb-4">欢迎来到您的网站</h2>
                        <p class="text-gray-600">还没有发布任何内容，开始创建您的第一篇文章吧！</p>
                    </div>
                <?php endif; ?>
            <?php } ?>
        </div>
        ';
    }
}
