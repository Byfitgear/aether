<?php
/**
 * Admin Notifications Display
 *
 * Handles displaying remote notifications in WP Admin
 *
 * @package zeroy
 */

defined('ABSPATH') || exit;

class ZeroY_Admin_Notifications {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Add admin notices - capability check is done inside the callback
        add_action('admin_notices', [$this, 'display_admin_notices']);

        // Enqueue dismiss script
        add_action('admin_enqueue_scripts', [$this, 'enqueue_dismiss_script']);
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        // Only show to users who can edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }

        // Only show on specific pages
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Show on: dashboard, plugins, updates
        // Note: zeroy settings page uses React component for notifications
        $allowed_screens = [
            'dashboard',
            'plugins',
            'update-core',
        ];

        if (!in_array($screen->id, $allowed_screens, true)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[ZeroY Admin Notifications] Screen not allowed: ' . $screen->id);
            }
            return;
        }

        $service = ZeroY_Remote_Notifications_Service::getInstance();
        $notifications = $service->get_notifications();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[ZeroY Admin Notifications] Got ' . count($notifications) . ' notifications');
            error_log('[ZeroY Admin Notifications] Notifications: ' . wp_json_encode($notifications));
        }

        if (empty($notifications)) {
            return;
        }

        // Only show high priority notifications in WP admin notices
        // (Other notifications will be shown in zeroy settings/editor)
        $high_priority_count = 0;
        foreach ($notifications as $notification) {
            if (($notification['priority'] ?? 'normal') !== 'high') {
                continue;
            }

            $high_priority_count++;
            $this->render_notice($notification);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[ZeroY Admin Notifications] Rendered ' . $high_priority_count . ' high priority notifications');
        }
    }

    /**
     * Render a single notice
     *
     * @param array $notification
     */
    private function render_notice($notification) {
        $id = esc_attr($notification['id'] ?? '');
        $title = esc_html($notification['title'] ?? '');
        $message = wp_kses_post($notification['message'] ?? '');
        $priority = $notification['priority'] ?? 'normal';

        // High priority uses warning style, others use info
        $notice_type = $priority === 'high' ? 'warning' : 'info';

        ?>
        <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible zeroy-remote-notice"
             data-notification-id="<?php echo $id; ?>">
            <p>
                <?php if ($title): ?>
                    <strong>[zeroy] <?php echo $title; ?></strong>
                    <br>
                <?php else: ?>
                    <strong>[zeroy]</strong>
                <?php endif; ?>
                <?php echo $message; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Enqueue dismiss script
     */
    public function enqueue_dismiss_script() {
        // Only show to users who can edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Only enqueue on pages where we show notices
        $allowed_screens = [
            'dashboard',
            'plugins',
            'update-core',
        ];

        if (!in_array($screen->id, $allowed_screens, true)) {
            return;
        }

        // Inline script for dismissing notifications
        $script = "
        jQuery(document).on('click', '.zeroy-remote-notice .notice-dismiss', function() {
            var notice = jQuery(this).closest('.zeroy-remote-notice');
            var notificationId = notice.data('notification-id');

            if (!notificationId) return;

            jQuery.post(ajaxurl, {
                action: 'zeroy_dismiss_notification',
                notification_id: notificationId,
                nonce: '" . wp_create_nonce('zeroy-editor') . "'
            });
        });
        ";

        wp_add_inline_script('jquery', $script);
    }
}

// Initialize
ZeroY_Admin_Notifications::getInstance();
