<?php
/**
 * Search Results Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Search
 */
class Aether_Template_Search {
    
    /**
     * Get search results template
     */
    public static function get() {
        return '
        <header class="mb-8">
            <h1 class="text-3xl font-bold mb-2">
                搜索结果: <?php echo get_search_query(); ?>
            </h1>
            
            <p class="text-gray-600">
                <?php
                global $wp_query;
                $results_count = $wp_query->found_posts;
                echo "找到 " . $results_count . " 个结果";
                ?>
            </p>
        </header>
        
        <div class="grid gap-8">
            <?php
            if (have_posts()) :
                while (have_posts()) : the_post();
                    ?>
                    <article class="pb-8 border-b border-gray-200 last:border-b-0">
                        <h2 class="text-2xl font-bold mb-2">
                            <a href="<?php the_permalink(); ?>" class="text-black no-underline hover:underline"><?php the_title(); ?></a>
                        </h2>
                        
                        <div class="text-gray-600 text-sm mb-4">
                            <span><?php echo get_post_type_object(get_post_type())->labels->singular_name; ?></span>
                            <span> | <?php echo get_the_date(); ?></span>
                        </div>
                        
                        <div class="mb-4">
                            <?php the_excerpt(); ?>
                        </div>
                        
                        <a href="<?php the_permalink(); ?>" class="inline-block px-4 py-2 border border-gray-800 text-black no-underline hover:bg-gray-800 hover:text-white">查看详情</a>
                    </article>
                    <?php
                endwhile;
                
                ?>
                <div class="mt-8 pt-8 border-t border-gray-300">
                    <?php
                    the_posts_pagination(array(
                        "prev_text" => "← 上一页",
                        "next_text" => "下一页 →",
                        "mid_size" => 2,
                        "screen_reader_text" => "搜索结果导航"
                    ));
                    ?>
                </div>
                <?php
            else :
                ?>
                <p class="mb-8">没有找到相关内容。请尝试其他关键词。</p>
                <?php get_search_form(); ?>
                <?php
            endif;
            ?>
        </div>
        ';
    }
}