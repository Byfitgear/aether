<?php
if (!defined('ABSPATH')) { exit; }

class WordExpress_Contact_Form_Service extends WordExpress_Base
{
    const SUBMISSIONS_TABLE = 'wp_wordexpress_contact_submissions';

    protected function init()
    {
        add_action('init', [$this, 'load_textdomain']);
        add_shortcode('wordexpress_contact_form', [$this, 'render_shortcode']);
        add_action('wp_ajax_wordexpress_submit_contact_form', [$this, 'ajax_submit']);
        add_action('wp_ajax_nopriv_wordexpress_submit_contact_form', [$this, 'ajax_submit']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_wordexpress_export_submissions', [$this, 'handle_export']);
    }

    public function load_textdomain()
    {
        load_plugin_textdomain('wordexpress', false, dirname(WORDEXPRESS_BASENAME) . '/languages');
    }

    // ── 数据库表初始化 ──────────────────────────────
    public static function install()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::SUBMISSIONS_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) DEFAULT '',
            subject VARCHAR(500) NOT NULL,
            message TEXT NOT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            user_agent VARCHAR(500) DEFAULT '',
            status VARCHAR(20) DEFAULT 'new',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email (email),
            KEY idx_status (status),
            KEY idx_created (created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // ── 管理菜单 ────────────────────────────────────
    public function add_admin_menu()
    {
        add_submenu_page(
            'wordexpress',
            __('表单提交', 'wordexpress'),
            __('表单提交', 'wordexpress'),
            'edit_posts',
            'wordexpress-forms',
            [$this, 'render_forms_page']
        );
    }

    public function render_forms_page()
    {
        global $wpdb;
        $table = $wpdb->prefix . self::SUBMISSIONS_TABLE;

        // 分页
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;

        // 筛选
        $where = '1=1';
        $params = [];
        if (!empty($_GET['status'])) {
            $where .= ' AND status = %s';
            $params[] = sanitize_text_field($_GET['status']);
        }
        if (!empty($_GET['search'])) {
            $where .= ' AND (name LIKE %s OR email LIKE %s OR subject LIKE %s OR message LIKE %s)';
            $search = '%' . $wpdb->esc_like(sanitize_text_field($_GET['search'])) . '%';
            $params = array_merge($params, [$search, $search, $search, $search]);
        }

        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params);
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...array_merge($params, [$per_page, $offset])
        );

        $total_pages = ceil($total / $per_page);

        ?>
        <div class="wrap">
            <h1><?php _e('WordExpress 表单提交记录', 'wordexpress'); ?></h1>

            <!-- 筛选表单 -->
            <form method="get" class="searchform" style="margin:16px 0;">
                <input type="hidden" name="page" value="wordexpress-forms">
                <input type="text" name="search" placeholder="<?php esc_attr_e('搜索...', 'wordexpress'); ?>"
                       value="<?php echo esc_attr($_GET['search'] ?? ''); ?>" style="width:300px;">
                <select name="status" style="margin-left:8px;">
                    <option value=""><?php _e('所有状态', 'wordexpress'); ?></option>
                    <option value="new" <?php selected($_GET['status'] ?? '', 'new'); ?>><?php _e('新提交', 'wordexpress'); ?></option>
                    <option value="read" <?php selected($_GET['status'] ?? '', 'read'); ?>><?php _e('已读', 'wordexpress'); ?></option>
                    <option value="replied" <?php selected($_GET['status'] ?? '', 'replied'); ?>><?php _e('已回复', 'wordexpress'); ?></option>
                </select>
                <button type="submit" class="button"><?php _e('筛选', 'wordexpress'); ?></button>
                <a href="<?php echo admin_url('admin-post.php?action=wordexpress_export_submissions'); ?>"
                   class="button button-secondary" style="margin-left:8px;">
                    <?php _e('导出 CSV', 'wordexpress'); ?>
                </a>
                <?php if (!empty($_GET['search']) || !empty($_GET['status'])): ?>
                    <a href="<?php echo admin_url('admin.php?page=wordexpress-forms'); ?>"
                       class="button button-secondary" style="margin-left:8px;">
                        <?php _e('清除筛选', 'wordexpress'); ?>
                    </a>
                <?php endif; ?>
            </form>

            <?php if (empty($rows)): ?>
                <p><?php _e('暂无提交记录。', 'wordexpress'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php _e('姓名', 'wordexpress'); ?></th>
                            <th><?php _e('邮箱', 'wordexpress'); ?></th>
                            <th><?php _e('电话', 'wordexpress'); ?></th>
                            <th><?php _e('主题', 'wordexpress'); ?></th>
                            <th><?php _e('状态', 'wordexpress'); ?></th>
                            <th><?php _e('时间', 'wordexpress'); ?></th>
                            <th><?php _e('操作', 'wordexpress'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr class="<?php echo $row->status === 'new' ? 'alternate' : ''; ?>">
                                <td><?php echo esc_html($row->id); ?></td>
                                <td><?php echo esc_html($row->name); ?></td>
                                <td><a href="mailto:<?php echo esc_attr($row->email); ?>"><?php echo esc_html($row->email); ?></a></td>
                                <td><?php echo esc_html($row->phone ?: '—'); ?></td>
                                <td><?php echo esc_html($row->subject); ?></td>
                                <td>
                                    <span class="status-badge" style="padding:2px 8px;border-radius:10px;font-size:12px;
                                        <?php echo $row->status==='new' ? 'background:#e3f2fd;color:#1565c0;' :
                                               ($row->status==='read' ? 'background:#f3e5f5;color:#7b1fa2;' : 'background:#e8f5e9;color:#2e7d32;'); ?>">
                                        <?php echo esc_html($row->status === 'new' ? '新提交' : ($row->status === 'read' ? '已读' : '已回复')); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(date('Y-m-d H:i', strtotime($row->created_at))); ?></td>
                                <td>
                                    <button class="button button-small view-row" data-id="<?php echo $row->id; ?>"
                                            data-name="<?php echo esc_attr($row->name); ?>"
                                            data-email="<?php echo esc_attr($row->email); ?>"
                                            data-phone="<?php echo esc_attr($row->phone); ?>"
                                            data-subject="<?php echo esc_attr($row->subject); ?>"
                                            data-message="<?php echo esc_attr($row->message); ?>"
                                            data-status="<?php echo esc_attr($row->status); ?>"
                                            data-created="<?php echo esc_attr($row->created_at); ?>">
                                        <?php _e('查看', 'wordexpress'); ?>
                                    </button>
                                    <a href="mailto:<?php echo esc_attr($row->email); ?>?subject=<?php echo rawurlencode($row->subject); ?>"
                                       class="button button-small" title="<?php esc_attr_e('回复', 'wordexpress'); ?>">
                                        <?php _e('回复', 'wordexpress'); ?>
                                    </a>
                                    <button class="button button-small delete-row" data-id="<?php echo $row->id; ?>"
                                            style="color:#c62828;">
                                        <?php _e('删除', 'wordexpress'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                $base_url = add_query_arg('page', 'wordexpress-forms');
                echo paginate_links([
                    'base'      => $base_url . '%#%',
                    'format'    => '',
                    'prev_text' => __('«'),
                    'next_text' => __('»'),
                    'total'     => $total_pages,
                    'current'   => $page,
                ]);
                ?>
            <?php endif; ?>
        </div>

        <!-- 详情弹窗 -->
        <div id="we-form-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;
             background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center;">
            <div style="background:#fff;padding:24px;border-radius:8px;max-width:600px;width:90%;max-height:80vh;overflow:auto;">
                <h3 style="margin:0 0 16px;"><?php _e('提交详情', 'wordexpress'); ?></h3>
                <div id="we-modal-body"></div>
                <div style="margin-top:16px;text-align:right;">
                    <button class="button" onclick="document.getElementById('we-form-modal').style.display='none'">
                        <?php _e('关闭', 'wordexpress'); ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.view-row').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var d = this.dataset;
                    var html = '<table style="width:100%;border-collapse:collapse;">';
                    html += '<tr><td style="padding:6px 12px 6px 0;font-weight:bold;">' + '<?php echo esc_js(__('姓名', 'wordexpress')); ?>' + '</td><td>' + d.name + '</td></tr>';
                    html += '<tr><td style="padding:6px 12px 6px 0;font-weight:bold;">' + '<?php echo esc_js(__('邮箱', 'wordexpress')); ?>' + '</td><td><a href="mailto:' + d.email + '">' + d.email + '</a></td></tr>';
                    if (d.phone) html += '<tr><td style="padding:6px 12px 6px 0;font-weight:bold;">' + '<?php echo esc_js(__('电话', 'wordexpress')); ?>' + '</td><td>' + d.phone + '</td></tr>';
                    html += '<tr><td style="padding:6px 12px 6px 0;font-weight:bold;">' + '<?php echo esc_js(__('主题', 'wordexpress')); ?>' + '</td><td>' + d.subject + '</td></tr>';
                    html += '<tr><td style="vertical-align:top;padding:6px 12px 6px 0;font-weight:bold;">' + '<?php echo esc_js(__('留言', 'wordexpress')); ?>' + '</td><td style="white-space:pre-wrap;">' + d.message + '</td></tr>';
                    html += '<tr><td style="padding:6px 12px 6px 0;font-weight:bold;">' + '<?php echo esc_js(__('提交时间', 'wordexpress')); ?>' + '</td><td>' + d.created + '</td></tr>';
                    html += '<tr><td style="padding:6px 12px 6px 0;font-weight:bold;">' + '<?php echo esc_js(__('状态', 'wordexpress')); ?>' + '</td><td>' + d.status + '</td></tr>';
                    html += '</table>';
                    document.getElementById('we-modal-body').innerHTML = html;
                    document.getElementById('we-form-modal').style.display = 'flex';

                    // Mark as read
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=wordexpress_mark_read&id=' + d.id
                    });
                });
            });

            document.querySelectorAll('.delete-row').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('<?php echo esc_js(__('确定删除此记录？', 'wordexpress')); ?>')) return;
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=wordexpress_delete_submission&id=' + this.dataset.id
                    }).then(function() { location.reload(); });
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX：标记已读 ──────────────────────────────
    public function ajax_mark_read()
    {
        check_ajax_referer('wordexpress_nonce', 'nonce', false);
        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_die();
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::SUBMISSIONS_TABLE,
            ['status' => 'read'],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
        wp_send_json_success();
    }

    // ── AJAX：删除提交 ──────────────────────────────
    public function ajax_delete_submission()
    {
        check_ajax_referer('wordexpress_nonce', 'nonce', false);
        if (!current_user_can('manage_options')) wp_die();
        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_die();
        global $wpdb;
        $wpdb->delete($wpdb->prefix . self::SUBMISSIONS_TABLE, ['id' => $id], ['%d']);
        wp_send_json_success();
    }

    // ── AJAX：提交表单 ──────────────────────────────
    public function ajax_submit()
    {
        // CSRF check
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wordexpress_contact_form')) {
            wp_send_json_error(['message' => __('安全校验失败，请刷新页面重试。', 'wordexpress')], 403);
        }

        $settings = WordExpress_Settings_Service::get_all();
        $fields = $settings['contact_form_fields'] ?? [
            ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => '邮箱', 'type' => 'email', 'required' => true],
            ['name' => 'subject', 'label' => '主题', 'type' => 'text', 'required' => true],
            ['name' => 'message', 'label' => '留言内容', 'type' => 'textarea', 'required' => true],
        ];

        // 收集数据
        $data = [];
        foreach ($fields as $field) {
            $key = $field['name'];
            $value = sanitize_text_field($_POST[$key] ?? '');
            $data[$key] = $value;
        }

        // 必填校验
        foreach ($fields as $field) {
            if ($field['required'] && empty($data[$field['name']])) {
                wp_send_json_error([
                    'message' => sprintf(__('请填写 "%s"。', 'wordexpress'), $field['label'])
                ], 422);
            }
        }

        // 邮箱格式校验
        if (!empty($data['email']) && !is_email($data['email'])) {
            wp_send_json_error(['message' => __('邮箱格式不正确。', 'wordexpress')], 422);
        }

        // 保存提交记录
        global $wpdb;
        $table = $wpdb->prefix . self::SUBMISSIONS_TABLE;
        $insert_data = [
            'name'         => $data['name'] ?? '',
            'email'        => $data['email'] ?? '',
            'phone'        => $data['phone'] ?? '',
            'subject'      => $data['subject'] ?? '',
            'message'      => $data['message'] ?? '',
            'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'status'       => 'new',
        ];
        $wpdb->insert($table, $insert_data, ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        $submission_id = $wpdb->insert_id;

        // 发送邮件通知
        $to      = $settings['contact_form_email'] ?? get_option('admin_email');
        $subject = sprintf('[%s] %s', get_bloginfo('name'), $data['subject'] ?? '');
        $body = sprintf(
            "<h2>%s</h2>\n<p><strong>%s:</strong> %s</p>\n<p><strong>%s:</strong> %s</p>\n<p><strong>%s:</strong> %s</p>\n<p><strong>%s:</strong> %s</p>\n<hr>\n<p>%s</p>",
            __('新联系表单提交', 'wordexpress'),
            __('姓名', 'wordexpress'), $data['name'] ?? '',
            __('邮箱', 'wordexpress'), $data['email'] ?? '',
            __('电话', 'wordexpress'), $data['phone'] ?? '—',
            __('主题', 'wordexpress'), $data['subject'] ?? '',
            __('留言', 'wordexpress'), nl2br($data['message'] ?? '')
        );
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($to, $subject, $body, $headers);

        wp_send_json_success([
            'message' => $settings['contact_form_success_message'] ?? __('感谢您的留言，我们会尽快回复您！', 'wordexpress'),
            'submission_id' => $submission_id,
        ]);
    }

    // ── REST API ────────────────────────────────────
    public function register_rest_routes()
    {
        register_rest_route('wordexpress/v1', '/contact/form-config', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_form_config'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('wordexpress/v1', '/contact/submit', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_submit'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_form_config($request)
    {
        $settings = WordExpress_Settings_Service::get_all();
        return new WP_REST_Response([
            'success' => true,
            'title' => $settings['contact_form_title'] ?? __('联系我们', 'wordexpress'),
            'fields' => $settings['contact_form_fields'] ?? [],
            'success_message' => $settings['contact_form_success_message'] ?? __('感谢您的留言！', 'wordexpress'),
        ]);
    }

    public function rest_submit($request)
    {
        $data = $request->get_json_params();
        $settings = WordExpress_Settings_Service::get_all();
        $fields = $settings['contact_form_fields'] ?? [];

        $cleaned = [];
        foreach ($fields as $field) {
            $cleaned[$field['name']] = sanitize_text_field($data[$field['name']] ?? '');
        }

        foreach ($fields as $field) {
            if ($field['required'] && empty($cleaned[$field['name']])) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => sprintf(__('请填写 "%s"。', 'wordexpress'), $field['label']),
                ], 422);
            }
        }

        if (!empty($cleaned['email']) && !is_email($cleaned['email'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('邮箱格式不正确。', 'wordexpress'),
            ], 422);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::SUBMISSIONS_TABLE;
        $wpdb->insert($table, [
            'name'       => $cleaned['name'] ?? '',
            'email'      => $cleaned['email'] ?? '',
            'phone'      => $cleaned['phone'] ?? '',
            'subject'    => $cleaned['subject'] ?? '',
            'message'    => $cleaned['message'] ?? '',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'status'     => 'new',
        ], ['%s','%s','%s','%s','%s','%s','%s','%s']);

        // 邮件通知
        $to = $settings['contact_form_email'] ?? get_option('admin_email');
        $subject = sprintf('[%s] %s', get_bloginfo('name'), $cleaned['subject'] ?? '');
        $body = sprintf("%s: %s\n%s: %s\n%s: %s\n%s: %s\n\n%s:\n%s",
            $cleaned['name'] ?? '', $cleaned['email'] ?? '',
            $cleaned['phone'] ?? '', $cleaned['subject'] ?? '',
            $cleaned['message'] ?? '',
            __('留言', 'wordexpress'), nl2br($cleaned['message'] ?? '')
        );
        wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);

        return new WP_REST_Response([
            'success' => true,
            'message' => $settings['contact_form_success_message'] ?? __('提交成功！', 'wordexpress'),
        ]);
    }

    // ── 导出 CSV ────────────────────────────────────
    public function handle_export()
    {
        if (!current_user_can('manage_options')) wp_die();
        global $wpdb;
        $table = $wpdb->prefix . self::SUBMISSIONS_TABLE;
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wordexpress-form-submissions.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['#', '姓名', '邮箱', '电话', '主题', '留言', 'IP', '状态', '时间']);
        foreach ($rows as $r) {
            fputcsv($out, [$r->id, $r->name, $r->email, $r->phone, $r->subject, $r->message, $r->ip_address, $r->status, $r->created_at]);
        }
        fclose($out);
        exit;
    }

    // ── 短代码渲染 ──────────────────────────────────
    public function render_shortcode($atts)
    {
        $settings = WordExpress_Settings_Service::get_all();
        $title = $atts['title'] ?? $settings['contact_form_title'] ?? __('联系我们', 'wordexpress');
        $fields = $atts['fields'] ? json_decode($atts['fields'], true) : ($settings['contact_form_fields'] ?? []);
        $success_msg = $settings['contact_form_success_message'] ?? __('感谢您的留言，我们会尽快回复您！', 'wordexpress');

        ob_start();
        ?>
        <div class="wordexpress-contact-form" id="we-contact-form-<?php echo esc_attr(wp_rand(1000, 9999)); ?>">
            <h3><?php echo esc_html($title); ?></h3>
            <form id="we-cf-form" novalidate>
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('wordexpress_contact_form')); ?>">
                <?php foreach ($fields as $field): ?>
                    <div class="we-cf-field" style="margin-bottom:16px;">
                        <label style="display:block;margin-bottom:4px;font-weight:600;">
                            <?php echo esc_html($field['label']); ?>
                            <?php if (!empty($field['required'])): ?><span style="color:#d32f2f;">*</span><?php endif; ?>
                        </label>
                        <?php if ($field['type'] === 'textarea'): ?>
                            <textarea name="<?php echo esc_attr($field['name']); ?>" rows="5"
                                      style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font:inherit;resize:vertical;"
                                      placeholder="<?php echo esc_attr($field['label']); ?>"></textarea>
                        <?php else: ?>
                            <input type="<?php echo esc_attr($field['type'] ?? 'text'); ?>" name="<?php echo esc_attr($field['name']); ?>"
                                   style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font:inherit;"
                                   placeholder="<?php echo esc_attr($field['label']); ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" id="we-cf-submit"
                        style="padding:12px 32px;background:#0073aa;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:16px;">
                    <?php _e('发送', 'wordexpress'); ?>
                </button>
                <div id="we-cf-msg" style="margin-top:12px;display:none;padding:12px;border-radius:4px;"></div>
            </form>
        </div>
        <style>
            .wordexpress-contact-form input:focus,
            .wordexpress-contact-form textarea:focus {
                border-color: #0073aa;
                outline: none;
                box-shadow: 0 0 0 2px rgba(0,115,170,.2);
            }
        </style>
        <script>
        (function(){
            var form = document.getElementById('we-cf-form');
            var btn = document.getElementById('we-cf-submit');
            var msg = document.getElementById('we-cf-msg');
            if (!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js(__('发送中...', 'wordexpress')); ?>';
                msg.style.display = 'none';
                var fd = new FormData(form);
                fd.set('action', 'wordexpress_submit_contact_form');
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', body:fd})
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.success) {
                        msg.style.display = 'block';
                        msg.style.background = '#e8f5e9';
                        msg.style.color = '#2e7d32';
                        msg.textContent = data.message || '<?php echo esc_js($success_msg); ?>';
                        form.reset();
                    } else {
                        msg.style.display = 'block';
                        msg.style.background = '#ffebee';
                        msg.style.color = '#c62828';
                        msg.textContent = data.message || '<?php echo esc_js(__('提交失败，请重试。', 'wordexpress')); ?>';
                    }
                }).catch(function(){
                    msg.style.display = 'block';
                    msg.style.background = '#ffebee';
                    msg.style.color = '#c62828';
                    msg.textContent = '<?php echo esc_js(__('网络错误，请检查连接后重试。', 'wordexpress')); ?>';
                }).finally(function(){
                    btn.disabled = false;
                    btn.textContent = '<?php echo esc_js(__('发送', 'wordexpress')); ?>';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
