<?php
/**
 * Plugin Name: KITH Carbon Fields
 * Plugin URI: https://github.com/KITH-web-studio/carbon-fields
 * Description: Custom fields for WordPress. A fork of Carbon Fields by htmlBurger, maintained and developed by KITH Web Studio.
 * Version: 3.6.11.14
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

if ( ! class_exists( 'KITH_Carbon_Fields\\Plugin_Updates', false ) ) {
	require_once __DIR__ . '/core/Plugin_Updates.php';
}
$kith_carbon_fields_updates = new \KITH_Carbon_Fields\Plugin_Updates( __FILE__ );
$kith_carbon_fields_updates->register();

add_action( 'after_setup_theme', function() {
	if ( defined( 'Carbon_Fields\VERSION' ) ) {
		return;
	}

	require_once __DIR__ . '/vendor/autoload.php';
	\Carbon_Fields\Carbon_Fields::boot();
}, 5 );
