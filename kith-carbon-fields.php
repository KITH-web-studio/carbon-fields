<?php
/**
 * Plugin Name: KITH Carbon Fields
 * Plugin URI: https://github.com/KITH-web-studio/carbon-fields
 * Description: Custom fields for WordPress. A fork of Carbon Fields by htmlBurger, maintained and developed by KITH Web Studio.
 * Version: 3.6.11.8
 * Requires at least: 5.0
 * Requires PHP: 5.6.20
 * Author: KITH Web Studio
 * License: GPL-2.0-only
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: carbon-fields
 * Domain Path: /languages
 * Update URI: https://github.com/KITH-web-studio/carbon-fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'after_setup_theme', function() {
	if ( defined( 'Carbon_Fields\VERSION' ) ) {
		return;
	}

	require_once __DIR__ . '/vendor/autoload.php';
	\Carbon_Fields\Carbon_Fields::boot();
}, 5 );
