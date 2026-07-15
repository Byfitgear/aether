<?php
/**
 * PHP runtime output cache policy.
 *
 * @package aether
 */

defined('ABSPATH') || exit;

class Aether_PHP_Runtime_Cache_Policy
{
    public const OUTPUT_META_KEY = '_aether_cached_php_output';
    public const HASH_META_KEY = '_aether_cache_hash';

    public static function content_contains_php($content): bool
    {
        if (!is_string($content) || $content === '') {
            return false;
        }

        return strpos($content, '<?php') !== false || strpos($content, '<?=') !== false;
    }

    public static function clear_output_cache($post_id): void
    {
        $post_id = (int) $post_id;

        if ($post_id <= 0) {
            return;
        }

        delete_post_meta($post_id, self::OUTPUT_META_KEY);
        delete_post_meta($post_id, self::HASH_META_KEY);
    }

    public static function clear_all_output_caches(): void
    {
        if (function_exists('delete_metadata')) {
            delete_metadata('post', 0, self::OUTPUT_META_KEY, '', true);
            delete_metadata('post', 0, self::HASH_META_KEY, '', true);
        }
    }
}
