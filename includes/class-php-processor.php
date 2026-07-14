<?php
defined('ABSPATH') || exit;

class WordExpress_PHP_Processor
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_filter('the_content', [$this, 'process_php_in_content'], 0);
    }

    public function process_php_in_content($content)
    {
        global $post;
        if (!$post) return $content;

        // Only process if wordexpress edited
        if (get_post_meta($post->ID, '_wordexpress_edited', true) !== '1') {
            return $content;
        }

        // Check if user can execute PHP
        if (!current_user_can('manage_options')) {
            return $content;
        }

        // Process PHP tags in content
        $content = do_shortcode($content);
        return $content;
    }
}
WordExpress_PHP_Processor::get_instance();
