<?php
/**
 * Page Template - Static pages
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Page
 */
class Aether_Template_Page
{

    /**
     * Get page template
     */
    public static function get()
    {
        return '
        <?php $post_id = get_the_ID(); $aether_edited = get_post_meta($post_id, "_aether_edited", true); ?>
        <article>
            <?php if (!$aether_edited && has_post_thumbnail()) : ?>
                <?php the_post_thumbnail("large", array("class" => "w-full h-auto")); ?>
            <?php endif; ?>
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
        </article>
        ';
    }
}
