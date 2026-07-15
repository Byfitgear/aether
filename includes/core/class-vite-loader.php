<?php
/**
 * Vite 资源加载器
 * 
 * @package Aether
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 处理 Vite 构建的资源加载
 */
class Aether_Vite_Loader extends Aether_Base
{

    /**
     * 实例
     */
    private static $instance = null;

    /**
     * 获取实例
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 初始化
     */
    protected function init()
    {
        // 不需要自动注册钩子
    }

    /**
     * 加载 Vite 资源
     * 
     * @param string $entry 入口名称
     * @param array $deps 依赖
     */
    public function enqueue($entry, $deps = [])
    {
        // 始终使用生产模式
        $this->enqueue_prod($entry, $deps);
    }

    /**
     * 生产模式加载
     */
    private function enqueue_prod($entry, $deps)
    {
        // Prefer versioned dist directory: dist/{AETHER_VERSION}/manifest.json
        $version_dir   = 'dist/' . AETHER_VERSION . '/';
        $manifest_path = AETHER_PATH . $version_dir . 'manifest.json';
        $base_url      = AETHER_URL . $version_dir;
        $base_path     = AETHER_PATH . $version_dir;

        // Fallback to legacy dist/manifest.json if versioned manifest not found
        if (!file_exists($manifest_path)) {
            $manifest_path = AETHER_PATH . 'dist/manifest.json';
            $base_url      = AETHER_URL . 'dist/';
            $base_path     = AETHER_PATH . 'dist/';
        }

        if (!file_exists($manifest_path)) {
            return;
        }

        $manifest = json_decode(file_get_contents($manifest_path), true);
        $entry_key = 'src/' . $entry . '/index.tsx';

        if (!isset($manifest[$entry_key])) {
            return;
        }

        $asset = $manifest[$entry_key];

        // 加载 CSS
        if (isset($asset['css'])) {
            foreach ($asset['css'] as $css) {
                $style_handle = 'aether-' . $entry . '-css';
                wp_enqueue_style(
                    $style_handle,
                    $base_url . $css,
                    [],
                    file_exists($base_path . $css) ? filemtime($base_path . $css) : AETHER_VERSION
                );
            }
        }

        // 加载 JS
        $script_handle = 'aether-' . $entry;
        wp_enqueue_script(
            $script_handle,
            $base_url . $asset['file'],
            $deps,
            file_exists($base_path . $asset['file']) ? filemtime($base_path . $asset['file']) : AETHER_VERSION,
            true
        );

        // 传递配置到前端（编辑器需要图片尺寸配置）
        if ($entry === 'editor') {
            wp_localize_script($script_handle, 'aetherConfig', [
                'imageSizes' => Aether_Image_Sizes::get_sizes(),
            ]);
        }

        // 传递版本号到设置页面
        if ($entry === 'settings') {
            wp_localize_script($script_handle, 'aetherConfig', [
                'version' => AETHER_VERSION,
            ]);
        }

        // 设置为模块类型
        add_filter('script_loader_tag', function ($tag, $handle) use ($entry) {
            if ($handle === 'aether-' . $entry) {
                return is_string($tag) ? str_replace('<script', '<script type="module"', $tag) : $tag;
            }
            return $tag;
        }, 10, 2);
    }
}
