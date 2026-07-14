<?php
defined('ABSPATH') || exit;

class Aether_Editor_Gutenberg extends Aether_Base
{
    protected function init()
    {
        add_action('enqueue_block_editor_assets', [$this, 'handle_gutenberg_editor']);
    }

    public function handle_gutenberg_editor()
    {
        global $post;
        if (!$post) return;

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

        $is_we_edited = get_post_meta($post->ID, '_aether_edited', true);
        if ($is_we_edited) {
            $editor_url = esc_url(add_query_arg(['page' => 'aether-editor', 'post_id' => $post->ID], admin_url('admin.php')));
            $script = "wp.domReady(function(){
                var editor=document.querySelector('.edit-post-layout');
                if(editor){
                    var notice=document.createElement('div');
                    notice.className='we-gutenberg-notice';
                    notice.innerHTML='<style>.we-gutenberg-notice{position:fixed;top:0;left:0;right:0;bottom:0;background:#fff;z-index:100000;display:flex;align-items:center;justify-content:center}.we-gutenberg-notice-content{text-align:center;max-width:600px;padding:40px}.we-gutenberg-notice h2{font-size:24px;margin:0 0 20px}.we-gutenberg-notice p{font-size:16px;margin:0 0 30px;color:#646970}.we-gutenberg-notice .button{margin:0 5px;padding:12px 24px;font-size:16px}.we-gutenberg-notice .warning{margin-top:40px;padding-top:30px;border-top:1px solid #dcdcde}.we-gutenberg-notice .warning p{color:#d63638;font-weight:600;margin-bottom:15px}</style>'
                    +'<div class=\"we-gutenberg-notice-content\"><h2>' + '<?php echo esc_js(__('此页面使用 Aether 编辑器创建', 'aether')); ?>' + '</h2>'
                    +'<p>' + '<?php echo esc_js(__('要继续编辑此页面，请使用 Aether 编辑器。', 'aether')); ?>' + '</p>'
                    +'<a href=\"' + '<?php echo esc_js($editor_url); ?>' + '\" class=\"button button-primary button-hero\">' + '<?php echo esc_js(__('打开 Aether 编辑器', 'aether')); ?>' + '</a>'
                    +'<div class=\"warning\"><p>' + '<?php echo esc_js(__('警告：返回 WordPress 编辑器可能会破坏页面布局', 'aether')); ?>' + '</p>'
                    +'<button type=\"button\" class=\"button button-link-delete\" onclick=\"if(confirm(\\'' . esc_js(__('确定继续？', 'aether')) . '\\')){weRemoveEditFlag(' . $post->ID . ');}\">' + '<?php echo esc_js(__('返回 WordPress 编辑器', 'aether')); ?>' + '</button></div></div>';
                    document.body.appendChild(notice);
                }
            });
            function weRemoveEditFlag(postId){wp.apiFetch({path:\"/wp/v2/posts/\"+postId,method:\"POST\",data:{meta:{_aether_edited:\"\"}}}).then(function(){window.location.reload();});}";
            wp_add_inline_script('wp-edit-post', $script);
        }
    }
}
