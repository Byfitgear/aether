<?php
/**
 * Submission Geo Backfill Service
 *
 * Incrementally repairs legacy submissions missing persisted geo snapshots.
 */

defined('ABSPATH') || exit;

class Aether_Submission_Geo_Backfill_Service
{
    const OPTION_NAME = 'aether_submission_geo_backfill_state';
    const LOCK_KEY = 'aether_submission_geo_backfill_lock';
    const CRON_HOOK = 'aether_submission_geo_backfill_tick';
    const SCAN_LIMIT = 20;
    const MAX_LIVE_LOOKUPS = 1;
    const DEFAULT_DELAY_SECONDS = 60;
    const LOCK_TTL = 30;
    const FALLBACK_RETRY_AFTER = 300;

    /**
     * Register cron hook.
     *
     * @return void
     */
    public static function init()
    {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled_tick']);
        add_action('admin_init', [__CLASS__, 'ensure_scheduled']);
    }

    /**
     * Initialize backfill state for legacy upgrades.
     *
     * @return array
     */
    public static function initialize_for_upgrade()
    {
        $upper_bound_id = self::query_current_upper_bound_id();
        $state = self::default_state();
        $state['upper_bound_id'] = $upper_bound_id;
        $state['next_before_id'] = $upper_bound_id > 0 ? $upper_bound_id + 1 : 0;
        $state['initialized_at'] = current_time('mysql');
        $state['status'] = $upper_bound_id > 0 ? 'idle' : 'completed';

        if ($upper_bound_id <= 0) {
            $state['completed_at'] = current_time('mysql');
            self::clear_scheduled_tick();
        } else {
            self::schedule_next_tick(1, true);
        }

        self::save_state($state);
        self::log_event('migration_initialized', [
            'upper_bound_id' => $upper_bound_id,
            'scheduled'      => $upper_bound_id > 0 ? 1 : 0,
        ]);

        return self::decorate_state($state);
    }

    /**
     * Expose current state for admin diagnostics.
     *
     * @return array
     */
    public static function get_state_response()
    {
        return self::decorate_state(self::bootstrap_state(self::get_state()));
    }

    /**
     * Re-arm cron when backfill is active but the scheduled event was lost.
     *
     * @return void
     */
    public static function ensure_scheduled()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $state = self::bootstrap_state(self::get_state());
        if (($state['status'] ?? '') === 'completed' || (int) ($state['upper_bound_id'] ?? 0) <= 0) {
            return;
        }

        if (wp_next_scheduled(self::CRON_HOOK) !== false) {
            return;
        }

        $now = time();
        $retry_after_until = (int) ($state['retry_after_until'] ?? 0);
        $delay = $retry_after_until > $now
            ? max(1, $retry_after_until - $now)
            : 1;

        self::schedule_next_tick($delay, false);
        self::log_event('schedule_rearmed', [
            'status'            => (string) ($state['status'] ?? 'idle'),
            'retry_after_until' => $retry_after_until,
            'delay'             => $delay,
        ]);
    }

    /**
     * Cron entrypoint.
     *
     * @return void
     */
    public static function run_scheduled_tick()
    {
        self::run_tick();
    }

    /**
     * Run a single incremental repair tick.
     *
     * @return array
     */
    public static function run_tick()
    {
        $state = self::bootstrap_state(self::get_state());
        $now = time();

        if (($state['status'] ?? '') === 'completed') {
            self::clear_scheduled_tick();
            return self::build_response($state, [
                'completed' => true,
            ]);
        }

        $retry_after_until = (int) ($state['retry_after_until'] ?? 0);
        if ($retry_after_until > $now) {
            $delay = max(1, $retry_after_until - $now);
            self::schedule_next_tick($delay, true);
            return self::build_response($state, [
                'status' => 'paused',
                'retry_after' => $delay,
            ]);
        }

        if (!self::acquire_lock()) {
            return self::build_response($state, [
                'status' => 'busy',
                'retry_after' => self::LOCK_TTL,
            ]);
        }

        try {
            return self::process_tick(self::bootstrap_state(self::get_state()));
        } finally {
            self::release_lock();
        }
    }

    /**
     * @param array $state
     * @return array
     */
    private static function process_tick($state)
    {
        $state = self::bootstrap_state($state);
        $post_ids = self::query_slice(
            (int) $state['upper_bound_id'],
            (int) $state['next_before_id']
        );

        self::log_event('tick_start', [
            'upper_bound_id' => (int) $state['upper_bound_id'],
            'next_before_id' => (int) $state['next_before_id'],
            'batch_size'     => count($post_ids),
        ]);

        if (empty($post_ids)) {
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql');
            $state['last_run_at'] = current_time('mysql');
            self::save_state($state);
            self::clear_scheduled_tick();
            self::log_event('tick_complete', [
                'status'         => 'completed',
                'upper_bound_id' => (int) $state['upper_bound_id'],
                'next_before_id' => (int) $state['next_before_id'],
            ]);

            return self::build_response($state, [
                'status' => 'completed',
                'completed' => true,
                'scanned' => 0,
                'updated' => 0,
                'skipped' => 0,
                'unresolved' => 0,
                'live_lookups' => 0,
                'cache_hits' => 0,
            ]);
        }

        $tick = [
            'scanned' => 0,
            'updated' => 0,
            'skipped' => 0,
            'unresolved' => 0,
            'live_lookups' => 0,
            'cache_hits' => 0,
            'retry_after' => 0,
            'status' => 'running',
            'completed' => false,
        ];
        $deferred = false;

        foreach ($post_ids as $post_id) {
            $allow_live_lookup = $tick['live_lookups'] < self::MAX_LIVE_LOOKUPS;
            $repair = Aether_Submission_Geo_Repair_Service::repair_submission_by_id($post_id, [
                'allow_live_lookup' => $allow_live_lookup,
            ]);

            $tick['scanned']++;

            if (!empty($repair['used_live_lookup'])) {
                $tick['live_lookups']++;
            }

            if (!empty($repair['used_cache'])) {
                $tick['cache_hits']++;
            }

            $reason = (string) ($repair['reason'] ?? '');
            $status = (string) ($repair['status'] ?? 'unresolved');

            if ($status === 'paused' && $reason === 'rate_limited') {
                $tick['status'] = 'paused';
                $tick['retry_after'] = max(
                    self::FALLBACK_RETRY_AFTER,
                    (int) ($repair['retry_after'] ?? 0)
                );
                self::log_event('tick_paused', [
                    'post_id'      => (int) $post_id,
                    'retry_after'  => $tick['retry_after'],
                    'live_lookups' => $tick['live_lookups'],
                ]);
                break;
            }

            if ($status === 'unresolved' && $reason === 'cache_miss' && !$allow_live_lookup) {
                $deferred = true;
                self::log_event('tick_deferred', [
                    'post_id'      => (int) $post_id,
                    'live_lookups' => $tick['live_lookups'],
                ]);
                break;
            }

            if ($status === 'updated') {
                $tick['updated']++;
            } elseif ($status === 'skipped') {
                $tick['skipped']++;
            } else {
                $tick['unresolved']++;
            }

            $state['next_before_id'] = (int) $post_id;

            self::log_event('tick_post', [
                'post_id'     => (int) $post_id,
                'status'      => $status,
                'reason'      => $reason,
                'ip_valid'    => !empty($repair['ip_valid']) ? 1 : 0,
                'geo_source'  => (string) (($repair['geo']['source'] ?? '') ?: ''),
                'used_cache'  => !empty($repair['used_cache']) ? 1 : 0,
                'used_live'   => !empty($repair['used_live_lookup']) ? 1 : 0,
            ]);
        }

        $state['status'] = $tick['status'] === 'paused' ? 'paused' : 'running';
        $state['retry_after_until'] = 0;
        $state['updated_total'] = (int) ($state['updated_total'] ?? 0) + $tick['updated'];
        $state['skipped_total'] = (int) ($state['skipped_total'] ?? 0) + $tick['skipped'];
        $state['unresolved_total'] = (int) ($state['unresolved_total'] ?? 0) + $tick['unresolved'];
        $state['live_lookup_total'] = (int) ($state['live_lookup_total'] ?? 0) + $tick['live_lookups'];
        $state['cache_hit_total'] = (int) ($state['cache_hit_total'] ?? 0) + $tick['cache_hits'];
        $state['scanned_total'] = (int) ($state['scanned_total'] ?? 0) + $tick['scanned'];
        $state['last_run_at'] = current_time('mysql');

        if ($tick['status'] === 'paused') {
            $state['retry_after_until'] = time() + $tick['retry_after'];
            self::save_state($state);
            self::schedule_next_tick($tick['retry_after'], true);

            return self::build_response($state, [
                'status' => 'paused',
                'retry_after' => $tick['retry_after'],
                'scanned' => $tick['scanned'],
                'updated' => $tick['updated'],
                'skipped' => $tick['skipped'],
                'unresolved' => $tick['unresolved'],
                'live_lookups' => $tick['live_lookups'],
                'cache_hits' => $tick['cache_hits'],
            ]);
        }

        if (!$deferred && count($post_ids) < self::SCAN_LIMIT) {
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql');
            $tick['completed'] = true;
            self::clear_scheduled_tick();
        } else {
            self::schedule_next_tick(self::DEFAULT_DELAY_SECONDS, true);
        }

        self::save_state($state);

        self::log_event('tick_complete', [
            'status'         => $state['status'],
            'next_before_id' => (int) $state['next_before_id'],
            'scanned'        => $tick['scanned'],
            'updated'        => $tick['updated'],
            'skipped'        => $tick['skipped'],
            'unresolved'     => $tick['unresolved'],
            'live_lookups'   => $tick['live_lookups'],
            'cache_hits'     => $tick['cache_hits'],
        ]);

        return self::build_response($state, [
            'status' => $state['status'],
            'completed' => $tick['completed'],
            'retry_after' => 0,
            'scanned' => $tick['scanned'],
            'updated' => $tick['updated'],
            'skipped' => $tick['skipped'],
            'unresolved' => $tick['unresolved'],
            'live_lookups' => $tick['live_lookups'],
            'cache_hits' => $tick['cache_hits'],
        ]);
    }

    /**
     * @param array $state
     * @param array $tick
     * @return array
     */
    private static function build_response($state, $tick)
    {
        $response = [
            'status' => $tick['status'] ?? ($state['status'] ?? 'idle'),
            'completed' => !empty($tick['completed']),
            'upper_bound_id' => (int) ($state['upper_bound_id'] ?? 0),
            'next_before_id' => (int) ($state['next_before_id'] ?? 0),
            'retry_after' => (int) ($tick['retry_after'] ?? 0),
            'scanned' => (int) ($tick['scanned'] ?? 0),
            'updated' => (int) ($tick['updated'] ?? 0),
            'skipped' => (int) ($tick['skipped'] ?? 0),
            'unresolved' => (int) ($tick['unresolved'] ?? 0),
            'live_lookups' => (int) ($tick['live_lookups'] ?? 0),
            'cache_hits' => (int) ($tick['cache_hits'] ?? 0),
            'updated_total' => (int) ($state['updated_total'] ?? 0),
            'skipped_total' => (int) ($state['skipped_total'] ?? 0),
            'unresolved_total' => (int) ($state['unresolved_total'] ?? 0),
            'live_lookup_total' => (int) ($state['live_lookup_total'] ?? 0),
            'cache_hit_total' => (int) ($state['cache_hit_total'] ?? 0),
            'scanned_total' => (int) ($state['scanned_total'] ?? 0),
            'last_run_at' => (string) ($state['last_run_at'] ?? ''),
            'completed_at' => (string) ($state['completed_at'] ?? ''),
        ];

        return self::decorate_state($response);
    }

    /**
     * @param array $state
     * @return array
     */
    private static function decorate_state($state)
    {
        $scheduled_at = wp_next_scheduled(self::CRON_HOOK);
        $state['scheduled'] = $scheduled_at !== false;
        $state['scheduled_at'] = $scheduled_at !== false ? (int) $scheduled_at : 0;
        return $state;
    }

    /**
     * @return array
     */
    private static function get_state()
    {
        $state = get_option(self::OPTION_NAME, []);
        if (!is_array($state)) {
            $state = [];
        }

        return wp_parse_args($state, self::default_state());
    }

    /**
     * @return array
     */
    private static function default_state()
    {
        return [
            'status' => 'idle',
            'upper_bound_id' => 0,
            'next_before_id' => 0,
            'retry_after_until' => 0,
            'updated_total' => 0,
            'skipped_total' => 0,
            'unresolved_total' => 0,
            'live_lookup_total' => 0,
            'cache_hit_total' => 0,
            'scanned_total' => 0,
            'initialized_at' => '',
            'last_run_at' => '',
            'completed_at' => '',
        ];
    }

    /**
     * @param array $state
     * @return array
     */
    private static function bootstrap_state($state)
    {
        $state = wp_parse_args($state, self::default_state());

        if ((int) $state['upper_bound_id'] > 0 || ($state['status'] ?? '') === 'completed') {
            return $state;
        }

        $upper_bound_id = self::query_current_upper_bound_id();
        $state['upper_bound_id'] = $upper_bound_id;
        $state['next_before_id'] = $upper_bound_id > 0 ? $upper_bound_id + 1 : 0;
        $state['initialized_at'] = $state['initialized_at'] ?: current_time('mysql');

        if ($upper_bound_id <= 0) {
            $state['status'] = 'completed';
            $state['completed_at'] = $state['completed_at'] ?: current_time('mysql');
        }

        self::save_state($state);
        return $state;
    }

    /**
     * @param array $state
     * @return void
     */
    private static function save_state($state)
    {
        update_option(self::OPTION_NAME, $state, false);
    }

    /**
     * @param int $delay_seconds
     * @param bool $force
     * @return void
     */
    private static function schedule_next_tick($delay_seconds, $force = false)
    {
        if ($force) {
            self::clear_scheduled_tick();
        } elseif (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        wp_schedule_single_event(time() + max(1, (int) $delay_seconds), self::CRON_HOOK);
    }

    /**
     * @return void
     */
    private static function clear_scheduled_tick()
    {
        while (($timestamp = wp_next_scheduled(self::CRON_HOOK)) !== false) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * @return bool
     */
    private static function acquire_lock()
    {
        if (get_transient(self::LOCK_KEY)) {
            return false;
        }

        return set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);
    }

    /**
     * @return void
     */
    private static function release_lock()
    {
        delete_transient(self::LOCK_KEY);
    }

    /**
     * @return int
     */
    private static function query_current_upper_bound_id()
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            Aether_Submissions_Service::POST_TYPE
        );

        return max(0, absint($wpdb->get_var($sql)));
    }

    /**
     * @param int $upper_bound_id
     * @param int $next_before_id
     * @return int[]
     */
    private static function query_slice($upper_bound_id, $next_before_id)
    {
        global $wpdb;

        $upper_bound_id = max(0, (int) $upper_bound_id);
        if ($upper_bound_id <= 0) {
            return [];
        }

        $next_before_id = max(1, (int) $next_before_id);
        if ($next_before_id <= 1) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT ID
            FROM {$wpdb->posts}
            WHERE post_type = %s
              AND post_status = 'publish'
              AND ID <= %d
              AND ID < %d
            ORDER BY ID DESC
            LIMIT %d",
            Aether_Submissions_Service::POST_TYPE,
            $upper_bound_id,
            $next_before_id,
            self::SCAN_LIMIT
        );

        return array_map('absint', $wpdb->get_col($sql));
    }

    /**
     * @param string $event
     * @param array $context
     * @return void
     */
    private static function log_event($event, $context = [])
    {
        $parts = ['Aether submission geo backfill event=' . self::sanitize_log_value($event)];

        foreach ($context as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }

            $parts[] = sanitize_key((string) $key) . '=' . self::sanitize_log_value($value);
        }

        error_log(implode(' ', $parts));
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function sanitize_log_value($value)
    {
        if (is_scalar($value)) {
            return preg_replace('/\s+/', '_', (string) $value);
        }

        return 'n/a';
    }
}
