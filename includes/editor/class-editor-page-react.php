<?php
defined('ABSPATH') || exit;

class Aether_Editor_Page_React extends Aether_Editor_Page
{
    public function enqueue_assets()
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
            if ($handle === 'aether-editor') {
                return str_replace('<script', '<script type="module"', $tag);
            }
            return $tag;
        }, 10, 2);

        wp_localize_script('aether-editor', 'aetherConfig', [
            'imageSizes' => $this->get_image_sizes(),
            'version' => AETHER_VERSION,
        ]);
    }

    private function get_image_sizes()
    {
        $sizes = [];
        foreach (get_intermediate_image_sizes() as $size) {
            if (in_array($size, ['thumbnail', 'medium', 'large', 'full'])) {
                $w = get_option("{$size}_size_w");
                $h = get_option("{$size}_size_h");
                $sizes[$size] = ['width' => $w ?: 0, 'height' => $h ?: 0];
            }
        }
        return $sizes;
    }
}
