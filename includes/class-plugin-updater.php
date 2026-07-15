<?php

/**
 * aether Plugin Updater
 * 
 * Handles automatic plugin updates from custom server
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Plugin_Updater {
    
    private $plugin_slug;
    private $version;
    private $author;
    private $update_path;
    private $plugin_name;
    private $plugin_file;
    
    public function __construct() {
        $this->plugin_slug = 'aether';
        $this->version = AETHER_VERSION;
        $this->author = 'Yansir';
        $this->update_path = 'https://www.aether.app/api/wp-updates/plugins/check';
        $this->plugin_name = 'aether';
        $this->plugin_file = AETHER_BASENAME;
        
        // 添加钩子
        // 仅在 WP 执行更新检查时触发，避免后台常规页面频繁远程请求
        add_filter('pre_set_site_transient_update_plugins', array($this, 'filter_update_transient'));
        add_filter('plugins_api', array($this, 'plugin_api_call'), 15, 3); // 优先级设为15，让Plugin_Info先处理

        // 当 WP 清除更新缓存时（如用户点击"再次检查"），同步清除 aether 缓存
        add_action('delete_site_transient_update_plugins', array($this, 'clear_update_cache'));
    }
    
    /**
     * 在 WP 将要设置 update_plugins transient 时注入我们的更新信息
     */
    public function filter_update_transient($transient) {
        // 可完全禁用更新检查
        if (defined('AETHER_DISABLE_UPDATE_CHECKS') && AETHER_DISABLE_UPDATE_CHECKS) {
            return $transient;
        }

        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        // 读取缓存（成功缓存默认12小时，开发模式5分钟；失败退避1小时）
        $cached = get_transient('aether_update_data');
        $backoff = get_transient('aether_update_backoff');

        if ($backoff) {
            // 处于失败退避期，避免频繁请求
            return $transient;
        }

        if ($cached !== false && is_array($cached)) {
            // 仅当远端版本大于本地版本时，才提示更新（兜底保护服务端误报）
            if (!empty($cached['update_available']) && $this->should_update($cached)) {
                $transient->response[$this->plugin_file] = (object) array(
                    'slug' => $this->plugin_slug,
                    'plugin' => $this->plugin_file,
                    'new_version' => $this->normalize_version($cached['latest_version'] ?? $this->version),
                    'url' => $cached['update_info']['url'] ?? '',
                    'package' => $cached['update_info']['package'] ?? '',
                    'requires' => $cached['update_info']['requires'] ?? '',
                    'tested' => $cached['update_info']['tested'] ?? '',
                    'requires_php' => $cached['update_info']['requires_php'] ?? '',
                    'id' => $this->plugin_slug . '/aether.php',
                );
            }
            return $transient;
        }

        // 无缓存时进行远程检查
        $remote_version = $this->get_remote_version();
        if ($remote_version === false) {
            // 设置失败退避1小时
            set_transient('aether_update_backoff', 1, HOUR_IN_SECONDS);
            return $transient;
        }

        // 设置缓存
        $ttl = $this->get_success_cache_ttl();
        set_transient('aether_update_data', $remote_version, $ttl);

        if (!empty($remote_version['update_available']) && $this->should_update($remote_version)) {
            $transient->response[$this->plugin_file] = (object) array(
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_file,
                'new_version' => $this->normalize_version($remote_version['latest_version'] ?? $this->version),
                'url' => $remote_version['update_info']['url'] ?? '',
                'package' => $remote_version['update_info']['package'] ?? '',
                'requires' => $remote_version['update_info']['requires'] ?? '',
                'tested' => $remote_version['update_info']['tested'] ?? '',
                'requires_php' => $remote_version['update_info']['requires_php'] ?? '',
                'id' => $this->plugin_slug . '/aether.php',
            );
        }

        return $transient;
    }
    
    /**
     * 获取远程版本信息
     */
    private function get_remote_version() {
        $url = $this->update_path . '?plugin=' . urlencode($this->plugin_slug) . '&version=' . urlencode($this->version);

        // 在开发环境下添加 dev 参数
        if ($this->is_dev()) {
            $url .= '&dev=1';
        }

        $args = array(
            'timeout' => 3,
            'redirection' => 2,
            'headers' => array(
                'User-Agent' => 'Aether/' . $this->version . ' (' . home_url() . ') WP/' . get_bloginfo('version'),
            ),
        );

        $request = wp_remote_get($url, $args);

        if (is_wp_error($request)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($request);

        if ($response_code === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);

            if (isset($data['update_available'])) {
                return $data;
            }
        }

        return false;
    }
    
    /**
     * 插件 API 调用
     */
    public function plugin_api_call($res, $action, $args) {
        if ($action !== 'plugin_information') {
            return $res;
        }
        
        // 确保 $args 是对象并且有 slug 属性
        if (!is_object($args) || !isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $res;
        }
        
        // 获取插件信息（带缓存与退避）
        $remote_info = $this->get_remote_info();

        if (!$remote_info) {
            return $res;
        }
        
        $res = new stdClass();
        $res->name = isset($remote_info['name']) ? $remote_info['name'] : $this->plugin_name;
        $res->slug = $this->plugin_slug;
        $res->version = isset($remote_info['version']) ? $remote_info['version'] : $this->version;
        $res->author = isset($remote_info['author']) ? $remote_info['author'] : $this->author;
        $res->author_profile = isset($remote_info['author_homepage']) ? $remote_info['author_homepage'] : '';
        $res->contributors = array(
            $this->author => array(
                'display_name' => isset($remote_info['author']) ? $remote_info['author'] : $this->author,
                'profile' => isset($remote_info['author_homepage']) ? $remote_info['author_homepage'] : ''
            )
        );
        $res->homepage = isset($remote_info['plugin_uri']) ? $remote_info['plugin_uri'] : '';
        $res->description = isset($remote_info['description']) ? $remote_info['description'] : '';
        $res->short_description = isset($remote_info['description']) ? $remote_info['description'] : '';
        $res->sections = array(
            'description' => isset($remote_info['description']) ? $remote_info['description'] : '',
            'changelog' => $this->get_changelog(),
        );
        $res->download_link = isset($remote_info['download_url']) ? $remote_info['download_url'] : '';
        $res->last_updated = isset($remote_info['last_updated']) ? $remote_info['last_updated'] : date('Y-m-d H:i:s');
        $res->requires = isset($remote_info['requires']) ? $remote_info['requires'] : '5.0';
        $res->tested = isset($remote_info['tested']) ? $remote_info['tested'] : '6.7';
        $res->requires_php = isset($remote_info['requires_php']) ? $remote_info['requires_php'] : '7.4';
        $res->active_installs = 0;
        $res->downloaded = 0;
        $res->rating = 0;
        $res->num_ratings = 0;
        $res->support_threads = 0;
        $res->support_threads_resolved = 0;
        
        return $res;
    }
    
    /**
     * 获取远程插件信息
     */
    private function get_remote_info() {
        $cache_key = 'aether_plugin_info';
        $backoff_key = 'aether_info_backoff';
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        if (get_transient($backoff_key)) {
            return false;
        }

        $url = 'https://www.aether.app/api/wp-updates/plugins/info/' . rawurlencode($this->plugin_slug);
        $args = array(
            'timeout' => 3,
            'headers' => array(
                'User-Agent' => 'Aether/' . $this->version . ' (' . home_url() . ') WP/' . get_bloginfo('version'),
            ),
        );
        $request = wp_remote_get($url, $args);
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            if (is_array($data)) {
                set_transient($cache_key, $data, DAY_IN_SECONDS);
                return $data;
            }
        }
        set_transient($backoff_key, 1, HOUR_IN_SECONDS);
        return false;
    }
    
    /**
     * 获取更新日志
     */
    private function get_changelog() {
        $cache_key = 'aether_plugin_changelog_html';
        $backoff_key = 'aether_changelog_backoff';
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        if (get_transient($backoff_key)) {
            return '暂无更新日志';
        }

        $url = 'https://www.aether.app/api/wp-updates/plugins/changelog/' . rawurlencode($this->plugin_slug);
        $args = array(
            'timeout' => 3,
            'headers' => array(
                'User-Agent' => 'Aether/' . $this->version . ' (' . home_url() . ') WP/' . get_bloginfo('version'),
            ),
        );
        $request = wp_remote_get($url, $args);
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) === 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            if (isset($data['changelog']) && is_array($data['changelog'])) {
                $changelog = '';
                foreach ($data['changelog'] as $entry) {
                    $changelog .= '<h4>版本 ' . esc_html($entry['version']) . ' - ' . esc_html(date('Y-m-d', strtotime($entry['date']))) . '</h4>';
                    $changelog .= '<ul>';
                    foreach ($entry['changes'] as $change) {
                        $changelog .= '<li>' . esc_html($change) . '</li>';
                    }
                    $changelog .= '</ul>';
                }
                set_transient($cache_key, $changelog, DAY_IN_SECONDS);
                return $changelog;
            }
        }
        set_transient($backoff_key, 1, HOUR_IN_SECONDS);
        return '暂无更新日志';
    }

    private function is_dev() {
        return (defined('WP_DEBUG') && WP_DEBUG && defined('AETHER_DEV_MODE') && AETHER_DEV_MODE);
    }

    private function get_success_cache_ttl() {
        // 成功缓存：生产12小时，开发5分钟
        return $this->is_dev() ? 5 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS;
    }

    /**
     * 比较远端版本是否大于本地版本
     */
    private function should_update($remote) {
        if (!is_array($remote)) {
            return false;
        }
        $rv = isset($remote['latest_version']) ? $this->normalize_version($remote['latest_version']) : '';
        if ($rv === '') {
            return false;
        }
        // 忽略服务端误报：只有当远端版本号严格大于本地时才提示
        return version_compare($rv, $this->version, '>');
    }

    /**
     * 规范化版本字符串（去空格、去掉前缀v/V）
     */
    private function normalize_version($v) {
        if (!is_string($v)) {
            return '';
        }
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        if ($v[0] === 'v' || $v[0] === 'V') {
            $v = substr($v, 1);
        }
        return $v;
    }

    /**
     * 清除 aether 更新缓存，使下次检查时重新请求远端
     */
    public function clear_update_cache() {
        delete_transient('aether_update_data');
        delete_transient('aether_update_backoff');
    }
}

// 初始化插件更新器
new Aether_Plugin_Updater();
