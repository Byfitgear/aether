<?php
/**
 * 资产管理类
 * 
 * 处理 CSS 和 JS 文件的加载
 *
 * @package aether
 * @subpackage Core
 */

defined('ABSPATH') || exit;

// Dependent classes loaded via autoloader

/**
 * 资产类
 */
class Aether_Assets extends Aether_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        // Asset management usually called by other classes, no need to auto-register hooks
    }
    
    /**
     * 注册编辑器资产
     */
    public function enqueue_editor_assets() {
        // Use Vite loader
        $vite_loader = Aether_Vite_Loader::getInstance();
        
        // Load editor resources
        $vite_loader->enqueue('editor');
        
        // WordPress media library
        wp_enqueue_media();
    }
    
    /**
     * 注册管理页面资产
     */
    public function enqueue_admin_assets() {
        // Use Vite loader
        $vite_loader = Aether_Vite_Loader::getInstance();
        
        // Load admin interface resources
        $vite_loader->enqueue('admin');
    }
}