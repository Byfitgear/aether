<?php
/**
 * Submission Geo Frontend Service
 *
 * Enqueues a tiny frontend runtime that attaches geo hints to Aether submission forms.
 */

defined('ABSPATH') || exit;

class Aether_Submission_Geo_Frontend_Service extends Aether_Base
{
    /**
     * Initialize hooks.
     *
     * @return void
     */
    protected function init()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue the frontend runtime and localize its boundaries.
     *
     * @return void
     */
    public function enqueue_assets()
    {
        $vite_loader = Aether_Vite_Loader::getInstance();
        $vite_loader->enqueue('frontend');

        wp_localize_script('aether-frontend', 'aetherFrontendConfig', [
            'submissionEndpoints' => [
                untrailingslashit(rest_url('aether/v1/submissions')),
                add_query_arg('rest_route', '/aether/v1/submissions', home_url('/')),
            ],
            'geoHintUrl' => apply_filters(
                'aether_submission_geo_hint_url',
                'https://ipapi.co/json/'
            ),
            'geoHintTimeoutMs' => max(
                0,
                (int) apply_filters('aether_submission_geo_hint_timeout_ms', 1500)
            ),
        ]);
    }
}
