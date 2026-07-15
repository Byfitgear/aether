<?php

/**
 * Plugin Uninstall Script
 *
 * Executed when user deletes the plugin from WordPress admin
 *
 * @package Aether
 */

// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Define AETHER_PATH for autoloader (main plugin file not loaded during uninstall)
if (!defined('AETHER_PATH')) {
    define('AETHER_PATH', __DIR__ . '/');
}

// Load autoloader
require_once __DIR__ . '/includes/core/class-autoloader.php';
Aether_Autoloader::register();

// Remove static cache rules from .htaccess
if (class_exists('Aether_Static_Cache_Manager')) {
    Aether_Static_Cache_Manager::remove_rules();
}

// Optional: Delete plugin settings (if complete uninstall is needed)
// delete_option('aether_settings');
// delete_option('aether_version');
