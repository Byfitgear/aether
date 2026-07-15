<?php
/**
 * Design System CSS Provider
 *
 * Provides extracted design system custom CSS for runtime injection.
 *
 * @package Aether
 * @subpackage Services\CSS\Providers
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists('Aether_CSS_Provider_Interface')) {
    require_once AETHER_PATH . 'includes/services/css/interface-css-provider.php';
}

class Aether_Design_System_CSS_Provider implements Aether_CSS_Provider_Interface
{
    /**
     * Injection mode: full | global
     *
     * @var string
     */
    private $mode;

    /**
     * Cached CSS content
     *
     * @var string|null
     */
    private $css_cache = null;

    /**
     * @param string $mode full 注入完整 custom CSS，global 仅注入全局补丁
     */
    public function __construct($mode = 'full')
    {
        $this->mode = $mode === 'global' ? 'global' : 'full';
    }

    /**
     * @return string
     */
    public function get_css()
    {
        if ($this->css_cache !== null) {
            return $this->css_cache;
        }

        $this->css_cache = '';

        if (!class_exists('Aether_Settings_Service')) {
            return $this->css_cache;
        }

        $option_key = $this->mode === 'global'
            ? 'extracted_global_custom_css'
            : 'extracted_custom_css';

        $css = Aether_Settings_Service::get($option_key, '');
        if (!empty($css)) {
            $this->css_cache = $css;
            return $this->css_cache;
        }

        $design_system_html = Aether_Settings_Service::get('design_system_html', '');
        if (empty($design_system_html) || !class_exists('Aether_Design_System_Extractor')) {
            return $this->css_cache;
        }

        $this->css_cache = $this->mode === 'global'
            ? Aether_Design_System_Extractor::extract_global_custom_css(
                Aether_Design_System_Extractor::extract_custom_css($design_system_html)
            )
            : Aether_Design_System_Extractor::extract_custom_css($design_system_html);

        return $this->css_cache;
    }

    /**
     * @return bool
     */
    public function is_active()
    {
        return !empty($this->get_css());
    }

    /**
     * @return int
     */
    public function get_priority()
    {
        return $this->mode === 'global' ? 15 : 25;
    }

    /**
     * @return string
     */
    public function get_css_id()
    {
        return $this->mode === 'global'
            ? 'aether-design-system-global-css'
            : 'aether-design-system-css';
    }

    /**
     * @return array
     */
    public function get_css_attributes()
    {
        return [
            'data-aether-css' => $this->mode === 'global'
                ? 'design-system-global'
                : 'design-system'
        ];
    }
}
