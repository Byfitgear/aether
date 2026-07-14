<?php
defined('ABSPATH') || exit;

class WordExpress_Editor_Page extends WordExpress_Editor_Page_Base
{
    protected function init()
    {
        add_action('admin_menu', [$this, 'add_editor_page']);
        add_action('admin_init', [$this, 'handle_editor_page']);
        add_filter('screen_options_show_screen', [$this, 'hide_screen_options'], 10, 2);
    }

    public function add_editor_page()
    {
        add_submenu_page('', __('WordExpress 编辑器', 'wordexpress'), __('WordExpress 编辑器', 'wordexpress'), 'edit_posts', 'wordexpress-editor', [$this, 'render_editor_page']);
    }

    public function render_editor_page()
    {
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        if (!$post_id) wp_die(__('无效的文章 ID。', 'wordexpress'));
        $post = $this->get_post($post_id);
        $template_vars = ['post' => $post, 'post_id' => $post_id];
        $this->load_template('editor-page', $template_vars);
        exit;
    }

    public function handle_editor_page()
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'wordexpress-editor') return;
        wp_dequeue_script('autosave');
        add_filter('admin_body_class', function($c) { return $c . ' wordexpress-fullscreen'; });
    }

    public function hide_screen_options($show, $screen)
    {
        return (isset($_GET['page']) && $_GET['page'] === 'wordexpress-editor') ? false : $show;
    }
}
