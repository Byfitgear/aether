<?php
/**
 * HTML 优化存储服务
 *
 * 负责管理优化后 HTML 的保存、检索和过期判断
 * 参考 CSS 编译存储方案 (class-css-storage-service.php)
 *
 * @package aether
 * @subpackage Services
 * @since 1.1.29
 */

defined('ABSPATH') || exit;

class Aether_HTML_Optimization_Service
{
    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * Meta 字段名
     */
    const META_OPTIMIZED_HTML = '_aether_optimized_html';
    const META_HTML_ENCODED = '_aether_html_encoded';
    const META_HTML_HASH = '_aether_html_hash';
    const META_HTML_OPTIMIZED_AT = '_aether_html_optimized_at';
    const META_HTML_IMAGE_COUNT = '_aether_html_image_count';
    const META_HTML_OPTIMIZED_SIZE = '_aether_html_optimized_size';
    const META_HTML_ORIGINAL_SIZE = '_aether_html_original_size';
    const META_HTML_OPTIMIZATION_ERROR = '_aether_html_optimization_error';
    const META_IMAGE_DEPENDENCY = '_aether_page_image_dependency';

    /**
     * Option 名称
     */
    const OPTION_ENABLED = 'aether_image_optimization_enabled';

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 防止递归处理
     */
    private $processing = false;

    /**
     * 私有Constructor
     */
    private function __construct()
    {
        // Aether 编辑器保存内容时生成优化 HTML（仅 Page）
        add_action('aether_content_saved', [$this, 'on_content_saved'], 20, 1);

        // 任何方式保存 Page 时清除优化 HTML（确保不会输出陈旧内容）
        // 如果是通过 aether 编辑器保存，会先清除再重新生成
        add_action('save_post_page', [$this, 'on_page_saved'], 5, 1);
    }

    /**
     * Page 保存时清除优化 HTML
     *
     * 确保不管是通过什么方式保存的，都不会输出陈旧的优化内容
     *
     * @param int $post_id Page ID
     */
    public function on_page_saved($post_id)
    {
        // 跳过自动保存和修订版
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page') {
            return;
        }

        // 清除优化 HTML
        $this->clear_optimized_html($post_id);
        $this->sync_page_image_dependencies($post_id, $post->post_content ?? '');
        $this->log(sprintf('Page 保存，清除优化 HTML - Page ID: %d', $post_id));
    }

    /**
     * 内容保存时的处理
     *
     * @param array $data 保存数据（需包含 post_id）
     */
    public function on_content_saved($data)
    {
        $post_id = isset($data['post_id']) ? intval($data['post_id']) : 0;
        if ($post_id <= 0) {
            return;
        }

        $this->refresh_page_optimized_html($post_id, false);
    }

    /**
     * 强制或按需刷新页面优化 HTML
     *
     * @param int $post_id 页面 ID
     * @param bool $force 是否忽略内容 hash 强制刷新
     * @return array 刷新结果
     */
    public function refresh_page_optimized_html($post_id, $force = true)
    {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return [
                'success' => false,
                'reason' => 'invalid_post_id',
            ];
        }

        if ($this->processing) {
            return [
                'success' => false,
                'reason' => 'already_processing',
            ];
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page') {
            return [
                'success' => false,
                'reason' => 'unsupported_post_type',
            ];
        }

        $content = $post->post_content ?? '';
        $this->sync_page_image_dependencies($post_id, $content);

        if (!$this->is_optimization_enabled()) {
            $this->clear_optimized_html($post_id);
            $this->log(sprintf('开关关闭，清除优化 HTML - Page ID: %d', $post_id));

            return [
                'success' => true,
                'refreshed' => false,
                'reason' => 'optimization_disabled',
            ];
        }

        if (!$force && !$this->is_html_outdated($post_id, $content)) {
            $this->log(sprintf('内容未变化，跳过生成 - Page ID: %d', $post_id));

            return [
                'success' => true,
                'refreshed' => false,
                'reason' => 'content_not_changed',
            ];
        }

        if ($content === '' || strlen($content) < 10) {
            $this->clear_optimized_html($post_id);

            return [
                'success' => true,
                'refreshed' => false,
                'reason' => 'empty_content',
            ];
        }

        $this->log(sprintf('开始生成优化 HTML - Page ID: %d', $post_id));

        try {
            $this->processing = true;
            $result = $this->generate_optimized_html($content);

            if ($result['image_count'] === 0) {
                $this->clear_optimized_html($post_id);
                $this->log(sprintf('没有图片需要优化 - Page ID: %d', $post_id));
            } else {
                $this->save_optimized_html($post_id, $result['html'], [
                    'image_count' => $result['image_count'],
                    'original_size' => strlen($content),
                ]);
            }

            return [
                'success' => true,
                'refreshed' => true,
                'image_count' => $result['image_count'],
                'status' => $this->get_page_image_optimization_status($post_id),
            ];
        } catch (\Exception $e) {
            $this->record_optimization_error($post_id, $e->getMessage());
            $this->log(sprintf('生成优化 HTML 失败 - Page ID: %d, 错误: %s', $post_id, $e->getMessage()));

            return [
                'success' => false,
                'reason' => 'exception',
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->processing = false;
        }
    }

    /**
     * 获取页面图片优化状态
     *
     * @param int $post_id 页面 ID
     * @return array 状态信息
     */
    public function get_page_image_optimization_status($post_id)
    {
        $post_id = intval($post_id);
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'page') {
            return [
                'success' => false,
                'reason' => 'unsupported_post_type',
                'enabled' => false,
                'total_images' => 0,
                'ready_images' => 0,
                'pending_images' => 0,
                'all_ready' => true,
                'pending_attachment_ids' => [],
            ];
        }

        $items = $this->extract_optimizable_image_items($post->post_content ?? '');
        $ready_images = 0;
        $pending_attachment_ids = [];

        foreach ($items as $item) {
            if (!empty($item['webp_ready'])) {
                $ready_images++;
            } else {
                $pending_attachment_ids[] = intval($item['attachment_id']);
            }
        }

        $total_images = count($items);
        $pending_images = $total_images - $ready_images;

        return [
            'success' => true,
            'enabled' => $this->is_optimization_enabled(),
            'total_images' => $total_images,
            'ready_images' => $ready_images,
            'pending_images' => $pending_images,
            'all_ready' => $pending_images === 0,
            'pending_attachment_ids' => array_values(array_unique($pending_attachment_ids)),
        ];
    }

    /**
     * 刷新引用了指定附件的所有页面
     *
     * @param int $attachment_id 附件 ID
     * @return array 刷新结果
     */
    public function refresh_pages_for_attachment($attachment_id)
    {
        global $wpdb;

        $attachment_id = intval($attachment_id);
        if ($attachment_id <= 0) {
            return [
                'success' => false,
                'reason' => 'invalid_attachment_id',
                'page_ids' => [],
                'refreshed_count' => 0,
            ];
        }

        $page_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = %s
            AND meta_value = %d",
            self::META_IMAGE_DEPENDENCY,
            $attachment_id
        ));

        $page_ids = array_values(array_unique(array_map('intval', $page_ids ?: [])));
        $refreshed_count = 0;

        foreach ($page_ids as $page_id) {
            $result = $this->refresh_page_optimized_html($page_id, true);
            if (!empty($result['success'])) {
                $refreshed_count++;
            }
        }

        return [
            'success' => true,
            'page_ids' => $page_ids,
            'refreshed_count' => $refreshed_count,
        ];
    }

    /**
     * 检查图片优化是否启用
     *
     * @return bool
     */
    public function is_optimization_enabled()
    {
        // 使用 Transient 缓存 5 分钟
        $cached = get_transient('aether_image_optimization_enabled_cache');
        if ($cached !== false) {
            return $cached === '1';
        }

        $enabled = get_option(self::OPTION_ENABLED, false);
        // 确保返回布尔值
        $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);

        set_transient('aether_image_optimization_enabled_cache', $enabled ? '1' : '0', 300);

        return $enabled;
    }

    /**
     * 设置图片优化开关
     *
     * @param bool $enabled 是否启用
     * @return bool 是否成功
     */
    public function set_optimization_enabled($enabled)
    {
        $enabled = (bool) $enabled;
        $result = update_option(self::OPTION_ENABLED, $enabled);

        // 清除缓存
        delete_transient('aether_image_optimization_enabled_cache');

        return $result;
    }

    /**
     * 保存优化后的 HTML
     *
     * @param int $page_id 页面 ID
     * @param string $html 优化后的 HTML
     * @param array $stats 统计信息 [image_count, original_size]
     * @return bool
     */
    public function save_optimized_html($page_id, $html, $stats = [])
    {
        if (empty($html)) {
            return $this->clear_optimized_html($page_id);
        }

        $post = get_post($page_id);
        if (!$post) {
            return false;
        }

        // 使用 base64 编码保存，避免 WordPress 转义问题
        $encoded_html = base64_encode($html);

        // 计算原始内容的 hash（用于过期判断）
        $content_hash = $this->calculate_content_hash($post->post_content);

        update_post_meta($page_id, self::META_OPTIMIZED_HTML, $encoded_html);
        update_post_meta($page_id, self::META_HTML_ENCODED, '1');
        update_post_meta($page_id, self::META_HTML_HASH, $content_hash);
        update_post_meta($page_id, self::META_HTML_OPTIMIZED_AT, current_time('timestamp'));
        update_post_meta($page_id, self::META_HTML_OPTIMIZED_SIZE, strlen($html));
        update_post_meta($page_id, self::META_HTML_ORIGINAL_SIZE, $stats['original_size'] ?? strlen($post->post_content));
        update_post_meta($page_id, self::META_HTML_IMAGE_COUNT, $stats['image_count'] ?? 0);
        update_post_meta($page_id, '_aether_html_cache_version', AETHER_VERSION);

        // 清除错误信息
        delete_post_meta($page_id, self::META_HTML_OPTIMIZATION_ERROR);

        $this->log(sprintf('保存优化 HTML - Page ID: %d, 大小: %d bytes, 图片数: %d',
            $page_id, strlen($html), $stats['image_count'] ?? 0));

        return true;
    }

    /**
     * 获取优化后的 HTML
     *
     * @param int $page_id 页面 ID
     * @param bool $decode 是否解码
     * @return string|null
     */
    public function get_optimized_html($page_id, $decode = true)
    {
        $html = get_post_meta($page_id, self::META_OPTIMIZED_HTML, true);

        if (empty($html)) {
            return null;
        }

        $is_encoded = get_post_meta($page_id, self::META_HTML_ENCODED, true);

        if ($decode && $is_encoded === '1') {
            $html = base64_decode($html);
        }

        return $html;
    }

    /**
     * 判断优化版本是否过期
     *
     * @param int $page_id 页面 ID
     * @param string|null $current_content 当前内容（可选，不传则从数据库读取）
     * @return bool true 表示已过期需要重新生成
     */
    public function is_html_outdated($page_id, $current_content = null)
    {
        $saved_hash = get_post_meta($page_id, self::META_HTML_HASH, true);

        // 没有保存的 hash，视为过期
        if (empty($saved_hash)) {
            return true;
        }

        // 获取当前内容
        if ($current_content === null) {
            $post = get_post($page_id);
            if (!$post) {
                return true;
            }
            $current_content = $post->post_content;
        }

        $current_hash = $this->calculate_content_hash($current_content);

        return $saved_hash !== $current_hash;
    }

    /**
     * 清除优化后的 HTML
     *
     * @param int $page_id 页面 ID
     * @return bool
     */
    public function clear_optimized_html($page_id)
    {
        delete_post_meta($page_id, self::META_OPTIMIZED_HTML);
        delete_post_meta($page_id, self::META_HTML_ENCODED);
        delete_post_meta($page_id, self::META_HTML_HASH);
        delete_post_meta($page_id, self::META_HTML_OPTIMIZED_AT);
        delete_post_meta($page_id, self::META_HTML_IMAGE_COUNT);
        delete_post_meta($page_id, self::META_HTML_OPTIMIZED_SIZE);
        delete_post_meta($page_id, self::META_HTML_ORIGINAL_SIZE);
        delete_post_meta($page_id, self::META_HTML_OPTIMIZATION_ERROR);

        $this->log(sprintf('清除优化 HTML - Page ID: %d', $page_id));

        return true;
    }

    /**
     * 记录优化错误
     *
     * @param int $page_id 页面 ID
     * @param string $error 错误信息
     * @return void
     */
    public function record_optimization_error($page_id, $error)
    {
        update_post_meta($page_id, self::META_HTML_OPTIMIZATION_ERROR, $error);
        $this->log(sprintf('优化错误 - Page ID: %d, 错误: %s', $page_id, $error));
    }

    /**
     * 获取优化统计信息
     *
     * @param int $page_id 页面 ID
     * @return array
     */
    public function get_stats($page_id)
    {
        return [
            'optimized_size' => (int) get_post_meta($page_id, self::META_HTML_OPTIMIZED_SIZE, true),
            'original_size' => (int) get_post_meta($page_id, self::META_HTML_ORIGINAL_SIZE, true),
            'image_count' => (int) get_post_meta($page_id, self::META_HTML_IMAGE_COUNT, true),
            'optimized_at' => (int) get_post_meta($page_id, self::META_HTML_OPTIMIZED_AT, true),
            'hash' => get_post_meta($page_id, self::META_HTML_HASH, true),
            'error' => get_post_meta($page_id, self::META_HTML_OPTIMIZATION_ERROR, true),
        ];
    }

    /**
     * 生成优化后的 HTML
     *
     * 将 post_content 中的 <img> 标签转换为 <picture> 标签
     *
     * @param string $content 原始 HTML
     * @return array [html => 优化后的 HTML, image_count => 转换的图片数]
     */
    public function generate_optimized_html($content)
    {
        if (empty($content) || strlen($content) < 10) {
            return [
                'html' => $content,
                'image_count' => 0,
            ];
        }

        $pattern = '/<img\s+[^>]*>/i';
        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [
                'html' => $content,
                'image_count' => 0,
            ];
        }

        $picture_ranges = $this->get_picture_ranges($content);
        $result = '';
        $cursor = 0;
        $processed_count = 0;

        foreach ($matches[0] as $match) {
            $img_tag = $match[0];
            $position = $match[1];

            // 追加 img 之前的部分
            $result .= substr($content, $cursor, $position - $cursor);
            $cursor = $position + strlen($img_tag);

            // 已经在 <picture> 内的 img 不处理
            if ($this->is_inside_picture($position, $picture_ranges)) {
                $result .= $img_tag;
                continue;
            }

            // 跳过包含 PHP 代码的 img 标签
            if (preg_match('/<\?(?:php|=)/i', $img_tag)) {
                $result .= $img_tag;
                continue;
            }

            $img_info = $this->parse_img_tag($img_tag);
            if (!$img_info || !isset($img_info['src'])) {
                $result .= $img_tag;
                continue;
            }

            // 跳过外部图片
            if (!$this->is_local_image($img_info['src'])) {
                $result .= $img_tag;
                continue;
            }

            // 从 URL 获取 attachment ID
            $attachment_id = $this->get_attachment_id_from_url($img_info['src']);
            if (!$attachment_id) {
                $result .= $img_tag;
                continue;
            }

            $responsive_img = $this->ensure_img_tag_responsive($img_tag, $img_info, $attachment_id);
            $effective_img_tag = $responsive_img['img_tag'];
            $effective_img_info = $responsive_img['img_info'];
            $source_attrs = $this->build_webp_source_attributes($effective_img_info, $attachment_id);

            if ($source_attrs) {
                $picture_tag = $this->generate_picture_tag($effective_img_tag, $source_attrs);

                if ($picture_tag) {
                    $result .= $picture_tag;
                    $processed_count++;
                    continue;
                }
            }

            $result .= $effective_img_tag;
            if ($effective_img_tag !== $img_tag) {
                $processed_count++;
            }
        }

        // 追加最后一段
        $result .= substr($content, $cursor);

        $this->log(sprintf('生成优化 HTML - 转换 %d 个图片标签', $processed_count));

        return [
            'html' => $result,
            'image_count' => $processed_count,
        ];
    }

    /**
     * 同步页面引用的图片依赖
     *
     * @param int $page_id 页面 ID
     * @param string $content 页面内容
     * @return void
     */
    private function sync_page_image_dependencies($page_id, $content)
    {
        $page_id = intval($page_id);
        if ($page_id <= 0) {
            return;
        }

        delete_post_meta($page_id, self::META_IMAGE_DEPENDENCY);

        $items = $this->extract_optimizable_image_items($content);
        $attachment_ids = array_values(array_unique(array_map(
            'intval',
            array_column($items, 'attachment_id')
        )));

        foreach ($attachment_ids as $attachment_id) {
            add_post_meta($page_id, self::META_IMAGE_DEPENDENCY, $attachment_id, false);
        }
    }

    /**
     * 清除所有页面的优化 HTML
     *
     * 使用 WordPress 原生 delete_metadata() 函数，自动处理缓存清理
     *
     * @return int 清除的 meta_key 数量
     */
    public function clear_all_optimized_html()
    {
        $meta_keys = [
            self::META_OPTIMIZED_HTML,
            self::META_HTML_ENCODED,
            self::META_HTML_HASH,
            self::META_HTML_OPTIMIZED_AT,
            self::META_HTML_IMAGE_COUNT,
            self::META_HTML_OPTIMIZED_SIZE,
            self::META_HTML_ORIGINAL_SIZE,
            self::META_HTML_OPTIMIZATION_ERROR,
        ];

        $deleted_count = 0;

        // 使用 WordPress 原生函数删除，自动清理缓存
        foreach ($meta_keys as $meta_key) {
            // delete_metadata('post', 0, $key, '', true) 删除所有帖子的该 meta_key
            // 第5个参数 delete_all = true 表示删除所有匹配的记录
            if (delete_metadata('post', 0, $meta_key, '', true)) {
                $deleted_count++;
            }
        }

        $this->log(sprintf('清除所有优化 HTML - 清理 %d 个 meta_key', $deleted_count));

        return $deleted_count;
    }

    /**
     * 计算内容 hash
     *
     * @param string $content 内容
     * @return string MD5 hash
     */
    private function calculate_content_hash($content)
    {
        // 规范化空白字符，避免格式变化导致 hash 变化
        $normalized = preg_replace('/\s+/', ' ', trim($content));
        return md5($normalized);
    }

    /**
     * 获取 <picture> 区域范围，避免重复处理
     *
     * @param string $content HTML 内容
     * @return array 区间数组
     */
    private function get_picture_ranges($content)
    {
        $ranges = [];
        $pattern = '/<picture\b[^>]*>.*?<\/picture>/is';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $start = $match[1];
                $end = $start + strlen($match[0]);
                $ranges[] = [$start, $end];
            }
        }

        return $ranges;
    }

    /**
     * 检查 img 是否已在 picture 内
     *
     * @param int $position img 的起始位置
     * @param array $ranges picture 范围
     * @return bool
     */
    private function is_inside_picture($position, $ranges)
    {
        foreach ($ranges as $range) {
            if ($position >= $range[0] && $position <= $range[1]) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查是否为本站图片
     *
     * @param string $url 图片 URL
     * @return bool
     */
    private function is_local_image($url)
    {
        // 相对路径：以 /wp-content/uploads/ 开头
        if (strpos($url, '/wp-content/uploads/') === 0) {
            return true;
        }

        // 绝对路径：包含站点 uploads base URL
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];

        return strpos($url, $base_url) !== false;
    }

    /**
     * 解析 img 标签提取属性
     *
     * @param string $img_tag img 标签 HTML
     * @return array|null 属性数组
     */
    private function parse_img_tag($img_tag)
    {
        $info = [];

        // 提取 src
        if (preg_match('/src=(["\'])(.*?)\1/i', $img_tag, $matches)) {
            $info['src'] = $matches[2];
        }

        // 提取 srcset（如果有）
        if (preg_match('/srcset=(["\'])(.*?)\1/i', $img_tag, $matches)) {
            $info['srcset'] = $matches[2];
        }

        // 提取 sizes（如果有）
        if (preg_match('/sizes=(["\'])(.*?)\1/i', $img_tag, $matches)) {
            $info['sizes'] = $matches[2];
        }

        // 提取 width
        if (preg_match('/width=(["\'])(\d+)\1/i', $img_tag, $matches)) {
            $info['width'] = intval($matches[2]);
        }

        // 提取 height
        if (preg_match('/height=(["\'])(\d+)\1/i', $img_tag, $matches)) {
            $info['height'] = intval($matches[2]);
        }

        // 提取 alt
        if (preg_match('/alt=(["\'])(.*?)\1/i', $img_tag, $matches)) {
            $info['alt'] = $matches[2];
        }

        // 提取 class
        if (preg_match('/class=(["\'])(.*?)\1/i', $img_tag, $matches)) {
            $info['class'] = $matches[2];
        }

        // 提取 loading
        if (preg_match('/loading=(["\'])(.*?)\1/i', $img_tag, $matches)) {
            $info['loading'] = $matches[2];
        }

        // 提取 decoding
        if (preg_match('/decoding=(["\'])(.*?)\1/i', $img_tag, $matches)) {
            $info['decoding'] = $matches[2];
        } else {
            $info['decoding'] = 'async';
        }

        return $info;
    }

    /**
     * 提取页面中可优化的图片项
     *
     * @param string $content 页面内容
     * @return array 图片项
     */
    private function extract_optimizable_image_items($content)
    {
        if (empty($content) || strlen($content) < 10) {
            return [];
        }

        $pattern = '/<img\s+[^>]*>/i';
        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return [];
        }

        $items = [];
        $picture_ranges = $this->get_picture_ranges($content);

        foreach ($matches[0] as $match) {
            $img_tag = $match[0];
            $position = $match[1];

            if ($this->is_inside_picture($position, $picture_ranges)) {
                continue;
            }

            if (preg_match('/<\?(?:php|=)/i', $img_tag)) {
                continue;
            }

            $img_info = $this->parse_img_tag($img_tag);
            if (!$img_info || empty($img_info['src']) || !$this->is_local_image($img_info['src'])) {
                continue;
            }

            $attachment_id = $this->get_attachment_id_from_url($img_info['src']);
            if (!$attachment_id) {
                continue;
            }

            $items[] = [
                'attachment_id' => intval($attachment_id),
                'webp_ready' => $this->build_webp_source_attributes($img_info, $attachment_id) !== null,
            ];
        }

        return $items;
    }

    /**
     * URL 缓存（单请求内有效）
     */
    private static $url_cache = [];

    /**
     * 从 URL 获取 attachment ID（带静态缓存）
     *
     * @param string $url 图片 URL
     * @return int|null attachment ID
     */
    private function get_attachment_id_from_url($url)
    {
        if (isset(self::$url_cache[$url])) {
            return self::$url_cache[$url];
        }

        global $wpdb;

        $file = $this->get_upload_relative_path_from_url($url);
        if (!$file) {
            self::$url_cache[$url] = null;
            return null;
        }

        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wp_attached_file'
            AND meta_value = %s
            LIMIT 1",
            $file
        ));

        if ($attachment_id) {
            $attachment_id = intval($attachment_id);
            self::$url_cache[$url] = $attachment_id;
            return $attachment_id;
        }

        // 如果是缩略图，尝试从基础文件名查找
        $file_base = preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $file);

        if ($file_base !== $file) {
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_wp_attached_file'
                AND meta_value = %s
                LIMIT 1",
                $file_base
            ));

            if ($attachment_id) {
                $attachment_id = intval($attachment_id);
                self::$url_cache[$url] = $attachment_id;
                return $attachment_id;
            }
        }

        self::$url_cache[$url] = null;
        return null;
    }

    /**
     * 从 uploads URL 提取相对路径
     *
     * @param string $url 图片 URL
     * @return string|null uploads 相对路径
     */
    private function get_upload_relative_path_from_url($url)
    {
        $upload_dir = wp_upload_dir();
        $base_url = rtrim($upload_dir['baseurl'], '/');
        $clean_url = preg_replace('/[?#].*/', '', $url);

        if (strpos($clean_url, $base_url . '/') === 0) {
            return substr($clean_url, strlen($base_url) + 1);
        }

        if (strpos($clean_url, '/wp-content/uploads/') === 0) {
            return substr($clean_url, strlen('/wp-content/uploads/'));
        }

        return null;
    }

    /**
     * 将原始图片 URL 映射为 WebP URL
     *
     * @param string $url 原始图片 URL
     * @return string|null WebP URL
     */
    private function get_webp_url_for_image_url($url)
    {
        $relative_path = $this->get_upload_relative_path_from_url($url);
        if (!$relative_path) {
            return null;
        }

        $matches = [];
        $clean_url = preg_replace('/[?#].*/', '', $url);
        $suffix = preg_match('/([?#].*)$/', $url, $matches) ? $matches[1] : '';
        $filename = basename($relative_path);
        $webp_filename = preg_replace('/\.(jpe?g|png)$/i', '.webp', $filename, 1, $replace_count);

        if ($replace_count !== 1 || !$webp_filename) {
            return null;
        }

        $webp_filename = sanitize_file_name($webp_filename);
        $dir = dirname($relative_path);
        $optimized_relative_path = ($dir !== '.' ? $dir . '/' : '') . $webp_filename;
        $upload_dir = wp_upload_dir();
        $webp_path = rtrim($upload_dir['basedir'], '/') . '/aether-optimized/' . $optimized_relative_path;

        if (!file_exists($webp_path)) {
            return null;
        }

        if (strpos($clean_url, '/wp-content/uploads/') === 0) {
            return '/wp-content/uploads/aether-optimized/' . $optimized_relative_path . $suffix;
        }

        return rtrim($upload_dir['baseurl'], '/') . '/aether-optimized/' . $optimized_relative_path . $suffix;
    }

    /**
     * 解析 srcset 字符串
     *
     * @param string $srcset 原始 srcset
     * @return array|null 解析后的候选数组
     */
    private function parse_srcset_candidates($srcset)
    {
        $candidates = [];
        $parts = preg_split('/\s*,\s*/', trim($srcset));

        if ($parts === false) {
            return null;
        }

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (!preg_match('/^(\S+)(?:\s+(.+))?$/', $part, $matches)) {
                return null;
            }

            $candidates[] = [
                'url' => $matches[1],
                'descriptor' => isset($matches[2]) ? trim($matches[2]) : '',
            ];
        }

        return empty($candidates) ? null : $candidates;
    }

    /**
     * 基于原始 img 的 src/srcset 构建 WebP source 属性
     *
     * @param array $img_info 图片信息
     * @return array|null source 属性
     */
    private function build_webp_source_attributes($img_info, $attachment_id = 0)
    {
        if (empty($img_info['src'])) {
            return null;
        }

        $img_info = $this->ensure_img_info_has_srcset($img_info, $attachment_id);
        $candidates = !empty($img_info['srcset'])
            ? $this->parse_srcset_candidates($img_info['srcset'])
            : [['url' => $img_info['src'], 'descriptor' => '']];

        if (!$candidates) {
            return null;
        }

        $webp_candidates = [];

        foreach ($candidates as $candidate) {
            if (empty($candidate['url']) || !$this->is_local_image($candidate['url'])) {
                return null;
            }

            $webp_url = $this->get_webp_url_for_image_url($candidate['url']);
            if (!$webp_url) {
                return null;
            }

            $item = $webp_url;
            if (!empty($candidate['descriptor'])) {
                $item .= ' ' . $candidate['descriptor'];
            }

            $webp_candidates[] = $item;
        }

        if (empty($webp_candidates)) {
            return null;
        }

        $source_attrs = [
            'type' => 'image/webp',
            'srcset' => implode(', ', $webp_candidates),
        ];

        if (!empty($img_info['srcset']) && !empty($img_info['sizes'])) {
            $source_attrs['sizes'] = $img_info['sizes'];
        }

        return $source_attrs;
    }

    /**
     * 为 img 标签补齐 canonical srcset。
     *
     * 真源是 WordPress attachment metadata；HTML 层只做属性回填，
     * 不自行发明尺寸集合，避免与核心响应式图片规则分叉。
     *
     * @param string $img_tag 原始 img 标签
     * @param array $img_info 解析后的 img 信息
     * @param int $attachment_id 附件 ID
     * @return array{img_tag:string,img_info:array}
     */
    private function ensure_img_tag_responsive($img_tag, $img_info, $attachment_id)
    {
        $effective_info = $this->ensure_img_info_has_srcset($img_info, $attachment_id);
        $effective_tag = $img_tag;

        if (empty($img_info['srcset']) && !empty($effective_info['srcset'])) {
            $effective_tag = $this->upsert_html_attribute($effective_tag, 'srcset', $effective_info['srcset']);
        }

        return [
            'img_tag' => $effective_tag,
            'img_info' => $effective_info,
        ];
    }

    /**
     * 用 attachment metadata 为 img 信息补 canonical srcset。
     *
     * @param array $img_info 原始 img 信息
     * @param int $attachment_id 附件 ID
     * @return array
     */
    private function ensure_img_info_has_srcset($img_info, $attachment_id)
    {
        if (!empty($img_info['srcset'])) {
            return $img_info;
        }

        $srcset = $this->build_attachment_srcset($attachment_id, $img_info);
        if (!$srcset) {
            return $img_info;
        }

        $img_info['srcset'] = $srcset;
        return $img_info;
    }

    /**
     * 基于 WordPress 核心规则为当前图片生成 canonical srcset。
     *
     * @param int $attachment_id 附件 ID
     * @param array $img_info 原始 img 信息
     * @return string|null
     */
    private function build_attachment_srcset($attachment_id, $img_info)
    {
        $attachment_id = intval($attachment_id);
        // 失败模型：极老 WordPress 或回归脚本运行时没有核心响应式图片生成器。
        // 移除条件：最低支持运行时明确保证 wp_calculate_image_srcset() 总是存在。
        if ($attachment_id <= 0 || empty($img_info['src']) || !function_exists('wp_calculate_image_srcset')) {
            return null;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata) || empty($metadata['file'])) {
            return null;
        }

        $requested_dimensions = $this->resolve_image_dimensions_from_attachment($img_info, $metadata);
        if (empty($requested_dimensions[0]) || empty($requested_dimensions[1])) {
            return null;
        }

        $srcset = wp_calculate_image_srcset(
            $requested_dimensions,
            $img_info['src'],
            $metadata,
            $attachment_id
        );

        if (!is_string($srcset)) {
            return null;
        }

        $srcset = trim($srcset);
        return $srcset !== '' ? $srcset : null;
    }

    /**
     * 根据当前 img 与 attachment metadata 解析请求尺寸。
     *
     * 优先使用原始标签 width/height；没有时再从当前 src 反查 metadata。
     *
     * @param array $img_info 原始 img 信息
     * @param array $metadata attachment metadata
     * @return array<int>|null [width, height]
     */
    private function resolve_image_dimensions_from_attachment($img_info, $metadata)
    {
        $width = isset($img_info['width']) ? intval($img_info['width']) : 0;
        $height = isset($img_info['height']) ? intval($img_info['height']) : 0;
        if ($width > 0 && $height > 0) {
            return [$width, $height];
        }

        $relative_path = !empty($img_info['src'])
            ? $this->get_upload_relative_path_from_url($img_info['src'])
            : null;

        if ($relative_path) {
            if (!empty($metadata['file']) && $relative_path === $metadata['file']) {
                $metadata_width = intval($metadata['width'] ?? 0);
                $metadata_height = intval($metadata['height'] ?? 0);
                if ($metadata_width > 0 && $metadata_height > 0) {
                    return [$metadata_width, $metadata_height];
                }
            }

            $metadata_dir = dirname($metadata['file'] ?? '');
            $relative_basename = basename($relative_path);

            foreach (($metadata['sizes'] ?? []) as $size_data) {
                if (empty($size_data['file'])) {
                    continue;
                }

                $candidate_relative_path = ($metadata_dir !== '.' ? $metadata_dir . '/' : '') . $size_data['file'];
                if ($relative_path !== $candidate_relative_path && $relative_basename !== $size_data['file']) {
                    continue;
                }

                $candidate_width = intval($size_data['width'] ?? 0);
                $candidate_height = intval($size_data['height'] ?? 0);
                if ($candidate_width > 0 && $candidate_height > 0) {
                    return [$candidate_width, $candidate_height];
                }
            }
        }

        if (!empty($metadata['width']) && !empty($metadata['height'])) {
            return [intval($metadata['width']), intval($metadata['height'])];
        }

        return null;
    }

    /**
     * 为单个 HTML 标签插入或替换属性。
     *
     * @param string $html 标签 HTML
     * @param string $name 属性名
     * @param string $value 属性值
     * @return string
     */
    private function upsert_html_attribute($html, $name, $value)
    {
        if ($html === '' || $name === '' || $value === '') {
            return $html;
        }

        $pattern = '/\s' . preg_quote($name, '/') . '=(["\']).*?\1/i';
        $replacement = sprintf(' %s="%s"', $name, esc_attr($value));

        if (preg_match($pattern, $html)) {
            return (string) preg_replace($pattern, $replacement, $html, 1);
        }

        return (string) preg_replace('/\s*(\/?)>$/', $replacement . '$1>', $html, 1);
    }

    /**
     * 生成 picture 标签
     *
     * @param string $img_tag 原始 img 标签
     * @param array $source_attrs source 属性
     * @return string|null picture 标签 HTML
     */
    private function generate_picture_tag($img_tag, $source_attrs)
    {
        if (empty($img_tag) || empty($source_attrs['srcset'])) {
            return null;
        }

        $source_attrs_str = '';
        foreach ($source_attrs as $key => $value) {
            $source_attrs_str .= sprintf(' %s="%s"', $key, esc_attr($value));
        }

        return sprintf('<picture><source%s />%s</picture>', $source_attrs_str, $img_tag);
    }

    /**
     * 日志记录
     *
     * @param string $message 日志消息
     * @return void
     */
    private function log($message)
    {
        if (defined('AETHER_DEBUG') && AETHER_DEBUG) {
            error_log('[Aether HTML Optimization Service] ' . $message);
        }
    }
}
