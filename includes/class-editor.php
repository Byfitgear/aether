<?php
defined('ABSPATH') || exit;

class Aether_Editor extends Aether_Base
{
    private $modules = [];

    protected function init()
    {
        $this->modules['ui']       = Aether_Editor_UI::getInstance();
        $this->modules['gutenberg'] = Aether_Editor_Gutenberg::getInstance();
        $this->modules['content']   = Aether_Editor_Content::getInstance();
        $this->modules['page']      = Aether_Editor_Page_React::getInstance();
        $this->modules['ajax']      = Aether_Editor_Ajax::getInstance();
        $this->modules['template_page'] = Aether_Template_Editor_Page::getInstance();
    }

    public function get_module($module)
    {
        return $this->modules[$module] ?? null;
    }

    public function get_modules()
    {
        return $this->modules;
    }
}
