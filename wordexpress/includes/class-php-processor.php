<?php
/**
 * PHP Code Processor
 * 
 * 安全处理和执行 PHP 代码
 *
 * @package zeroy
 */

defined('ABSPATH') || exit;

/**
 * PHP 代码处理器类
 */
class ZeroY_PHP_Processor extends ZeroY_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        // 为使用 zeroy 编辑的内容添加特殊处理
        add_filter('the_content', [$this, 'process_php_content'], 9999);
        
        // 清除缓存的钩子
        add_action('save_post', [$this, 'clear_post_cache']);
        add_action('edit_post', [$this, 'clear_post_cache']);
        add_action('zeroy_content_saved', [$this, 'clear_cache_on_zeroy_save']);
    }
    
    /**
     * 处理包含 PHP 代码的内容
     * 
     * @param string $content 内容
     * @return string
     */
    public function process_php_content($content) {
        global $post;
        
        // 确保内容不为空
        if ($content === null) {
            $content = '';
        }
        
        if (!$post) {
            return $content;
        }
        
        // 检查是否使用 zeroy 编辑
        $zeroy_edited = get_post_meta($post->ID, '_zeroy_edited', true);
        
        if (!$zeroy_edited) {
            return $content;
        }
        
        // 检查是否包含 PHP 代码
        if ($this->contains_php_code($content)) {
            // 执行 PHP 代码
            return $this->execute_php_content($content, $post->ID);
        }
        
        return $content;
    }
    
    /**
     * 检查内容是否包含 PHP 代码
     * 
     * @param string $content 内容
     * @return bool
     */
    private function contains_php_code($content) {
        return (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false);
    }
    
    /**
     * 执行包含 PHP 代码的内容
     * 
     * @param string $content 内容
     * @param int $post_id 文章 ID
     * @return string
     */
    private function execute_php_content($content, $post_id) {
        // 设置 WordPress 上下文
        global $post;
        $original_post = $post;
        $post = get_post($post_id);
        setup_postdata($post);
        
        // 捕获输出
        ob_start();
        $error = null;
        
        try {
            // 使用输出缓冲执行 PHP
            // 注意：这里使用 eval 是有意的，因为这是核心功能
            // 只执行已通过 zeroY 保存的内容
            eval('?>' . $content);
            
        } catch (ParseError $e) {
            $error = 'Parse Error: ' . $e->getMessage();
        } catch (Error $e) {
            $error = 'Fatal Error: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
        
        $output = ob_get_clean();
        
        // 恢复原始 post
        $post = $original_post;
        if ($original_post) {
            setup_postdata($original_post);
        }
        
        if ($error) {
            // 在开发模式下显示错误
            if (defined('WP_DEBUG') && WP_DEBUG) {
                return '<div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; margin: 10px 0;">' . 
                       '<strong>ZeroY PHP Error:</strong> ' . esc_html($error) . 
                       '</div>';
            }
            // 生产环境返回原始内容
            return $content;
        }
        
        return $output;
    }
    
    /**
     * 清除文章的 PHP 输出缓存
     * 
     * @param int $post_id 文章 ID
     */
    public function clear_post_cache($post_id) {
        // 清除该文章的 PHP 输出缓存
        ZeroY_PHP_Runtime_Cache_Policy::clear_output_cache($post_id);
    }
    
    /**
     * 当通过 zeroy 保存内容时清除缓存
     * 
     * @param array $data 保存的数据
     */
    public function clear_cache_on_zeroy_save($data) {
        if (isset($data['post_id'])) {
            $this->clear_post_cache($data['post_id']);
        }
    }
}
