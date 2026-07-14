<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
global $wpdb;

// Remove settings
delete_option('aether_settings');
delete_option('aether_version');

// Remove contact form submissions table
$table_name = $wpdb->prefix . 'aether_contact_submissions';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");
