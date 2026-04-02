<?php
/**
 * Plugin Name:       Zabure Content Paywall
 * Plugin URI:        https://wp-fundi.com
 * Description:       Restricts premium WordPress post content behind a Zabure payment wall. Users pay a one-time fee per post to unlock full content permanently.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            chris
 * Author URI:        https://wp-fundi.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zabure-content-paywall
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'ZABURE_PAYWALL_VERSION',  '1.0.0' );
define( 'ZABURE_PAYWALL_PATH',     plugin_dir_path( __FILE__ ) );
define( 'ZABURE_PAYWALL_URL',      plugin_dir_url( __FILE__ ) );
define( 'ZABURE_PAYWALL_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4-style autoloader.
 *
 * Maps class names like Zabure_Database → includes/class-zabure-database.php.
 */
spl_autoload_register(
	function ( string $class_name ): void {
		// Only autoload classes with the Zabure_ prefix.
		if ( 0 !== strpos( $class_name, 'Zabure_' ) ) {
			return;
		}

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		$file_path = ZABURE_PAYWALL_PATH . 'includes/' . $file_name;

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
);

/**
 * Activation hook — create DB tables.
 *
 * Handles multisite by iterating over every blog.
 */
register_activation_hook(
	__FILE__,
	function ( bool $network_wide ): void {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
			foreach ( $sites as $blog_id ) {
				switch_to_blog( $blog_id );
				Zabure_Database::create_tables();
				restore_current_blog();
			}
		} else {
			Zabure_Database::create_tables();
		}

		flush_rewrite_rules();
	}
);

/**
 * Deactivation hook — flush rewrite rules.
 */
register_deactivation_hook(
	__FILE__,
	[ 'Zabure_Database', 'flush_rewrite_rules' ]
);

/**
 * Bootstrap all plugin classes once WordPress and all plugins are loaded.
 */
add_action(
	'plugins_loaded',
	function (): void {
		// Load translations.
		load_plugin_textdomain(
			'zabure-content-paywall',
			false,
			dirname( ZABURE_PAYWALL_BASENAME ) . '/languages'
		);

		// Instantiate all classes (they register their own hooks in __construct).
		new Zabure_Content_Filter();
		new Zabure_Callback_Handler();
		new Zabure_Payment_Handler();
		new Zabure_Webhook_Handler();

		new Zabure_Admin();
	}
);
