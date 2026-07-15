<?php
/**
 * Page CSS Provider
 *
 * Provides compiled CSS for pages
 * 改造为页面级 CSS 提供者
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
 * Page CSS Provider Class
 */
class Aether_Page_CSS_Provider implements Aether_CSS_Provider_Interface
{

    /**
     * Storage service instance
     *
     * @var Aether_CSS_Storage_Service
     */
    private $storage_service;

    /**
     * Constructor
     */
    public function __construct()
    {
        if (class_exists('Aether_CSS_Storage_Service')) {
            $this->storage_service = Aether_CSS_Storage_Service::get_instance();
        }
    }

    /**
     * Get the page CSS content for current page
     *
     * @return string CSS content
     */
    public function get_css()
    {
        if (!$this->storage_service) {
            return '';
        }

        $post_id = get_the_ID();
        if ($post_id) {
            return $this->get_css_for_post($post_id);
        }

        return '';
    }

    /**
     * Get CSS for a specific post
     *
     * @param int $post_id
     * @return string CSS content
     */
    public function get_css_for_post($post_id)
    {
        if (!$this->storage_service || !$post_id) {
            return '';
        }

        return $this->storage_service->get_page_css($post_id);
    }

    /**
     * Get CSS for a specific template type
     *
     * @param string $template_type
     * @return string CSS content
     */
    public function get_css_for_template($template_type)
    {
        if (!$this->storage_service || empty($template_type)) {
            return '';
        }

        return $this->storage_service->get_template_css($template_type);
    }

    /**
     * Check if this provider should be active
     * 由 CSS_Injection_Manager 决定是否使用此 provider
     *
     * @return bool True if provider should inject CSS
     */
    public function is_active()
    {
        return true;
    }

    /**
     * Get the priority for injection order
     *
     * @return int Priority (0-100)
     */
    public function get_priority()
    {
        // Page CSS should be injected early
        return 5;
    }

    /**
     * Get the CSS ID for the style tag
     *
     * @return string Unique ID for the style tag
     */
    public function get_css_id()
    {
        return 'aether-compiled-css';
    }

    /**
     * Get CSS attributes for the style tag
     *
     * @return array Additional attributes for the style tag
     */
    public function get_css_attributes()
    {
        return [
            'data-aether-css' => 'compiled'
        ];
    }
}
