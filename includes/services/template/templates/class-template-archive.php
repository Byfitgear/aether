<?php
/**
 * Archive Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Archive
 */
class Aether_Template_Archive {
    
    /**
     * Get archive template
     */
    public static function get($type = 'archive_post') {
        return '
        <header class="mb-8">
            <h1 class="text-3xl font-bold mb-2">
                <?php
                if (is_post_type_archive()) {
                    post_type_archive_title();
                } elseif (is_date()) {
                    if (is_day()) {
                        echo get_the_date();
                    } elseif (is_month()) {
                        echo get_the_date("F Y");
                    } elseif (is_year()) {
                        echo get_the_date("Y");
                    }
                } else {
                    the_archive_title();
                }
                ?>
            </h1>
            
            <?php
            $description = get_the_archive_description();
            if ($description) {
                echo \'<div class="text-gray-600">\' . $description . \'</div>\';
            }
            ?>
        </header>
        
        ' . self::get_posts_grid();
    }
    
    /**
     * Get posts grid layout (shared by multiple templates)
     */
    public static function get_posts_grid() {
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
                        
                        <h2 class="text-xl font-bold mb-2">
                            <a href="<?php the_permalink(); ?>" class="text-black no-underline hover:underline"><?php the_title(); ?></a>
                        </h2>
                        
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
                <p>暂无内容。</p>
                <?php
            endif;
            ?>
        ';
    }
}