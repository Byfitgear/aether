<?php
/**
 * Font CSS Provider
 * 
 * Provides font CSS for both development and production modes
 * 
 * @package Aether
 * @subpackage Services\CSS\Providers
 */

// 如果直接访问此文件，则中止执行
if (!defined('ABSPATH')) {
    exit;
}

// Load interface if not already loaded
if (!interface_exists('Aether_CSS_Provider_Interface')) {
    require_once AETHER_PATH . 'includes/services/css/interface-css-provider.php';
}

/**
 * Font CSS Provider Class
 */
class Aether_Font_CSS_Provider implements Aether_CSS_Provider_Interface {
    
    /**
     * Get the font CSS content
     *
     * @return string CSS content
     */
    public function get_css() {
        if (!class_exists('Aether_Font_Manager_Service')) {
            return '';
        }
        
        return Aether_Font_Manager_Service::get_font_css();
    }
    
    /**
     * Check if this provider should be active
     * Font CSS 只在没有编译 CSS 时需要（编译 CSS 已包含字体）
     *
     * @return bool True if provider should inject CSS
     */
    public function is_active() {
        // Font CSS is only needed when there's no compiled CSS
        // Compiled CSS already includes font definitions
        // Let the CSS_Injection_Manager decide whether to include fonts
        return true;
    }
    
    /**
     * Get the priority for injection order
     *
     * @return int Priority (0-100)
     */
    public function get_priority() {
        // Font CSS should be injected early so other styles can use the fonts
        return 10;
    }
    
    /**
     * Get the CSS ID for the style tag
     *
     * @return string Unique ID for the style tag
     */
    public function get_css_id() {
        return 'aether-font-css';
    }
    
    /**
     * Get CSS attributes for the style tag
     *
     * @return array Additional attributes for the style tag
     */
    public function get_css_attributes() {
        return [
            'data-aether-css' => 'fonts'
        ];
    }
}