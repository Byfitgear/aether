<?php

/**
 * Static Cache Manager
 *
 * Features:
 * - Auto configure .htaccess cache rules on plugin activation
 * - Auto remove rules on plugin deactivation/uninstall
 * - Support for images, fonts, CSS, JS and other common static assets
 *
 * @package Aether
 */

defined('ABSPATH') || exit;

class Aether_Static_Cache_Manager
{
    /**
     * .htaccess marker name
     */
    const MARKER = 'AETHER STATIC CACHE RULES';

    /**
     * Add static cache rules to .htaccess
     *
     * @param bool $force Force overwrite even if rules already exist (default: false)
     * @return bool|WP_Error Returns true on success, WP_Error on failure
     */
    public static function add_rules($force = false)
    {
        // Check if Apache server
        if (!self::is_apache()) {
            return new WP_Error(
                'not_apache',
                __('Current server is not Apache/LiteSpeed, skip .htaccess configuration', 'aether')
            );
        }

        // Load necessary WordPress functions
        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $htaccess = ABSPATH . '.htaccess';

        // Check if file is writable
        if (!is_writable($htaccess) && !is_writable(ABSPATH)) {
            return new WP_Error(
                'not_writable',
                sprintf(
                    __('.htaccess file is not writable. Please ensure %s or its directory is writable.', 'aether'),
                    $htaccess
                )
            );
        }

        // Skip if rules already exist and are identical (unless forced)
        if (!$force && self::rules_exist_and_identical($htaccess)) {
            error_log('[Aether] Static cache rules already exist in .htaccess, skip writing');
            return true;
        }

        // Generate cache rules
        $rules = self::get_cache_rules();

        // Write to .htaccess
        $result = insert_with_markers($htaccess, self::MARKER, $rules);

        if ($result) {
            $action = $force ? 'updated' : 'written';
            error_log("[Aether] Static cache rules {$action} to .htaccess");
            return true;
        } else {
            return new WP_Error(
                'write_failed',
                __('Failed to write .htaccess, please check file permissions', 'aether')
            );
        }
    }

    /**
     * Remove cache rules from .htaccess
     *
     * @return bool|WP_Error Returns true on success, WP_Error on failure
     */
    public static function remove_rules()
    {
        if (!self::is_apache()) {
            return new WP_Error('not_apache', 'Not Apache server');
        }

        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $htaccess = ABSPATH . '.htaccess';

        if (!file_exists($htaccess)) {
            return true; // File doesn't exist, considered as cleaned
        }

        // Pass empty array to remove rules
        $result = insert_with_markers($htaccess, self::MARKER, []);

        if ($result) {
            error_log('[Aether] Static cache rules removed from .htaccess');
            return true;
        } else {
            return new WP_Error('remove_failed', 'Failed to remove rules from .htaccess');
        }
    }

    /**
     * Check if rules already exist and are identical
     *
     * @param string $htaccess Path to .htaccess file
     * @return bool True if rules exist and are identical
     */
    private static function rules_exist_and_identical($htaccess)
    {
        if (!file_exists($htaccess)) {
            return false;
        }

        $content = file_get_contents($htaccess);
        if ($content === false) {
            return false;
        }

        // Check if our marker exists
        $marker = self::MARKER;
        if (strpos($content, "# BEGIN {$marker}") === false) {
            return false;
        }

        // Extract existing rules between markers
        $pattern = '/# BEGIN ' . preg_quote($marker, '/') . '(.*?)# END ' . preg_quote($marker, '/') . '/s';
        if (!preg_match($pattern, $content, $matches)) {
            return false;
        }

        $existing_rules = trim($matches[1]);
        $new_rules = implode("\n", self::get_cache_rules());

        // Compare existing and new rules
        return $existing_rules === $new_rules;
    }

    /**
     * Get cache rules configuration
     *
     * @return array Rules array
     */
    private static function get_cache_rules()
    {
        return [
            '# MIME type declarations (fix AVIF/WebP download issue)',
            '<IfModule mod_mime.c>',
            '  AddType image/avif .avif',
            '  AddType image/webp .webp',
            '  AddType font/woff2 .woff2',
            '</IfModule>',
            '',
            '# Browser cache (Expires)',
            '<IfModule mod_expires.c>',
            '  ExpiresActive On',
            '',
            '  # Images - 1 year',
            '  ExpiresByType image/avif "access plus 1 year"',
            '  ExpiresByType image/webp "access plus 1 year"',
            '  ExpiresByType image/jpeg "access plus 1 year"',
            '  ExpiresByType image/jpg "access plus 1 year"',
            '  ExpiresByType image/png "access plus 1 year"',
            '  ExpiresByType image/gif "access plus 1 year"',
            '  ExpiresByType image/svg+xml "access plus 1 year"',
            '  ExpiresByType image/x-icon "access plus 1 year"',
            '',
            '  # Fonts - 1 year',
            '  ExpiresByType font/woff2 "access plus 1 year"',
            '  ExpiresByType font/woff "access plus 1 year"',
            '  ExpiresByType font/ttf "access plus 1 year"',
            '  ExpiresByType font/otf "access plus 1 year"',
            '  ExpiresByType application/font-woff2 "access plus 1 year"',
            '',
            '  # CSS/JS - 1 month',
            '  ExpiresByType text/css "access plus 1 month"',
            '  ExpiresByType application/javascript "access plus 1 month"',
            '  ExpiresByType text/javascript "access plus 1 month"',
            '',
            '  # Documents - 1 week',
            '  ExpiresByType application/pdf "access plus 1 week"',
            '</IfModule>',
            '',
            '# Cache-Control headers (for Lighthouse optimization)',
            '<IfModule mod_headers.c>',
            '  # Images - immutable (content never changes)',
            '  <FilesMatch "\.(avif|webp|jpe?g|png|gif|svg|ico)$">',
            '    Header set Cache-Control "public, max-age=31536000, immutable"',
            '  </FilesMatch>',
            '',
            '  # Fonts - immutable',
            '  <FilesMatch "\.(woff2?|ttf|otf|eot)$">',
            '    Header set Cache-Control "public, max-age=31536000, immutable"',
            '  </FilesMatch>',
            '',
            '  # CSS/JS - 1 month',
            '  <FilesMatch "\.(css|js)$">',
            '    Header set Cache-Control "public, max-age=2592000"',
            '  </FilesMatch>',
            '',
            '  # PDF - 1 week',
            '  <FilesMatch "\.pdf$">',
            '    Header set Cache-Control "public, max-age=604800"',
            '  </FilesMatch>',
            '</IfModule>',
        ];
    }

    /**
     * Detect if current server is Apache
     *
     * @return bool
     */
    private static function is_apache()
    {
        // Check server software
        $server_software = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';

        // Both Apache and LiteSpeed support .htaccess
        return (
            stripos($server_software, 'apache') !== false ||
            stripos($server_software, 'litespeed') !== false
        );
    }
}
