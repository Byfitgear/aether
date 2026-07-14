<?php
defined('ABSPATH') || exit;

class WordExpress_Assets extends WordExpress_Base
{
    protected function init() {}

    public function enqueue_editor_assets()
    {
        $manifest_path = WORDEXPRESS_PATH . 'dist/1.0.0/manifest.json';
        if (!file_exists($manifest_path)) {
            $manifest_path = WORDEXPRESS_PATH . 'dist/manifest.json';
        }
        if (!file_exists($manifest_path)) return;

        $manifest = json_decode(file_get_contents($manifest_path), true);
        $entry_key = 'src/editor/index.tsx';
        if (!isset($manifest[$entry_key])) return;

        $asset = $manifest[$entry_key];
        $dir = dirname($manifest_path) . '/';
        $url = dirname($manifest_path) . '/';

        if (isset($asset['css'])) {
            foreach ($asset['css'] as $css) {
                wp_enqueue_style('wordexpress-editor-css', $url . $css, [], file_exists($dir . $css) ? filemtime($dir . $css) : WORDEXPRESS_VERSION);
            }
        }
        wp_enqueue_script('wordexpress-editor', $url . $asset['file'], [], file_exists($dir . $asset['file']) ? filemtime($dir . $asset['file']) : WORDEXPRESS_VERSION, true);
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'wordexpress-editor') return str_replace('<script', '<script type="module"', $tag);
            return $tag;
        }, 10, 2);
        wp_enqueue_media();
    }

    public function enqueue_admin_assets()
    {
        // Admin page assets placeholder
    }
}
