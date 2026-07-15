<?php
/**
 * 插件详情信息处理
 *
 * @package aether
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Aether_Plugin_Info
{

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 获取单例实例
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct()
    {
        // 添加插件行动链接
        add_filter('plugin_action_links_' . AETHER_BASENAME, [$this, 'add_action_links']);

        // 添加插件元信息链接
        add_filter('plugin_row_meta', [$this, 'add_row_meta'], 10, 2);

        // 注册 AJAX 处理
        add_action('wp_ajax_aether_plugin_information', [$this, 'ajax_plugin_information']);

        // 拦截插件信息请求 - 优先级改为10，先于Plugin_Updater处理
        add_filter('plugins_api', [$this, 'plugin_information'], 10, 3);
    }

    /**
     * 添加插件行动链接
     */
    public function add_action_links($links)
    {
        $action_links = [
            '<a href="' . admin_url('admin.php?page=aether') . '">' . __('设置', 'aether') . '</a>',
        ];

        return array_merge($action_links, $links);
    }

    /**
     * 添加插件元信息链接
     */
    public function add_row_meta($links, $file)
    {
        if (AETHER_BASENAME !== $file) {
            return $links;
        }

        $row_meta = [
            'docs' => '<a href="https://www.aether.app/docs" target="_blank">' . __('文档', 'aether') . '</a>',
            'changelog' => '<a href="#" class="aether-view-details" data-plugin="aether">' . __('查看详情', 'aether') . '</a>',
        ];

        // 添加内联脚本
        if (!wp_script_is('aether-plugin-info', 'enqueued')) {
            add_action('admin_footer', [$this, 'enqueue_scripts']);
        }

        return array_merge($links, $row_meta);
    }

    /**
     * 加载脚本
     */
    public function enqueue_scripts()
    {
        ?>
        <style>
            #plugin-information-scrollable {
                max-height: 500px;
            }

            .aether-plugin-info-section {
                margin: 20px 0;
            }

            .aether-plugin-info-section h3 {
                margin-bottom: 10px;
                font-size: 16px;
            }

            .aether-changelog-entry {
                margin-bottom: 20px;
                padding-bottom: 20px;
                border-bottom: 1px solid #ddd;
            }

            .aether-changelog-entry:last-child {
                border-bottom: none;
            }

            .aether-changelog-version {
                font-weight: bold;
                font-size: 14px;
                color: #333;
            }

            .aether-changelog-date {
                color: #666;
                font-size: 12px;
                margin-left: 10px;
            }

            .aether-changelog-changes {
                margin-top: 10px;
                margin-left: 20px;
            }

            .aether-changelog-changes li {
                list-style: disc;
                margin-bottom: 5px;
            }
        </style>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                $(document).on('click', '.aether-view-details', function (e) {
                    e.preventDefault();

                    // 使用 WordPress 内置的插件信息弹窗
                    tb_show('aether', '#TB_inline?inlineId=aether-plugin-info&width=772&height=800');

                    // 加载插件信息
                    $.post(ajaxurl, {
                        action: 'aether_plugin_information',
                        _ajax_nonce: '<?php echo wp_create_nonce('aether_plugin_info'); ?>'
                    }, function (response) {
                        if (response.success) {
                            $('#TB_ajaxContent').html(response.data);
                        }
                    });
                });
            });
        </script>
        <div id="aether-plugin-info" style="display:none;"></div>
        <?php
        wp_enqueue_script('jquery');
        add_thickbox();
    }

    /**
     * AJAX 处理插件信息请求
     */
    public function ajax_plugin_information()
    {
        check_ajax_referer('aether_plugin_info');

        $info = $this->get_plugin_information();

        ob_start();
        ?>
        <div class="aether-plugin-info-content">
            <div class="aether-plugin-info-section">
                <h3><?php _e('描述', 'aether'); ?></h3>
                <div><?php echo wp_kses_post($info['description']); ?></div>
            </div>

            <div class="aether-plugin-info-section">
                <h3><?php _e('功能特点', 'aether'); ?></h3>
                <ul>
                    <?php foreach ($info['features'] as $feature): ?>
                        <li><?php echo esc_html($feature); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="aether-plugin-info-section">
                <h3><?php _e('常见问题', 'aether'); ?></h3>
                <?php foreach ($info['faq'] as $faq): ?>
                    <div style="margin-bottom: 15px;">
                        <strong>问：<?php echo esc_html($faq['question']); ?></strong><br>
                        答：<?php echo esc_html($faq['answer']); ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="aether-plugin-info-section">
                <h3><?php _e('修订历史', 'aether'); ?></h3>
                <div id="aether-changelog">
                    <?php echo $this->render_changelog($info['changelog']); ?>
                </div>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    /**
     * 获取插件信息
     */
    private function get_plugin_information()
    {
        // 基本信息
        $info = [
            'name' => 'aether',
            'version' => AETHER_VERSION,
            'author' => 'Yansir',
            'description' => 'aether 是一个极简的 WordPress 可视化编辑器，支持纯 HTML 编辑。它结合了传统 PHP/WordPress 架构与现代 React/TypeScript 前端技术，提供了强大的模板系统和 AI 辅助编辑功能。',
            'features' => [
                '纯 HTML 编辑模式，完全控制输出',
                '支持直接编写 PHP 代码的动态模板系统',
                'AI 驱动的智能编辑辅助',
                '实时预览与元素选择',
                '智能 CSS 编译优化',
                '支持自定义字体管理',
                '响应式设计系统',
                '代码格式化与语法高亮'
            ],
            'faq' => [
                [
                    'question' => '如何启用开发模式？',
                    'answer' => '插件会自动检测 Vite 开发服务器（localhost:5173）。也可以在 wp-config.php 中添加 define(\'AETHER_DEV_MODE\', true);'
                ],
                [
                    'question' => '如何使用动态模板？',
                    'answer' => '在 aether 管理页面中选择"模板"标签，可以为不同页面类型创建自定义模板。'
                ],
                [
                    'question' => '生产模式和开发模式有什么区别？',
                    'answer' => '生产模式使用编译后的优化 CSS，加载更快。开发模式使用 Tailwind CDN，方便调试。'
                ]
            ],
            'changelog' => []
        ];

        // 尝试从 API 获取 changelog
        $changelog = $this->fetch_changelog();
        if ($changelog) {
            $info['changelog'] = $changelog;
        } else {
            // 使用本地 changelog
            $info['changelog'] = $this->get_local_changelog();
        }

        return $info;
    }

    /**
     * 从 API 获取 changelog
     */
    private function fetch_changelog()
    {
        $transient_key = 'aether_changelog';
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            return $cached;
        }

        // 检查是否在开发模式
        $vite_running = false;
        $vite_response = wp_remote_get('http://localhost:5173/@vite/client', ['timeout' => 1]);
        if (!is_wp_error($vite_response) && wp_remote_retrieve_response_code($vite_response) === 200) {
            $vite_running = true;
        }

        $is_dev = (defined('AETHER_DEV_MODE') && AETHER_DEV_MODE) || $vite_running;
        $url = $is_dev ? 'http://localhost:5173/api/wp-updates/plugins/changelog/aether' : 'https://api.aether.com/wp-updates/plugins/changelog/aether';

        $response = wp_remote_get($url, [
            'timeout' => 5,
            'sslverify' => !$is_dev
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['changelog'])) {
            set_transient($transient_key, $data['changelog'], HOUR_IN_SECONDS);
            return $data['changelog'];
        }

        return false;
    }

    /**
     * 获取本地 changelog
     */
    private function get_local_changelog()
    {
        return [
            [
                'version' => '1.0.10',
                'date' => '2025-07-28',
                'changes' => [
                    '修复生产模式下单页 CSS 无法加载的问题',
                    '优化 CSS 注入系统架构',
                    '改进模板检测逻辑'
                ]
            ],
            [
                'version' => '1.0.9',
                'date' => '2025-07-27',
                'changes' => [
                    '实现统一的 CSS 注入系统',
                    '添加设计系统自动提取功能',
                    '修复日期标签无法单独存在的问题',
                    '改进 token 验证和 CORS 处理'
                ]
            ],
            [
                'version' => '1.0.8',
                'date' => '2025-07-26',
                'changes' => [
                    '迁移到 Vite + React + TypeScript',
                    '引入 Zustand 状态管理',
                    '添加 Tailwind CSS v4 支持',
                    '重构编辑器组件'
                ]
            ]
        ];
    }

    /**
     * 渲染 changelog
     */
    private function render_changelog($changelog)
    {
        $html = '';

        foreach ($changelog as $entry) {
            $date = isset($entry['date']) ? $entry['date'] : '';
            $html .= '<div class="aether-changelog-entry">';
            $html .= '<div class="aether-changelog-header">';
            $html .= '<span class="aether-changelog-version">版本 ' . esc_html($entry['version']) . '</span>';
            if ($date) {
                $html .= '<span class="aether-changelog-date">' . esc_html($date) . '</span>';
            }
            $html .= '</div>';

            if (!empty($entry['changes'])) {
                $html .= '<ul class="aether-changelog-changes">';
                foreach ($entry['changes'] as $change) {
                    $html .= '<li>' . esc_html($change) . '</li>';
                }
                $html .= '</ul>';
            }

            $html .= '</div>';
        }

        return $html;
    }

    /**
     * 为插件更新提供信息
     */
    public function plugin_information($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        // 确保 $args 是对象并且有 slug 属性
        if (!is_object($args) || !isset($args->slug) || $args->slug !== 'aether') {
            return $result;
        }

        // 如果已经有结果了，只补充缺失的部分
        if ($result && is_object($result) && $result->slug === 'aether') {
            $info = $this->get_plugin_information();
            
            // 补充本地信息
            if (!isset($result->sections['faq'])) {
                $result->sections['faq'] = $this->render_faq($info['faq']);
            }
            if (!isset($result->sections['features'])) {
                $result->sections['features'] = $this->render_features($info['features']);
            }
            
            return $result;
        }

        // 如果没有结果，创建一个基本的
        $info = $this->get_plugin_information();

        $obj = new stdClass();
        $obj->name = 'aether';
        $obj->slug = 'aether';
        $obj->version = AETHER_VERSION;
        $obj->author = '<a href="https://github.com/yansircc">Yansir</a>';
        $obj->homepage = 'https://github.com/yansircc/aether';
        $obj->sections = [
            'description' => $info['description'],
            'changelog' => $this->render_changelog($info['changelog']),
            'faq' => $this->render_faq($info['faq']),
            'features' => $this->render_features($info['features'])
        ];

        return $obj;
    }

    /**
     * 渲染 FAQ
     */
    private function render_faq($faq)
    {
        $html = '';
        foreach ($faq as $item) {
            $html .= '<p><strong>' . esc_html($item['question']) . '</strong><br>';
            $html .= esc_html($item['answer']) . '</p>';
        }
        return $html;
    }

    /**
     * 渲染功能特点
     */
    private function render_features($features)
    {
        $html = '<ul>';
        foreach ($features as $feature) {
            $html .= '<li>' . esc_html($feature) . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
}

// 初始化
Aether_Plugin_Info::get_instance();