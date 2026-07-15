<?php
/**
 * Auth API Routes
 *
 * Provides endpoints related to authentication helpers (e.g., nonce refresh)
 *
 * @package aether
 */

defined('ABSPATH') || exit;

/**
 * Auth API Routes Class
 */
class Aether_API_Routes_Auth extends Aether_API_Routes_Base {

    /**
     * Register routes
     */
    public function register_routes() {
        // Refresh REST nonce for logged-in users
        $this->register_route('/auth/nonce', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_rest_nonce'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
        ]);
    }

    /**
     * Return a fresh REST API nonce
     */
    public function get_rest_nonce($request) {
        return $this->success_response([
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}

