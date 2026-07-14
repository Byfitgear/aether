<?php
if (!defined('ABSPATH')) { exit; }

class WordExpress_Vite_Loader extends WordExpress_Base
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function init() {}

    public function enqueue($entry, $deps = [])
    {
        $version_dir = 'dist/' . WORDEXPRESS_VERSION . '/';
        $manifest_path = WORDEXPRESS_PATH . $version_dir . 'manifest.json';
        $base_url = WORDEXPRESS_URL . $version_dir;
        $base_path = WORDEXPRESS_PATH . $version_dir;

        if (!file_exists($manifest_path)) {
            $manifest_path = WORDEXPRESS_PATH . 'dist/manifest.json';
            $base_url = WORDEXPRESS_URL . 'dist/';
            $base_path = WORDEXPRESS_PATH . 'dist/';
        }
        if (!file_exists($manifest_path)) return;

        $manifest = json_decode(file_get_contents($manifest_path), true);
        $entry_key = 'src/' . $entry . '/index.tsx';
        if (!isset($manifest[$entry_key])) return;

        $asset = $manifest[$entry_key];
        if (isset($asset['css'])) {
            foreach ($asset['css'] as $css) {
                wp_enqueue_style('wordexpress-' . $entry . '-css', $base_url . $css, [], file_exists($base_path . $css) ? filemtime($base_path . $css) : WORDEXPRESS_VERSION);
            }
        }
        wp_enqueue_script('wordexpress-' . $entry, $base_url . $asset['file'], $deps, file_exists($base_path . $asset['file']) ? filemtime($base_path . $asset['file']) : WORDEXPRESS_VERSION, true);
        add_filter('script_loader_tag', function($tag, $handle) use ($entry) {
            if ($handle === 'wordexpress-' . $entry) return str_replace('<script', '<script type="module"', $tag);
            return $tag;
        }, 10, 2);
    }
}
