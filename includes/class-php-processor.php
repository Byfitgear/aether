<?php
/**
 * PHP Code Processor
 * 
 * 安全处理和执行 PHP 代码
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * PHP 代码处理器类
 */
class Aether_PHP_Processor extends Aether_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        add_filter('the_content', [$this, 'process_php_content'], 9999);
        add_action('save_post', [$this, 'clear_post_cache']);
        add_action('edit_post', [$this, 'clear_post_cache']);
        add_action('aether_content_saved', [$this, 'clear_cache_on_aether_save']);
    }
    
    /**
     * 处理包含 PHP 代码的内容
     * 
     * @param string $content 内容
     * @return string
     */
    public function process_php_content($content) {
        global $post;
        
        if ($content === null) {
            $content = '';
        }
        
        if (!$post) {
            return $content;
        }
        
        $aether_edited = get_post_meta($post->ID, '_aether_edited', true);
        
        if (!$aether_edited) {
            return $content;
        }
        
        if ($this->contains_php_code($content)) {
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
     * 执行包含 PHP 代码的内容（使用临时文件替代 eval）
     * 
     * @param string $content 内容
     * @param int $post_id 文章 ID
     * @return string
     */
    private function execute_php_content($content, $post_id) {
        global $post;
        $original_post = $post;
        $post = get_post($post_id);
        setup_postdata($post);
        
        $error = null;
        $temp_file = null;
        
        try {
            // 创建临时文件，写入内容后 include
            $temp_file = tempnam(sys_get_temp_dir(), 'aether_');
            file_put_contents($temp_file, $content);
            
            ob_start();
            include $temp_file;
            $output = ob_get_clean();
            
            // 立即删除临时文件
            wp_delete_file($temp_file);
            $temp_file = null;
            
        } catch (ParseError $e) {
            $error = 'Parse Error: ' . $e->getMessage();
            $output = $content;
        } catch (Error $e) {
            $error = 'Fatal Error: ' . $e->getMessage();
            $output = $content;
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
            $output = $content;
        } finally {
            // 确保清理临时文件
            if ($temp_file && file_exists($temp_file)) {
                wp_delete_file($temp_file);
            }
        }
        
        // 恢复原始 post
        $post = $original_post;
        if ($original_post) {
            setup_postdata($original_post);
        }
        
        if ($error) {
            error_log('[Aether PHP Processor] ' . $error);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                return '<div style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; margin: 10px 0;">' . 
                       '<strong>Aether PHP Error:</strong> ' . esc_html($error) . 
                       '</div>';
            }
            return $content;
        }
        
        return $output ?: '';
    }
    
    /**
     * 清除文章的 PHP 输出缓存
     * 
     * @param int $post_id 文章 ID
     */
    public function clear_post_cache($post_id) {
        Aether_PHP_Runtime_Cache_Policy::clear_output_cache($post_id);
    }
    
    /**
     * 当通过 aether 保存内容时清除缓存
     * 
     * @param array $data 保存的数据
     */
    public function clear_cache_on_aether_save($data) {
        if (isset($data['post_id'])) {
            $this->clear_post_cache($data['post_id']);
        }
    }
}
