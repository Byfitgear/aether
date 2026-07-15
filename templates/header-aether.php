<?php
/**
 * Header template for themes that support custom header
 * 
 * Include this file in your theme's header.php like:
 * <?php get_template_part('header', 'aether'); ?>
 * 
 * Or call the function directly:
 * <?php if (function_exists('aether_custom_header')) aether_custom_header(); ?>
 *
 * @package Aether
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Output custom header if function exists
if ( function_exists( 'aether_custom_header' ) ) {
	aether_custom_header();
}