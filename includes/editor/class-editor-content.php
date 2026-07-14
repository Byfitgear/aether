<?php
defined('ABSPATH') || exit;

class Aether_Editor_Content extends Aether_Base
{
    protected function init()
    {
        add_filter('the_content', [$this, 'filter_content'], 20);
        add_action('init', [$this, 'register_post_type']);
    }

    public function register_post_type()
    {
        // Register a hidden post type for templates if needed
    }

    public function filter_content($content)
    {
        global $post;
        if (!$post) return $content;

        // Skip if not a aether-edited post
        if (get_post_meta($post->ID, '_aether_edited', true) !== '1') {
            return $content;
        }

        // Return raw content without WordPress auto-paragraphs
        remove_filter('the_content', 'wpautop');
        return $content;
    }
}
