<?php
/**
 * Editor Page Base Class
 * 
 * Abstract base class for all editor page implementations
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Abstract Editor Page Base Class
 */
abstract class Aether_Editor_Page_Base extends Aether_Base {
    
    /**
     * Get the page slug
     *
     * @return string
     */
    abstract protected function get_page_slug();
    
    /**
     * Get the page title
     *
     * @return string
     */
    abstract protected function get_page_title();
    
    /**
     * Get the required capability
     *
     * @return string
     */
    abstract protected function get_capability();
    
    /**
     * Get the URL parameter name
     *
     * @return string
     */
    abstract protected function get_param_name();
    
    /**
     * Get content ID from request
     *
     * @return int|null
     */
    abstract protected function get_content_id_from_request();
    
    /**
     * Validate content and get content object
     *
     * @param int $content_id
     * @return WP_Post|null
     */
    abstract protected function validate_content($content_id);
    
    /**
     * Get localized data for the editor
     *
     * @param int $content_id
     * @param WP_Post $content
     * @return array
     */
    abstract protected function get_localized_data($content_id, $content);
    
    /**
     * Get body CSS class
     *
     * @return string
     */
    abstract protected function get_body_class();
    
    /**
     * Get additional inline scripts
     *
     * @param array $settings
     * @return string
     */
    protected function get_inline_scripts($settings) {
        return '';
    }
    
    /**
     * Initialize
     */
    protected function init() {
        add_action('admin_menu', [$this, 'add_editor_page']);
        add_action('admin_init', [$this, 'handle_editor_redirect']);
    }
    
    /**
     * Add hidden editor page
     */
    public function add_editor_page() {
        add_submenu_page(
            '',
            $this->get_page_title(),
            $this->get_page_title(),
            $this->get_capability(),
            $this->get_page_slug(),
            [$this, 'render_editor_page']
        );
    }
    
    /**
     * Handle editor redirect
     */
    public function handle_editor_redirect() {
        global $pagenow;
        
        if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === $this->get_page_slug()) {
            $content_id = $this->get_content_id_from_request();
            
            if (!$content_id) {
                wp_die(__('无效的请求', 'aether'));
            }
            
            $this->render($content_id);
            exit;
        }
    }
    
    /**
     * Render editor page
     */
    public function render_editor_page() {
        $content_id = $this->get_content_id_from_request();
        
        if (!$content_id) {
            wp_die(__('无效的请求', 'aether'));
        }
        
        $this->render($content_id);
    }
    
    /**
     * Render the editor
     *
     * @param int $content_id
     */
    public function render($content_id) {
        // 禁用 admin bar
        remove_action('wp_footer', 'wp_admin_bar_render', 1000);
        
        // 验证内容
        $content = $this->validate_content($content_id);
        if (!$content) {
            return;
        }
        
        // 获取设置
        $settings = Aether_Settings_Service::get_all();
        
        // 获取字体 CSS
        $font_css = '';
        if (class_exists('Aether_Font_Manager_Service')) {
            $font_css = Aether_Font_Manager_Service::get_font_css();
        }
        
        // 获取主题样式表 URL
        $theme_stylesheet_url = get_stylesheet_uri();
        
        // 获取本地化数据
        $editor_data = $this->get_localized_data($content_id, $content);
        
        // 获取 WordPress 上下文数据 - 使用新的服务
        $context_service = Aether_WordPress_Context_Service::getInstance();
        $wordpress_context = $context_service->get_context([
            'include_posts' => true,
            'include_fields' => true,
            'include_terms' => true,
            'include_menu_items' => false, // For performance, only include if needed
            'posts_per_type' => 20,
        ]);
        
        // Legacy support - will be removed after full migration
        $post_types = [];
        $fields_by_type = new stdClass();
        $menus = [];
        
        // Extract legacy format from new context for backward compatibility
        if (!empty($wordpress_context['post_types'])) {
            foreach ($wordpress_context['post_types'] as $type_name => $type_data) {
                $post_types[] = [
                    'name' => $type_data['name'],
                    'label' => $type_data['singular_label'],
                    'description' => $type_data['description'],
                ];
            }
        }
        
        if (!empty($wordpress_context['fields'])) {
            foreach ($wordpress_context['fields'] as $type => $fields) {
                $fields_by_type->$type = $fields;
            }
        }
        
        if (!empty($wordpress_context['menus'])) {
            foreach ($wordpress_context['menus'] as $menu) {
                $menus[] = [
                    'id' => $menu['id'],
                    'name' => $menu['name'],
                    'slug' => $menu['slug'],
                ];
            }
        }
        
        // 添加通用字段
        $editor_data = array_merge($editor_data, [
            'nonce' => wp_create_nonce('wp_rest'), // For REST API
            'ajaxNonce' => wp_create_nonce('aether-editor'), // For AJAX requests
            'apiUrl' => rest_url('aether/v1'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'pluginUrl' => AETHER_URL,
            'aiSettings' => $settings,
            'fontCss' => $font_css,
            'themeStylesheetUrl' => $theme_stylesheet_url,
            'designSystemHtml' => $settings['design_system_html'] ?? '',
            'extractedTailwindConfig' => $settings['extracted_tailwind_config'] ?? '',
            // New comprehensive context
            'wordpressContext' => $wordpress_context,
            // Legacy fields for backward compatibility (to be removed)
            'postTypes' => $post_types,
            'fieldsByType' => $fields_by_type,
            'menus' => $menus,
        ]);
        
        // 渲染页面
        $this->render_html($editor_data, $settings);
    }
    
    /**
     * Render HTML
     *
     * @param array $editor_data
     * @param array $settings
     */
    protected function render_html($editor_data, $settings) {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($this->get_page_title()); ?></title>
            <?php
            // 加载必要的 WordPress 资源
            wp_enqueue_media();
            
            // 加载 React 编辑器资源
            $vite_loader = Aether_Vite_Loader::getInstance();
            $vite_loader->enqueue('editor', ['wp-api', 'wp-i18n']);
            
            // 本地化数据（必须在 enqueue 之后）
            wp_localize_script('aether-editor', 'aetherEditor', $editor_data);
            
            // 添加额外的内联脚本
            $inline_scripts = $this->get_inline_scripts($settings);
            if (!empty($inline_scripts)) {
                wp_add_inline_script('aether-editor', $inline_scripts, 'before');
            }
            
            // 输出头部资源
            wp_head();
            ?>
        </head>
        <body class="<?php echo esc_attr($this->get_body_class()); ?>">
            <div id="aether-editor-root"></div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
}