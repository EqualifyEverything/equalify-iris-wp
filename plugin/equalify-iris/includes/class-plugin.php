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
	 * Set up when the plugin is switched on, or back on.
	 *
	 * WHAT A REACTIVATION HAS TO CATCH UP ON
	 *
	 * While the plugin is off, nothing is watching posts. A page unpublished in that
	 * time keeps its sightings, and a PDF linked only from it would keep a public
	 * accessible version for good, because the thing that would have noticed was not
	 * running. So every sighting on a post that is no longer public is dropped here,
	 * and whatever that leaves linked from nowhere is retired before anyone can reach
	 * it. See Documents::prune_sightings_for_site().
	 *
	 * An edit that took a link out, or put a new one in, cannot be spotted that
	 * cheaply — it needs each post's content rendering again, which is the sweep's
	 * job. So after a reactivation a finished sweep is reopened, exactly as it is for
	 * a new site joining the network. It goes at the sweep's usual unhurried pace,
	 * and only while processing is running.
	 *
	 * The tick is scheduled here as well as on admin_init. A network run from a real
	 * cron job and reactivated from WP-CLI may not load an admin screen for days, and
	 * until something did, nothing ran.
	 */
	public static function on_activate(): void {
		Equalify_Iris_Database::on_activate();
		Equalify_Iris_Scheduler::schedule();

		$was_deactivated = (bool) get_site_option( 'equalify_iris_deactivated_at' );

		delete_site_option( 'equalify_iris_deactivated_at' );

		if ( ! $was_deactivated || ! is_multisite() || ! Equalify_Iris_Database::tables_exist() ) {
			return;
		}

		$lost_a_sighting = array();

		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
			$lost_a_sighting = array_merge( $lost_a_sighting, Equalify_Iris_Documents::prune_sightings_for_site( (int) $site_id ) );
		}

		$retired = Equalify_Iris_Documents::retire_if_unlinked( $lost_a_sighting );

		if ( $retired ) {
			Equalify_Iris_Logger::log(
				sprintf(
					/* translators: %s: a number of documents. */
					_n(
						'While the plugin was off, %s PDF lost the last public page linking to it, so its accessible version has been unpublished.',
						'While the plugin was off, %s PDFs lost the last public page linking to them, so their accessible versions have been unpublished.',
						$retired,
						'equalify-iris'
					),
					number_format_i18n( $retired )
				)
			);
		}

		if ( Equalify_Iris_Sweeper::is_complete() ) {
			Equalify_Iris_Sweeper::reset();

			Equalify_Iris_Logger::log(
				__( 'The plugin was switched back on, so the search for PDFs has been restarted to catch anything that changed while it was off.', 'equalify-iris' )
			);
		}
	}

	/**
	 * Tidy up when the plugin is switched off.
	 *
	 * DELIBERATELY DOES NOT DELETE ANY DOCUMENTS.
	 *
	 * Deactivating is not the same as uninstalling, and people deactivate plugins to
	 * test something for five minutes. Wiping a queue that took three weeks to build
	 * because someone was debugging a theme would be unforgivable. The converted
	 * pages are left exactly as they are, and everything resumes where it left off
	 * when the plugin comes back.
	 *
	 * Deleting data belongs in uninstall.php, where it happens only if someone
	 * chooses Delete and confirms it.
	 *
	 * WHAT VISITORS SEE WHILE IT IS OFF
	 *
	 * No icons: they are added as each page renders, so they stop with the plugin.
	 * And a plain 404 at every accessible document's address, because the post type
	 * is no longer registered and our URL rule is taken out of every site below.
	 * The one thing this cannot reach is a page cache holding a copy rendered while
	 * the plugin was on; that copy keeps its icons until the cache expires or is
	 * cleared, and the icons lead to that 404.
	 */
	public static function on_deactivate(): void {
		Equalify_Iris_Scheduler::unschedule();
		Equalify_Iris_Post_Type::remove_rewrite_rule_everywhere();

		// So reactivation knows it has a gap to catch up on. See on_activate().
		update_site_option( 'equalify_iris_deactivated_at', time() );
	}
}
