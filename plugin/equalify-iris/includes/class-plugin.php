<?php
/**
 * WHAT IS THIS FILE?
 *
 * The wiring: one place to see what the plugin is made of.
 *
 *   Equalify_Iris_Discovery  which PDFs visitors can reach               (every site)
 *   Equalify_Iris_Tagger     one site's share of the background job       (every site)
 *   Equalify_Iris_Runner     the background job, for the whole network
 *   Equalify_Iris_Links      points PDF links at tagged copies            (front end)
 *   Equalify_Iris_Admin      the Equalify Iris screens and the notice     (admin)
 *   Equalify_Iris_CLI        `wp equalify-iris`                           (WP-CLI)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Plugin {

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'load_translations' ) );

		Equalify_Iris_Discovery::init();
		Equalify_Iris_Tagger::init();
		Equalify_Iris_Runner::init();
		Equalify_Iris_Links::init();

		if ( is_admin() ) {
			Equalify_Iris_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'equalify-iris', 'Equalify_Iris_CLI' );
		}
	}

	public static function load_translations(): void {
		load_plugin_textdomain( 'equalify-iris', false, dirname( plugin_basename( EQUALIFY_IRIS_FILE ) ) . '/languages' );
	}

	/**
	 * Content may have changed while the plugin was off, so every site reads it
	 * again. Without touching each site here: on a large network that would take
	 * hours. Sites that tag automatically are due at once; the rest read their
	 * content when someone next uses the plugin there.
	 */
	public static function on_activate(): void {
		Equalify_Iris_Discovery::restart_everywhere();
		Equalify_Iris_Runner::wake_all( ! Equalify_Iris_Settings::network_auto() );

		self::on_main_site( array( 'Equalify_Iris_Runner', 'keep_scheduled' ) );
	}

	/**
	 * Stop the job. Links go back to the originals by themselves, because nothing
	 * swaps them while the plugin is off. Tagged copies stay on disk, so
	 * reactivating picks up where it left off.
	 */
	public static function on_deactivate(): void {
		self::on_main_site(
			static function () {
				wp_clear_scheduled_hook( Equalify_Iris_Runner::HOOK );
			}
		);
	}

	private static function on_main_site( callable $callback ): void {
		$switch = is_multisite() && ! is_main_site();

		if ( $switch ) {
			switch_to_blog( get_main_site_id() );
		}

		$callback();

		if ( $switch ) {
			restore_current_blog();
		}
	}
}
