<?php
/**
 * Plugin Name:       Equalify Iris
 * Plugin URI:        https://github.com/EqualifyEverything/equalify-iris-wp
 * Description:       Adds accessibility tags to the PDFs a site's visitors can reach with Equalify Iris, and points every link to them at the tagged version.
 * Version:           0.2.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Equalify
 * License:           GPL-2.0-or-later
 * Text Domain:       equalify-iris
 * Network:           true
 *
 * ---------------------------------------------------------------------------
 * WHAT IS THIS FILE?
 *
 * The plugin's front door. WordPress reads the header above, then runs this file
 * on every request. All it does is define a few constants, load the classes, and
 * hand over to Equalify_Iris_Plugin::boot().
 *
 * "Network: true" means the plugin is activated for the whole network at once.
 * Each site still decides for itself whether its PDFs are tagged automatically,
 * unless a super admin has turned that on for every site.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EQUALIFY_IRIS_VERSION', '0.2.2' );
define( 'EQUALIFY_IRIS_PATH', plugin_dir_path( __FILE__ ) );
define( 'EQUALIFY_IRIS_FILE', __FILE__ );

require_once EQUALIFY_IRIS_PATH . 'includes/class-settings.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-api-client.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-pdf-inspector.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-discovery.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-tagger.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-runner.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-links.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-plugin.php';

if ( is_admin() ) {
	require_once EQUALIFY_IRIS_PATH . 'admin/class-admin.php';
	require_once EQUALIFY_IRIS_PATH . 'admin/class-list-table.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once EQUALIFY_IRIS_PATH . 'includes/class-cli.php';
}

register_activation_hook( EQUALIFY_IRIS_FILE, array( 'Equalify_Iris_Plugin', 'on_activate' ) );
register_deactivation_hook( EQUALIFY_IRIS_FILE, array( 'Equalify_Iris_Plugin', 'on_deactivate' ) );

add_action( 'plugins_loaded', array( 'Equalify_Iris_Plugin', 'boot' ) );
