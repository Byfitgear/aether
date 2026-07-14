<?php
if (!defined('ABSPATH')) { exit; }

class Aether_Vite_Loader extends Aether_Base
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
        $version_dir = 'dist/' . AETHER_VERSION . '/';
        $manifest_path = AETHER_PATH . $version_dir . 'manifest.json';
        $base_url = AETHER_URL . $version_dir;
        $base_path = AETHER_PATH . $version_dir;

        if (!file_exists($manifest_path)) {
            $manifest_path = AETHER_PATH . 'dist/manifest.json';
            $base_url = AETHER_URL . 'dist/';
            $base_path = AETHER_PATH . 'dist/';
        }
        if (!file_exists($manifest_path)) return;

        $manifest = json_decode(file_get_contents($manifest_path), true);
        $entry_key = 'src/' . $entry . '/index.tsx';
        if (!isset($manifest[$entry_key])) return;

        $asset = $manifest[$entry_key];
        if (isset($asset['css'])) {
            foreach ($asset['css'] as $css) {
                wp_enqueue_style('aether-' . $entry . '-css', $base_url . $css, [], file_exists($base_path . $css) ? filemtime($base_path . $css) : AETHER_VERSION);
            }
        }
        wp_enqueue_script('aether-' . $entry, $base_url . $asset['file'], $deps, file_exists($base_path . $asset['file']) ? filemtime($base_path . $asset['file']) : AETHER_VERSION, true);
        add_filter('script_loader_tag', function($tag, $handle) use ($entry) {
            if ($handle === 'aether-' . $entry) return str_replace('<script', '<script type="module"', $tag);
            return $tag;
        }, 10, 2);
    }
}
