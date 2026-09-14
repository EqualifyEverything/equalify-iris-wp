<?php
/**
 * WHAT IS THIS FILE?
 *
 * The background job. One run of it is called a "tick".
 *
 * WHY DOES IT EXIST?
 *
 * Converting thousands of PDFs takes days. Nobody can sit and watch it, and no
 * single web request could do it. So the work is broken into ticks: every five
 * minutes, do a small, strictly bounded amount, save the results, and stop.
 *
 * THE RESOURCE CONTRACT
 *
 * This is the part that keeps the plugin from being the reason a host complains.
 * No matter how many thousands of documents are queued, ONE TICK DOES AT MOST:
 *
 *   - 20 seconds of wall-clock time
 *   - 1 PDF upload
 *   - 5 status checks
 *   - 2 imports
 *   - 20 posts scanned by the sweep
 *
 * Every one of those is configurable, and every one is checked BETWEEN
 * operations, never in the middle of one. That is what makes an interrupted tick
 * safe: whenever it stops, it stops somewhere the database already knows about.
 * There is no state held in a variable that would be lost.
 *
 * WHY IS THE ORDER OF WORK BELOW WHAT IT IS?
 *
 * Finishing beats starting. Imports come before uploads, because an import
 * completes a document a visitor is waiting for and frees a conversion slot,
 * whereas an upload only adds more work in progress. Retirement comes early
 * because it is a privacy obligation, not an optimisation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Worker {

	private Equalify_Iris_Sweeper $sweeper;

	/** When this tick started, from microtime(), for the time budget. */
	private float $started_at = 0.0;

	/** How many seconds this tick may run for. */
	private int $budget = 20;

	public function __construct( Equalify_Iris_Sweeper $sweeper ) {
		$this->sweeper = $sweeper;
	}

	/**
	 * Run one tick.
	 *
	 * @return array A summary of what happened, for WP-CLI and the tests.
	 */
	public function run(): array {
		$this->started_at = microtime( true );
		$this->budget     = max( 5, (int) Equalify_Iris_Settings::get( 'tick_budget_seconds' ) );

		$summary = array(
			'ran'          => false,
			'reason'       => '',
			'checked'      => 0,
			'imported'     => 0,
			'uploaded'     => 0,
			'retired'      => 0,
			'swept'        => 0,
			'found'        => 0,
			'seconds'      => 0.0,
		);

		// Record that we ran, before doing anything else. The dashboard uses this
		// to tell "nothing is happening because cron is broken" apart from
		// "nothing is happening because there is nothing to do" — and those look
		// identical from the outside, so the distinction has to be recorded even
		// on a tick that does no work at all.
		Equalify_Iris_Settings::set( 'last_tick', time() );

		if ( ! Equalify_Iris_Settings::get( 'running' ) ) {
			$summary['reason'] = 'stopped';

			return $summary;
		}

		// Only stop when Iris has actually refused us and a human has to act. Not
		// "have we got a credential": Iris has no sign-in, most deployments are
		// open, and gating on a stored token would mean converting nothing at all
		// on a correctly configured network. See blocked_by_auth().
		if ( Equalify_Iris_Settings::blocked_by_auth() ) {
			$summary['reason'] = 'needs_token';

			return $summary;
		}

		if ( ! Equalify_Iris_Database::tables_exist() ) {
			$summary['reason'] = 'no_tables';

			return $summary;
		}

		if ( Equalify_Iris_API_Client::circuit_is_open() ) {
			// Iris is unreachable or asked us to slow down. Do the local-only work
			// anyway — sweeping and retiring need no network at all, so an Iris
			// outage should not also stop us discovering documents.
			$summary['reason'] = 'paused';

			$summary['retired'] = $this->retire_orphans();
			$sweep              = $this->sweep();
			$summary['swept']   = $sweep['scanned'];
			$summary['found']   = $sweep['found'];
			$summary['seconds'] = round( microtime( true ) - $this->started_at, 2 );

			return $summary;
		}

		$summary['ran'] = true;

		// 1. Ask Iris how the in-flight documents are doing. Cheap, and it is what
		//    moves documents towards being finished.
		$summary['checked'] = $this->check_converting();

		// 2. Import anything that finished. Highest value work in the tick: this is
		//    what actually publishes a page.
		$summary['imported'] = $this->import_ready();

		// 3. Send new work, but only if there is room at Iris.
		$summary['uploaded'] = $this->upload_pending();

		// 4. Unpublish documents that are no longer on any public page.
		$summary['retired'] = $this->retire_orphans();

		// 5. Keep walking the network, if the first sweep is still going. Last
		//    because it only creates work, and only matters once the rest is done.
		$sweep            = $this->sweep();
		$summary['swept'] = $sweep['scanned'];
		$summary['found'] = $sweep['found'];

		$summary['seconds'] = round( microtime( true ) - $this->started_at, 2 );

		return $summary;
	}

	/**
	 * Is there time left in this tick?
	 *
	 * Called between operations. Every loop below checks it, which is what makes
	 * the time budget real rather than aspirational.
	 */
	private function has_time(): bool {
		return ( microtime( true ) - $this->started_at ) < $this->budget;
	}

	// -----------------------------------------------------------------------
	// Step 1: check on documents Iris is converting
	// -----------------------------------------------------------------------

	/**
	 * Ask Iris about each document it is working on.
	 */
	private function check_converting(): int {
		$limit     = max( 1, (int) Equalify_Iris_Settings::get( 'status_checks_per_tick' ) );
		$documents = Equalify_Iris_Documents::due_for_status_check( $limit );
		$checked   = 0;

		foreach ( $documents as $document ) {
			if ( ! $this->has_time() ) {
				break;
			}

			$this->check_one( $document );
			++$checked;
		}

		return $checked;
	}

	/**
	 * Check one converting document.
	 */
	private function check_one( object $document ): void {
		$id = (int) $document->id;

		// Has it been at Iris too long? Iris really does queue work when busy, so a
		// long wait is not proof of a problem — which is why this timeout is hours
		// rather than minutes. But something has to end, or we would poll a lost
		// document forever.
		if ( $document->started_at ) {
			$elapsed = time() - (int) strtotime( $document->started_at . ' UTC' );

			if ( $elapsed > Equalify_Iris_Documents::CONVERSION_TIMEOUT ) {
				Equalify_Iris_Documents::fail(
					$id,
					__( 'This document did not finish converting within two hours. It can be retried.', 'equalify-iris' )
				);

				return;
			}
		}

		$session = Equalify_Iris_API_Client::get_session( (string) $document->iris_session_id );

		if ( is_wp_error( $session ) ) {
			// A failed check is not a failed document — the network may simply have
			// blipped. Try again on the next tick, and only give up after
			// MAX_ATTEMPTS worth of these.
			$this->delay_next_check( $document );

			return;
		}

		$status = isset( $session['status'] ) ? (string) $session['status'] : '';

		if ( 'ready_for_review' === $status || 'closed' === $status ) {
			Equalify_Iris_Documents::set_status( $id, Equalify_Iris_Documents::READY );

			return;
		}

		if ( 'failed' === $status ) {
			// Iris explains its own failures well, so use its words rather than
			// inventing ours.
			$reason = isset( $session['error'] ) && $session['error']
				? (string) $session['error']
				: __( 'Equalify Iris could not convert this document.', 'equalify-iris' );

			Equalify_Iris_Documents::fail( $id, $reason );

			return;
		}

		// Anything else — queued, running, or a status this version of the plugin
		// has never heard of — means keep waiting. The Iris docs specifically say a
		// client should treat that list as open and fall back to waiting rather
		// than failing on an unfamiliar value.
		$this->delay_next_check( $document );
	}

	/**
	 * Wait longer before the next check, the longer this has been going.
	 *
	 * Backoff schedule: 1 minute, 2, 4, 8, then 15 minutes from then on. Checking
	 * every minute for two hours would be 120 requests for one document, and would
	 * scale with the queue rather than with anything useful.
	 */
	private function delay_next_check( object $document ): void {
		$checks = (int) $document->checks + 1;

		$delay = min( 900, 60 * ( 2 ** min( 6, $checks - 1 ) ) );

		Equalify_Iris_Documents::update(
			(int) $document->id,
			array(
				'checks'         => $checks,
				'next_action_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Step 2: import finished documents
	// -----------------------------------------------------------------------

	/**
	 * Download and publish everything that is ready.
	 */
	private function import_ready(): int {
		$limit    = max( 1, (int) Equalify_Iris_Settings::get( 'imports_per_tick' ) );
		$imported = 0;

		while ( $imported < $limit && $this->has_time() ) {
			// Claimed rather than simply selected, so two overlapping ticks cannot
			// both import the same document. See Documents::claim_next().
			$document = Equalify_Iris_Documents::claim_next(
				Equalify_Iris_Documents::READY,
				Equalify_Iris_Documents::IMPORTING
			);

			if ( ! $document ) {
				break;
			}

			$this->import_one( $document );
			++$imported;
		}

		return $imported;
	}

	/**
	 * Import one finished document: download, clean, and publish it.
	 */
	private function import_one( object $document ): void {
		$id   = (int) $document->id;
		$html = Equalify_Iris_API_Client::get_output( (string) $document->iris_session_id );

		if ( is_wp_error( $html ) ) {
			// A 409 means Iris says it is not ready after all. Not a failure — go
			// back to waiting.
			$data        = $html->get_error_data();
			$status_code = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

			if ( 409 === $status_code ) {
				Equalify_Iris_Documents::update(
					$id,
					array(
						'status'         => Equalify_Iris_Documents::CONVERTING,
						'next_action_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
					)
				);

				return;
			}

			Equalify_Iris_Documents::fail( $id, $html->get_error_message() );

			return;
		}

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			Equalify_Iris_Documents::fail(
				$id,
				__( 'Equalify Iris returned an empty document.', 'equalify-iris' )
			);

			return;
		}

		// Iris can deliver a document that is missing a page, and say so. Find out
		// before cleaning, because the marker is an HTML comment and cleaning
		// removes comments.
		$missing_pages = self::count_missing_pages( $html );

		$post_id = $this->publish_document( $document, $html, $missing_pages );

		if ( ! $post_id ) {
			Equalify_Iris_Documents::fail(
				$id,
				__( 'The converted document could not be saved as a page.', 'equalify-iris' )
			);

			return;
		}

		Equalify_Iris_Documents::update(
			$id,
			array(
				'status'      => Equalify_Iris_Documents::PUBLISHED,
				'doc_post_id' => $post_id,
				'last_error'  => '',
				'attempts'    => 0,
			)
		);

		// Tell Iris we are done so it can free its disk. Best effort — we already
		// have the HTML, so a failure here must never cost us a finished document.
		Equalify_Iris_API_Client::close_session( (string) $document->iris_session_id );

		if ( $missing_pages > 0 ) {
			// Worth a warning rather than a quiet success. The page is genuinely
			// better than the PDF and worth publishing, but a screen reader user
			// reading it has no way to know that some of the document is not there,
			// and neither would an admin looking at a green "published" row.
			Equalify_Iris_Logger::warning(
				sprintf(
					/* translators: 1: the PDF file name. 2: number of pages. */
					_n(
						'Published an accessible version of "%1$s", but Equalify Iris could not read %2$d of its pages, so that page is missing from it.',
						'Published an accessible version of "%1$s", but Equalify Iris could not read %2$d of its pages, so those pages are missing from it.',
						$missing_pages,
						'equalify-iris'
					),
					Equalify_Iris_Documents::display_name( $document ),
					$missing_pages
				),
				array( 'document_id' => $id )
			);

			return;
		}

		Equalify_Iris_Logger::log(
			sprintf(
				/* translators: %s: the PDF file name. */
				__( 'Published an accessible version of "%s".', 'equalify-iris' ),
				Equalify_Iris_Documents::display_name( $document )
			),
			Equalify_Iris_Logger::INFO,
			array( 'document_id' => $id )
		);
	}

	/**
	 * How many of the source PDF's pages are missing from the converted HTML.
	 *
	 * Iris converts each page separately, and one page can fail on its own without
	 * failing the whole document — a dense table that overran the model's output
	 * limit, say. When that happens Iris still delivers everything else, and marks
	 * the hole with a comment after the closing </main>:
	 *
	 *     <!-- @page-failed 7
	 *       This document is incomplete: ...
	 *     -->
	 *
	 * That comment is placed after </main> specifically so it cannot be edited
	 * away, which is what makes it reliable to look for.
	 *
	 * We count distinct page NUMBERS rather than comments, because the same page is
	 * usually marked twice — once where its content would have been, and once after
	 * </main> — and the first of the two can go missing. Counting occurrences would
	 * report one lost page as two, or as one, depending on which survived.
	 */
	private static function count_missing_pages( string $html ): int {
		if ( ! preg_match_all( '/@page-failed\s+(\d+)/', $html, $matches ) ) {
			return 0;
		}

		return count( array_unique( $matches[1] ) );
	}

	/**
	 * Save the cleaned HTML as a page on the document's own site.
	 *
	 * @param int $missing_pages How many source pages Iris could not read. Stored
	 *                           on the page so anything reading it later can tell
	 *                           a complete conversion from an incomplete one.
	 * @return int The post id, or 0 on failure.
	 */
	private function publish_document( object $document, string $html, int $missing_pages = 0 ): int {
		$site_id       = (int) $document->site_id;
		$attachment_id = (int) $document->attachment_id;

		switch_to_blog( $site_id );

		// Clean before storing. Never store HTML from another service unchecked —
		// and never let cleaning strip the accessibility features that are the
		// whole point. See class-html-cleaner.php.
		$cleaned = Equalify_Iris_HTML_Cleaner::clean( $html );

		// Add ids to headings and collect them, so the page can offer a table of
		// contents whose links actually resolve.
		$with_headings = Equalify_Iris_HTML_Cleaner::extract_headings( $cleaned['html'] );

		$title = get_the_title( $attachment_id );

		if ( '' === trim( (string) $title ) ) {
			$title = Equalify_Iris_Documents::display_name( $document );
		}

		$existing = Equalify_Iris_Post_Type::find_for_attachment( $attachment_id );

		$postarr = array(
			'post_type'    => Equalify_Iris_Post_Type::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => Equalify_Iris_Post_Type::build_slug( $attachment_id, $title ),
			'post_content' => $with_headings['html'],
		);

		if ( $existing ) {
			// Update in place so the URL never changes. A URL we have already
			// published next to a PDF link is a promise to anyone who bookmarked
			// or shared it.
			$postarr['ID'] = $existing->ID;
		}

		// kses filters are added based on the current user's capabilities, and a
		// cron run has no user at all — so whether WordPress would sanitize on save
		// depends on how the tick was triggered. We do not rely on that: the HTML
		// is already cleaned above, deliberately and predictably, and this makes
		// sure WordPress does not then strip the accessibility attributes our own
		// allowlist just took care to keep.
		kses_remove_filters();

		$post_id = wp_insert_post( $postarr, true );

		kses_init_filters();

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			restore_current_blog();

			return 0;
		}

		$post_id = (int) $post_id;

		update_post_meta( $post_id, Equalify_Iris_Post_Type::META_ATTACHMENT, $attachment_id );
		update_post_meta( $post_id, Equalify_Iris_Post_Type::META_SESSION, (string) $document->iris_session_id );
		update_post_meta( $post_id, Equalify_Iris_Post_Type::META_HASH, (string) $document->file_hash );
		update_post_meta( $post_id, Equalify_Iris_Post_Type::META_CONVERTED_AT, time() );
		update_post_meta( $post_id, Equalify_Iris_Post_Type::META_HEADINGS, $with_headings['headings'] );
		update_post_meta( $post_id, Equalify_Iris_Post_Type::META_PAGE_COUNT, (int) $document->page_count );
		update_post_meta( $post_id, Equalify_Iris_Post_Type::META_FILE_BYTES, (int) $document->file_bytes );
		update_post_meta( $post_id, Equalify_Iris_Post_Type::META_PAGES_MISSING, $missing_pages );

		restore_current_blog();

		if ( $cleaned['removed_bytes'] > 0 ) {
			// Worth recording as a number rather than ignoring: heavy stripping
			// means either Iris started emitting something new, or our allowlist is
			// wrong. Both are things we want to notice from the dashboard instead of
			// from a reader complaining that a table lost its headers.
			Equalify_Iris_Logger::log(
				sprintf(
					/* translators: 1: file name, 2: a formatted byte size. */
					__( 'Cleaning "%1$s" removed %2$s of markup that is not on the allowed list.', 'equalify-iris' ),
					Equalify_Iris_Documents::display_name( $document ),
					size_format( $cleaned['removed_bytes'] )
				)
			);
		}

		return $post_id;
	}

	// -----------------------------------------------------------------------
	// Step 3: send new documents to Iris
	// -----------------------------------------------------------------------

	/**
	 * Upload pending documents, within both the per-tick and concurrency limits.
	 */
	private function upload_pending(): int {
		$per_tick      = max( 1, (int) Equalify_Iris_Settings::get( 'uploads_per_tick' ) );
		$max_in_flight = max( 1, (int) Equalify_Iris_Settings::get( 'max_in_flight' ) );
		$uploaded      = 0;

		while ( $uploaded < $per_tick && $this->has_time() ) {
			// The concurrency check goes INSIDE the loop, and is re-read each time,
			// because each upload changes the answer.
			//
			// Why bother at all, when Iris accepts everything and queues it? Because
			// a document sitting in an Iris queue is a document WE have to keep
			// polling, and polling is not free. Uploading 500 PDFs to a server that
			// runs 2 at a time would give us 500 documents to check on and no
			// conversions finished any sooner.
			if ( Equalify_Iris_Documents::in_flight_count() >= $max_in_flight ) {
				break;
			}

			$document = Equalify_Iris_Documents::claim_next(
				Equalify_Iris_Documents::PENDING,
				Equalify_Iris_Documents::CHECKING
			);

			if ( ! $document ) {
				break;
			}

			$this->upload_one( $document );
			++$uploaded;
		}

		return $uploaded;
	}

	/**
	 * Inspect and upload one PDF.
	 */
	private function upload_one( object $document ): void {
		$id      = (int) $document->id;
		$site_id = (int) $document->site_id;

		switch_to_blog( $site_id );
		$file_path = get_attached_file( (int) $document->attachment_id );
		restore_current_blog();

		if ( ! $file_path ) {
			Equalify_Iris_Documents::set_status(
				$id,
				Equalify_Iris_Documents::FAILED,
				__( 'The PDF file could not be found on the server.', 'equalify-iris' )
			);

			return;
		}

		// Check the file before spending an upload on it. This is what turns a
		// 60-page PDF into a clear sentence instead of a wasted 50 MB round trip
		// and a rejected session.
		$inspection = Equalify_Iris_PDF_Inspector::inspect( $file_path );

		Equalify_Iris_Documents::update(
			$id,
			array(
				'page_count' => $inspection['pages'],
				'file_bytes' => $inspection['bytes'],
				'file_hash'  => $inspection['hash'],
			)
		);

		if ( ! $inspection['ok'] ) {
			// too_long and too_big are final, not failures to retry. Documents::fail()
			// would keep trying and would file this under a count an admin can drive
			// to zero, which this is not.
			Equalify_Iris_Documents::set_status( $id, $inspection['status'], $inspection['message'] );

			return;
		}

		// Without cURL the whole file has to pass through PHP's memory. Rather than
		// exhausting memory and taking the entire tick down with it, leave this
		// document for later and carry on with smaller ones.
		$has_curl = function_exists( 'curl_file_create' );

		if ( ! $has_curl && ! Equalify_Iris_PDF_Inspector::fits_in_memory( $inspection['bytes'] ) ) {
			Equalify_Iris_Documents::update(
				$id,
				array(
					'status'         => Equalify_Iris_Documents::PENDING,
					'last_error'     => __( 'Not enough memory to upload this file right now. It will be tried again later.', 'equalify-iris' ),
					'next_action_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
				)
			);

			return;
		}

		Equalify_Iris_Documents::set_status( $id, Equalify_Iris_Documents::UPLOADING );

		$session = Equalify_Iris_API_Client::create_session( $file_path );

		if ( is_wp_error( $session ) ) {
			// Iris refusing the file is different from Iris being unreachable. A
			// refusal will be a refusal again in five minutes, so record it and stop
			// — same treatment as "too long" and "too big", which we catch ourselves
			// above. This covers the ones we cannot see in advance: chiefly a PDF
			// whose pages are physically large, because Iris renders every page at a
			// fixed resolution and a poster-sized page becomes an image too big for
			// the model to read.
			if ( Equalify_Iris_API_Client::is_permanent_rejection( $session ) ) {
				Equalify_Iris_Documents::set_status(
					$id,
					Equalify_Iris_Documents::FAILED,
					$session->get_error_message()
				);

				Equalify_Iris_Logger::error(
					sprintf(
						/* translators: 1: the PDF file name. 2: the reason Equalify Iris gave. */
						__( 'Equalify Iris will not convert "%1$s". %2$s', 'equalify-iris' ),
						Equalify_Iris_Documents::display_name( $document ),
						$session->get_error_message()
					),
					array( 'document_id' => $id )
				);

				return;
			}

			Equalify_Iris_Documents::fail( $id, $session->get_error_message() );

			return;
		}

		if ( empty( $session['session_id'] ) ) {
			Equalify_Iris_Documents::fail(
				$id,
				__( 'Equalify Iris accepted the upload but did not say which session it created.', 'equalify-iris' )
			);

			return;
		}

		Equalify_Iris_Documents::update(
			$id,
			array(
				'status'          => Equalify_Iris_Documents::CONVERTING,
				'iris_session_id' => (string) $session['session_id'],
				'started_at'      => current_time( 'mysql', true ),
				'checks'          => 0,
				'last_error'      => '',
				'next_action_at'  => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Step 4: retire documents that are no longer public
	// -----------------------------------------------------------------------

	/**
	 * Unpublish the accessible version of any PDF that is no longer on a public page.
	 *
	 * THIS IS A PRIVACY OBLIGATION, NOT HOUSEKEEPING.
	 *
	 * Without it, unpublishing a page would leave the full text of its PDF readable
	 * at a public URL of our making — a side door around the site's own privacy
	 * settings, and exactly the sort of surprise that gets a plugin removed from a
	 * network.
	 *
	 * The row and the post are kept rather than deleted, so republishing the page
	 * brings the accessible version straight back at the same URL with no
	 * re-conversion.
	 */
	private function retire_orphans(): int {
		$retired = 0;

		foreach ( Equalify_Iris_Documents::orphaned( 20 ) as $document ) {
			if ( ! $this->has_time() ) {
				break;
			}

			if ( $document->doc_post_id ) {
				switch_to_blog( (int) $document->site_id );

				wp_update_post(
					array(
						'ID'          => (int) $document->doc_post_id,
						'post_status' => 'draft',
					)
				);

				restore_current_blog();
			}

			Equalify_Iris_Documents::set_status(
				(int) $document->id,
				Equalify_Iris_Documents::RETIRED,
				__( 'This PDF is no longer linked from any published page, so its accessible version has been unpublished.', 'equalify-iris' )
			);

			++$retired;
		}

		return $retired;
	}

	// -----------------------------------------------------------------------
	// Step 5: keep sweeping
	// -----------------------------------------------------------------------

	/**
	 * Continue the one-time walk through the network, if it is still going.
	 */
	private function sweep(): array {
		if ( ! $this->has_time() || Equalify_Iris_Sweeper::is_complete() ) {
			return array(
				'scanned' => 0,
				'found'   => 0,
			);
		}

		return $this->sweeper->run_batch();
	}
}
