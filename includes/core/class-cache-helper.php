<?php
if (!defined('ABSPATH')) { exit; }

class WordExpress_Cache_Helper
{
    public static function clear_page_cache()
    {
        if (function_exists('wp_cache_clear')) {
            return wp_cache_clear();
        }
        return true;
    }
}
