<?php
/**
 * WHAT IS THIS FILE?
 *
 * The one background job for the whole network. Each run works through the
 * sites that have something to do, a slice of each, until its time is up.
 *
 * WHY ONE JOB, NOT ONE PER SITE
 *
 * WP-Cron only runs when a site is visited, and a large network has sites
 * nobody visits for months. One job on the main site reaches all of them. It is
 * also the one place that can keep the number of PDFs at Iris under a single
 * limit, however many sites there are.
 *
 * WHICH SITES HAVE WORK
 *
 * A site with something to do has an `equalify_iris_due` row in the network's
 * blogmeta table, saying when it is next due. A run asks for the sites that are
 * due, longest-waiting first, so no site waits behind a busy one. A site with
 * nothing to do has no row, so a network of 100,000 quiet sites costs one
 * indexed query per run.
 *
 * HOW LONG A RUN LASTS
 *
 * EQUALIFY_IRIS_RUN_SECONDS, 90 by default: inside the 120 seconds that hosts
 * such as Pantheon give any PHP process, with room for other cron jobs. Every
 * request to Iris is cut to fit in what is left of the run.
 *
 * WHAT STARTS A RUN
 *
 * WP-Cron on the main site, every five minutes; any admin page on any site, when
 * the job is overdue; a person asking for something from an Equalify Iris screen
 * or the editor, so they see it start without waiting for the schedule; and
 * `wp equalify-iris run`, for a real scheduler. They all take the same lock (see
 * lock()), so two runs never overlap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Runner {

	const HOOK     = 'equalify_iris_run';
	const SCHEDULE = 'equalify_iris_five_minutes';

	/** Blogmeta: when a site is next due, as a timestamp. */
	const DUE = 'equalify_iris_due';

	/** Blogmeta: the site's admin has turned on automatic tagging. */
	const AUTO = 'equalify_iris_auto';

	/** Blogmeta: the site has started using the plugin, so has data to clean up. */
	const USED = 'equalify_iris_used';

	/** Network options. */
	const LOCK     = 'equalify_iris_lock';
	const LAST_RUN = 'equalify_iris_last_run';
	const AT_IRIS  = 'equalify_iris_at_iris';

	/** Seconds one site gets to read content before the run moves on. */
	const SLICE = 20;

	/** An admin page starts a run when the last one is older than this. */
	const OVERDUE = 10 * MINUTE_IN_SECONDS;

	/** Someone asked for something this request, so start a run when it ends. */
	private static $kick = false;

	/** This request holds the database lock (see lock()). */
	private static $db_lock = false;

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'from_cron' ) );
		add_action( 'init', array( __CLASS__, 'keep_scheduled' ) );
		add_action( 'admin_init', array( __CLASS__, 'nudge' ) );
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ) );
	}

	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Equalify Iris)', 'equalify-iris' ),
		);

		return $schedules;
	}

	/** The job lives on the main site only. */
	public static function keep_scheduled(): void {
		if ( is_main_site() && ! wp_next_scheduled( self::HOOK ) ) {
			// Registered here as well as on the filter, because the schedule has to
			// exist on the request that first uses it.
			add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * WP-Cron on the main site only fires when the main site is visited. If it
	 * has fallen behind, an admin page anywhere on the network starts a run, the
	 * same way WordPress starts its own: a request that does not wait for an answer.
	 */
	public static function nudge(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || time() - self::last_run() < self::OVERDUE ) {
			return;
		}

		if ( get_site_transient( 'equalify_iris_nudged' ) || ! self::waiting() ) {
			return;
		}

		set_site_transient( 'equalify_iris_nudged', 1, 5 * MINUTE_IN_SECONDS );
		self::start_now();
	}

	/**
	 * Start a run as this request ends, because a person just asked for
	 * something. At most once a minute, so bulk actions and imports do not
	 * start one per PDF; a run already going covers it anyway.
	 */
	public static function kick(): void {
		if ( self::$kick || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		self::$kick = true;

		add_action(
			'shutdown',
			static function () {
				if ( ! get_site_transient( 'equalify_iris_kicked' ) && ! self::running() ) {
					set_site_transient( 'equalify_iris_kicked', 1, MINUTE_IN_SECONDS );
					self::start_now();
				}
			}
		);
	}

	/** Run the job now, in a request of its own that this one does not wait for. */
	private static function start_now(): void {
		// wp-cron.php only runs events that are due, so make it due.
		$switch = is_multisite() && ! is_main_site();

		if ( $switch ) {
			switch_to_blog( get_main_site_id() );
		}

		$next = wp_next_scheduled( self::HOOK );

		if ( ! $next || $next > time() ) {
			wp_clear_scheduled_hook( self::HOOK );
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}

		if ( $switch ) {
			restore_current_blog();
		}

		wp_remote_post(
			get_site_url( get_main_site_id(), 'wp-cron.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core's filter.
			)
		);
	}

	public static function on_new_site( WP_Site $site ): void {
		if ( Equalify_Iris_Settings::network_auto() ) {
			self::wake( (int) $site->blog_id );
		}
	}

	public static function run_seconds(): int {
		return defined( 'EQUALIFY_IRIS_RUN_SECONDS' ) ? max( 30, (int) EQUALIFY_IRIS_RUN_SECONDS ) : 90;
	}

	/** PDFs at Iris at once, across the whole network. */
	public static function max_at_iris(): int {
		return defined( 'EQUALIFY_IRIS_MAX_AT_IRIS' ) ? max( 1, (int) EQUALIFY_IRIS_MAX_AT_IRIS ) : 4;
	}

	public static function last_run(): int {
		return (int) get_site_option( self::LAST_RUN, 0 );
	}

	// -----------------------------------------------------------------------
	// A run
	// -----------------------------------------------------------------------

	public static function from_cron(): void {
		self::run();
	}

	/**
	 * Work through the sites that are due until the time is up.
	 *
	 * @param int $seconds 0 for run_seconds().
	 * @return array{sites: int, queued: int, uploaded: int, tagged: int, failed: int, locked: bool}
	 */
	public static function run( int $seconds = 0 ): array {
		$seconds  = $seconds > 0 ? $seconds : self::run_seconds();
		$deadline = time() + $seconds;
		$done     = array( 'sites' => 0, 'queued' => 0, 'uploaded' => 0, 'tagged' => 0, 'failed' => 0, 'locked' => false );

		if ( ! self::lock( $seconds ) ) {
			$done['locked'] = true;
			return $done;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $seconds + 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
		}

		try {
			update_site_option( self::LAST_RUN, time() );
			self::forget_stale_sessions();

			$seen = array();

			while ( time() < $deadline - 5 ) {
				$sites = self::due( 50, $seen );

				if ( ! $sites ) {
					break;
				}

				foreach ( $sites as $site_id ) {
					if ( time() >= $deadline - 5 ) {
						break 2;
					}

					// Once per run. A site with more to do is due again at once, and
					// would otherwise keep the rest waiting.
					$seen[] = $site_id;
					self::run_site( $site_id, $deadline, $done );
				}
			}
		} finally {
			self::unlock();
		}

		return $done;
	}

	private static function run_site( int $site_id, int $deadline, array &$done ): void {
		if ( is_multisite() ) {
			switch_to_blog( $site_id );
		}

		try {
			$result = Equalify_Iris_Tagger::work( $deadline, min( $deadline, time() + self::SLICE ) );

			foreach ( $result as $key => $count ) {
				$done[ $key ] += $count;
			}

			++$done['sites'];

			self::set_due( $site_id, Equalify_Iris_Tagger::next_due() );
		} catch ( Throwable $e ) {
			// One site's broken content or plugin must not stop the rest of the
			// network: it would stay first in line and fail again every run.
			// Try it again later, and say why in the PHP error log.
			error_log( sprintf( 'Equalify Iris: site %d failed during a run and will be retried in 15 minutes: %s in %s:%d', $site_id, $e->getMessage(), $e->getFile(), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			self::set_due( $site_id, time() + 15 * MINUTE_IN_SECONDS );
		} finally {
			if ( is_multisite() ) {
				restore_current_blog();
			}

			// A run can read thousands of posts across many sites. Not
			// clean_post_cache(): page-cache plugins purge the CDN on it.
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
	}

	/**
	 * Only one run at a time, across every web server.
	 *
	 * A database lock where the database has them (MySQL and MariaDB do): taking
	 * it is atomic, and it is let go by itself when a run dies, because its
	 * connection closes. The network option is then only a note for the screens
	 * that a run is going. Without one, the option is the lock, and a run that
	 * died is taken over once it is older than any run can be, by exactly one of
	 * the runs that notice.
	 */
	private static function lock( int $seconds ): bool {
		global $wpdb;

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( null !== $got ) {
			if ( '1' !== (string) $got ) {
				return false;
			}

			self::$db_lock = true;
			update_site_option( self::LOCK, time() );

			return true;
		}

		if ( add_site_option( self::LOCK, time() ) ) {
			return true;
		}

		$held = (string) get_site_option( self::LOCK );

		return time() - (int) $held > $seconds + 120 && self::swap_lock( $held, (string) time() );
	}

	private static function unlock(): void {
		global $wpdb;

		delete_site_option( self::LOCK );

		if ( self::$db_lock ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::$db_lock = false;
		}
	}

	/** Is a run going now? */
	public static function running(): bool {
		global $wpdb;

		$free = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK( %s )', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( null !== $free ) {
			return '0' === (string) $free;
		}

		return time() - (int) get_site_option( self::LOCK, 0 ) < self::run_seconds() + 120;
	}

	/** One name per network per database. Lock names are server-wide, and at most 64 characters. */
	private static function lock_name(): string {
		global $wpdb;

		return 'equalify_iris_' . md5( DB_NAME . '|' . $wpdb->base_prefix . '|' . get_current_network_id() );
	}

	/** Replace the stale lock, if no other run replaced it first. */
	private static function swap_lock( string $old, string $new ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( is_multisite() ) {
			$swapped = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->sitemeta} SET meta_value = %s WHERE site_id = %d AND meta_key = %s AND meta_value = %s", $new, get_current_network_id(), self::LOCK, $old ) );
			wp_cache_delete( get_current_network_id() . ':' . self::LOCK, 'site-options' );
		} else {
			$swapped = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new, self::LOCK, $old ) );
			wp_cache_delete( self::LOCK, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		// phpcs:enable

		return 1 === (int) $swapped;
	}

	// -----------------------------------------------------------------------
	// Which sites are due
	// -----------------------------------------------------------------------

	/**
	 * Sites due now, longest-waiting first.
	 *
	 * @param int[] $skip Sites already seen this run.
	 * @return int[]
	 */
	private static function due( int $limit, array $skip ): array {
		global $wpdb;

		if ( ! is_multisite() ) {
			$at = (int) get_option( self::DUE, 0 );

			return $at && $at <= time() && ! $skip ? array( get_current_blog_id() ) : array();
		}

		$sql  = "SELECT m.blog_id FROM {$wpdb->blogmeta} m JOIN {$wpdb->blogs} b ON b.blog_id = m.blog_id
			WHERE m.meta_key = %s AND CAST( m.meta_value AS UNSIGNED ) <= %d
			AND b.site_id = %d AND b.deleted = 0 AND b.archived = 0 AND b.spam = 0";
		$args = array( self::DUE, time(), get_current_network_id() );

		if ( $skip ) {
			$sql .= ' AND m.blog_id NOT IN (' . implode( ',', array_fill( 0, count( $skip ), '%d' ) ) . ')';
			$args = array_merge( $args, $skip );
		}

		$sql   .= ' ORDER BY CAST( m.meta_value AS UNSIGNED ), m.blog_id LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/** How many sites are due now. */
	public static function waiting(): int {
		global $wpdb;

		if ( ! is_multisite() ) {
			$at = (int) get_option( self::DUE, 0 );
			return (int) ( $at && $at <= time() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->blogmeta} m JOIN {$wpdb->blogs} b ON b.blog_id = m.blog_id
				WHERE m.meta_key = %s AND CAST( m.meta_value AS UNSIGNED ) <= %d
				AND b.site_id = %d AND b.deleted = 0 AND b.archived = 0 AND b.spam = 0",
				self::DUE,
				time(),
				get_current_network_id()
			)
		);
	}

	/**
	 * Make a site due by a time, unless it is due sooner already.
	 *
	 * The table is read and written directly, not through get_site_meta(): the job
	 * queries it in SQL, so a cached copy would only ever be out of date.
	 */
	public static function wake( int $site_id = 0, int $at = 0 ): void {
		global $wpdb;

		$site_id = $site_id ? $site_id : get_current_blog_id();
		$at      = $at ? $at : time();

		if ( ! is_multisite() ) {
			$current = (int) get_option( self::DUE, 0 );

			if ( ! $current || $at < $current ) {
				update_option( self::DUE, $at, false );
			}

			self::kick();
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->blogmeta} WHERE blog_id = %d AND meta_key = %s LIMIT 1", $site_id, self::DUE ) );

		if ( null === $current ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $wpdb->blogmeta, array( 'blog_id' => $site_id, 'meta_key' => self::DUE, 'meta_value' => (string) $at ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( (int) $current > $at ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->blogmeta, array( 'meta_value' => (string) $at ), array( 'blog_id' => $site_id, 'meta_key' => self::DUE ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		self::kick();
	}

	/**
	 * Make every site on the network due now, in two queries whatever its size.
	 *
	 * @param bool $only_auto Only the sites whose admins turned on automatic tagging.
	 */
	public static function wake_all( bool $only_auto = false ): void {
		global $wpdb;

		if ( ! is_multisite() ) {
			if ( ! $only_auto || Equalify_Iris_Settings::site_auto() ) {
				self::wake();
			}
			return;
		}

		$now  = time();
		$auto = $only_auto ? $wpdb->prepare( " AND EXISTS ( SELECT 1 FROM {$wpdb->blogmeta} a WHERE a.blog_id = b.blog_id AND a.meta_key = %s )", self::AUTO ) : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->blogmeta} m JOIN {$wpdb->blogs} b ON b.blog_id = m.blog_id
				SET m.meta_value = %s
				WHERE m.meta_key = %s AND CAST( m.meta_value AS UNSIGNED ) > %d AND b.site_id = %d $auto",
				(string) $now,
				self::DUE,
				$now,
				get_current_network_id()
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->blogmeta} ( blog_id, meta_key, meta_value )
				SELECT b.blog_id, %s, %s FROM {$wpdb->blogs} b
				WHERE b.site_id = %d AND b.deleted = 0 AND b.archived = 0 AND b.spam = 0 $auto
				AND NOT EXISTS ( SELECT 1 FROM {$wpdb->blogmeta} d WHERE d.blog_id = b.blog_id AND d.meta_key = %s )",
				self::DUE,
				(string) $now,
				get_current_network_id(),
				self::DUE
			)
		);
		// phpcs:enable
	}

	/** @param int $at 0 when the site has nothing left to do. */
	private static function set_due( int $site_id, int $at ): void {
		global $wpdb;

		if ( ! is_multisite() ) {
			$at ? update_option( self::DUE, $at, false ) : delete_option( self::DUE );
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$wpdb->delete( $wpdb->blogmeta, array( 'blog_id' => $site_id, 'meta_key' => self::DUE ) );

		if ( $at ) {
			$wpdb->insert( $wpdb->blogmeta, array( 'blog_id' => $site_id, 'meta_key' => self::DUE, 'meta_value' => (string) $at ) );
		}
		// phpcs:enable
	}

	public static function mark_used(): void {
		if ( is_multisite() && ! get_site_meta( get_current_blog_id(), self::USED, true ) ) {
			update_site_meta( get_current_blog_id(), self::USED, 1 );
		}
	}

	/** Record whether the current site's admin has automatic tagging on. */
	public static function set_site_auto_flag( bool $on ): void {
		if ( ! is_multisite() ) {
			return;
		}

		$on ? update_site_meta( get_current_blog_id(), self::AUTO, 1 ) : delete_site_meta( get_current_blog_id(), self::AUTO );
	}

	// -----------------------------------------------------------------------
	// PDFs at Iris, across the network
	// -----------------------------------------------------------------------

	/** @return array<string, int> "site:attachment" => when it was uploaded. */
	private static function at_iris(): array {
		return (array) get_site_option( self::AT_IRIS, array() );
	}

	/** How many more PDFs the network may send to Iris now. */
	public static function slots(): int {
		return self::max_at_iris() - count( self::at_iris() );
	}

	/**
	 * Replace the current site's entries with the PDFs it has at Iris now.
	 *
	 * @param int[] $working
	 */
	public static function sync_at_iris( array $working ): void {
		$prefix = get_current_blog_id() . ':';
		$all    = self::at_iris();
		$kept   = array();

		foreach ( $all as $key => $since ) {
			if ( ! str_starts_with( (string) $key, $prefix ) ) {
				$kept[ $key ] = $since;
			}
		}

		foreach ( $working as $id ) {
			$kept[ $prefix . $id ] = $all[ $prefix . $id ] ?? time();
		}

		if ( $kept !== $all ) {
			update_site_option( self::AT_IRIS, $kept );
		}
	}

	/**
	 * Drop entries for PDFs that are no longer at Iris: a site admin deleted or
	 * replaced them, or the site was deleted, between runs.
	 */
	private static function forget_stale_sessions(): void {
		$all  = self::at_iris();
		$kept = $all;

		foreach ( $all as $key => $since ) {
			if ( time() - (int) $since < 15 * MINUTE_IN_SECONDS ) {
				continue;
			}

			list( $site_id, $id ) = array_map( 'intval', explode( ':', (string) $key ) + array( 0, 0 ) );

			$still = false;

			if ( ! is_multisite() || get_site( $site_id ) ) {
				if ( is_multisite() ) {
					switch_to_blog( $site_id );
				}

				$still = Equalify_Iris_Tagger::WORKING === Equalify_Iris_Tagger::status( $id );

				if ( is_multisite() ) {
					restore_current_blog();
				}
			}

			if ( ! $still ) {
				unset( $kept[ $key ] );
			}
		}

		if ( $kept !== $all ) {
			update_site_option( self::AT_IRIS, $kept );
		}
	}

	/** How many PDFs are at Iris now, across the network. */
	public static function count_at_iris(): int {
		return count( self::at_iris() );
	}
}
