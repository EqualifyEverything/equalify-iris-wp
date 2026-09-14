<?php
/**
 * WHAT IS THIS FILE?
 *
 * The one-time walk through every site in the network, looking for PDFs on
 * already-published content.
 *
 * WHY DOES IT EXIST?
 *
 * The publish hook in class-discovery.php only sees content published from now on.
 * A network that has existed for ten years has thousands of PDFs already sitting
 * on published pages, and those are the ones people are struggling with today. The
 * sweep is how we catch up with the past.
 *
 * THE ONE HARD REQUIREMENT: IT MUST SURVIVE BEING INTERRUPTED
 *
 * This walk can take days on a large network. In that time there will be a deploy,
 * a PHP timeout, an admin pressing Stop, a server restart. If any of those made
 * the sweep start over, it would never finish — and if any of them made it skip
 * ahead, we would silently miss documents and never know which.
 *
 * So the sweep keeps its place in a network option, updated after every batch:
 * which site it is on, and the last post id it handled there. Restarting from that
 * cursor is exactly equivalent to never having stopped. Nothing is scanned twice
 * and nothing is skipped.
 *
 * WHY ASCENDING POST ID ORDER?
 *
 * Because it is the only ordering that is stable while the site is being edited. If
 * we walked by date modified, editing an old post during the sweep would move it
 * behind our cursor and we would never see it. Ids never change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Sweeper {

	private Equalify_Iris_Discovery $discovery;

	public function __construct( Equalify_Iris_Discovery $discovery ) {
		$this->discovery = $discovery;
	}

	/**
	 * Has the sweep finished walking the whole network?
	 *
	 * The dashboard uses this to decide whether to call its totals "estimated".
	 * Until the sweep is done we genuinely do not know how many PDFs exist, and a
	 * progress bar whose total quietly grows is worse than one that admits it does
	 * not know yet.
	 */
	public static function is_complete(): bool {
		$cursor = (array) Equalify_Iris_Settings::get( 'sweep_cursor' );

		return ! empty( $cursor['complete'] );
	}

	/** Where the sweep has got to. */
	public static function cursor(): array {
		return wp_parse_args(
			(array) Equalify_Iris_Settings::get( 'sweep_cursor' ),
			array(
				'site_id'  => 0,
				'post_id'  => 0,
				'complete' => false,
			)
		);
	}

	/** Start the sweep over from the beginning. */
	public static function reset(): void {
		Equalify_Iris_Settings::set(
			'sweep_cursor',
			array(
				'site_id'  => 0,
				'post_id'  => 0,
				'complete' => false,
			)
		);
	}

	/**
	 * Do one batch of sweeping.
	 *
	 * Called once per background tick. Reads at most `posts_per_tick` posts, saves
	 * its place, and returns.
	 *
	 * @return array{scanned: int, found: int, site_id: int, complete: bool}
	 */
	public function run_batch(): array {
		$result = array(
			'scanned'  => 0,
			'found'    => 0,
			'site_id'  => 0,
			'complete' => false,
		);

		if ( self::is_complete() ) {
			$result['complete'] = true;

			return $result;
		}

		$cursor    = self::cursor();
		$site_ids  = self::site_ids();

		if ( ! $site_ids ) {
			self::mark_complete();
			$result['complete'] = true;

			return $result;
		}

		// Pick up on the site we were on, or start at the first one.
		$site_id = (int) $cursor['site_id'];

		if ( ! $site_id || ! in_array( $site_id, $site_ids, true ) ) {
			// Either we have not started, or the site we were on was deleted
			// mid-sweep. Either way, move to the first site at or after our
			// position rather than starting the whole network again.
			$site_id = self::next_site_after( $site_ids, $site_id );

			if ( ! $site_id ) {
				self::mark_complete();
				$result['complete'] = true;

				return $result;
			}

			$cursor['post_id'] = 0;
		}

		$result['site_id'] = $site_id;

		$per_batch = max( 1, (int) Equalify_Iris_Settings::get( 'posts_per_tick' ) );

		// switch_to_blog() makes every WordPress function act as though we are on
		// that site — its posts, its uploads, its options. It must always be
		// matched by restore_current_blog(), which is why the loop below is
		// wrapped rather than returning early.
		switch_to_blog( $site_id );

		$post_ids = $this->post_ids_after( (int) $cursor['post_id'], $per_batch );

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( $post ) {
				$result['found'] += $this->discovery->scan_post( $post );
			}

			++$result['scanned'];

			// Save our place after EVERY post, not after the batch. If the process
			// dies halfway through a batch, we resume from the last post actually
			// finished rather than redoing the batch.
			$cursor['post_id'] = $post_id;
		}

		restore_current_blog();

		if ( count( $post_ids ) < $per_batch ) {
			// We reached the end of this site. Move to the next one, or finish.
			$next_site = self::next_site_after( $site_ids, $site_id );

			if ( $next_site ) {
				$cursor['site_id'] = $next_site;
				$cursor['post_id'] = 0;
			} else {
				self::mark_complete();
				$result['complete'] = true;

				return $result;
			}
		} else {
			$cursor['site_id'] = $site_id;
		}

		Equalify_Iris_Settings::set( 'sweep_cursor', $cursor );

		return $result;
	}

	/**
	 * The next batch of post ids on the current site, after a given id.
	 *
	 * Deliberately a direct query rather than WP_Query. We want ids only, in id
	 * order, with no meta joins, no term joins, no post object hydration and no
	 * found-rows count — because we are going to run this thousands of times and
	 * WP_Query's convenience would cost more than it saves here.
	 */
	private function post_ids_after( int $after_id, int $limit ): array {
		global $wpdb;

		$post_types = Equalify_Iris_Settings::included_post_types();

		if ( ! $post_types ) {
			return array();
		}

		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		$values = array_merge( $post_types, array( $after_id, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				  WHERE post_type IN ({$type_placeholders})
				    AND post_status = 'publish'
				    AND post_password = ''
				    AND ID > %d
				  ORDER BY ID ASC
				  LIMIT %d",
				...$values
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Every site we should sweep, in ascending id order.
	 */
	private static function site_ids(): array {
		$sites = get_sites(
			array(
				'fields'   => 'ids',
				'number'   => 0, // No limit. get_sites() defaults to 100, which
				                 // would silently ignore site 101 onwards.
				'orderby'  => 'id',
				'order'    => 'ASC',
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);

		$sites = array_map( 'intval', (array) $sites );

		return array_values(
			array_filter(
				$sites,
				static function ( int $site_id ): bool {
					return Equalify_Iris_Settings::site_is_included( $site_id );
				}
			)
		);
	}

	/**
	 * The first site id greater than the one given, or 0 if there is none.
	 */
	private static function next_site_after( array $site_ids, int $current ): int {
		foreach ( $site_ids as $site_id ) {
			if ( $site_id > $current ) {
				return $site_id;
			}
		}

		return 0;
	}

	/** Record that the whole network has been walked. */
	private static function mark_complete(): void {
		$cursor             = self::cursor();
		$cursor['complete'] = true;

		Equalify_Iris_Settings::set( 'sweep_cursor', $cursor );

		Equalify_Iris_Logger::log(
			__( 'Finished searching every site for PDFs. From now on, new PDFs are picked up when content is published or updated.', 'equalify-iris' )
		);
	}

	/**
	 * A rough count of how many published posts exist across the network.
	 *
	 * Used only to show sweep progress as a fraction. It is an estimate and the
	 * dashboard says so: posts are published and deleted while the sweep runs, so
	 * any total is out of date the moment it is counted.
	 */
	public static function estimated_total_posts(): int {
		$cached = get_site_transient( 'equalify_iris_post_total' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$total = 0;

		foreach ( self::site_ids() as $site_id ) {
			switch_to_blog( $site_id );

			foreach ( Equalify_Iris_Settings::included_post_types() as $post_type ) {
				$counts = wp_count_posts( $post_type );
				$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
			}

			restore_current_blog();
		}

		// Cached for an hour: this loops every site in the network, so it is far
		// too expensive to run on every dashboard page load.
		set_site_transient( 'equalify_iris_post_total', $total, HOUR_IN_SECONDS );

		return $total;
	}
}
