<?php
/**
 * Tag Template - Tag archives
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Tag
 */
class Aether_Template_Tag {
    
    /**
     * Get tag template
     */
    public static function get() {
        return '
        <div class="min-h-screen">
            <header class="mb-8 pb-4 border-b border-gray-300">
                <h1 class="text-3xl font-bold mb-4">标签: <?php single_tag_title(); ?></h1>
                
                <?php if (tag_description()) : ?>
                    <div class="text-gray-600">
                        <?php echo tag_description(); ?>
                    </div>
                <?php endif; ?>
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
                    <h2 class="text-2xl font-bold mb-4">该标签暂无文章</h2>
                    <p class="text-gray-600">此标签下还没有发布任何文章。</p>
                </div>
            <?php endif; ?>
        </div>
        ';
    }
}