<?php
/**
 * Submissions API Routes
 *
 * Minimal endpoints to receive HTMX form posts and list submissions.
 */

defined('ABSPATH') || exit;

class Aether_API_Routes_Submissions extends Aether_API_Routes_Base
{
    public function __construct()
    {
        // Keep legacy cron callbacks to drain any events queued before this update
        add_action('aether_send_serverchan_notification', [$this, 'send_serverchan_notification_async'], 10, 2);
        add_action('aether_send_sales_notifications', [$this, 'send_sales_notifications_async'], 10, 2);

        // Weekly cleanup for orphaned files in aether-submissions folder
        add_action('aether_cleanup_orphan_files', [$this, 'cron_cleanup_orphan_files']);
        add_filter('cron_schedules', function ($schedules) {
            if (!isset($schedules['weekly'])) {
                $schedules['weekly'] = [
                    'interval' => 7 * DAY_IN_SECONDS,
                    'display'  => 'Once Weekly',
                ];
            }
            return $schedules;
        });
        if (!wp_next_scheduled('aether_cleanup_orphan_files')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', 'aether_cleanup_orphan_files');
        }
    }

    public function register_routes()
    {
        // Public POST endpoint to receive submissions
        $this->register_route('/submissions', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'receive_submission'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_submissions'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args' => [
                    'per_page' => [
                        'validate_callback' => function ($param) { return is_numeric($param) && $param > 0 && $param <= 200; },
                        'sanitize_callback' => 'absint',
                        'default' => 50,
                    ],
                    'paged' => [
                        'validate_callback' => function ($param) { return is_numeric($param) && $param >= 1; },
                        'sanitize_callback' => 'absint',
                        'default' => 1,
                    ],
                ],
            ],
        ]);

        // Admin: delete a submission
        $this->register_route('/submissions/(?P<id>\\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_submission'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        // Admin: export CSV
        $this->register_route('/submissions/export', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'export_csv'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        $this->register_route('/submissions/geo-backfill/tick', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'run_geo_backfill_tick'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        $this->register_route('/submissions/geo-backfill/state', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_geo_backfill_state'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);
    }

    public function check_admin_permission()
    {
        return current_user_can('manage_options');
    }

    /**
     * Receive a submission from HTMX form post.
     * Stores the payload in a private CPT entry.
     */
    public function receive_submission($request)
    {
        // Body size guard (200KB)
        $raw_body = (string) $request->get_body();
        if (strlen($raw_body) > 200 * 1024) {
            return $this->error_response('内容过大', 413);
        }

        // Parse incoming data (form-encoded or JSON)
        $data = $request->get_body_params();
        if (empty($data)) {
            $json = json_decode($raw_body, true);
            if (is_array($json)) {
                $data = $json;
            }
        }
        if (!is_array($data)) {
            $data = [];
        }

        // Gather request context
        $headers = $request->get_headers();
        $client_ip = Aether_Submissions_Utils::get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
        // Normalize HX headers: WP may return string or array
        $hx_current_raw = $headers['hx-current-url'] ?? '';
        if (!$hx_current_raw) { $hx_current_raw = $request->get_header('hx-current-url'); }
        if (is_array($hx_current_raw)) { $hx_current_raw = $hx_current_raw[0] ?? ''; }
        if (!$hx_current_raw && isset($_SERVER['HTTP_HX_CURRENT_URL'])) { $hx_current_raw = $_SERVER['HTTP_HX_CURRENT_URL']; }
        $hx_current_url = (string) $hx_current_raw;

        // Rate limit by IP
        if (!Aether_Submissions_Utils::check_rate_limit($client_ip)) {
            return $this->error_response('请求过于频繁', 429, ['retry_after' => 60]);
        }

        // Basic origin check (same host if referer/hx-current-url/origin provided)
        if (!Aether_Submissions_Utils::is_same_origin($referer, $hx_current_url, $origin)) {
            // Log detailed context for troubleshooting potential false positives
            error_log(sprintf(
                'Aether Security: Origin check failed [ip:%s, ua:%s, origin:%s, referer:%s, hx_current:%s]',
                $client_ip,
                substr($user_agent, 0, 100),
                $origin ?: 'none',
                $referer ? parse_url($referer, PHP_URL_HOST) : 'none',
                $hx_current_url ? parse_url($hx_current_url, PHP_URL_HOST) : 'none'
            ));
            return $this->error_response('来源不被允许', 403);
        }

        // Honeypot validation: require field existence and empty value
        // Best practice: silently reject to avoid revealing defense mechanisms
        $hp_fields = apply_filters('aether_submission_honeypot_fields', ['_aether_hp']);
        foreach ($hp_fields as $hp) {
            $honeypot_triggered = false;
            $log_message = '';

            // Check 1: Field must exist in submission (bots often skip hidden fields)
            if (!array_key_exists($hp, $data)) {
                $honeypot_triggered = true;
                $log_message = sprintf(
                    'Aether Security: Honeypot field missing - likely bot [field:%s, ip:%s, ua:%s]',
                    $hp,
                    $client_ip,
                    substr($user_agent, 0, 100)
                );
            }
            // Check 2: Field must be empty (if filled, bot caught)
            elseif (!empty($data[$hp])) {
                $honeypot_triggered = true;
                $log_message = sprintf(
                    'Aether Security: Honeypot field filled - bot caught [field:%s, value:%s, ip:%s]',
                    $hp,
                    substr((string)$data[$hp], 0, 50),
                    $client_ip
                );
            }

            // Handle honeypot violation: silently reject
            if ($honeypot_triggered) {
                error_log($log_message);
                return $this->silent_reject_bot($headers, $request);
            }
        }

        // Optional timing check: if client included __ts, ensure >= 2s
        if (isset($data['__ts'])) {
            $ts = absint($data['__ts']);
            if ($ts > 0 && (time() - $ts) < 2) {
                return $this->error_response('提交过快', 400);
            }
        }

        // Load submission-related settings
        $settings = Aether_Settings_Service::get_all();
        $subs_cfg = isset($settings['submissions']) && is_array($settings['submissions']) ? $settings['submissions'] : [];

        $response_config = $this->build_submission_response_config($data, $subs_cfg);

        // Sanitize payload recursively (form text fields only)
        $sanitized = Aether_Submissions_Utils::sanitize_payload($data);
        $public_payload = Aether_Submissions_Utils::prune_private_fields($sanitized);
        $dedupe_window = Aether_Submissions_Utils::get_submission_dedupe_window();
        $dedupe_source = Aether_Submissions_Utils::normalize_submission_source_url($hx_current_url, $referer);
        $dedupe_fingerprint = Aether_Submissions_Utils::build_submission_fingerprint(
            $client_ip,
            $dedupe_source,
            $public_payload,
            $request->get_file_params()
        );
        $dedupe_context = [
            'client_ip' => $client_ip,
            'source' => $dedupe_source,
        ];
        $dedupe_claim_acquired = false;

        if ($dedupe_fingerprint !== '') {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $claim = Aether_Submissions_Utils::claim_submission_fingerprint(
                    $dedupe_fingerprint,
                    $dedupe_window,
                    $dedupe_context
                );
                $state = isset($claim['state']) ? (string) $claim['state'] : '';

                if ($state === 'complete' && !empty($claim['post_id'])) {
                    $this->log_duplicate_submission_event(
                        'duplicate_suppressed',
                        $dedupe_fingerprint,
                        $dedupe_source,
                        $client_ip,
                        (int) $claim['post_id']
                    );
                    return $this->respond_submission_success(
                        $headers,
                        $request,
                        $response_config,
                        ['id' => (int) $claim['post_id']]
                    );
                }

                if ($state === 'acquired') {
                    $dedupe_claim_acquired = true;
                    if (!empty($claim['recovered'])) {
                        $recovered_post_id = Aether_Submissions_Utils::find_recent_submission_by_fingerprint(
                            $dedupe_fingerprint,
                            $dedupe_window
                        );
                        if ($recovered_post_id > 0) {
                            Aether_Submissions_Utils::store_submission_result(
                                $dedupe_fingerprint,
                                $recovered_post_id,
                                $dedupe_window,
                                $dedupe_context
                            );
                            $this->log_duplicate_submission_event(
                                'duplicate_claim_recovered',
                                $dedupe_fingerprint,
                                $dedupe_source,
                                $client_ip,
                                $recovered_post_id
                            );
                            return $this->respond_submission_success(
                                $headers,
                                $request,
                                $response_config,
                                ['id' => $recovered_post_id]
                            );
                        }
                    }
                    break;
                }

                if ($state !== 'pending') {
                    break;
                }

                $this->log_duplicate_submission_event(
                    'duplicate_claim_pending',
                    $dedupe_fingerprint,
                    $dedupe_source,
                    $client_ip
                );

                $resolved = Aether_Submissions_Utils::wait_for_submission_result(
                    $dedupe_fingerprint,
                    1500,
                    100
                );
                if (is_array($resolved) && !empty($resolved['post_id'])) {
                    $post_id = (int) $resolved['post_id'];
                    $this->log_duplicate_submission_event(
                        'duplicate_suppressed',
                        $dedupe_fingerprint,
                        $dedupe_source,
                        $client_ip,
                        $post_id
                    );
                    return $this->respond_submission_success(
                        $headers,
                        $request,
                        $response_config,
                        ['id' => $post_id]
                    );
                }

                $recovered_post_id = Aether_Submissions_Utils::find_recent_submission_by_fingerprint(
                    $dedupe_fingerprint,
                    $dedupe_window
                );
                if ($recovered_post_id > 0) {
                    Aether_Submissions_Utils::store_submission_result(
                        $dedupe_fingerprint,
                        $recovered_post_id,
                        $dedupe_window,
                        $dedupe_context
                    );
                    $this->log_duplicate_submission_event(
                        'duplicate_claim_recovered',
                        $dedupe_fingerprint,
                        $dedupe_source,
                        $client_ip,
                        $recovered_post_id
                    );
                    return $this->respond_submission_success(
                        $headers,
                        $request,
                        $response_config,
                        ['id' => $recovered_post_id]
                    );
                }

                // Fail open on uncertainty: if the in-flight claim did not resolve
                // to a concrete stored result, allow this request to continue.
                break;
            }
        }

        $should_release_claim = $dedupe_claim_acquired;

        try {
            // Process file uploads (supports single 'attachment' or multiple 'attachments[]')
            $upload_errors = [];
            $uploaded_attachments = $this->process_submission_attachments($request, $upload_errors);

            // Merge uploaded attachment metadata to payload if any
            if (!empty($uploaded_attachments)) {
                // Strip internal-only keys (e.g., _file_path) before persisting
                $payload_attachments = array_map(function ($item) {
                    if (isset($item['_file_path'])) unset($item['_file_path']);
                    return $item;
                }, $uploaded_attachments);
                $public_payload['attachments'] = $payload_attachments;
            }

            // Note: 上传失败默认不阻断提交；如需阻断可通过过滤器 aether_submission_partial_upload 返回 false

            // Title: optional form name + timestamp
            $form_name = isset($sanitized['form']) && is_string($sanitized['form']) ? sanitize_text_field($sanitized['form']) : '';
            $title = ($form_name ? ("Form: $form_name - ") : '') . current_time('mysql');

            // Optionally call out to filters for spam checks (Akismet etc.)
            $is_spam = (bool) apply_filters('aether_submission_is_spam', false, $sanitized, [
                'ip' => $client_ip,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'hx_current' => $hx_current_url,
            ]);
            if ($is_spam) {
                if ($dedupe_claim_acquired) {
                    Aether_Submissions_Utils::release_submission_fingerprint($dedupe_fingerprint);
                    $should_release_claim = false;
                }
                return $this->silent_reject_bot($headers, $request);
            }

            $geo = Aether_Submission_Geo_Resolver::resolve_submission_geo($client_ip, $sanitized, $_SERVER);

            $context = [
                'ip' => $client_ip,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'hx_current' => $hx_current_url,
                'geo' => $geo,
            ];
            if ($dedupe_fingerprint !== '') {
                $context['dedupe'] = [
                    'fingerprint' => $dedupe_fingerprint,
                    'source' => $dedupe_source,
                ];
            }

            // Persist as CPT
            $postarr = [
                'post_type' => Aether_Submissions_Service::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $title,
                'post_content' => wp_json_encode([
                    'payload' => $public_payload,
                    'context' => $context,
                ], JSON_UNESCAPED_UNICODE),
            ];

            $post_id = wp_insert_post($postarr, true);
            if (is_wp_error($post_id)) {
                return $this->error_response('保存失败', 500, ['error' => $post_id->get_error_message()]);
            }

            if ($dedupe_claim_acquired) {
                Aether_Submissions_Utils::store_submission_result(
                    $dedupe_fingerprint,
                    $post_id,
                    $dedupe_window,
                    $dedupe_context
                );
                $should_release_claim = false;
            }

            if ($form_name) {
                update_post_meta($post_id, '_aether_form_name', $form_name);
            }

            // Link attachments to this submission post as parent (if any)
            if (!empty($uploaded_attachments)) {
                foreach ($uploaded_attachments as $att) {
                    if (!empty($att['id'])) {
                        wp_update_post([
                            'ID' => absint($att['id']),
                            'post_parent' => $post_id,
                        ]);
                    }
                }
            }

            // Send notifications synchronously (WP-Cron loopback is unreliable on many hosts)
            $notification_timestamp = current_time('mysql');

            // Webhook 通知
            if (!empty($subs_cfg['webhook_enabled']) && !empty($subs_cfg['webhook_url'])) {
                try {
                    $this->send_webhook_notification([
                        'post_id' => $post_id,
                        'payload' => $public_payload,
                        'client_ip' => $client_ip,
                        'user_agent' => $user_agent,
                        'hx_current' => $hx_current_url,
                        'referer' => $referer,
                        'timestamp' => $notification_timestamp,
                        'form_name' => $form_name,
                        'geo' => $geo,
                    ], $subs_cfg);
                } catch (\Throwable $e) {
                    error_log('Aether: Webhook notification failed: ' . $e->getMessage());
                }
            }

            // Optional Server酱通知
            if (!empty($subs_cfg['serverchan_enabled']) && !empty($subs_cfg['serverchan_sendkey'])) {
                try {
                    $this->send_serverchan_notification_async([
                        'sendkey' => sanitize_text_field($subs_cfg['serverchan_sendkey']),
                        'post_id' => $post_id,
                        'payload' => $public_payload,
                        'client_ip' => $client_ip,
                        'hx_current' => $hx_current_url,
                        'referer' => $referer,
                        'timestamp' => $notification_timestamp,
                        'geo' => $geo,
                    ], $subs_cfg);
                } catch (\Throwable $e) {
                    error_log('Aether: Server酱 notification failed: ' . $e->getMessage());
                }
            }

            // Sales email notifications (wp_mail)
            if (!empty($subs_cfg['email_enabled'])) {
                try {
                    $this->send_sales_notifications_async([
                        'post_id' => $post_id,
                        'payload' => $public_payload,
                        'client_ip' => $client_ip,
                        'hx_current' => $hx_current_url,
                        'referer' => $referer,
                        'timestamp' => $notification_timestamp,
                        'form_name' => $form_name,
                        'geo' => $geo,
                    ], $subs_cfg);
                } catch (\Throwable $e) {
                    error_log('Aether: Sales email notification failed: ' . $e->getMessage());
                }
            }

            return $this->respond_submission_success(
                $headers,
                $request,
                $response_config,
                ['id' => $post_id]
            );
        } finally {
            if ($should_release_claim && $dedupe_fingerprint !== '') {
                Aether_Submissions_Utils::release_submission_fingerprint($dedupe_fingerprint);
            }
        }
    }

    /**
     * Normalize and process uploaded files for a submission.
     * - Enforces count and size limits
     * - Validates MIME/type via WP helpers
     * - Saves to media library and returns metadata list
     *
     * @param WP_REST_Request $request
     * @param array $errors Collected validation errors (by reference)
     * @return array<int,array{id:int,url:string,filename:string,size:int,mime:string}>
     */
    private function process_submission_attachments($request, &$errors)
    {
        $errors = [];
        $files = $request->get_file_params();
        if (empty($files) || (!isset($files['attachment']) && !isset($files['attachments']))) {
            return [];
        }

        $file_entries = Aether_Submissions_Utils::normalize_submission_file_entries(
            $files,
            ['attachment', 'attachments']
        );
        $file_entries = array_values(array_filter($file_entries, function ($f) {
            return !empty($f['tmp_name']);
        }));
        if (empty($file_entries)) return [];

        // Limits
        $max_count = (int) apply_filters('aether_submission_max_file_count', 5);
        if (count($file_entries) > $max_count) {
            $errors[] = '文件数量超过限制';
            return $this->handle_upload_error_policy([], $errors);
        }

        $max_bytes = (int) apply_filters('aether_submission_max_file_size', 10 * MB_IN_BYTES);
        $allowed_mimes = $this->get_allowed_submission_mimes();

        // Ensure required includes
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        // Temporarily redirect uploads into /uploads/aether-submissions/YYYY/MM
        $ts = current_time('timestamp');
        $ym = '/' . date('Y', $ts) . '/' . date('m', $ts);
        $upload_dir_cb = function ($dirs) use ($ym) {
            $subdir = '/aether-submissions' . $ym;
            $dirs['subdir'] = $subdir;
            $dirs['path'] = trailingslashit($dirs['basedir']) . ltrim($subdir, '/');
            $dirs['url']  = trailingslashit($dirs['baseurl']) . ltrim($subdir, '/');
            if (!is_dir($dirs['path'])) {
                wp_mkdir_p($dirs['path']);
            }
            return $dirs;
        };
        add_filter('upload_dir', $upload_dir_cb, 999);

        $results = [];
        $use_media_library = (bool) apply_filters('aether_submission_use_media_library', false);
        // Adjust memory for many/large files
        $total_size = 0;
        foreach ($file_entries as $fe) { $total_size += (int)($fe['size'] ?? 0); }
        if ((count($file_entries) > 2 || $total_size > 20 * MB_IN_BYTES) && function_exists('ini_set')) {
            @ini_set('memory_limit', '256M');
        }
        foreach ($file_entries as $idx => $file) {
            $label = isset($file['name']) ? (string)$file['name'] : ('file_' . $idx);
            // Basic PHP upload errors
            if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = $label . ': upload failed (PHP code ' . (int)$file['error'] . ')';
                continue;
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0 || $size > $max_bytes) {
                $errors[] = $label . ': file too large (max ' . size_format($max_bytes) . ')';
                continue;
            }

            // Server-side type validation
            $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);
            $ext = isset($checked['ext']) ? (string)$checked['ext'] : '';
            $type = isset($checked['type']) ? (string)$checked['type'] : '';
            if (!$ext || !$type || !isset($allowed_mimes[$ext])) {
                $allowed_exts = implode(', ', array_keys($allowed_mimes));
                $errors[] = $label . ': unsupported file type (allowed: ' . $allowed_exts . ')';
                continue;
            }

            // Upload to uploads dir
            $overrides = [
                'test_form' => false,
                'mimes' => $allowed_mimes,
                'unique_filename_callback' => function($dir, $name, $ext) {
                    $name = sanitize_file_name($name);
                    $mt = microtime(true);
                    $ms = sprintf('%03d', (int)(($mt - floor($mt)) * 1000));
                    $prefix = 'aether-sub-' . date('YmdHis') . $ms . '-' . wp_generate_password(8, false, false);
                    return $prefix . '-' . $name;
                }
            ];
            $moved = wp_handle_upload($file, $overrides);
            if (isset($moved['error'])) {
                $errors[] = $label . ': upload handler error - ' . sanitize_text_field($moved['error']);
                continue;
            }

            $url = (string)$moved['url'];
            $file_path = (string)$moved['file'];
            $mime = (string)$moved['type'];

            if ($use_media_library) {
                // Insert as attachment
                $attachment_id = wp_insert_attachment([
                    'post_mime_type' => $mime,
                    'post_title'     => sanitize_file_name(wp_basename($file_path)),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ], $file_path);

                if (is_wp_error($attachment_id)) {
                    $errors[] = $label . ': failed to create attachment - ' . $attachment_id->get_error_message();
                    continue;
                }

                // Generate attachment metadata when applicable (images)
                $metadata = @wp_generate_attachment_metadata($attachment_id, $file_path);
                if (!is_wp_error($metadata) && !empty($metadata)) {
                    wp_update_attachment_metadata($attachment_id, $metadata);
                }

                $results[] = [
                    'id' => (int)$attachment_id,
                    'url' => esc_url_raw($url),
                    'filename' => sanitize_file_name(wp_basename($file_path)),
                    'size' => (int) filesize($file_path),
                    'mime' => sanitize_mime_type($mime),
                ];
            } else {
                // Do not create Media Library items; just return URL and metadata
                $results[] = [
                    'id' => 0,
                    'url' => esc_url_raw($url),
                    'filename' => sanitize_file_name(wp_basename($file_path)),
                    'size' => (int) filesize($file_path),
                    'mime' => sanitize_mime_type($mime),
                    // keep internal for cleanup on error (not persisted)
                    '_file_path' => $file_path,
                ];
            }
        }

        if (!empty($errors)) {
            $out = $this->handle_upload_error_policy($results, $errors);
            remove_filter('upload_dir', $upload_dir_cb, 999);
            return $out;
        }
        remove_filter('upload_dir', $upload_dir_cb, 999);
        return $results;
    }

    /**
     * Weekly cron: cleanup orphan files in uploads/aether-submissions not referenced by any submission
     */
    public function cron_cleanup_orphan_files()
    {
        $use_media_library = (bool) apply_filters('aether_submission_use_media_library', false);
        if ($use_media_library) {
            return; // Media Library manages its own attachments; skip
        }
        $uploads = wp_upload_dir();
        $base_path = trailingslashit($uploads['basedir']) . 'aether-submissions';
        $base_url  = trailingslashit($uploads['baseurl']) . 'aether-submissions';
        if (!is_dir($base_path)) return;

        // Build set of referenced URLs from submission payloads
        $referenced = [];
        $paged = 1;
        do {
            $q = new WP_Query([
                'post_type'      => Aether_Submissions_Service::POST_TYPE,
                'posts_per_page' => 200,
                'paged'          => $paged,
                'fields'         => 'ids',
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
            foreach ($q->posts as $pid) {
                $content = get_post_field('post_content', $pid);
                $decoded = json_decode((string)$content, true);
                if (is_array($decoded) && !empty($decoded['payload']['attachments']) && is_array($decoded['payload']['attachments'])) {
                    foreach ($decoded['payload']['attachments'] as $att) {
                        if (!empty($att['url'])) {
                            $referenced[$att['url']] = true;
                        }
                    }
                }
            }
            $paged++;
        } while ($q->max_num_pages && $paged <= $q->max_num_pages);

        $days = (int) apply_filters('aether_submission_cleanup_days', 30);
        $threshold = time() - max(1, $days) * DAY_IN_SECONDS;

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_path, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $fileinfo) {
            if (!$fileinfo->isFile()) continue;
            $path = $fileinfo->getPathname();
            $mtime = $fileinfo->getMTime();
            if ($mtime > $threshold) continue; // keep recent files
            // Map to URL and check if referenced
            $rel = ltrim(str_replace($base_path, '', $path), '/');
            $url = trailingslashit($base_url) . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            if (isset($referenced[$url])) continue;
            @unlink($path);
        }
    }

    private function handle_upload_error_policy($partial_results, $errors)
    {
        $allow_partial = (bool) apply_filters('aether_submission_partial_upload', true, $errors, $partial_results);
        if ($allow_partial) {
            // Record errors in debug log and continue without aborting submission
            if (!empty($errors)) {
                error_log('Aether submission partial upload: ' . wp_json_encode($errors));
            }
            // Keep successful results (may be empty if all failed)
            return $partial_results;
        }
        // Abort path: cleanup and signal error for caller to handle
        if (!empty($partial_results)) {
            foreach ($partial_results as $att) {
                if (!empty($att['id'])) {
                    wp_delete_attachment((int)$att['id'], true);
                } elseif (!empty($att['_file_path'])) {
                    $p = (string) $att['_file_path'];
                    if ($p && file_exists($p)) @unlink($p);
                }
            }
        }
        $GLOBALS['aether_last_upload_errors'] = $errors;
        return [];
    }

    private function get_allowed_submission_mimes()
    {
        // Default allowed types: Office docs, images, selected design formats
        $allowed = [
            // Documents
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt'  => 'text/plain',
            'rtf'  => 'application/rtf',
            'csv'  => 'text/csv',
            // Images
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            // Design (limited set)
            'psd'  => 'image/vnd.adobe.photoshop',
            'tif'  => 'image/tiff',
            'tiff' => 'image/tiff',
        ];

        // Explicitly disallow compressed containers and risky types by not including them:
        // zip, rar, 7z, gz, tar, svg, exe, js, php, etc.
        return (array) apply_filters('aether_submission_allowed_mimes', $allowed);
    }

    /**
     * List submissions (admin only).
     */
    public function list_submissions($request)
    {
        $per_page = max(1, min(200, absint($request->get_param('per_page') ?: 50)));
        $paged = max(1, absint($request->get_param('paged') ?: 1));

        $query = new WP_Query([
            'post_type'      => Aether_Submissions_Service::POST_TYPE,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);

        $items = [];
        foreach ($query->posts as $pid) {
            $record = $this->load_submission_record($pid);
            $decoded = $record['decoded'];
            $context = $record['context'];
            $source_url = Aether_Submissions_Utils::resolve_source_url($context);
            $ip = isset($context['ip']) ? (string) $context['ip'] : '';
            $country = Aether_Submissions_Utils::get_country_from_context($context);
            $region = Aether_Submissions_Utils::get_region_from_context($context);
            $city = Aether_Submissions_Utils::get_city_from_context($context);
            $items[] = [
                'id'         => $pid,
                'date'       => get_post_field('post_date', $pid),
                'title'      => get_post_field('post_title', $pid),
                'form'       => get_post_meta($pid, '_aether_form_name', true) ?: '',
                'payload'    => $decoded['payload'] ?? new stdClass(),
                'context'    => $context ?: new stdClass(),
                'source_url' => $source_url,
                'ip'         => $ip,
                'country'    => $country,
                'region'     => $region,
                'city'       => $city,
                'location'   => Aether_Submissions_Utils::get_location_from_context($context),
            ];
        }

        return $this->success_response([
            'items'       => $items,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'per_page'    => $per_page,
            'paged'       => $paged,
        ]);
    }

    /**
     * Delete a submission by ID (admin only)
     */
    public function delete_submission($request)
    {
        $id = absint($request->get_param('id'));
        if (!$id) {
            return $this->error_response('无效的ID', 400);
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== Aether_Submissions_Service::POST_TYPE) {
            return $this->error_response('提交不存在', 404);
        }

        $result = wp_delete_post($id, true);
        if (!$result) {
            return $this->error_response('删除失败', 500);
        }

        return $this->success_response(['id' => $id], '删除成功');
    }

    /**
     * Send Webhook notification (fire-and-forget).
     *
     * @param array $data Notification data (post_id, payload, client_ip, etc.)
     * @param array $config Submissions settings
     */
    private function send_webhook_notification($data, $config)
    {
        $url = esc_url_raw($config['webhook_url'] ?? '');
        if (!$url) {
            return;
        }

        $post_id   = absint($data['post_id'] ?? 0);
        $payload   = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $form_name = isset($data['form_name']) ? (string) $data['form_name'] : '';

        // Separate attachments from fields
        $attachments = [];
        if (!empty($payload['attachments']) && is_array($payload['attachments'])) {
            foreach ($payload['attachments'] as $att) {
                $attachments[] = [
                    'filename' => isset($att['filename']) ? (string) $att['filename'] : '',
                    'url'      => isset($att['url']) ? (string) $att['url'] : '',
                ];
            }
            unset($payload['attachments']);
        }

        // Resolve source URL
        $source_url = Aether_Submissions_Utils::resolve_source_url([
            'hx_current' => $data['hx_current'] ?? '',
            'referer'    => $data['referer'] ?? '',
        ]);

        // Build JSON body
        $body = [
            'event'         => 'form_submission',
            'submission_id' => $post_id,
            'form_name'     => $form_name,
            'timestamp'     => gmdate('c'),
            'fields'        => $payload,
            'meta'          => [
                'ip'         => $data['client_ip'] ?? '',
                'country'    => $this->get_country_from_notification_data($data),
                'location'   => $this->get_location_from_notification_data($data),
                'source_url' => $source_url,
                'user_agent' => $data['user_agent'] ?? '',
            ],
        ];
        if (!empty($attachments)) {
            $body['attachments'] = $attachments;
        }

        // Build headers
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent'   => 'aether/' . AETHER_VERSION,
        ];

        // Custom auth header (both key and value must be set)
        $secret_key   = trim($config['webhook_secret_key'] ?? '');
        $secret_value = $config['webhook_secret'] ?? '';
        if ($secret_key !== '' && $secret_value !== '') {
            $headers[$secret_key] = $secret_value;
        }

        $response = wp_remote_post($url, [
            'headers'  => $headers,
            'body'     => wp_json_encode($body),
            'timeout'  => 5,
            'blocking' => false,
        ]);

        if (is_wp_error($response)) {
            error_log('Aether: Webhook POST failed: ' . $response->get_error_message() . ' [url:' . $url . ']');
        }
    }

    /**
     * Send Server酱 notification asynchronously
     * This method is called by WordPress cron/scheduled events
     *
     * @param array $data Notification data
     * @param array $config Plugin settings
     */
    public function send_serverchan_notification_async($data, $config)
    {
        // Double-check sendkey exists
        if (empty($data['sendkey'])) {
            return;
        }

        $api_base = 'https://sctapi.ftqq.com/' . rawurlencode($data['sendkey']) . '.send';
        $title = '收到一封询盘';

        // Build notification content (compact)
        $source_url = Aether_Submissions_Utils::resolve_source_url([
            'hx_current' => $data['hx_current'] ?? '',
            'referer' => $data['referer'] ?? '',
        ]);
        $country = $this->get_country_from_notification_data($data);

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        // Build attachments as Markdown links in one line
        $attach_line = '';
        if (!empty($payload['attachments']) && is_array($payload['attachments'])) {
            $links = [];
            foreach ($payload['attachments'] as $att) {
                $u = isset($att['url']) ? (string)$att['url'] : '';
                if (!$u) continue;
                $name = isset($att['filename']) ? (string)$att['filename'] : basename(parse_url($u, PHP_URL_PATH) ?: '');
                $links[] = '[' . $name . '](' . $u . ')';
            }
            if (!empty($links)) {
                $attach_line = 'Attachments: ' . implode(', ', $links);
            }
            unset($payload['attachments']);
        }

        // Flatten remaining payload into concise lines (limit and truncate)
        $flat = $this->flatten_payload($payload);
        // Drop empty values from flattened payload
        $flat = array_filter($flat, function($v){
            $s = (string)$v;
            return trim($s) !== '';
        });
        $field_lines = [];
        $max_lines = 12;
        foreach ($flat as $k => $v) {
            $val = (string) ($v === '' ? '(empty)' : $v);
            if (function_exists('mb_substr')) {
                $val = mb_substr($val, 0, 120);
            } else {
                $val = substr($val, 0, 120);
            }
            $field_lines[] = $k . ': ' . $val;
            if (count($field_lines) >= $max_lines) break;
        }

        $lines = [];
        $lines[] = 'Time: ' . ($data['timestamp'] ?? current_time('mysql'));
        if (!empty($source_url)) $lines[] = 'Source: ' . $source_url;
        if (!empty($data['client_ip'])) $lines[] = 'IP: ' . $data['client_ip'];
        if (!empty($country)) $lines[] = 'Country: ' . $country;
        $location = $this->get_location_from_notification_data($data);
        if (!empty($location)) $lines[] = 'Location: ' . $location;
        if (!empty($data['post_id'])) $lines[] = 'ID: ' . $data['post_id'];
        if ($attach_line) $lines[] = $attach_line;
        if (!empty($field_lines)) {
            $lines[] = '';
            $lines[] = 'Fields:';
            $lines = array_merge($lines, $field_lines);
        }
        
        $desp = implode("\n", $lines);
        
        // Truncate if too long
        if (strlen($desp) > 6000) {
            $desp = substr($desp, 0, 6000) . "\n... (截断)";
        }

        $url = add_query_arg([
            'title' => $title,
            'desp'  => $desp,
        ], $api_base);

        // Send the notification (this runs in background)
        wp_remote_get($url, [
            'timeout' => 10, // Can use longer timeout since it's async
            'blocking' => true, // Use blocking mode since we're already async
        ]);
    }

    /**
     * Send sales notifications via wp_mail and/or MailerSend asynchronously
     *
     * @param array $data Notification data
     * @param array $config Submissions settings
     */
    public function send_sales_notifications_async($data, $config)
    {
        $post_id = absint($data['post_id'] ?? 0);
        if (!$post_id) return;

        // Build recipients list from config, support comma or semicolon
        $recipients = [];
        $raw = isset($config['email_to']) ? (string) $config['email_to'] : '';
        if ($raw !== '') {
            $parts = preg_split('/[;,]+/', $raw);
            if (is_array($parts)) {
                foreach ($parts as $item) {
                    $email = sanitize_email(trim((string)$item));
                    if ($email && is_email($email)) {
                        $recipients[] = $email;
                    }
                }
            }
        }
        if (empty($recipients)) {
            $admin = get_option('admin_email');
            if ($admin && is_email($admin)) {
                $recipients[] = $admin;
            }
        }
        if (empty($recipients)) {
            // No valid recipients -> nothing to do
            return;
        }

        // Subject
        $site = get_bloginfo('name');
        $base_subject = isset($config['email_subject']) && is_string($config['email_subject']) && $config['email_subject'] !== ''
            ? sanitize_text_field($config['email_subject'])
            : '[' . $site . '] New Inquiry';
        $subject = $base_subject . ' #' . $post_id;

        // Build HTML and TEXT body
        $html = $this->build_sales_email_html($data, $site);
        $text = wp_strip_all_tags($html);

        // Optional: collect attachments for email if explicitly enabled by filter
        $mail_attachments = [];
        $attach_in_mail = (bool) apply_filters('aether_submission_email_attach_files', false, $data, $config);
        if ($attach_in_mail && is_array($data['payload'] ?? null)) {
            $max_each = 5 * MB_IN_BYTES;  // 5MB each
            $max_total = 10 * MB_IN_BYTES; // 10MB total
            $total = 0;
            $allowed_mimes = $this->get_allowed_submission_mimes();
            $atts = $data['payload']['attachments'] ?? [];
            if (is_array($atts)) {
                foreach ($atts as $item) {
                    $att_id = isset($item['id']) ? absint($item['id']) : 0;
                    if (!$att_id) continue;
                    $path = get_attached_file($att_id);
                    if (!$path || !file_exists($path)) continue;
                    $size = filesize($path);
                    if ($size <= 0 || $size > $max_each) continue;

                    // Restrict mime to allowed set
                    $mime = wp_check_filetype(wp_basename($path));
                    if (empty($mime['ext']) || !isset($allowed_mimes[$mime['ext']])) continue;

                    if ($total + $size > $max_total) break;
                    $mail_attachments[] = $path;
                    $total += $size;
                }
            }
        }

        // Send via wp_mail
        if (!empty($config['email_enabled'])) {
            // Duplicate guard
            $sent = get_post_meta($post_id, '_aether_sales_mail_sent_wp', true);
            if (!$sent) {
                $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

                // Detect and set Reply-To from submitted email field
                $reply_to = $this->extract_reply_to_email($data['payload'] ?? []);
                $reply_to = apply_filters('aether_submission_email_reply_to', $reply_to, $data, $config);
                if ($reply_to && is_email($reply_to)) {
                    $headers[] = 'Reply-To: ' . sanitize_email($reply_to);
                }

                // wp_mail accepts array recipients
                if (!empty($mail_attachments)) {
                    wp_mail($recipients, $subject, $html, $headers, $mail_attachments);
                } else {
                    wp_mail($recipients, $subject, $html, $headers);
                }
                update_post_meta($post_id, '_aether_sales_mail_sent_wp', 1);
            }
        }

        // MailerSend integration removed per request; keep only SMTP/wp_mail path
    }

    /**
     * Extract email address for Reply-To from payload
     * Searches common email field names in priority order
     *
     * @param array $payload Submission payload
     * @return string Email address or empty string
     */
    private function extract_reply_to_email($payload)
    {
        if (!is_array($payload)) {
            return '';
        }

        // Common email field names in priority order
        $email_fields = [
            'email',
            'user_email',
            'your_email',
            'contact_email',
            'customer_email',
            'reply_email',
            'sender_email',
            'from_email',
            'e-mail',
            'mail',
        ];

        // Try exact matches first
        foreach ($email_fields as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $email = trim($payload[$field]);
                if ($email && is_email($email)) {
                    return $email;
                }
            }
        }

        // Try case-insensitive search
        $payload_lower = array_change_key_case($payload, CASE_LOWER);
        foreach ($email_fields as $field) {
            if (isset($payload_lower[$field]) && is_string($payload_lower[$field])) {
                $email = trim($payload_lower[$field]);
                if ($email && is_email($email)) {
                    return $email;
                }
            }
        }

        // Fallback: scan for any field containing 'email' or 'mail' that has valid email value
        foreach ($payload as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            $key_lower = strtolower($key);
            if ((strpos($key_lower, 'email') !== false || strpos($key_lower, 'mail') !== false)) {
                $email = trim($value);
                if ($email && is_email($email)) {
                    return $email;
                }
            }
        }

        return '';
    }

    /**
     * Build HTML table email body with meta and flattened payload
     */
    private function build_sales_email_html($data, $site)
    {
        $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];
        $context = [
            'timestamp' => $data['timestamp'] ?? current_time('mysql'),
            'source'    => Aether_Submissions_Utils::resolve_source_url([
                'hx_current' => $data['hx_current'] ?? '',
                'referer'    => $data['referer'] ?? '',
            ]),
            'ip'        => $data['client_ip'] ?? '',
            'country'   => $this->get_country_from_notification_data($data),
            'location'  => $this->get_location_from_notification_data($data),
            'site'      => $site,
            'form'      => isset($data['form_name']) ? (string)$data['form_name'] : '',
            'id'        => isset($data['post_id']) ? (string)$data['post_id'] : '',
        ];

        // Special-case attachments: collapse into one cell with links
        $attachments_html = '';
        if (!empty($payload['attachments']) && is_array($payload['attachments'])) {
            $links = [];
            foreach ($payload['attachments'] as $att) {
                $url = isset($att['url']) ? esc_url($att['url']) : '';
                $name = isset($att['filename']) ? sanitize_text_field((string)$att['filename']) : basename(parse_url($url, PHP_URL_PATH) ?: '');
                if ($url) {
                    $links[] = '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html($name ?: $url) . '</a>';
                }
            }
            if (!empty($links)) {
                $attachments_html = implode(', ', $links);
            }
            // Remove attachments from payload before flattening to avoid verbose rows
            unset($payload['attachments']);
        }

        $flat = $this->flatten_payload($payload);

        ob_start();
        ?>
        <div style="font-family: -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif; font-size:14px; color:#111;">
          <h2 style="margin:0 0 12px; font-size:16px;">New Inquiry</h2>
          <table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse; width:100%;">
            <thead>
              <tr style="background:#f9fafb">
                <th align="left" style="border:1px solid #f1f5f9;">Field</th>
                <th align="left" style="border:1px solid #f1f5f9;">Value</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($flat as $k => $v): ?>
                <tr>
                  <td style="vertical-align:top; border:1px solid #f1f5f9;"><code><?php echo esc_html($k); ?></code></td>
                  <td style="vertical-align:top; border:1px solid #f1f5f9;"><?php echo nl2br(esc_html((string)$v)); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($attachments_html): ?>
                <tr>
                  <td style="vertical-align:top; border:1px solid #f1f5f9;"><code>attachments</code></td>
                  <td style="vertical-align:top; border:1px solid #f1f5f9;">
                    <?php echo wp_kses($attachments_html, ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>

          <h3 style="margin:14px 0 6px; font-size:13px; color:#6b7280;">Meta</h3>
          <table cellpadding="4" cellspacing="0" border="0" style="border-collapse:collapse; width:100%; font-size:12px; color:#6b7280;">
            <tbody>
              <tr><td>Time</td><td><?php echo esc_html($context['timestamp']); ?></td></tr>
              <?php if (!empty($context['source'])): ?>
              <tr><td>Source</td><td><a href="<?php echo esc_url($context['source']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($context['source']); ?></a></td></tr>
              <?php endif; ?>
              <?php if (!empty($context['ip'])): ?>
              <tr><td>IP</td><td><?php echo esc_html($context['ip']); ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($context['country'])): ?>
              <tr><td>Country</td><td><?php echo esc_html($context['country']); ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($context['location'])): ?>
              <tr><td>Location</td><td><?php echo esc_html($context['location']); ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($context['form'])): ?><tr><td>Form</td><td><?php echo esc_html($context['form']); ?></td></tr><?php endif; ?>
              <?php if (!empty($context['id'])): ?><tr><td>ID</td><td>#<?php echo esc_html($context['id']); ?></td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php
        $out = ob_get_clean();

        // Truncate if too long
        if (strlen($out) > 6000) {
            $out = substr($out, 0, 6000) . '\n<!-- truncated -->';
        }
        return $out;
    }

    /**
     * Flatten payload to key => scalar string map
     */
    private function flatten_payload($data, $prefix = '')
    {
        $result = [];
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $k = is_int($key) ? '[' . $key . ']' : ($prefix ? $prefix . '.' . $key : (string)$key);
                if ($prefix && is_int($key)) {
                    $k = $prefix . $k; // prefix + [index]
                }
                if (is_array($value)) {
                    $result += $this->flatten_payload($value, $k);
                } else {
                    if (is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    } elseif (is_null($value)) {
                        $value = '';
                    } elseif (is_object($value)) {
                        $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    $result[$k] = (string)$value;
                }
            }
        }
        return $result;
    }

    /**
     * Export submissions as CSV (admin only)
     */
    public function export_csv($request)
    {
        // Fetch all submissions (simple version)
        $query = new WP_Query([
            'post_type'      => Aether_Submissions_Service::POST_TYPE,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ]);

        // Prepare CSV output
        $filename = 'aether-submissions-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header row
        fputcsv($out, ['id', 'date', 'form', 'ip', 'location', 'source_url', 'payload_json']);

        foreach ($query->posts as $pid) {
            $record = $this->load_submission_record($pid);
            $payload = $record['decoded']['payload'] ?? [];
            $context = $record['context'];

            $ip = $context['ip'] ?? '';
            $location = Aether_Submissions_Utils::get_location_from_context($context);
            $source_url = Aether_Submissions_Utils::resolve_source_url($context);
            $row = [
                $pid,
                get_post_field('post_date', $pid),
                get_post_meta($pid, '_aether_form_name', true) ?: '',
                $ip,
                $location,
                $source_url,
                wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            ];
            fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /**
     * Run a single automatic geo backfill tick.
     *
     * @return WP_REST_Response
     */
    public function run_geo_backfill_tick($request)
    {
        return $this->success_response(Aether_Submission_Geo_Backfill_Service::run_tick());
    }

    /**
     * Read current automatic geo backfill state.
     *
     * @return WP_REST_Response
     */
    public function get_geo_backfill_state($request)
    {
        return $this->success_response(Aether_Submission_Geo_Backfill_Service::get_state_response());
    }

    private function build_submission_response_config($data, $subs_cfg)
    {
        $cfg_redirect = '';
        if (isset($data['_aether_redirect']) && is_string($data['_aether_redirect'])) {
            $tmp = esc_url_raw($data['_aether_redirect']);
            if ($tmp && Aether_Submissions_Utils::is_same_origin('', $tmp)) {
                $cfg_redirect = $tmp;
            }
        }

        $cfg_success_html = '';
        if (isset($data['_aether_success_html']) && is_string($data['_aether_success_html'])) {
            $allowed_html = wp_kses_allowed_html('post');
            $allowed_html['button']['onclick'] = true;
            $allowed_html['div']['onclick'] = true;
            $allowed_html['a']['onclick'] = true;
            $allowed_html['span']['onclick'] = true;
            $allowed_html['button']['data-action'] = true;
            $allowed_html['div']['data-action'] = true;
            $allowed_html['svg'] = array(
                'class' => true,
                'fill' => true,
                'stroke' => true,
                'viewbox' => true,
                'width' => true,
                'height' => true,
                'xmlns' => true,
            );
            $allowed_html['path'] = array(
                'd' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
                'fill-rule' => true,
                'clip-rule' => true,
            );
            $allowed_html['script'] = array();
            $cfg_success_html = wp_kses($data['_aether_success_html'], $allowed_html);
        }

        $cfg_refresh = false;
        if (isset($data['_aether_refresh'])) {
            $val = $data['_aether_refresh'];
            $cfg_refresh = ($val === '1' || $val === 1 || strtolower((string) $val) === 'true' || strtolower((string) $val) === 'yes');
        }

        return [
            'redirect' => $cfg_redirect,
            'success_html' => $cfg_success_html,
            'refresh' => $cfg_refresh,
            'thankyou' => isset($subs_cfg['thankyou_url']) ? esc_url_raw($subs_cfg['thankyou_url']) : '',
        ];
    }

    private function respond_submission_success($headers, $request, $response_config, $data = [])
    {
        if ($this->is_htmx_request($headers, $request)) {
            if (!empty($response_config['redirect'])) {
                header('HX-Redirect: ' . $response_config['redirect']);
                exit;
            }
            if (!empty($response_config['thankyou'])) {
                header('HX-Redirect: ' . $response_config['thankyou']);
                exit;
            }
            if (!empty($response_config['refresh'])) {
                header('HX-Refresh: true');
                exit;
            }

            header('Content-Type: text/html; charset=UTF-8');
            echo !empty($response_config['success_html'])
                ? $response_config['success_html']
                : '<div class="aether-htmx-success" style="color:#16a34a;">提交成功</div>';
            exit;
        }

        $payload = is_array($data) ? $data : [];
        if (!empty($response_config['thankyou'])) {
            $payload['redirect'] = $response_config['thankyou'];
        }

        return $this->success_response($payload, '提交成功', 201);
    }

    private function is_htmx_request($headers, $request = null)
    {
        $hx_req = $headers['hx-request'] ?? '';
        if (!$hx_req && $request) {
            $hx_req = $request->get_header('hx-request');
        }
        if (is_array($hx_req)) {
            $hx_req = $hx_req[0] ?? '';
        }
        if (!$hx_req && isset($_SERVER['HTTP_HX_REQUEST'])) {
            $hx_req = $_SERVER['HTTP_HX_REQUEST'];
        }

        return strtolower((string) $hx_req) === 'true';
    }

    private function log_duplicate_submission_event($event, $fingerprint, $source, $client_ip, $post_id = 0)
    {
        $source_path = '';
        if ($source !== '') {
            $source_path = (string) (wp_parse_url($source, PHP_URL_PATH) ?: '');
        }

        error_log(sprintf(
            'Aether submission %s key=%s post_id=%d source=%s ip=%s',
            sanitize_key($event),
            substr((string) $fingerprint, 0, 12),
            max(0, (int) $post_id),
            $source_path !== '' ? $source_path : '-',
            $client_ip !== '' ? substr(md5((string) $client_ip), 0, 8) : '-'
        ));
    }

    /**
     * Get stored submission context for notification payloads.
     *
     * @param int $post_id
     * @return array
     */
    private function get_submission_context_by_id($post_id)
    {
        return $this->load_submission_record($post_id)['context'];
    }

    /**
     * Load a submission record and opportunistically repair cached geo only.
     *
     * @param int $post_id
     * @return array{decoded: array, context: array}
     */
    private function load_submission_record($post_id)
    {
        $post_id = absint($post_id);
        if (!$post_id) {
            return [
                'decoded' => [],
                'context' => [],
            ];
        }

        $repair = Aether_Submission_Geo_Repair_Service::repair_submission_by_id($post_id, [
            'allow_live_lookup' => false,
        ]);

        $decoded = is_array($repair['decoded'] ?? null) ? $repair['decoded'] : [];
        $context = isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [];

        return [
            'decoded' => $decoded,
            'context' => $context,
        ];
    }

    /**
     * Resolve notification country from persisted geo snapshot.
     *
     * @param array $data
     * @return string
     */
    private function get_country_from_notification_data($data)
    {
        $context = [];
        if (is_array($data) && isset($data['geo']) && is_array($data['geo'])) {
            $context['geo'] = $data['geo'];
        }

        if (Aether_Submissions_Utils::get_country_from_context($context) === '' && !empty($data['post_id'])) {
            $context = $this->get_submission_context_by_id($data['post_id']);
        }

        return Aether_Submissions_Utils::get_country_from_context($context);
    }

    /**
     * Resolve notification location from persisted geo snapshot.
     *
     * @param array $data
     * @return string
     */
    private function get_location_from_notification_data($data)
    {
        $context = [];
        if (is_array($data) && isset($data['geo']) && is_array($data['geo'])) {
            $context['geo'] = $data['geo'];
        }

        if (Aether_Submissions_Utils::get_location_from_context($context) === '' && !empty($data['post_id'])) {
            $context = $this->get_submission_context_by_id($data['post_id']);
        }

        return Aether_Submissions_Utils::get_location_from_context($context);
    }

    /**
     * Silently reject bot submission with fake success response
     * Returns generic success to avoid revealing defense mechanisms
     *
     * @param array $headers Request headers
     * @return WP_REST_Response|void
     */
    private function silent_reject_bot($headers, $request = null)
    {
        // Return appropriate fake success response
        if ($this->is_htmx_request($headers, $request)) {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<div class="aether-htmx-success" style="color:#16a34a;">提交成功</div>';
            exit;
        }

        return $this->success_response(['ignored' => true], '提交成功', 201);
    }
}
