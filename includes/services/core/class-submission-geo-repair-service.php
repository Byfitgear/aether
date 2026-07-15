<?php
/**
 * Submission Geo Repair Service
 *
 * Repairs legacy submissions that only persisted IP context.
 */

defined('ABSPATH') || exit;

class Aether_Submission_Geo_Repair_Service
{
    /**
     * Repair a single submission's persisted geo snapshot.
     *
     * @param int $post_id
     * @param array $options
     * @return array
     */
    public static function repair_submission_by_id($post_id, $options = [])
    {
        $options = wp_parse_args($options, [
            'allow_live_lookup' => false,
            'resolved_geo'      => [],
        ]);

        $post_id = absint($post_id);
        $result = [
            'post_id'           => $post_id,
            'status'            => 'unresolved',
            'reason'            => 'invalid_post_id',
            'decoded'           => null,
            'context'           => [],
            'geo'               => [],
            'ip'                => '',
            'ip_valid'          => false,
            'used_cache'        => false,
            'used_live_lookup'  => false,
            'retry_after'       => 0,
            'error_code'        => 0,
        ];

        if ($post_id <= 0) {
            return $result;
        }

        $decoded = self::read_submission_payload($post_id);
        $result['decoded'] = $decoded;
        if (!is_array($decoded)) {
            $result['reason'] = 'invalid_payload';
            return $result;
        }

        $context = isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [];
        $result['context'] = $context;

        $country = Aether_Submissions_Utils::get_country_from_context($context);
        if ($country !== '') {
            $result['status'] = 'skipped';
            $result['reason'] = 'already_present';
            $result['geo'] = Aether_Submissions_Utils::get_geo_from_context($context);
            return $result;
        }

        $ip = isset($context['ip']) && is_string($context['ip']) ? trim($context['ip']) : '';
        $result['ip'] = $ip;
        $result['ip_valid'] = Aether_Submissions_Utils::is_valid_public_ip($ip);

        if ($ip === '') {
            $result['reason'] = 'missing_ip';
            return $result;
        }

        if (!$result['ip_valid']) {
            $result['reason'] = 'invalid_ip';
            return $result;
        }

        $resolved_geo = Aether_Submission_Geo_Resolver::build_snapshot($options['resolved_geo']);
        if (!empty($resolved_geo['country'])) {
            return self::persist_geo_snapshot($decoded, $result, $resolved_geo, 'updated_from_provided_geo');
        }

        $cached_geo = Aether_Submission_Geo_Resolver::resolve_cached_by_ip($ip);
        if (!empty($cached_geo['country'])) {
            $result['used_cache'] = true;
            return self::persist_geo_snapshot($decoded, $result, $cached_geo, 'updated_from_cache');
        }

        if (empty($options['allow_live_lookup'])) {
            $result['reason'] = 'cache_miss';
            return $result;
        }

        $lookup = Aether_Submission_Geo_Resolver::lookup_by_ip($ip, true);
        $result['used_live_lookup'] = true;
        $result['error_code'] = (int) ($lookup['error_code'] ?? 0);
        $result['retry_after'] = max(0, (int) ($lookup['retry_after'] ?? 0));

        if ($result['error_code'] === 429) {
            $result['status'] = 'paused';
            $result['reason'] = 'rate_limited';
            return $result;
        }

        $lookup_geo = is_array($lookup['geo'] ?? null) ? $lookup['geo'] : [];
        if (empty($lookup_geo['country'])) {
            $result['reason'] = 'live_lookup_failed';
            return $result;
        }

        return self::persist_geo_snapshot($decoded, $result, $lookup_geo, 'updated_from_live');
    }

    /**
     * @param int $post_id
     * @return array|null
     */
    public static function read_submission_payload($post_id)
    {
        $content = get_post_field('post_content', $post_id);
        if (!is_string($content) || $content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array $decoded
     * @param array $result
     * @param array $geo
     * @param string $reason
     * @return array
     */
    private static function persist_geo_snapshot($decoded, $result, $geo, $reason)
    {
        $geo = Aether_Submission_Geo_Resolver::build_snapshot($geo);
        if (empty($geo['country'])) {
            $result['reason'] = 'normalized_geo_empty';
            return $result;
        }

        $context = isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [];
        if (Aether_Submissions_Utils::get_country_from_context($context) !== '') {
            $result['status'] = 'skipped';
            $result['reason'] = 'already_present';
            $result['context'] = $context;
            $result['geo'] = Aether_Submissions_Utils::get_geo_from_context($context);
            return $result;
        }

        $context['geo'] = $geo;
        $decoded['context'] = $context;

        $json = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            $result['reason'] = 'persist_encode_failed';
            return $result;
        }

        $update = wp_update_post([
            'ID'           => (int) $result['post_id'],
            'post_content' => $json,
        ], true);

        if (is_wp_error($update)) {
            error_log(sprintf(
                'Aether submission geo repair persist_failed post_id=%d reason=%s error=%s',
                (int) $result['post_id'],
                $reason,
                $update->get_error_message()
            ));
            $result['reason'] = 'persist_failed';
            return $result;
        }

        clean_post_cache((int) $result['post_id']);

        $result['status'] = 'updated';
        $result['reason'] = $reason;
        $result['decoded'] = $decoded;
        $result['context'] = $context;
        $result['geo'] = $geo;

        return $result;
    }
}
