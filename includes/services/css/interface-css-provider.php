<?php
/**
 * CSS Provider Interface
 * 
 * Defines the contract for CSS providers in the unified injection system
 * 
 * @package Aether
 * @subpackage Services\CSS
 */

// 如果直接访问此文件，则中止执行
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for CSS providers
 */
interface Aether_CSS_Provider_Interface {
    
    /**
     * Get the CSS content
     *
     * @return string CSS content
     */
    public function get_css();
    
    /**
     * Check if this provider should be active
     *
     * @return bool True if provider should inject CSS
     */
    public function is_active();
    
    /**
     * Get the priority for injection order
     * Lower numbers are injected first
     *
     * @return int Priority (0-100)
     */
    public function get_priority();
    
    /**
     * Get the CSS ID for the style tag
     *
     * @return string Unique ID for the style tag
     */
    public function get_css_id();
    
    /**
     * Get CSS attributes for the style tag
     *
     * @return array Additional attributes for the style tag
     */
    public function get_css_attributes();
}