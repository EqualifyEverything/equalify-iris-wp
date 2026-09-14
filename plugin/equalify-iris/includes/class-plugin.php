<?php
/**
 * WHAT IS THIS FILE?
 *
 * The wiring. It creates each part of the plugin and connects them.
 *
 * WHY DOES IT EXIST?
 *
 * So that there is exactly one place to look to see what this plugin is made of
 * and what talks to what. Every class in this plugin is either created here or
 * used through static methods; nothing reaches out and creates its own
 * collaborators. That means you can read boot() below and know the whole shape of
 * the plugin in a minute.
 *
 * WHY PASS OBJECTS IN RATHER THAN LETTING CLASSES FIND EACH OTHER?
 *
 * Because the worker needs a sweeper, and the sweeper needs discovery. If each
 * one created its own, there would be no way to test any of them in isolation and
 * no way to see the dependency from outside. Handing them in — "dependency
 * injection", which sounds far grander than it is — keeps that visible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Plugin {

	/** The one instance of the plugin. */
	private static ?Equalify_Iris_Plugin $instance = null;

	public Equalify_Iris_Discovery $discovery;
	public Equalify_Iris_Sweeper $sweeper;
	public Equalify_Iris_Worker $worker;
	public Equalify_Iris_Scheduler $scheduler;
	public Equalify_Iris_Post_Type $post_type;
	public Equalify_Iris_Frontend $frontend;

	/**
	 * Start the plugin.
	 *
	 * Called once, on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->wire();
	}

	/** Get the running plugin, for WP-CLI and the admin screens. */
	public static function instance(): ?Equalify_Iris_Plugin {
		return self::$instance;
	}

	/**
	 * Create everything and let each part register its own hooks.
	 *
	 * Each class has an init() that adds its own hooks, rather than this file adding
	 * hooks on their behalf. That way the answer to "what does this class hook
	 * into?" is always at the top of that class, not here.
	 */
	private function wire(): void {
		// The order of construction is the order of dependency: discovery is used by
		// the sweeper, which is used by the worker, which is used by the scheduler.
		$this->discovery = new Equalify_Iris_Discovery();
		$this->sweeper   = new Equalify_Iris_Sweeper( $this->discovery );
		$this->worker    = new Equalify_Iris_Worker( $this->sweeper );
		$this->scheduler = new Equalify_Iris_Scheduler( $this->worker );
		$this->post_type = new Equalify_Iris_Post_Type();
		$this->frontend  = new Equalify_Iris_Frontend();

		// The post type and the icons run everywhere, on every site, because the
		// converted pages have to be reachable and the icons have to appear.
		$this->post_type->init();
		$this->frontend->init();

		// Discovery runs everywhere too — a PDF can be published on any site.
		$this->discovery->init();

		// The clock only runs on the main site. See class-scheduler.php.
		$this->scheduler->init();

		add_action( 'init', array( $this, 'load_translations' ) );
		add_action( 'init', array( 'Equalify_Iris_Post_Type', 'maybe_flush_rewrites' ), 99 );
		add_action( 'admin_init', array( 'Equalify_Iris_Database', 'maybe_upgrade' ) );
		add_action( 'admin_init', array( 'Equalify_Iris_Scheduler', 'schedule' ) );

		// A site added to the network after activation has no tables of its own to
		// create — our tables are network-wide — but its posts do need sweeping, so
		// the sweep is reopened. Without this, a site created after the first sweep
		// finished would never be searched at all.
		add_action( 'wp_initialize_site', array( $this, 'on_new_site' ), 20 );

		if ( is_admin() ) {
			( new Equalify_Iris_Admin( $this ) )->init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Equalify_Iris_CLI::register( $this );
		}
	}

	/** Load the plugin's translations, if any have been installed. */
	public function load_translations(): void {
		load_plugin_textdomain(
			'equalify-iris',
			false,
			dirname( plugin_basename( EQUALIFY_IRIS_FILE ) ) . '/languages'
		);
	}

	/**
	 * A new site joined the network.
	 */
	public function on_new_site(): void {
		if ( ! Equalify_Iris_Sweeper::is_complete() ) {
			// The sweep has not finished, so it will reach the new site on its own.
			return;
		}

		Equalify_Iris_Sweeper::reset();

		Equalify_Iris_Logger::log(
			__( 'A new site was added to the network, so the search for PDFs has been restarted to include it.', 'equalify-iris' )
		);
	}

	/**
	 * Tidy up when the plugin is switched off.
	 *
	 * DELIBERATELY DOES NOT DELETE ANYTHING.
	 *
	 * Deactivating is not the same as uninstalling, and people deactivate plugins to
	 * test something for five minutes. Wiping a queue that took three weeks to build
	 * because someone was debugging a theme would be unforgivable. All this does is
	 * stop the clock, which means the converted pages stay published and everything
	 * resumes where it left off when the plugin comes back.
	 *
	 * Deleting data belongs in uninstall.php, where it happens only if someone
	 * chooses Delete and confirms it.
	 */
	public static function on_deactivate(): void {
		Equalify_Iris_Scheduler::unschedule();

		// Rewrite rules are cached with our rule in them. Clearing the flag and
		// flushing means the URLs stop resolving cleanly rather than resolving to
		// nothing with no explanation.
		flush_rewrite_rules( false );
	}
}
