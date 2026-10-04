<?php
/**
 * WHAT IS THIS FILE?
 *
 * Takes one site's public PDFs through Equalify Iris and saves a tagged copy of
 * each one next to the original. Which PDFs are public is Equalify_Iris_Discovery's
 * job; a PDF that is not is never uploaded.
 *
 * WHERE THE STATE LIVES
 *
 * On the attachment, as post meta. A PDF with no status has never been asked
 * about. Otherwise it is one of:
 *
 *   queued   waiting to be uploaded
 *   working  uploaded; Iris is converting it, or we are waiting for the tags
 *   tagged   the tagged copy is saved and links point at it
 *   failed   Iris could not tag it; the reason is saved with it
 *   removed  a site admin deleted the tagged copy, so automatic tagging leaves
 *            this PDF alone until somebody asks again
 *
 * HOW THE WORK GETS DONE
 *
 * Iris takes minutes per PDF, so nothing happens during the request that asked
 * for it. Asking makes the site due, and Equalify_Iris_Runner gives each due site
 * a slice of its next run:
 *
 *   1. Reads content written before the plugin was active, for PDF links.
 *   2. If automatic tagging is on, queues public PDFs nobody has asked about.
 *   3. Checks on PDFs Iris is working on.
 *   4. Uploads queued PDFs, if they are still public, while the network has room
 *      at Iris.
 *   5. Fetches the tags for one PDF that is ready, with whatever time the run has
 *      left. Iris can take minutes to answer, so this goes last.
 *
 * THE TAGGED FILE
 *
 * Saved in the same uploads folder as the original, as `name-accessible.pdf`. It
 * is a plain file, not an attachment, so it never appears in the media library as
 * a second PDF that needs tagging. The original is never changed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Tagger {

	const QUEUED  = 'queued';
	const WORKING = 'working';
	const TAGGED  = 'tagged';
	const FAILED  = 'failed';
	const REMOVED = 'removed';

	const META_STATUS   = '_equalify_iris_status';
	const META_SESSION  = '_equalify_iris_session';
	const META_ERROR    = '_equalify_iris_error';
	const META_ATTEMPTS = '_equalify_iris_attempts';
	const META_FILE     = '_equalify_iris_file';
	const META_WARNINGS = '_equalify_iris_warnings';
	const META_SINCE    = '_equalify_iris_since';
	const META_STAGE    = '_equalify_iris_stage';

	/** PDFs at Iris at once, per site, so one site cannot take every slot. */
	const MAX_WORKING = 2;

	/** Problems in a row (Iris down, busy, timing out) before giving up on a PDF. */
	const MAX_ATTEMPTS = 10;

	/** PDFs queued per run when catching up on a media library. */
	const SCAN_BATCH = 50;

	/** Seconds a fetch needs to be worth starting. */
	const MIN_FETCH = 30;

	public static function init(): void {
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete' ) );
	}

	/**
	 * When the current site next needs a slice of a run, or 0 for never.
	 */
	public static function next_due(): int {
		if ( ! Equalify_Iris_Discovery::indexed() || get_option( Equalify_Iris_Discovery::STALE ) || Equalify_Iris_Settings::needs_scan() ) {
			return time();
		}

		if ( self::ids( self::QUEUED, 1 ) ) {
			// While the network has no room at Iris, there is no point asking often.
			return time() + ( Equalify_Iris_Runner::slots() > 0 ? MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		}

		if ( self::ids( self::WORKING, 1 ) ) {
			return time() + 2 * MINUTE_IN_SECONDS;
		}

		return 0;
	}

	// -----------------------------------------------------------------------
	// One PDF
	// -----------------------------------------------------------------------

	public static function is_pdf( int $attachment_id ): bool {
		return 'attachment' === get_post_type( $attachment_id )
			&& 'application/pdf' === get_post_mime_type( $attachment_id );
	}

	public static function status( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_STATUS, true );
	}

	/** When the PDF reached its current status, as a timestamp, or 0. */
	public static function since( int $attachment_id ): int {
		return (int) get_post_meta( $attachment_id, self::META_SINCE, true );
	}

	/** What Iris last said about a PDF it has: queued, running or ready_for_review. */
	public static function stage( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_STAGE, true );
	}

	/** Problems in a row while retrying. */
	public static function attempts( int $attachment_id ): int {
		return (int) get_post_meta( $attachment_id, self::META_ATTEMPTS, true );
	}

	/** '' for never asked about. */
	private static function set_status( int $attachment_id, string $status ): void {
		if ( '' === $status ) {
			delete_post_meta( $attachment_id, self::META_STATUS );
			delete_post_meta( $attachment_id, self::META_SINCE );
		} elseif ( self::status( $attachment_id ) !== $status ) {
			update_post_meta( $attachment_id, self::META_STATUS, $status );
			update_post_meta( $attachment_id, self::META_SINCE, time() );
		}

		delete_post_meta( $attachment_id, self::META_STAGE );
	}

	public static function error( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_ERROR, true );
	}

	/** @return string[] The tagger's warning codes for the saved copy. */
	public static function warnings( int $attachment_id ): array {
		return (array) get_post_meta( $attachment_id, self::META_WARNINGS, true );
	}

	/** The tagged copy's URL, or '' when there is none. */
	public static function tagged_url( int $attachment_id ): string {
		$file = (string) get_post_meta( $attachment_id, self::META_FILE, true );

		if ( '' === $file ) {
			return '';
		}

		return trailingslashit( wp_get_upload_dir()['baseurl'] ) . $file;
	}

	/**
	 * Ask for a PDF to be tagged, replacing any tagged copy it already has.
	 *
	 * @return bool False when it is not a PDF linked from a published page.
	 */
	public static function queue( int $attachment_id ): bool {
		if ( ! self::is_pdf( $attachment_id ) || ! Equalify_Iris_Discovery::is_public( $attachment_id ) ) {
			return false;
		}

		$session = (string) get_post_meta( $attachment_id, self::META_SESSION, true );

		if ( self::WORKING === self::status( $attachment_id ) && '' !== $session ) {
			// Already at Iris. Starting again would only cost a second upload.
			return true;
		}

		self::set_status( $attachment_id, self::QUEUED );
		delete_post_meta( $attachment_id, self::META_ERROR );
		delete_post_meta( $attachment_id, self::META_ATTEMPTS );

		Equalify_Iris_Runner::wake();

		return true;
	}

	/**
	 * Delete a PDF's tagged copy, so its links go back to the original.
	 *
	 * @param bool $remember Mark it `removed`, so automatic tagging leaves it alone.
	 *                       False when the attachment itself is being deleted.
	 */
	public static function remove( int $attachment_id, bool $remember = true ): void {
		$file = (string) get_post_meta( $attachment_id, self::META_FILE, true );

		if ( '' !== $file ) {
			wp_delete_file( trailingslashit( wp_get_upload_dir()['basedir'] ) . $file );
		}

		$original = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$map      = Equalify_Iris_Settings::link_map();

		if ( isset( $map[ $original ] ) ) {
			unset( $map[ $original ] );
			Equalify_Iris_Settings::set_link_map( $map );
		}

		$session = (string) get_post_meta( $attachment_id, self::META_SESSION, true );

		if ( '' !== $session ) {
			Equalify_Iris_API_Client::close_session( $session );
		}

		foreach ( array( self::META_SESSION, self::META_ERROR, self::META_ATTEMPTS, self::META_FILE, self::META_WARNINGS, self::META_STAGE ) as $key ) {
			delete_post_meta( $attachment_id, $key );
		}

		if ( $remember ) {
			self::set_status( $attachment_id, self::REMOVED );
		} else {
			self::set_status( $attachment_id, '' );
		}

		if ( '' !== $session ) {
			Equalify_Iris_Runner::sync_at_iris( self::ids( self::WORKING ) );
		}
	}

	/** @return int How many were queued. */
	public static function queue_all(): int {
		$queued = 0;

		foreach ( Equalify_Iris_Discovery::find( 'untagged' ) as $id ) {
			$queued += (int) self::queue( $id );
		}

		return $queued;
	}

	public static function on_delete( int $attachment_id ): void {
		if ( self::is_pdf( $attachment_id ) && '' !== self::status( $attachment_id ) ) {
			self::remove( $attachment_id, false );
		}
	}

	// -----------------------------------------------------------------------
	// The background job
	// -----------------------------------------------------------------------

	/**
	 * The current site's slice of a run.
	 *
	 * @param int $deadline When the run must be over.
	 * @param int $reading  When this site must stop reading content.
	 * @return array{queued: int, uploaded: int, tagged: int, failed: int} What it did.
	 */
	public static function work( int $deadline, int $reading ): array {
		$done = array( 'queued' => 0, 'uploaded' => 0, 'tagged' => 0, 'failed' => 0 );

		try {
			Equalify_Iris_Discovery::catch_up( $reading );
			$done['queued'] = self::scan();

			$ready = self::check_working( $done );

			self::upload_queued( $done, $deadline );

			if ( $ready ) {
				self::fetch_tagged( $ready, $done, $deadline );
			}
		} finally {
			Equalify_Iris_Runner::sync_at_iris( self::ids( self::WORKING ) );
		}

		return $done;
	}

	/** Queue public PDFs that were there before automatic tagging was on. */
	private static function scan(): int {
		if ( ! Equalify_Iris_Settings::needs_scan() ) {
			return 0;
		}

		$ids = Equalify_Iris_Discovery::find( 'untracked', self::SCAN_BATCH );

		foreach ( $ids as $id ) {
			self::set_status( $id, self::QUEUED );
		}

		// Done once the content has all been read and nothing is left over.
		if ( Equalify_Iris_Discovery::indexed() && count( $ids ) < self::SCAN_BATCH ) {
			Equalify_Iris_Settings::mark_scanned();
		}

		return count( $ids );
	}

	/** @return int A PDF whose tags are ready to fetch, or 0. */
	private static function check_working( array &$done ): int {
		$ready = 0;

		foreach ( self::ids( self::WORKING, 10 ) as $id ) {
			$session = (string) get_post_meta( $id, self::META_SESSION, true );
			$state   = Equalify_Iris_API_Client::get_session( $session );

			if ( is_wp_error( $state ) ) {
				if ( 404 === Equalify_Iris_API_Client::status( $state ) ) {
					// Iris no longer has the session. Upload it again.
					delete_post_meta( $id, self::META_SESSION );
					self::set_status( $id, self::QUEUED );
				}

				self::retry_or_fail( $id, $state, $done );
				continue;
			}

			$status = (string) ( $state['status'] ?? '' );

			if ( '' !== $status && 'failed' !== $status && self::stage( $id ) !== $status ) {
				update_post_meta( $id, self::META_STAGE, $status );
			}

			if ( 'failed' === $status ) {
				self::fail(
					$id,
					! empty( $state['error'] ) ? (string) $state['error'] : __( 'Equalify Iris could not convert this PDF.', 'equalify-iris' ),
					$done
				);
				continue;
			}

			// Still converting, or ready but one is already picked for this run.
			if ( ! $ready && in_array( $status, array( 'ready_for_review', 'closed' ), true ) ) {
				$ready = $id;
			}
		}

		return $ready;
	}

	private static function fetch_tagged( int $id, array &$done, int $deadline ): void {
		$session = (string) get_post_meta( $id, self::META_SESSION, true );
		$timeout = min( Equalify_Iris_API_Client::TIMEOUT_TAG, $deadline - time() - 5 );

		// Not enough of the run left. The site is due again soon; try then.
		if ( $timeout < self::MIN_FETCH ) {
			return;
		}

		$result = Equalify_Iris_API_Client::tagged_pdf( $session, $timeout );

		if ( is_wp_error( $result ) ) {
			self::retry_or_fail( $id, $result, $done );
			return;
		}

		$saved = self::save( $id, $result['pdf'] );

		if ( is_wp_error( $saved ) ) {
			self::fail( $id, $saved->get_error_message(), $done );
			return;
		}

		self::set_status( $id, self::TAGGED );
		update_post_meta( $id, self::META_WARNINGS, array_values( array_unique( $result['warnings'] ) ) );
		delete_post_meta( $id, self::META_SESSION );
		delete_post_meta( $id, self::META_ERROR );
		delete_post_meta( $id, self::META_ATTEMPTS );

		Equalify_Iris_API_Client::close_session( $session );

		++$done['tagged'];
	}

	/**
	 * Write the tagged copy next to the original and point links at it.
	 *
	 * @return true|WP_Error
	 */
	private static function save( int $id, string $pdf ) {
		$original = (string) get_post_meta( $id, '_wp_attached_file', true );
		$path     = get_attached_file( $id );

		if ( '' === $original || ! $path ) {
			return new WP_Error( 'equalify_iris_file_missing', __( 'The original PDF is no longer on the server.', 'equalify-iris' ) );
		}

		// Replace an earlier tagged copy rather than leave it lying about.
		$old = (string) get_post_meta( $id, self::META_FILE, true );

		if ( '' !== $old ) {
			wp_delete_file( trailingslashit( wp_get_upload_dir()['basedir'] ) . $old );
		}

		$dir  = dirname( $path );
		$name = wp_unique_filename( $dir, pathinfo( $path, PATHINFO_FILENAME ) . '-accessible.pdf' );

		if ( false === file_put_contents( $dir . '/' . $name, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'equalify_iris_write_failed', __( 'The tagged PDF could not be saved in the uploads folder. Check that it is writable.', 'equalify-iris' ) );
		}

		$folder = dirname( $original );
		$tagged = ( '.' === $folder ? '' : $folder . '/' ) . $name;

		update_post_meta( $id, self::META_FILE, $tagged );

		$map              = Equalify_Iris_Settings::link_map();
		$map[ $original ] = $tagged;
		Equalify_Iris_Settings::set_link_map( $map );

		return true;
	}

	private static function upload_queued( array &$done, int $deadline ): void {
		$slots = min(
			Equalify_Iris_Runner::slots(),
			self::MAX_WORKING - count( self::ids( self::WORKING, self::MAX_WORKING ) )
		);

		if ( $slots <= 0 ) {
			return;
		}

		foreach ( self::ids( self::QUEUED, $slots ) as $id ) {
			if ( $deadline - time() < Equalify_Iris_API_Client::TIMEOUT_UPLOAD + 5 ) {
				return;
			}

			// Its page was unpublished while it waited. Put it back as it was, so
			// it is queued again if the page comes back.
			if ( ! Equalify_Iris_Discovery::is_public( $id ) ) {
				if ( get_post_meta( $id, self::META_FILE, true ) ) {
					self::set_status( $id, self::TAGGED );
				} else {
					self::set_status( $id, '' );
				}

				delete_post_meta( $id, self::META_ERROR );
				delete_post_meta( $id, self::META_ATTEMPTS );
				continue;
			}

			$path  = (string) get_attached_file( $id );
			$check = Equalify_Iris_PDF_Inspector::check( $path );

			if ( is_wp_error( $check ) ) {
				self::fail( $id, $check->get_error_message(), $done );
				continue;
			}

			$session = Equalify_Iris_API_Client::create_session( $path );

			if ( is_wp_error( $session ) ) {
				self::retry_or_fail( $id, $session, $done );

				// Iris is unreachable or refusing uploads; the next PDF would fare
				// no better.
				if ( ! Equalify_Iris_API_Client::is_permanent( $session ) ) {
					return;
				}

				continue;
			}

			update_post_meta( $id, self::META_SESSION, (string) $session['session_id'] );
			self::set_status( $id, self::WORKING );

			// Counted now, so the next site in this run sees one fewer slot.
			Equalify_Iris_Runner::sync_at_iris( self::ids( self::WORKING ) );

			++$done['uploaded'];
		}
	}

	/** Stop on a permanent refusal; otherwise try again next run, up to a limit. */
	private static function retry_or_fail( int $id, WP_Error $error, array &$done ): void {
		if ( Equalify_Iris_API_Client::is_permanent( $error ) ) {
			self::fail( $id, $error->get_error_message(), $done );
			return;
		}

		// `busy` and `invalid_state` are Iris saying "not yet", not a problem.
		if ( in_array( Equalify_Iris_API_Client::error_code( $error ), array( 'busy', 'invalid_state' ), true ) ) {
			return;
		}

		$attempts = (int) get_post_meta( $id, self::META_ATTEMPTS, true ) + 1;

		// Iris carries on tagging after we stop waiting, so each try costs it a
		// whole run. Three is enough to know this server cannot wait long enough.
		$limit = 'equalify_iris_timeout' === $error->get_error_code() ? 3 : self::MAX_ATTEMPTS;

		if ( $attempts >= $limit ) {
			self::fail(
				$id,
				sprintf(
					/* translators: %s: the last error. */
					__( 'Gave up after several attempts. The last problem was: %s', 'equalify-iris' ),
					$error->get_error_message()
				),
				$done
			);
			return;
		}

		update_post_meta( $id, self::META_ATTEMPTS, $attempts );
		update_post_meta( $id, self::META_ERROR, $error->get_error_message() );
	}

	private static function fail( int $id, string $message, array &$done ): void {
		$session = (string) get_post_meta( $id, self::META_SESSION, true );

		if ( '' !== $session ) {
			Equalify_Iris_API_Client::close_session( $session );
			delete_post_meta( $id, self::META_SESSION );
		}

		self::set_status( $id, self::FAILED );
		update_post_meta( $id, self::META_ERROR, $message );
		delete_post_meta( $id, self::META_ATTEMPTS );

		++$done['failed'];
	}

	/**
	 * PDF attachments on the current site with this status, oldest first.
	 *
	 * @return int[]
	 */
	public static function ids( string $status, int $limit = -1 ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'application/pdf',
					'fields'         => 'ids',
					'posts_per_page' => $limit,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'meta_key'       => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $status, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}
}
