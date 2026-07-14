<?php
defined('ABSPATH') || exit;

class Aether_Assets extends Aether_Base
{
    protected function init() {}

    public function enqueue_editor_assets()
    {
        $manifest_path = AETHER_PATH . 'dist/1.0.0/manifest.json';
        if (!file_exists($manifest_path)) {
            $manifest_path = AETHER_PATH . 'dist/manifest.json';
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
                wp_enqueue_style('aether-editor-css', $url . $css, [], file_exists($dir . $css) ? filemtime($dir . $css) : AETHER_VERSION);
            }
        }
        wp_enqueue_script('aether-editor', $url . $asset['file'], [], file_exists($dir . $asset['file']) ? filemtime($dir . $asset['file']) : AETHER_VERSION, true);
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'aether-editor') return str_replace('<script', '<script type="module"', $tag);
            return $tag;
        }, 10, 2);
        wp_enqueue_media();
    }

    public function enqueue_admin_assets()
    {
        // Admin page assets placeholder
    }
}
