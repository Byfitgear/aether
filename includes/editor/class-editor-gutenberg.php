<?php
/**
 * Gutenberg 集成
 * 
 * 处理 Gutenberg 编辑器相关功能
 *
 * @package aether
 * @subpackage Editor
 */

defined('ABSPATH') || exit;

/**
 * Gutenberg 集成类
 */
class Aether_Editor_Gutenberg extends Aether_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        add_action('enqueue_block_editor_assets', [$this, 'handle_gutenberg_editor']);
    }
    
    /**
     * 处理 Gutenberg 编辑器
     */
    public function handle_gutenberg_editor() {
        global $post;
        
        if (!$post) {
            return;
        }
        
        // 检查 post type 是否在允许列表中
        $allowed_types = Aether_Settings_Service::get('post_types', null);
        
        // If no saved types, get all available post types dynamically
        if (empty($allowed_types)) {
            $allowed_types = [];
            $all_post_types = get_post_types(['public' => true], 'names');
            $excluded_types = [
                'attachment',
                'aether_template'
            ];
            
            foreach ($all_post_types as $post_type) {
                if (!in_array($post_type, $excluded_types)) {
                    $allowed_types[] = $post_type;
                }
            }
        }
        
        if (!in_array($post->post_type, $allowed_types)) {
            return;
        }
        
        if (!Aether_Permission_Service::can_edit_post($post->ID)) {
            return;
        }
        
        $is_aether_edited = get_post_meta($post->ID, '_aether_edited', true);
        
        if ($is_aether_edited) {
            // Inject scripts for Gutenberg editor
            $script = $this->get_gutenberg_override_script($post->ID);
            wp_add_inline_script('wp-edit-post', $script);
        }
    }
    
    /**
     * 获取 Gutenberg 覆盖脚本
     * 
     * @param int $post_id 文章 ID
     * @return string
     */
    private function get_gutenberg_override_script($post_id) {
        $editor_url = esc_url(add_query_arg(['page' => 'aether-editor', 'post_id' => $post_id], admin_url('admin.php')));
        
        return '
            wp.domReady(function() {
                const editor = document.querySelector(".edit-post-layout");
                if (editor) {
                    const notice = document.createElement("div");
                    notice.className = "aether-gutenberg-notice";
                    notice.innerHTML = `
                        <style>
                            .aether-gutenberg-notice {
                                position: fixed;
                                top: 0;
                                left: 0;
                                right: 0;
                                bottom: 0;
                                background: #fff;
                                z-index: 100000;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                            }
                            .aether-gutenberg-notice-content {
                                text-align: center;
                                max-width: 600px;
                                padding: 40px;
                            }
                            .aether-gutenberg-notice h2 {
                                font-size: 24px;
                                margin: 0 0 20px 0;
                            }
                            .aether-gutenberg-notice p {
                                font-size: 16px;
                                margin: 0 0 30px 0;
                                color: #646970;
                            }
                            .aether-gutenberg-notice .button {
                                margin: 0 5px;
                                padding: 12px 24px;
                                font-size: 16px;
                            }
                            .aether-gutenberg-notice .warning {
                                margin-top: 40px;
                                padding-top: 30px;
                                border-top: 1px solid #dcdcde;
                            }
                            .aether-gutenberg-notice .warning p {
                                color: #d63638;
                                font-weight: 600;
                                margin-bottom: 15px;
                            }
                        </style>
                        <div class="aether-gutenberg-notice-content">
                            <h2>' . esc_js(__('此页面使用 aether 编辑器创建', 'aether')) . '</h2>
                            <p>' . esc_js(__('要继续编辑此页面，请使用 aether 编辑器。', 'aether')) . '</p>
                            <a href="' . $editor_url . '" class="button button-primary button-hero">
                                ' . esc_js(__('打开 aether 编辑器', 'aether')) . '
                            </a>
                            <div class="warning">
                                <p>' . esc_js(__('警告：返回 WordPress 编辑器可能会破坏页面布局', 'aether')) . '</p>
                                <button type="button" class="button button-link-delete" onclick="if(confirm(\'' . esc_js(__('警告：返回 WordPress 编辑器可能会破坏使用 aether 创建的页面布局。\\n\\n确定要继续吗？', 'aether')) . '\')) { aetherRemoveEditFlag(' . $post_id . '); }">
                                    ' . esc_js(__('返回 WordPress 编辑器', 'aether')) . '
                                </button>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(notice);
                }
            });
            
            function aetherRemoveEditFlag(postId) {
                wp.apiFetch({
                    path: "/wp/v2/posts/" + postId,
                    method: "POST",
                    data: {
                        meta: {
                            _aether_edited: "",
                            _aether_last_edited: ""
                        }
                    }
                }).then(function() {
                    window.location.reload();
                });
            }
        ';
    }
}