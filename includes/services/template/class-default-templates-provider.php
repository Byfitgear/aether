<?php
/**
 * Default Templates Provider
 * 
 * Provides minimalist default templates when user hasn't created custom ones
 * Using Tailwind CSS for styling
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Default_Templates_Provider
 * 
 * Provides default minimalist templates for all WordPress page types
 */
class Aether_Default_Templates_Provider {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Template classes directory
     */
    private $templates_dir;
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct() {
        $this->templates_dir = plugin_dir_path(__FILE__) . 'templates/';
        $this->load_template_classes();
    }
    
    /**
     * Load all template classes
     */
    private function load_template_classes() {
        $template_files = array(
            'class-template-header.php',
            'class-template-footer.php',
            'class-template-index.php',
            'class-template-front-page.php',
            'class-template-home.php',
            'class-template-page.php',
            'class-template-single.php',
            'class-template-archive.php',
            'class-template-category.php',
            'class-template-tag.php',
            'class-template-date.php',
            'class-template-taxonomy.php',
            'class-template-author.php',
            'class-template-search.php',
            'class-template-attachment.php',
            'class-template-404.php'
        );
        
        foreach ($template_files as $file) {
            $file_path = $this->templates_dir . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
        
        // Also load the styles provider
        require_once plugin_dir_path(__FILE__) . 'class-template-styles.php';
    }
    
    /**
     * Get default template for a specific type
     * 
     * @param string $type Template type
     * @return string Template content
     */
    public function get_default_template($type) {
        $method = 'get_' . $type . '_template';
        
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        
        // For dynamic types like single_post, archive_post, etc.
        if (strpos($type, 'single_') === 0) {
            return $this->get_single_template($type);
        } elseif (strpos($type, 'archive_') === 0) {
            return $this->get_archive_template($type);
        } elseif (strpos($type, 'taxonomy_') === 0) {
            return $this->get_taxonomy_template($type);
        }
        
        return '';
    }
    
    /**
     * Get header template
     */
    public function get_header_template() {
        return Aether_Template_Header::get();
    }
    
    /**
     * Get footer template
     */
    public function get_footer_template() {
        return Aether_Template_Footer::get();
    }
    
    /**
     * Get home/blog template
     */
    public function get_home_template() {
        return Aether_Template_Home::get();
    }
    
    /**
     * Get blog template (alias for home)
     */
    public function get_blog_template() {
        return $this->get_home_template();
    }
    
    /**
     * Get single post/page template
     */
    public function get_single_template($type = 'single_post') {
        // Use separate page template for pages
        if ($type === 'single_page') {
            return Aether_Template_Page::get();
        }
        return Aether_Template_Single::get($type);
    }
    
    /**
     * Get archive template
     */
    public function get_archive_template($type = 'archive_post') {
        return Aether_Template_Archive::get($type);
    }
    
    /**
     * Get taxonomy (category/tag) template
     */
    public function get_taxonomy_template($type) {
        return Aether_Template_Taxonomy::get($type);
    }
    
    /**
     * Get category template
     */
    public function get_category_template() {
        return Aether_Template_Category::get();
    }
    
    /**
     * Get tag template
     */
    public function get_tag_template() {
        return Aether_Template_Tag::get();
    }
    
    /**
     * Get author template
     */
    public function get_author_template() {
        return Aether_Template_Author::get();
    }
    
    /**
     * Get search results template
     */
    public function get_search_template() {
        return Aether_Template_Search::get();
    }
    
    /**
     * Get 404 template
     */
    public function get_404_template() {
        return Aether_Template_404::get();
    }
    
    /**
     * Get index (fallback) template
     */
    public function get_index_template() {
        return Aether_Template_Index::get();
    }
    
    /**
     * Get front page template
     */
    public function get_front_page_template() {
        return Aether_Template_Front_Page::get();
    }
    
    /**
     * Get page template
     */
    public function get_page_template() {
        return Aether_Template_Page::get();
    }
    
    /**
     * Get date archive template
     */
    public function get_date_template() {
        return Aether_Template_Date::get();
    }
    
    /**
     * Get attachment template
     */
    public function get_attachment_template() {
        return Aether_Template_Attachment::get();
    }
    
    /**
     * Check if default template should be used
     * 
     * @param string $type Template type
     * @return bool
     */
    public function should_use_default($type) {
        // Check if user has created a custom template for this type
        $templates = Aether_Templates::getInstance();
        $custom_template = $templates->get_active_template($type, false);
        
        // Use default if no custom template exists
        return empty($custom_template);
    }
}