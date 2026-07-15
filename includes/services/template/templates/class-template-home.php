<?php
/**
 * Home/Blog Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Home
 */
class Aether_Template_Home {
    
    /**
     * Get home/blog template
     */
    public static function get() {
        require_once __DIR__ . '/class-template-archive.php';
        return Aether_Template_Archive::get_posts_grid();
    }
}