<?php
defined('ABSPATH') || exit;

class WordExpress_Editor extends WordExpress_Base
{
    private $modules = [];

    protected function init()
    {
        $this->modules['ui']       = WordExpress_Editor_UI::getInstance();
        $this->modules['gutenberg'] = WordExpress_Editor_Gutenberg::getInstance();
        $this->modules['content']   = WordExpress_Editor_Content::getInstance();
        $this->modules['page']      = WordExpress_Editor_Page_React::getInstance();
        $this->modules['ajax']      = WordExpress_Editor_Ajax::getInstance();
        $this->modules['template_page'] = WordExpress_Template_Editor_Page::getInstance();
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
