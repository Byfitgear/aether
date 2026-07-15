<?php
/**
 * zeroy 编辑器核心类（重构版）
 * 
 * 协调编辑器各个模块
 *
 * @package zeroy
 */

defined('ABSPATH') || exit;

/**
 * 编辑器类
 */
class ZeroY_Editor extends ZeroY_Base {
    
    /**
     * 模块实例
     */
    private $modules = [];
    
    /**
     * 初始化
     */
    protected function init() {
        // 初始化各个模块
        $this->modules['ui'] = ZeroY_Editor_UI::getInstance();
        $this->modules['gutenberg'] = ZeroY_Editor_Gutenberg::getInstance();
        $this->modules['content'] = ZeroY_Editor_Content::getInstance();
        // 使用 React 版本的编辑器页面
        $this->modules['page'] = ZeroY_Editor_Page_React::getInstance();
        $this->modules['ajax'] = ZeroY_Editor_Ajax::getInstance();
        // 模板编辑器页面
        $this->modules['template_page'] = ZeroY_Template_Editor_Page::getInstance();
    }
    
    /**
     * 获取模块实例
     * 
     * @param string $module 模块名称
     * @return object|null
     */
    public function get_module($module) {
        return isset($this->modules[$module]) ? $this->modules[$module] : null;
    }
    
    /**
     * 获取所有模块
     * 
     * @return array
     */
    public function get_modules() {
        return $this->modules;
    }
}