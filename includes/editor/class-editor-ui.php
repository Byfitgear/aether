<?php
defined('ABSPATH') || exit;

class Aether_Editor_UI extends Aether_Base
{
    protected function init()
    {
        $public_post_types = get_post_types(['public' => true]);
        foreach ($public_post_types as $post_type) {
            if ($post_type === 'attachment') continue;
            add_filter("{$post_type}_row_actions", [$this, 'add_editor_link'], 10, 2);
        }
        add_action('edit_form_after_title', [$this, 'add_editor_button']);
        add_filter('display_post_states', [$this, 'add_post_state'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    public function add_editor_link($actions, $post)
    {
        $allowed_types = Aether_Settings_Service::get('post_types', null);
        if (empty($allowed_types)) {
            $allowed_types = [];
            $all = get_post_types(['public' => true], 'names');
            foreach ($all as $pt) {
                if (!in_array($pt, ['attachment', 'aether_template'])) {
                    $allowed_types[] = $pt;
                }
            }
        }
        if (!in_array($post->post_type, $allowed_types)) return $actions;
        if (Aether_Permission_Service::can_edit_post($post->ID)) {
            $url = add_query_arg([
                'page' => 'aether-editor',
                'post_id' => $post->ID,
            ], admin_url('admin.php'));
            $actions['aether_edit'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($url),
                __('Aether 编辑器', 'aether')
            );
        }
        return $actions;
    }

    public function add_editor_button($post)
    {
        $allowed_types = Aether_Settings_Service::get('post_types', null);
        if (empty($allowed_types)) {
            $allowed_types = [];
            $all = get_post_types(['public' => true], 'names');
            foreach ($all as $pt) {
                if (!in_array($pt, ['attachment', 'aether_template'])) {
                    $allowed_types[] = $pt;
                }
            }
        }
        if (!in_array($post->post_type, $allowed_types)) return;
        if (!Aether_Permission_Service::can_edit_post($post->ID)) return;

        $url = add_query_arg([
            'page' => 'aether-editor',
            'post_id' => $post->ID,
        ], admin_url('admin.php'));
        $is_we_edited = get_post_meta($post->ID, '_aether_edited', true);

        if ($is_we_edited) {
            $this->render_we_notice($url, $post->ID);
        } else {
            $save_nonce = wp_create_nonce('aether_save_draft');
            ?>
            <div style="margin: 10px 0;">
                <a href="<?php echo esc_url($url); ?>"
                   class="button button-primary button-large"
                   data-we-url="<?php echo esc_url($url); ?>"
                   data-we-nonce="<?php echo esc_attr($save_nonce); ?>">
                    <?php _e('使用 Aether 编辑器', 'aether'); ?>
                </a>
            </div>
            <?php
        }
    }

    private function render_we_notice($url, $post_id)
    {
        ?>
        <style>
            #postdivrich, .editor-post-title, .edit-post-visual-editor,
            .edit-post-text-editor, .block-editor-writing-flow,
            .interface-interface-skeleton__content, .editor-styles-wrapper { display:none !important; }
            .edit-post-header-toolbar, .edit-post-visual-editor__post-title-wrapper { display:none !important; }
        </style>
        <div class="we-editor-notice" style="margin:20px 0;padding:20px;background:#f0f0f1;border-radius:4px;text-align:center;">
            <h2 style="margin:0 0 20px 0;color:#1e1e1e;"><?php _e('此页面使用 Aether 编辑器创建', 'aether'); ?></h2>
            <p style="margin:0 0 20px 0;color:#646970;"><?php _e('要继续编辑此页面，请使用 Aether 编辑器。', 'aether'); ?></p>
            <div style="margin:20px 0;"><a href="<?php echo esc_url($url); ?>" class="button button-primary button-hero"><?php _e('打开 Aether 编辑器', 'aether'); ?></a></div>
            <div style="margin-top:30px;padding-top:20px;border-top:1px solid #dcdcde;">
                <p style="margin:0 0 10px 0;color:#d63638;font-weight:600;"><?php _e('警告：返回 WordPress 编辑器可能会破坏页面布局', 'aether'); ?></p>
                <button type="button" class="button button-link-delete" onclick="weConfirmReturn(<?php echo $post_id; ?>)">
                    <?php _e('返回 WordPress 编辑器', 'aether'); ?>
                </button>
            </div>
        </div>
        <script>
        function weConfirmReturn(postId){
            if(confirm('<?php echo esc_js(__('警告：返回 WordPress 编辑器可能会破坏页面布局。\n\n确定要继续吗？', 'aether')); ?>')){
                jQuery.post(ajaxurl,{action:'aether_remove_edit_flag',post_id:postId,nonce:'<?php echo wp_create_nonce('aether_remove_edit_flag'); ?>'},function(r){if(r.success)location.reload();});
            }
        }
        </script>
        <?php
    }

    public function add_post_state($post_states, $post)
    {
        if (get_post_meta($post->ID, '_aether_edited', true)) {
            $post_states['aether'] = 'Aether';
        }
        return $post_states;
    }

    public function enqueue_admin_styles($hook)
    {
        if (in_array($hook, ['post.php', 'post-new.php'])) {
            wp_add_inline_style('wp-admin', '
                .we-editor-notice{animation:fadeIn .3s ease-in}@keyframes fadeIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
                .we-editor-notice .button-hero{height:48px;line-height:46px;font-size:18px;padding:0 36px}
                body.block-editor-page .we-editor-notice{position:relative;z-index:10}
            ');
        }
    }
}
