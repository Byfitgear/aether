<?php
/**
 * Author Archive Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Author
 */
class Aether_Template_Author {
    
    /**
     * Get author template
     */
    public static function get() {
        return '
        <header class="mb-8">
            <h1 class="text-3xl font-bold mb-4">
                作者: <?php the_author(); ?>
            </h1>
            
            <?php
            $author_description = get_the_author_meta("description");
            if ($author_description) {
                echo \'<div class="text-gray-600 mb-4">\' . $author_description . \'</div>\';
            }
            ?>
            
            <?php
            $author_url = get_the_author_meta("url");
            if ($author_url) {
                echo \'<div><a href="\' . esc_url($author_url) . \'" target="_blank" class="text-blue-600 hover:underline">访问网站</a></div>\';
            }
            ?>
        </header>
        
        <h2 class="text-2xl font-bold mb-6">作者文章</h2>
        
        ' . self::get_posts_grid();
    }
    
    /**
     * Get posts grid layout (reused from archive)
     */
    private static function get_posts_grid() {
        return '
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php
            if (have_posts()) :
                while (have_posts()) : the_post();
                    ?>
                    <article class="border border-gray-200 p-4 hover:shadow-lg transition-shadow">
                        <?php if (has_post_thumbnail()) : ?>
                            <div class="-m-4 mb-4">
                                <a href="<?php the_permalink(); ?>">
                                    <?php the_post_thumbnail("medium", array("class" => "w-full h-48 object-cover")); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <h3 class="text-xl font-bold mb-2">
                            <a href="<?php the_permalink(); ?>" class="text-black no-underline hover:underline"><?php the_title(); ?></a>
                        </h3>
                        
                        <div class="text-gray-600 text-sm mb-4">
                            <span><?php echo get_the_date(); ?></span>
                        </div>
                        
                        <div class="mb-4 text-sm">
                            <?php echo wp_trim_words(get_the_excerpt(), 20); ?>
                        </div>
                        
                        <a href="<?php the_permalink(); ?>" class="text-sm text-gray-800 hover:text-black underline">阅读更多 →</a>
                    </article>
                    <?php
                endwhile;
                
                ?>
                </div>
                <div class="mt-8 pt-8 border-t border-gray-300">
                    <?php
                    the_posts_pagination(array(
                        "prev_text" => "← 上一页",
                        "next_text" => "下一页 →",
                        "mid_size" => 2,
                        "screen_reader_text" => "文章导航"
                    ));
                    ?>
                </div>
                <?php
            else :
                ?>
                <p>该作者暂无文章。</p>
                <?php
            endif;
            ?>
        ';
    }
}