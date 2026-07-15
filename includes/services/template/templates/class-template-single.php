<?php
/**
 * Single Post/Page Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Single
 */
class Aether_Template_Single {
    
    /**
     * Get single post/page template
     */
    public static function get($type = 'single_post') {
        return '
        <?php $post_id = get_the_ID(); $aether_edited = get_post_meta($post_id, "_aether_edited", true); ?>
        <article>
            <?php if (!$aether_edited) : ?>
                <header class="mb-8 pb-4 border-b border-gray-300">
                    <h1 class="text-3xl font-bold mb-4"><?php the_title(); ?></h1>
                    
                    <?php if (get_post_type() === "post") : ?>
                        <div class="text-gray-600 text-sm">
                            <span>发布于: <?php echo get_the_date(); ?></span>
                            <span> | 作者: <?php the_author(); ?></span>
                            <span> | 分类: <?php the_category(", "); ?></span>
                            <?php
                            $tags_list = get_the_tag_list("", ", ");
                            if ($tags_list) {
                                echo \'<span> | 标签: \' . $tags_list . \'</span>\';
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                </header>
            <?php endif; ?>
            
            <?php if (!$aether_edited && has_post_thumbnail()) : ?>
                <?php the_post_thumbnail("large", array("class" => "w-full h-auto")); ?>
            <?php endif; ?>
            
            <?php 
            // Only apply WYSIWYG styles for posts, not pages
            $content_class = get_post_type() === "post" ? "aether-wysiwyg-content mb-8" : "mb-8";
            ?>
            <div class="<?php echo esc_attr($content_class); ?>">
                <?php if ($aether_edited) : ?>
                    <?php $template_service = Aether_Template_Service::getInstance(); global $post; echo $template_service->render_with_optimization($post->post_content, $post_id); ?>
                <?php else : ?>
                    <?php
                    the_content();
                    
                    wp_link_pages(array(
                        "before" => \'<div class="mt-8 pt-4 border-t border-gray-300">页面:\',
                        "after" => \'</div>\',
                        "link_before" => \'<span class="px-3 py-1 border border-gray-300 inline-block mr-2">\',
                        "link_after" => \'</span>\'
                    ));
                    ?>
                <?php endif; ?>
            </div>
            
            <?php if (!$aether_edited && get_post_type() === "post") : ?>
                <nav class="flex justify-between mt-8 pt-8 border-t border-gray-300">
                    <?php
                    $prev_post = get_previous_post();
                    $next_post = get_next_post();
                    
                    if ($prev_post) {
                        echo \'<div><a href="\' . get_permalink($prev_post) . \'" class="text-black hover:underline">← \' . get_the_title($prev_post) . \'</a></div>\';
                    } else {
                        echo \'<div></div>\';
                    }
                    
                    if ($next_post) {
                        echo \'<div><a href="\' . get_permalink($next_post) . \'" class="text-black hover:underline">\' . get_the_title($next_post) . \' →</a></div>\';
                    }
                    ?>
                </nav>
            <?php endif; ?>
        </article>
        ';
    }
}
