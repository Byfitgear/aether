<?php
/**
 * Tailwind CDN Provider
 * 
 * Provides Tailwind Play CDN script and configuration for development mode
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
 * Tailwind CDN Provider Class
 */
class Aether_Tailwind_CDN_Provider implements Aether_CSS_Provider_Interface {
    
    /**
     * Tailwind config cache
     *
     * @var string|null
     */
    private $tailwind_config = null;
    
    /**
     * Get the CSS content
     * Note: This provider outputs scripts, not CSS
     *
     * @return string Empty string (scripts are handled separately)
     */
    public function get_css() {
        // This provider doesn't inject CSS directly
        return '';
    }
    
    /**
     * Check if this provider should be active
     * CDN 只在没有编译 CSS 或编辑器预览时使用
     *
     * @return bool True if provider should inject CSS
     */
    public function is_active() {
        // 编辑器预览模式始终使用 CDN
        if ($this->is_editor_preview()) {
            return true;
        }

        // 检查当前页面是否有编译 CSS（由 CSS_Injection_Manager 统一判断）
        // 这里返回 true，让 manager 决定是否需要 CDN
        return true;
    }

    /**
     * 检查是否是编辑器预览模式
     *
     * @return bool
     */
    private function is_editor_preview() {
        return isset($_GET['aether_preview']) || isset($_GET['preview']);
    }
    
    /**
     * Get the priority for injection order
     *
     * @return int Priority (0-100)
     */
    public function get_priority() {
        // CDN should be loaded first in development mode
        return 20;
    }
    
    /**
     * Get the CSS ID for the style tag
     *
     * @return string Unique ID for the style tag
     */
    public function get_css_id() {
        return 'aether-tailwind-cdn';
    }
    
    /**
     * Get CSS attributes for the style tag
     *
     * @return array Additional attributes for the style tag
     */
    public function get_css_attributes() {
        return [];
    }
    
    /**
     * Inject Tailwind CDN scripts
     * Called by the manager when this provider is active
     */
    public function inject_scripts() {
        if (!$this->is_active()) {
            return;
        }
        
        $manager = Aether_CSS_Injection_Manager::get_instance();

        // Output comment
        echo '<!-- aether Development Mode: Tailwind Play CDN -->' . "\n";

        // Output Tailwind CDN script
        $manager->output_script(
            'https://cdn.tailwindcss.com',
            'aether-tailwind-cdn-script',
            []
        );
        
        // Get Tailwind config
        $config = $this->get_tailwind_config();
        
        // Output Tailwind config
        $manager->output_inline_script(
            $config,
            'aether-tailwind-config'
        );
    }
    
    /**
     * Get Tailwind configuration
     *
     * @return string JavaScript code for Tailwind config
     */
    private function get_tailwind_config() {
        if (null !== $this->tailwind_config) {
            return $this->tailwind_config;
        }
        
        // Get pre-extracted Tailwind config from settings
        if (class_exists('Aether_Settings_Service')) {
            $extracted_config = Aether_Settings_Service::get('extracted_tailwind_config', '');
            
            if (!empty($extracted_config)) {
                $this->tailwind_config = $extracted_config;
                return $this->tailwind_config;
            }
        }
        
        // Default config
        $this->tailwind_config = 'tailwind.config = {
  theme: {
    extend: {}
  }
}';
        
        return $this->tailwind_config;
    }
}