<?php
/**
 * API Routes - Notifications
 *
 * REST API endpoints for remote notifications
 *
 * @package aether
 */

defined('ABSPATH') || exit;

class Aether_API_Routes_Notifications extends Aether_API_Routes_Base {

    /**
     * Register routes
     */
    public function register_routes() {
        // GET /notifications - Get all active notifications
        $this->register_route('/notifications', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_notifications'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // POST /notifications/dismiss - Dismiss a notification
        $this->register_route('/notifications/dismiss', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'dismiss_notification'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'notification_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // POST /notifications/refresh - Force refresh notifications cache
        $this->register_route('/notifications/refresh', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'refresh_notifications'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }

    /**
     * Get notifications
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_notifications($request) {
        $service = Aether_Remote_Notifications_Service::getInstance();
        $data = $service->get_notifications_for_api();

        return $this->success_response($data);
    }

    /**
     * Dismiss a notification
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function dismiss_notification($request) {
        $notification_id = $request->get_param('notification_id');

        if (empty($notification_id)) {
            return $this->error_response('Missing notification ID', 400);
        }

        $service = Aether_Remote_Notifications_Service::getInstance();
        $result = $service->dismiss_notification($notification_id);

        if ($result) {
            return $this->success_response(['dismissed' => true]);
        }

        return $this->error_response('Failed to dismiss notification', 500);
    }

    /**
     * Force refresh notifications cache
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function refresh_notifications($request) {
        $service = Aether_Remote_Notifications_Service::getInstance();

        // Clear cache first
        $service->clear_cache();

        // Fetch fresh notifications
        $data = $service->get_notifications_for_api();

        return $this->success_response($data, 'Notifications refreshed');
    }

    /**
     * Check if user has admin permission
     *
     * @return bool
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }
}
