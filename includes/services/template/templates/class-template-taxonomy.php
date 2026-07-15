<?php
/**
 * Taxonomy (Category/Tag) Template
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Taxonomy
 */
class Aether_Template_Taxonomy {
    
    /**
     * Get taxonomy template
     */
    public static function get($type) {
        return '
        <header class="mb-8">
            <h1 class="text-3xl font-bold mb-2"><?php single_term_title(); ?></h1>
            
            <?php
            $description = term_description();
            if ($description) {
                echo \'<div class="text-gray-600">\' . $description . \'</div>\';
            }
            ?>
        </header>
        
        ' . self::get_posts_grid();
    }
    
    /**
     * Get posts grid layout (reused from archive)
     */
    private static function get_posts_grid() {
        require_once __DIR__ . '/class-template-archive.php';
        return Aether_Template_Archive::get_posts_grid();
    }
}