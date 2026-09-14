<?php
/**
 * Plugin Name:       Equalify Iris
 * Plugin URI:        https://github.com/EqualifyEverything/equalify-iris-wp
 * Description:       Finds every PDF linked from published content across a multisite network, converts it to accessible HTML with Equalify Iris, and publishes that HTML as its own page with a link next to the original PDF.
 * Version:           0.1.0
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
 * This is the plugin's front door. WordPress reads the comment block above to
 * learn the plugin exists, then runs this file on every single request.
 *
 * WHY DOES IT EXIST?
 *
 * To do as little as possible. All this file does is:
 *   1. Define a few constants everything else uses.
 *   2. Load the class files.
 *   3. Hand control to Equalify_Iris_Plugin::boot().
 *
 * Keeping it thin matters because this code runs on every page load of every
 * site in the network, including requests that have nothing to do with us.
 *
 * "Network: true" in the header above means this plugin can only be activated
 * for the whole network at once, not site by site. That is deliberate — every
 * control lives in the Network Admin dashboard, so a single-site activation
 * would give someone a plugin with no interface.
 * ---------------------------------------------------------------------------
 */

// Stop anyone loading this file directly by typing its URL into a browser.
// ABSPATH is only defined once WordPress itself has started, so its absence
// means we are being loaded out of context.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The plugin version. Used to bust asset caches and to run upgrade routines. */
define( 'EQUALIFY_IRIS_VERSION', '0.1.0' );

/** Absolute path to this plugin's folder, with a trailing slash. */
define( 'EQUALIFY_IRIS_PATH', plugin_dir_path( __FILE__ ) );

/** Public URL of this plugin's folder, with a trailing slash. */
define( 'EQUALIFY_IRIS_URL', plugin_dir_url( __FILE__ ) );

/** The main plugin file, which WordPress needs for activation hooks. */
define( 'EQUALIFY_IRIS_FILE', __FILE__ );

// Load every class. These are plain `require` calls rather than an autoloader
// on purpose: there are few enough files to list, and a reader can see the
// entire codebase from here without knowing how autoloading works.
require_once EQUALIFY_IRIS_PATH . 'includes/class-settings.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-logger.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-database.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-documents.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-api-client.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-pdf-inspector.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-html-cleaner.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-post-type.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-discovery.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-sweeper.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-worker.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-scheduler.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-frontend.php';
require_once EQUALIFY_IRIS_PATH . 'includes/class-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once EQUALIFY_IRIS_PATH . 'includes/class-cli.php';
}

if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once EQUALIFY_IRIS_PATH . 'admin/class-admin.php';
	require_once EQUALIFY_IRIS_PATH . 'admin/class-admin-overview.php';
	require_once EQUALIFY_IRIS_PATH . 'admin/class-admin-documents.php';
	require_once EQUALIFY_IRIS_PATH . 'admin/class-admin-settings.php';
	require_once EQUALIFY_IRIS_PATH . 'admin/class-admin-log.php';
}

// Create the database tables when the plugin is switched on, and again for any
// site added to the network later. See class-database.php for why both are
// needed.
register_activation_hook( EQUALIFY_IRIS_FILE, array( 'Equalify_Iris_Database', 'on_activate' ) );
register_deactivation_hook( EQUALIFY_IRIS_FILE, array( 'Equalify_Iris_Plugin', 'on_deactivate' ) );

// `plugins_loaded` is the earliest hook where every plugin is available, which
// is where a plugin should start work. Doing it here rather than at the bottom
// of this file means other plugins get a chance to filter our behaviour.
add_action( 'plugins_loaded', array( 'Equalify_Iris_Plugin', 'boot' ) );
