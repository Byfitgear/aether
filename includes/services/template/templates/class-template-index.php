<?php
/**
 * Index Template - Fallback template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Index
 */
class Aether_Template_Index {
    
    /**
     * Get index (fallback) template
     */
    public static function get() {
        return '
        <div class="min-h-screen">
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
                                
                                <?php if (get_post_type() === "post") : ?>
                                    <div class="text-gray-600 text-sm">
                                        <span>发布于: <?php echo get_the_date(); ?></span>
                                        <span> | 作者: <?php the_author(); ?></span>
                                    </div>
                                <?php endif; ?>
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
                    <h2 class="text-2xl font-bold mb-4">未找到内容</h2>
                    <p class="text-gray-600">抱歉，此页面暂无内容。</p>
                </div>
            <?php endif; ?>
        </div>
        ';
    }
}