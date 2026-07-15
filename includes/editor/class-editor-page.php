<?php
/**
 * 编辑器页面
 * 
 * 处理编辑器页面的渲染和设置
 *
 * @package aether
 * @subpackage Editor
 */

defined('ABSPATH') || exit;

/**
 * 编辑器页面类
 */
class Aether_Editor_Page extends Aether_Base {
    
    /**
     * 资产管理器
     * 
     * @var Aether_Assets
     */
    private $assets;
    
    /**
     * 初始化
     */
    protected function init() {
        $this->assets = Aether_Assets::getInstance();
        
        add_action('admin_menu', [$this, 'add_editor_page']);
        add_action('admin_init', [$this, 'handle_editor_page']);
        add_filter('screen_options_show_screen', [$this, 'hide_screen_options'], 10, 2);
    }
    
    /**
     * 添加编辑器页面（隐藏菜单项）
     */
    public function add_editor_page() {
        add_submenu_page(
            '', // 空字符串而不是 null，不在菜单中显示
            __('aether 编辑器', 'aether'),
            __('aether 编辑器', 'aether'),
            'edit_posts',
            'aether-editor',
            [$this, 'render_editor_page']
        );
    }
    
    /**
     * 渲染编辑器页面
     */
    public function render_editor_page() {
        // 检查权限
        if (!Aether_Permission_Service::can_use_editor()) {
            wp_die(__('您没有权限访问此页面。', 'aether'));
        }
        
        // 获取文章 ID
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id) {
            wp_die(__('无效的文章 ID。', 'aether'));
        }
        
        // 检查文章是否存在和编辑权限
        $post = get_post($post_id);
        if (!$post || !Aether_Permission_Service::can_edit_post($post_id)) {
            wp_die(__('文章不存在或您没有权限编辑。', 'aether'));
        }
        
        // 准备模板变量
        $template_vars = [
            'post' => $post,
            'post_id' => $post_id,
            'ai_settings' => Aether_Settings_Service::get_all()
        ];
        
        // 加载模板
        $this->load_template('editor-page', $template_vars);
        
        // 重要：阻止 WordPress 继续输出
        exit;
    }
    
    /**
     * 处理编辑器页面设置
     */
    public function handle_editor_page() {
        // 确保在正确的页面
        if (!isset($_GET['page']) || $_GET['page'] !== 'aether-editor') {
            return;
        }
        
        // 禁用自动保存
        wp_dequeue_script('autosave');
        
        // 加载编辑器资源
        $this->assets->enqueue_editor_assets();
        
        // 添加全屏模式类
        add_filter('admin_body_class', function($classes) {
            return $classes . ' aether-fullscreen';
        });
    }
    
    /**
     * 隐藏屏幕选项
     * 
     * @param bool $show 是否显示
     * @param WP_Screen $screen 当前屏幕对象
     * @return bool
     */
    public function hide_screen_options($show, $screen) {
        if (isset($_GET['page']) && $_GET['page'] === 'aether-editor') {
            return false;
        }
        return $show;
    }
    
    /**
     * 加载模板文件
     * 
     * @param string $template 模板名称
     * @param array $vars 模板变量
     */
    protected function load_template($template, $vars = []) {
        extract($vars);
        include AETHER_PATH . 'templates/' . $template . '.php';
    }
}