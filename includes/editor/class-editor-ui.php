<?php
/**
 * 编辑器 UI 集成
 * 
 * 处理编辑器按钮、链接等 UI 元素
 *
 * @package aether
 * @subpackage Editor
 */

defined('ABSPATH') || exit;

/**
 * 编辑器 UI 类
 */
class Aether_Editor_UI extends Aether_Base {
    
    /**
     * 初始化
     */
    protected function init() {
        // 为所有可能的 post type 注册钩子
        $public_post_types = get_post_types(['public' => true]);
        foreach ($public_post_types as $post_type) {
            if ($post_type === 'attachment') {
                continue;
            }
            add_filter("{$post_type}_row_actions", [$this, 'add_editor_link'], 10, 2);
        }
        
        add_action('edit_form_after_title', [$this, 'add_editor_button']);
        add_filter('display_post_states', [$this, 'add_post_state'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }
    
    /**
     * 在文章列表添加编辑器链接
     * 
     * @param array $actions 当前操作列表
     * @param WP_Post $post 文章对象
     * @return array
     */
    public function add_editor_link($actions, $post) {
        // 检查 API Token 是否已配置
        $api_token = Aether_Settings_Service::get('api_token');
        if (empty($api_token)) {
            return $actions;
        }
        
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
            return $actions;
        }
        
        if (Aether_Permission_Service::can_edit_post($post->ID)) {
            $url = add_query_arg([
                'page' => 'aether-editor',
                'post_id' => $post->ID,
            ], admin_url('admin.php'));
            
            $actions['aether_edit'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($url),
                __('aether 编辑器', 'aether')
            );
        }
        
        return $actions;
    }
    
    /**
     * 在编辑页面添加编辑器按钮
     * 
     * @param WP_Post $post 文章对象
     */
    public function add_editor_button($post) {
        // 检查 API Token 是否已配置
        $api_token = Aether_Settings_Service::get('api_token');
        if (empty($api_token)) {
            // 显示配置提示
            $this->render_token_setup_notice();
            return;
        }
        
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
        
        $url = add_query_arg([
            'page' => 'aether-editor',
            'post_id' => $post->ID,
        ], admin_url('admin.php'));
        
        $is_aether_edited = get_post_meta($post->ID, '_aether_edited', true);
        
        if ($is_aether_edited) {
            $this->render_aether_notice($url, $post->ID);
        } else {
            $save_nonce = wp_create_nonce('aether_save_draft');
            ?>
            <div style="margin: 10px 0;">
                <a href="<?php echo esc_url($url); ?>"
                   class="button button-primary button-large aether-open-editor-button"
                   data-aether-url="<?php echo esc_url($url); ?>"
                   data-aether-nonce="<?php echo esc_attr($save_nonce); ?>">
                    <?php _e('使用 aether 编辑器', 'aether'); ?>
                </a>
            </div>
            <?php
            static $aether_button_script_added = false;
            if (!$aether_button_script_added) {
                $aether_button_script_added = true;
                ?>
                <script>
                (function($) {
                    function getClassicContent() {
                        if (typeof tinyMCE !== 'undefined') {
                            var editor = tinyMCE.get('content');
                            if (editor && !editor.isHidden()) {
                                return editor.getContent({ format: 'raw' });
                            }
                        }
                        var textarea = document.getElementById('content');
                        return textarea ? textarea.value : '';
                    }

                    function getExcerpt() {
                        var excerpt = document.getElementById('excerpt');
                        return excerpt ? excerpt.value : '';
                    }

                    function normalizeRichText(value) {
                        if (!value) {
                            return '';
                        }

                        var trimmed = value
                            .replace(/&nbsp;/gi, ' ')
                            .replace(/<br[^>]*>/gi, '')
                            .replace(/<\/p>/gi, '\n')
                            .replace(/<p[^>]*>/gi, '')
                            .trim();

                        return trimmed === '' ? '' : value;
                    }

                    function normalizePlainText(value) {
                        return value ? value.trim() : '';
                    }

                    function clearBeforeUnload() {
                        if (typeof window !== 'undefined') {
                            window.onbeforeunload = null;
                        }
                        if (typeof $ !== 'undefined' && $.fn && $.fn.off) {
                            $(window).off('beforeunload.edit-post');
                            $(window).off('beforeunload');
                        }
                    }

                    function syncHiddenFields(data) {
                        var title = data.title;
                        var content = normalizeRichText(data.content);
                        var excerpt = normalizeRichText(data.excerpt);

                        var $hiddenTitle = $('#hidden_post_title');
                        if ($hiddenTitle.length) {
                            $hiddenTitle.val(title);
                        }

                        var $hiddenContent = $('#hidden_post_content');
                        if ($hiddenContent.length) {
                            $hiddenContent.val(content);
                        }

                        var $hiddenExcerpt = $('#hidden_post_excerpt');
                        if ($hiddenExcerpt.length) {
                            $hiddenExcerpt.val(excerpt);
                        }

                        var excerptField = document.getElementById('excerpt');
                        if (excerptField) {
                            excerptField.defaultValue = excerpt;
                        }

                        var editor = typeof tinyMCE !== 'undefined' ? tinyMCE.get('content') : null;
                        if (editor && content === '' && typeof editor.setContent === 'function') {
                            editor.setContent('');
                        }
                        if (editor && typeof editor.setDirty === 'function') {
                            editor.setDirty(false);
                        } else if (editor) {
                            editor.isNotDirty = true;
                        }

                        var textarea = document.getElementById('content');
                        if (textarea) {
                            textarea.defaultValue = content || '';
                            if (content === '') {
                                textarea.value = '';
                            }
                        }
                    }

                    function hasUnsavedChanges(currentStatus, title, content, excerpt) {
                        var normalizedContent = normalizeRichText(content);
                        var normalizedExcerpt = normalizeRichText(excerpt);
                        var editor = typeof tinyMCE !== 'undefined' ? tinyMCE.get('content') : null;
                        var editorDirty = false;
                        if (editor && !editor.isHidden()) {
                            if (typeof editor.isDirty === 'function') {
                                editorDirty = editor.isDirty();
                            } else {
                                editorDirty = !editor.isNotDirty;
                            }
                        }

                        var titleChanged = false;
                        var contentChanged = false;
                        var excerptChanged = false;

                        var $hiddenTitle = $('#hidden_post_title');
                        if ($hiddenTitle.length) {
                            titleChanged = normalizePlainText($hiddenTitle.val()) !== normalizePlainText(title);
                        }

                        var $hiddenContent = $('#hidden_post_content');
                        if ($hiddenContent.length) {
                            contentChanged = normalizeRichText($hiddenContent.val()) !== normalizedContent;
                        }

                        var $hiddenExcerpt = $('#hidden_post_excerpt');
                        if ($hiddenExcerpt.length) {
                            excerptChanged = normalizeRichText($hiddenExcerpt.val()) !== normalizedExcerpt;
                        }

                        return editorDirty || titleChanged || contentChanged || excerptChanged || currentStatus === 'auto-draft' || currentStatus === '';
                    }

                    function redirectToEditor(url) {
                        clearBeforeUnload();
                        window.location.href = url;
                    }

                    $(document).on('click', '.aether-open-editor-button', function(event) {
                        event.preventDefault();

                        var $button = $(this);
                        if ($button.data('saving')) {
                            return;
                        }

                        var redirectUrl = $button.data('aether-url');

                        if (typeof ajaxurl === 'undefined') {
                            redirectToEditor(redirectUrl);
                            return;
                        }

                        var $postIdField = $('#post_ID');
                        if (!$postIdField.length) {
                            redirectToEditor(redirectUrl);
                            return;
                        }

                        var postId = parseInt($postIdField.val(), 10);
                        if (!postId) {
                            redirectToEditor(redirectUrl);
                            return;
                        }

                        var titleValue = $('#title').val() || '';
                        var contentValue = normalizeRichText(getClassicContent());
                        var excerptValue = normalizeRichText(getExcerpt());

                        var $originalStatus = $('#original_post_status');
                        var currentStatus = ($originalStatus.val() || '').toLowerCase();
                        var requiresDraftSave = hasUnsavedChanges(currentStatus, titleValue, contentValue, excerptValue);

                        if (!requiresDraftSave) {
                            redirectToEditor(redirectUrl);
                            return;
                        }

                        $button.data('saving', true);
                        $button.addClass('disabled').attr('aria-disabled', 'true');

                        var request = {
                            action: 'aether_save_draft',
                            nonce: $button.data('aether-nonce'),
                            post_id: postId,
                            title: titleValue,
                            content: contentValue
                        };

                        if (excerptValue) {
                            request.excerpt = excerptValue;
                        }

                        $.post(ajaxurl, request)
                            .done(function(response) {
                                if (response && response.success) {
                                    var newStatus = (response.data && response.data.post_status) ? response.data.post_status : 'draft';
                                    var $hiddenStatus = $('#hidden_post_status');
                                    if ($hiddenStatus.length) {
                                        $hiddenStatus.val(newStatus);
                                    }
                                    $originalStatus.val(newStatus);
                                    syncHiddenFields({
                                        title: titleValue,
                                        content: contentValue,
                                        excerpt: excerptValue
                                    });
                                    redirectToEditor(redirectUrl);
                                } else {
                                    var message = response && response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('保存草稿失败，请稍后重试。', 'aether')); ?>';
                                    alert(message);
                                    $button.removeClass('disabled').attr('aria-disabled', 'false');
                                    $button.data('saving', false);
                                }
                            })
                            .fail(function() {
                                alert('<?php echo esc_js(__('保存草稿失败，请稍后重试。', 'aether')); ?>');
                                $button.removeClass('disabled').attr('aria-disabled', 'false');
                                $button.data('saving', false);
                            });
                    });
                })(jQuery);
                </script>
                <?php
            }
        }
    }
    
    /**
     * 渲染 aether 编辑提示
     * 
     * @param string $url 编辑器 URL
     * @param int $post_id 文章 ID
     */
    private function render_aether_notice($url, $post_id) {
        ?>
        <style>
            /* 隐藏区块编辑器 */
            #postdivrich,
            .editor-post-title,
            .edit-post-visual-editor,
            .edit-post-text-editor,
            .block-editor-writing-flow,
            .interface-interface-skeleton__content,
            .editor-styles-wrapper {
                display: none !important;
            }
            
            /* 隐藏编辑器相关的工具栏 */
            .edit-post-header-toolbar,
            .edit-post-visual-editor__post-title-wrapper {
                display: none !important;
            }
        </style>
        
        <div class="aether-editor-notice" style="margin: 20px 0; padding: 20px; background: #f0f0f1; border-radius: 4px; text-align: center;">
            <h2 style="margin: 0 0 20px 0; color: #1e1e1e;">
                <?php _e('此页面使用 aether 编辑器创建', 'aether'); ?>
            </h2>
            <p style="margin: 0 0 20px 0; color: #646970;">
                <?php _e('要继续编辑此页面，请使用 aether 编辑器。', 'aether'); ?>
            </p>
            <div style="margin: 20px 0;">
                <a href="<?php echo esc_url($url); ?>" class="button button-primary button-hero">
                    <?php _e('打开 aether 编辑器', 'aether'); ?>
                </a>
            </div>
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #dcdcde;">
                <p style="margin: 0 0 10px 0; color: #d63638; font-weight: 600;">
                    <?php _e('警告：返回 WordPress 编辑器可能会破坏页面布局', 'aether'); ?>
                </p>
                <button type="button" class="button button-link-delete" onclick="aetherConfirmReturn(<?php echo $post_id; ?>)">
                    <?php _e('返回 WordPress 编辑器', 'aether'); ?>
                </button>
            </div>
        </div>

        <script>
            function aetherConfirmReturn(postId) {
                if (confirm('<?php _e('警告：返回 WordPress 编辑器可能会破坏使用 aether 创建的页面布局。\\n\\n确定要继续吗？', 'aether'); ?>')) {
                    jQuery.post(ajaxurl, {
                        action: 'aether_remove_edit_flag',
                        post_id: postId,
                        nonce: '<?php echo wp_create_nonce('aether_remove_edit_flag'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        }
                    });
                }
            }
        </script>
        <?php
    }
    
    /**
     * 在文章列表中添加 aether 标识
     * 
     * @param array $post_states 当前文章状态数组
     * @param WP_Post $post 文章对象
     * @return array
     */
    public function add_post_state($post_states, $post) {
        if (get_post_meta($post->ID, '_aether_edited', true)) {
            $post_states['aether'] = 'aether';
        }
        return $post_states;
    }
    
    /**
     * 渲染 Token 设置提示
     */
    private function render_token_setup_notice() {
        $settings_url = admin_url('admin.php?page=aether');
        ?>
        <div class="aether-editor-notice notice notice-warning" style="margin: 10px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <h3 style="margin: 0 0 10px 0; color: #856404;">
                <?php _e('需要配置 aether API Token', 'aether'); ?>
            </h3>
            <p style="margin: 0 0 15px 0; color: #856404;">
                <?php _e('要使用 aether 编辑器，您需要先配置 API Token。请前往设置页面完成配置。', 'aether'); ?>
            </p>
            <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary">
                <?php _e('前往设置', 'aether'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * 注册管理员样式
     * 
     * @param string $hook 当前页面钩子
     */
    public function enqueue_admin_styles($hook) {
        if (in_array($hook, ['post.php', 'post-new.php'])) {
            wp_add_inline_style('wp-admin', '
                .aether-editor-notice {
                    animation: fadeIn 0.3s ease-in;
                }
                
                @keyframes fadeIn {
                    from { opacity: 0; transform: translateY(-10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                
                .aether-editor-notice .button-hero {
                    height: 48px;
                    line-height: 46px;
                    font-size: 18px;
                    padding: 0 36px;
                }
                
                body.block-editor-page .aether-editor-notice {
                    position: relative;
                    z-index: 10;
                }
            ');
        }
    }
}
