<?php
/**
 * WHAT IS THIS FILE?
 *
 * Every read and write of the two tables from class-database.php. The queue.
 *
 * WHY DOES IT EXIST?
 *
 * So that no other file writes SQL. Everything else in the plugin asks this
 * class questions in plain language — "give me the next document to upload",
 * "how many are converting right now?", "mark this one failed" — and this is the
 * only place that knows those are database queries.
 *
 * THE STATUS LIFECYCLE, IN ORDER
 *
 *   pending    We know about it and have not started.
 *   checking   We are reading the file for its page count and size.
 *   uploading  We are sending it to Iris right now.
 *   converting Iris has it. We wait, and check back with growing gaps.
 *   ready      Iris finished. Nobody has downloaded it yet.
 *   importing  We are downloading and saving the HTML right now.
 *   published  Done. The page is live and the icon appears.
 *
 * WHY ARE `ready` AND `importing` TWO SEPARATE STATUSES?
 *
 * Because of how claiming works. A background tick takes ownership of a document
 * by moving it OUT of one status and INTO another in a single database statement
 * (see claim_next() below). That only works if the two statuses are different — if
 * a tick claimed `importing` by setting it to `importing`, the database would
 * report that nothing changed and the claim would look like it failed.
 *
 * So `ready` means "waiting for someone to pick this up" and `importing` means
 * "someone has picked it up". Splitting them is what makes it impossible for two
 * overlapping ticks to import the same document.
 *
 * And the ways out of that path:
 *
 *   retired    No longer on any published page, so the HTML was unpublished.
 *   too_long   More than 25 pages. Iris cannot take it, and never will.
 *   too_big    Over the file size limit.
 *   failed     Something went wrong. Worth retrying.
 *   skipped    Deliberately excluded by a setting.
 *
 * WHY too_long AND too_big ARE NOT JUST "failed"
 *
 * A `failed` document is worth retrying; a 60-page PDF will never succeed no
 * matter how many times we try. Mixing them would make the dashboard's failure
 * count meaningless — an admin would see "412 failed" forever, learn that the
 * number never goes down, and stop looking at it. Separating them means the
 * failure count is a number someone can actually drive to zero.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Documents {

	const PENDING    = 'pending';
	const CHECKING   = 'checking';
	const UPLOADING  = 'uploading';
	const CONVERTING = 'converting';
	const READY      = 'ready';
	const IMPORTING  = 'importing';
	const PUBLISHED  = 'published';
	const RETIRED    = 'retired';
	const TOO_LONG   = 'too_long';
	const TOO_BIG    = 'too_big';
	const FAILED     = 'failed';
	const SKIPPED    = 'skipped';

	/**
	 * How many times we try a document before leaving it alone.
	 *
	 * After this many attempts it stays `failed` until a human presses Retry.
	 * Retrying forever would mean one permanently broken PDF consuming an upload
	 * slot every few minutes for the rest of time.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * How long a document may sit at Iris before we call it stuck, in seconds.
	 *
	 * Two hours. Iris genuinely queues work when busy — a long wait is not proof
	 * of a problem, which is why this is hours and not minutes. But a document
	 * that never resolves must not be polled until the end of time.
	 */
	const CONVERSION_TIMEOUT = 7200;

	/**
	 * Statuses that mean "Iris is working on this right now".
	 *
	 * Used to count what is in flight, so we never exceed the concurrency limit.
	 */
	public static function in_flight_statuses(): array {
		return array( self::UPLOADING, self::CONVERTING, self::READY, self::IMPORTING );
	}

	/**
	 * Every status, with a plain-language label for the dashboard.
	 */
	public static function status_labels(): array {
		return array(
			self::PENDING    => __( 'Waiting to start', 'equalify-iris' ),
			self::CHECKING   => __( 'Checking the file', 'equalify-iris' ),
			self::UPLOADING  => __( 'Uploading', 'equalify-iris' ),
			self::CONVERTING => __( 'Converting', 'equalify-iris' ),
			self::READY      => __( 'Ready to save', 'equalify-iris' ),
			self::IMPORTING  => __( 'Saving the page', 'equalify-iris' ),
			self::PUBLISHED  => __( 'Published', 'equalify-iris' ),
			self::RETIRED    => __( 'Retired — no longer public', 'equalify-iris' ),
			self::TOO_LONG   => __( 'Too long to convert', 'equalify-iris' ),
			self::TOO_BIG    => __( 'File too large', 'equalify-iris' ),
			self::FAILED     => __( 'Failed', 'equalify-iris' ),
			self::SKIPPED    => __( 'Skipped', 'equalify-iris' ),
		);
	}

	/** The human label for one status. */
	public static function status_label( string $status ): string {
		$labels = self::status_labels();

		return $labels[ $status ] ?? $status;
	}

	// -----------------------------------------------------------------------
	// Adding and finding documents
	// -----------------------------------------------------------------------

	/**
	 * Record that a PDF exists and should be converted.
	 *
	 * Safe to call repeatedly for the same PDF: the unique key on
	 * (site_id, attachment_id) means the second call finds the existing row and
	 * returns it rather than creating a duplicate. Discovery runs constantly and
	 * re-finds the same PDFs all the time, so this has to be cheap and idempotent.
	 *
	 * @return int The document id, or 0 if it could not be stored.
	 */
	public static function add( int $site_id, int $attachment_id, string $pdf_url ): int {
		global $wpdb;

		$existing = self::find_by_attachment( $site_id, $attachment_id );

		if ( $existing ) {
			if ( self::RETIRED === $existing->status ) {
				// This PDF went away and has come back — someone republished the
				// page, or linked the file somewhere new. Bring the accessible
				// version back rather than converting it a second time.
				self::revive( $existing );
			}

			return (int) $existing->id;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Equalify_Iris_Database::documents_table(),
			array(
				'site_id'        => $site_id,
				'attachment_id'  => $attachment_id,
				'pdf_url'        => $pdf_url,
				'status'         => self::PENDING,
				'next_action_at' => $now,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Bring a retired document back to life.
	 *
	 * Retiring never deleted anything — the converted HTML is still sitting there as
	 * a draft. So coming back is just republishing it, at the same URL it had before.
	 * Every link anyone shared still works, and we do not pay to convert the same
	 * file twice.
	 *
	 * If there is no saved page (the document was retired before it ever finished),
	 * it simply goes back in the queue.
	 */
	private static function revive( object $document ): void {
		$has_page = false;

		if ( $document->doc_post_id ) {
			switch_to_blog( (int) $document->site_id );

			$post = get_post( (int) $document->doc_post_id );

			// 'publish' is WordPress's word for a live post. Our own PUBLISHED
			// constant is 'published' and means something different — the stage our
			// pipeline has reached — so the two must not be compared.
			if ( $post && 'publish' !== $post->post_status ) {
				wp_update_post(
					array(
						'ID'          => (int) $document->doc_post_id,
						'post_status' => 'publish',
					)
				);
			}

			$has_page = (bool) $post;

			restore_current_blog();
		}

		self::update(
			(int) $document->id,
			array(
				'status'         => $has_page ? self::PUBLISHED : self::PENDING,
				'last_error'     => '',
				'next_action_at' => current_time( 'mysql', true ),
			)
		);
	}

	/** Look one document up by the PDF it belongs to. */
	public static function find_by_attachment( int $site_id, int $attachment_id ) {
		global $wpdb;

		$table = Equalify_Iris_Database::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE site_id = %d AND attachment_id = %d",
				$site_id,
				$attachment_id
			)
		);
	}

	/** Look one document up by our own id. */
	public static function find( int $id ) {
		global $wpdb;

		$table = Equalify_Iris_Database::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Update columns on one document. Always stamps `updated_at`.
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;

		$fields['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			Equalify_Iris_Database::documents_table(),
			$fields,
			array( 'id' => $id )
		);

		return false !== $updated;
	}

	/**
	 * Move a document to a new status, optionally with an explanation.
	 *
	 * @param string $error A full sentence for the admin, or '' to clear the last
	 *                      error. Clearing on success matters: a document that
	 *                      failed, was retried, and succeeded should not still
	 *                      show yesterday's error next to a green status.
	 */
	public static function set_status( int $id, string $status, string $error = '' ): bool {
		return self::update(
			$id,
			array(
				'status'     => $status,
				'last_error' => $error,
			)
		);
	}

	/**
	 * Mark a document failed, counting the attempt.
	 *
	 * Below MAX_ATTEMPTS the document goes back to `pending` with a delay, so it
	 * is retried automatically. At the limit it stays `failed` and waits for a
	 * human. The distinction is invisible to the caller on purpose — nothing
	 * outside this class should have to remember the retry policy.
	 */
	public static function fail( int $id, string $error ): void {
		$document = self::find( $id );

		if ( ! $document ) {
			return;
		}

		$attempts = (int) $document->attempts + 1;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::update(
				$id,
				array(
					'status'          => self::FAILED,
					'attempts'        => $attempts,
					'last_error'      => $error,
					'iris_session_id' => '',
				)
			);

			Equalify_Iris_Logger::error(
				sprintf(
					/* translators: 1: PDF file name, 2: the reason it failed. */
					__( 'Gave up on "%1$s" after several attempts. %2$s', 'equalify-iris' ),
					self::display_name( $document ),
					$error
				),
				array( 'document_id' => $id )
			);

			return;
		}

		// Back off before the next attempt: 5 minutes, then 10, then 20, then 40.
		// A failure is often something temporary (a busy server, a network
		// blip), and retrying immediately would just fail again while using up
		// the attempt budget.
		$delay = 300 * ( 2 ** ( $attempts - 1 ) );

		self::update(
			$id,
			array(
				'status'          => self::PENDING,
				'attempts'        => $attempts,
				'last_error'      => $error,
				'iris_session_id' => '',
				'checks'          => 0,
				'next_action_at'  => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			)
		);
	}

	/**
	 * A readable name for a document, for logs and the dashboard.
	 *
	 * Falls back to the file name from the URL, because the attachment may live
	 * on another site in the network and looking up its real title would mean a
	 * switch_to_blog() call for every row on a listing page.
	 */
	public static function display_name( object $document ): string {
		$path = wp_parse_url( $document->pdf_url, PHP_URL_PATH );

		return $path ? basename( $path ) : ( '#' . $document->id );
	}

	// -----------------------------------------------------------------------
	// Claiming work — the part that has to be right
	// -----------------------------------------------------------------------

	/**
	 * Take ownership of the next document in a given status, if there is one.
	 *
	 * THIS IS THE MOST IMPORTANT FUNCTION IN THE PLUGIN, so it is worth reading
	 * slowly.
	 *
	 * Two cron runs overlapping is normal on a busy site, not an edge case: WP
	 * Cron fires on page loads, and two visitors can arrive at the same moment.
	 * If both runs did "find the next pending document, then mark it uploading",
	 * both would find the SAME document in the gap between those two steps, and
	 * we would upload it twice — paying twice, and racing to write two different
	 * session ids onto one row.
	 *
	 * The fix is to make finding and claiming a single step. The UPDATE below
	 * changes the row only if it is still in the status we expect, and the
	 * database tells us how many rows that changed. Exactly one caller can get
	 * `1`; everyone else gets `0` and moves on. No separate lock table, no
	 * transient, no window for two processes to both believe they won — the
	 * database's own row locking does the work.
	 *
	 * @param string $from The status to claim from.
	 * @param string $to   The status to move it to, marking it ours.
	 * @return object|null The claimed document, or null if there was nothing to
	 *                     claim or another process claimed it first.
	 */
	public static function claim_next( string $from, string $to ) {
		global $wpdb;

		$table = Equalify_Iris_Database::documents_table();
		$now   = current_time( 'mysql', true );

		// Find a candidate. This may be stale by the time we act on it, which is
		// fine — the UPDATE below is what actually decides.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				  WHERE status = %s AND next_action_at <= %s
				  ORDER BY next_action_at ASC, id ASC
				  LIMIT 5",
				$from,
				$now
			)
		);

		foreach ( $candidate_ids as $candidate_id ) {
			// The claim. `status = %s` in the WHERE clause is the whole safety
			// mechanism: if another process already moved this row, the WHERE
			// no longer matches and this changes nothing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
					$to,
					$now,
					(int) $candidate_id,
					$from
				)
			);

			if ( 1 === (int) $claimed ) {
				return self::find( (int) $candidate_id );
			}

			// Zero rows changed: someone else got this one. Try the next
			// candidate rather than giving up, so a tick is not wasted just
			// because it lost one race.
		}

		return null;
	}

	/**
	 * Documents Iris is currently working on, oldest check first.
	 *
	 * @param int $limit How many to return, so a tick can bound its polling.
	 */
	public static function due_for_status_check( int $limit ): array {
		global $wpdb;

		$table = Equalify_Iris_Database::documents_table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				  WHERE status = %s AND next_action_at <= %s
				  ORDER BY next_action_at ASC
				  LIMIT %d",
				self::CONVERTING,
				$now,
				$limit
			)
		);
	}

	/**
	 * How many documents are at Iris right now.
	 *
	 * This is what keeps us inside the concurrency limit. It counts uploading,
	 * converting and importing together, because all three mean a session exists
	 * on the server.
	 */
	public static function in_flight_count(): int {
		global $wpdb;

		$table    = Equalify_Iris_Database::documents_table();
		$statuses = self::in_flight_statuses();

		// One %s placeholder per status. Building the list this way rather than
		// interpolating the array keeps the query prepared.
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})",
				...$statuses
			)
		);
	}

	/**
	 * How many documents are in each status, network-wide.
	 *
	 * One grouped query rather than one COUNT per status, because the dashboard
	 * shows all of them at once and eleven queries to draw one panel is careless.
	 */
	public static function counts_by_status(): array {
		global $wpdb;

		$table = Equalify_Iris_Database::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );

		// Start every status at zero so the dashboard never has to check whether
		// a key exists before printing it.
		$counts = array_fill_keys( array_keys( self::status_labels() ), 0 );

		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->total;
		}

		$counts['total'] = array_sum( $counts );

		return $counts;
	}

	/**
	 * How many documents finished in the last day.
	 *
	 * Used to estimate how long the remaining work will take. Measuring the real
	 * rate is the only honest way to do that — the theoretical rate depends on
	 * page counts and on how busy the shared Iris deployment is, neither of which
	 * we can know in advance.
	 */
	public static function published_in_last_day(): int {
		global $wpdb;

		$table = Equalify_Iris_Database::documents_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND updated_at >= %s",
				self::PUBLISHED,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * A page of documents for the dashboard table.
	 *
	 * @param array $args site_id, status, search, per_page, page.
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'site_id'  => 0,
				'status'   => '',
				'search'   => '',
				'per_page' => 25,
				'page'     => 1,
			)
		);

		$table  = Equalify_Iris_Database::documents_table();
		$where  = array( '1=1' );
		$values = array();

		if ( $args['site_id'] ) {
			$where[]  = 'site_id = %d';
			$values[] = (int) $args['site_id'];
		}

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( $args['search'] ) {
			$where[]  = 'pdf_url LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $values ? $wpdb->prepare( $count_sql, ...$values ) : $count_sql );

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$items_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$items_values = array_merge( $values, array( $per_page, $offset ) );
		$items        = $wpdb->get_results( $wpdb->prepare( $items_sql, ...$items_values ) );
		// phpcs:enable

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Put a document back in the queue for another try.
	 *
	 * Resets the attempt counter, because a human pressing Retry is making a
	 * fresh decision and should get the full set of attempts again rather than
	 * one last go.
	 */
	public static function retry( int $id ): void {
		self::update(
			$id,
			array(
				'status'          => self::PENDING,
				'attempts'        => 0,
				'checks'          => 0,
				'last_error'      => '',
				'iris_session_id' => '',
				'next_action_at'  => current_time( 'mysql', true ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Sightings — where each PDF appears
	// -----------------------------------------------------------------------

	/**
	 * Record that a PDF appears on a post, or refresh when we last saw it there.
	 */
	public static function record_sighting( int $document_id, int $site_id, int $post_id ): void {
		global $wpdb;

		$table = Equalify_Iris_Database::sightings_table();
		$now   = current_time( 'mysql', true );

		// "Insert, or update if it is already there." Doing this in one statement
		// rather than SELECT-then-INSERT avoids the same race as claim_next():
		// discovery can run twice at once, and the unique key would otherwise
		// turn the second insert into a database error in the log.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (document_id, site_id, post_id, last_seen_at)
				 VALUES (%d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)",
				$document_id,
				$site_id,
				$post_id,
				$now
			)
		);
	}

	/**
	 * Forget every sighting on one post.
	 *
	 * Called when a post is unpublished, trashed, deleted, or edited — in the
	 * edit case, discovery immediately re-adds the PDFs that are still there, so
	 * removing a link from a post correctly drops its sighting.
	 */
	public static function clear_sightings_for_post( int $site_id, int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			Equalify_Iris_Database::sightings_table(),
			array(
				'site_id' => $site_id,
				'post_id' => $post_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * The published documents for the PDFs on one post.
	 *
	 * THIS IS THE QUERY THE FRONT END RUNS, so it is written to be cheap.
	 *
	 * The obvious way to answer "which of this page's PDFs have an accessible
	 * version?" would be to pull out every PDF link, resolve each one to an
	 * attachment, then look up a document for each — several queries per link, on
	 * every page view, forever.
	 *
	 * The sightings table already knows the answer. We recorded which PDFs appear on
	 * which post when we scanned it, so one indexed join gives us everything the
	 * icons need in a single query, no matter how many PDFs the page has. Building
	 * the sightings table for retirement and getting this for free is the main
	 * reason it exists.
	 *
	 * @return array Rows with pdf_url, attachment_id and doc_post_id.
	 */
	public static function published_for_post( int $site_id, int $post_id ): array {
		global $wpdb;

		$documents = Equalify_Iris_Database::documents_table();
		$sightings = Equalify_Iris_Database::sightings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.pdf_url, d.attachment_id, d.doc_post_id, d.page_count, d.file_bytes
				   FROM {$sightings} s
				   INNER JOIN {$documents} d ON d.id = s.document_id
				  WHERE s.site_id = %d
				    AND s.post_id = %d
				    AND d.status = %s
				    AND d.doc_post_id > 0",
				$site_id,
				$post_id,
				self::PUBLISHED
			)
		);
	}

	/** Which posts on which sites link to this PDF? */
	public static function sightings_for( int $document_id ): array {
		global $wpdb;

		$table = Equalify_Iris_Database::sightings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE document_id = %d", $document_id )
		);
	}

	/** How many places does this PDF appear? */
	public static function sighting_count( int $document_id ): int {
		global $wpdb;

		$table = Equalify_Iris_Database::sightings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE document_id = %d", $document_id )
		);
	}

	/**
	 * Documents that have no sightings left at all.
	 *
	 * These are the candidates for retirement: nothing published links to them
	 * any more, so their accessible version should not stay public. Statuses that
	 * never produced a page are excluded — there is nothing to retire, and
	 * flipping a `too_long` document to `retired` would hide a real gap from the
	 * admin.
	 *
	 * THE GRACE PERIOD
	 *
	 * A document is created and its first sighting recorded a fraction of a second
	 * later, in that order. For that fraction of a second the row genuinely has no
	 * sightings — and if a background tick looked in exactly then, it would retire a
	 * document that was only just discovered. Ignoring anything created in the last
	 * ten minutes closes that window completely, and costs nothing: a PDF that
	 * really has stopped being public can wait ten minutes to be noticed.
	 *
	 * @param int $limit Bounded, because this runs inside a tick's budget.
	 */
	public static function orphaned( int $limit = 20 ): array {
		global $wpdb;

		$documents = Equalify_Iris_Database::documents_table();
		$sightings = Equalify_Iris_Database::sightings_table();

		// LEFT JOIN ... WHERE the joined id IS NULL is the standard way to ask
		// "rows on the left with nothing matching on the right".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.* FROM {$documents} d
				  LEFT JOIN {$sightings} s ON s.document_id = d.id
				  WHERE s.id IS NULL
				    AND d.status IN (%s, %s, %s)
				    AND d.created_at < %s
				  LIMIT %d",
				self::PUBLISHED,
				self::PENDING,
				self::FAILED,
				gmdate( 'Y-m-d H:i:s', time() - ( 10 * MINUTE_IN_SECONDS ) ),
				$limit
			)
		);
	}
}
