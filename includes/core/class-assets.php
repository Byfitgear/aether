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

// 依赖类通过自动加载器加载

/**
 * 资产类
 */
class Aether_Assets extends Aether_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        // 资产管理通常由其他类调用，不需要自动注册钩子
    }
    
    /**
     * 注册编辑器资产
     */
    public function enqueue_editor_assets() {
        // 使用 Vite 加载器
        $vite_loader = Aether_Vite_Loader::getInstance();
        
        // 加载编辑器资源
        $vite_loader->enqueue('editor');
        
        // WordPress 媒体库
        wp_enqueue_media();
    }
    
    /**
     * 注册管理页面资产
     */
    public function enqueue_admin_assets() {
        // 使用 Vite 加载器
        $vite_loader = Aether_Vite_Loader::getInstance();
        
        // 加载管理界面资源
        $vite_loader->enqueue('admin');
    }
}