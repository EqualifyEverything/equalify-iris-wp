<?php
/**
 * WHAT IS THIS FILE?
 *
 * Takes one site's public PDFs through Equalify Iris and puts the tagged version
 * of each one at the original's own address. Which PDFs are public is Equalify_Iris_Discovery's
 * job; a PDF that is not is never uploaded.
 *
 * WHERE THE STATE LIVES
 *
 * On the attachment, as post meta. A PDF with no status has never been asked
 * about. Otherwise it is one of:
 *
 *   queued   waiting to be uploaded
 *   working  uploaded; Iris is converting it, or we are waiting for the tags
 *   tagged   the tagged version is at the PDF's address
 *   failed   Iris could not tag it; the reason is saved with it
 *   removed  a site admin deleted the tagged version, so automatic tagging leaves
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
 * Written over the original, at the same address. A link to a file in the
 * uploads folder is answered by the web server without WordPress running, and
 * some hosts, Pantheon among them, give a plugin no way to redirect it. So the
 * only way old links, bookmarks and search results get the tagged version is
 * for the file at that address to be the tagged version.
 *
 * The original is kept beside it, as `name-iris-original.pdf`, a plain file and
 * not an attachment, so it never appears in the media library. Deleting the
 * tagged version puts it back; so does deleting the plugin. Deactivating leaves
 * the tagged versions where they are.
 *
 * What was written is remembered by its checksum. If anything else replaces the
 * file afterwards, such as a media-replace plugin, the new file is never
 * overwritten with the old one, nor tagged from the old one.
 *
 * 0.2.x and earlier saved the tagged version as a second file,
 * `name-accessible.pdf`, and pointed links at it. The job moves those into
 * place, a few at a time (see move_old_copies()).
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
	const META_ORIGINAL = '_equalify_iris_original';
	const META_HASH     = '_equalify_iris_hash';
	const META_SENT     = '_equalify_iris_sent';
	const META_WARNINGS = '_equalify_iris_warnings';
	const META_SINCE    = '_equalify_iris_since';
	const META_STAGE    = '_equalify_iris_stage';

	/** 0.2.x's separate tagged copy, until move_old_copies() moves it into place. */
	const META_FILE = '_equalify_iris_file';

	/** Option: no moving 0.2.x copies until then, because the last one could not be written. */
	const MOVE_PAUSED = 'equalify_iris_move_paused';

	/** 0.2.x copies moved into place per run. */
	const MOVE_BATCH = 10;

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
		$at = self::tagging_due();

		if ( self::old_copies( 1 ) ) {
			$move = max( time(), (int) get_option( self::MOVE_PAUSED, 0 ) );
			$at   = $at ? min( $at, $move ) : $move;
		}

		return $at;
	}

	private static function tagging_due(): int {
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
		wp_cache_delete( 'counts', 'equalify_iris' );
	}

	public static function error( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::META_ERROR, true );
	}

	/** @return string[] The tagger's warning codes for the saved copy. */
	public static function warnings( int $attachment_id ): array {
		return (array) get_post_meta( $attachment_id, self::META_WARNINGS, true );
	}

	/** Is a tagged version at the PDF's address (or, from 0.2.x, beside it)? */
	public static function has_version( int $attachment_id ): bool {
		return '' !== (string) get_post_meta( $attachment_id, self::META_HASH, true )
			|| '' !== (string) get_post_meta( $attachment_id, self::META_FILE, true );
	}

	/** The tagged version's URL, as visitors' links have it, or '' when there is none. */
	public static function tagged_url( int $attachment_id ): string {
		if ( ! self::has_version( $attachment_id ) ) {
			return '';
		}

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$map  = Equalify_Iris_Settings::link_map();

		return Equalify_Iris_Links::url( wp_get_upload_dir()['baseurl'], (string) ( $map[ $file ] ?? $file ) );
	}

	/** Is the file at the PDF's address still the tagged version we wrote there? */
	private static function is_ours( int $attachment_id ): bool {
		$hash = (string) get_post_meta( $attachment_id, self::META_HASH, true );
		$path = (string) get_attached_file( $attachment_id );

		return '' !== $hash && '' !== $path && is_file( $path ) && md5_file( $path ) === $hash;
	}

	/** The kept original's full path, or '' when there is none on disk. */
	private static function original_path( int $attachment_id ): string {
		$path = self::stored_path( (string) get_post_meta( $attachment_id, self::META_ORIGINAL, true ) );

		return '' !== $path && is_file( $path ) ? $path : '';
	}

	/**
	 * The file Iris should be sent: the kept original, while the tagged version is
	 * at its address, or else whatever is at the address now.
	 */
	private static function source( int $attachment_id ): string {
		$kept = self::is_ours( $attachment_id ) ? self::original_path( $attachment_id ) : '';

		return '' !== $kept ? $kept : (string) get_attached_file( $attachment_id );
	}

	/**
	 * Ask for a PDF to be tagged, replacing any tagged version it already has.
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

	/** Forget the PDF's session at Iris, and send it again from the start. */
	public static function start_over( int $attachment_id ): void {
		if ( self::WORKING !== self::status( $attachment_id ) ) {
			return;
		}

		delete_post_meta( $attachment_id, self::META_SESSION );
		delete_post_meta( $attachment_id, self::META_ATTEMPTS );
		self::set_status( $attachment_id, self::QUEUED );
	}

	/**
	 * Delete a PDF's tagged version, and put the original back at its address.
	 *
	 * @param bool $remember Mark it `removed`, so automatic tagging leaves it alone.
	 *                       False when the attachment itself is being deleted.
	 * @return bool False when the original could not be put back, so nothing changed.
	 */
	public static function remove( int $attachment_id, bool $remember = true ): bool {
		$had  = self::has_version( $attachment_id );
		$kept = self::original_path( $attachment_id );
		$path = (string) get_attached_file( $attachment_id );
		$ours = self::is_ours( $attachment_id );

		// Without the original, the tagged version is the only copy there is, so
		// it stays, and so does everything about it.
		if ( $remember && $ours && '' === $kept ) {
			return false;
		}

		if ( $remember && '' !== $kept && '' !== $path && ( $ours || ! is_file( $path ) ) ) {
			if ( ! rename( $kept, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return false;
			}

			self::set_filesize( $attachment_id );
		} elseif ( '' !== $kept ) {
			// The attachment is being deleted, and WordPress deletes its file; or
			// something replaced the file since it was tagged, and that file stays.
			wp_delete_file( $kept );
		}

		$old = self::stored_path( (string) get_post_meta( $attachment_id, self::META_FILE, true ) );

		if ( '' !== $old ) {
			wp_delete_file( $old );
		}

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$map  = Equalify_Iris_Settings::link_map();

		if ( isset( $map[ $file ] ) ) {
			unset( $map[ $file ] );
			Equalify_Iris_Settings::set_link_map( $map );
		}

		if ( $had ) {
			self::links_changed( $attachment_id );
		}

		$session = (string) get_post_meta( $attachment_id, self::META_SESSION, true );

		if ( '' !== $session ) {
			Equalify_Iris_API_Client::close_session( $session );
		}

		foreach ( array( self::META_SESSION, self::META_ERROR, self::META_ATTEMPTS, self::META_ORIGINAL, self::META_HASH, self::META_SENT, self::META_FILE, self::META_WARNINGS, self::META_STAGE ) as $key ) {
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

		return true;
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

			self::move_old_copies( $deadline );

			// Iris would not let us in a moment ago, and would say the same again:
			// nothing changes for any PDF until the settings do, or a while passes.
			if ( Equalify_Iris_Settings::refused_recently() ) {
				return $done;
			}

			$ready = self::check_working( $done, $deadline );

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
	private static function check_working( array &$done, int $deadline ): int {
		$ready = 0;

		foreach ( self::ids( self::WORKING, 10 ) as $id ) {
			// Each check may wait its full timeout. The rest wait for the next run.
			if ( $deadline - time() < Equalify_Iris_API_Client::TIMEOUT_STATUS + 5 ) {
				break;
			}

			$session = (string) get_post_meta( $id, self::META_SESSION, true );
			$state   = Equalify_Iris_API_Client::get_session( $session );

			if ( is_wp_error( $state ) ) {
				if ( Equalify_Iris_API_Client::is_refused( $state ) ) {
					self::retry_or_fail( $id, $state, $done );
					return 0;
				}

				if ( 404 === Equalify_Iris_API_Client::status( $state ) ) {
					// Iris no longer has the session. Upload it again.
					delete_post_meta( $id, self::META_SESSION );
					self::set_status( $id, self::QUEUED );
				}

				self::retry_or_fail( $id, $state, $done );
				continue;
			}

			Equalify_Iris_Settings::clear_refused();

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

		$original = self::source( $id );
		$result   = Equalify_Iris_API_Client::tagged_pdf( $session, $timeout, is_file( $original ) ? (int) filesize( $original ) : 0 );

		if ( is_wp_error( $result ) ) {
			self::retry_or_fail( $id, $result, $done );
			return;
		}

		$saved = self::save( $id, $result['pdf'] );

		if ( is_wp_error( $saved ) && 'equalify_iris_changed' === $saved->get_error_code() ) {
			// These tags are for a file that is no longer there. Send the new one.
			Equalify_Iris_API_Client::close_session( $session );
			delete_post_meta( $id, self::META_SESSION );
			self::set_status( $id, self::QUEUED );
			return;
		}

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
	 * Put the tagged version at the PDF's address, keeping the original beside it.
	 *
	 * @return true|WP_Error
	 */
	private static function save( int $id, string $pdf ) {
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$path = (string) get_attached_file( $id );

		if ( '' === $file || '' === $path || ! is_file( $path ) ) {
			return new WP_Error( 'equalify_iris_file_missing', __( 'The original PDF is no longer on the server.', 'equalify-iris' ) );
		}

		$stale = self::original_path( $id );
		$kept  = self::is_ours( $id ) ? $stale : '';
		$sent  = (string) get_post_meta( $id, self::META_SENT, true );

		if ( '' !== $sent && md5_file( '' !== $kept ? $kept : $path ) !== $sent ) {
			return new WP_Error( 'equalify_iris_changed', __( 'The PDF was replaced while Iris was tagging it.', 'equalify-iris' ) );
		}

		$dir   = dirname( $path );
		$error = new WP_Error( 'equalify_iris_write_failed', __( 'The tagged PDF could not be saved in the uploads folder. Check that it is writable and has space.', 'equalify-iris' ) );

		// Written beside it first, then moved into place in one step, so a full
		// disk or a killed process never leaves half a file at the PDF's address,
		// nor the address empty for a moment.
		$temp    = $dir . '/.' . basename( $path ) . '.' . wp_generate_password( 8, false ) . '.tmp';
		$written = file_put_contents( $temp, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( strlen( $pdf ) !== $written ) {
			wp_delete_file( $temp );
			return $error;
		}

		$copied = '';

		if ( '' === $kept ) {
			$copied = $dir . '/' . wp_unique_filename( $dir, pathinfo( $path, PATHINFO_FILENAME ) . '-iris-original.pdf' );

			if ( ! copy( $path, $copied ) || filesize( $copied ) !== filesize( $path ) ) {
				wp_delete_file( $temp );
				wp_delete_file( $copied );
				return $error;
			}
		}

		if ( ! rename( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $temp );

			if ( '' !== $copied ) {
				wp_delete_file( $copied );
			}

			return $error;
		}

		$folder = dirname( $file );

		if ( '' !== $copied ) {
			update_post_meta( $id, self::META_ORIGINAL, ( '.' === $folder ? '' : $folder . '/' ) . basename( $copied ) );

			// A kept original from before the file was replaced is of no use now.
			if ( '' !== $stale ) {
				wp_delete_file( $stale );
			}
		}

		update_post_meta( $id, self::META_HASH, md5( $pdf ) );
		delete_post_meta( $id, self::META_SENT );

		$old = self::stored_path( (string) get_post_meta( $id, self::META_FILE, true ) );

		if ( '' !== $old ) {
			wp_delete_file( $old );
		}

		delete_post_meta( $id, self::META_FILE );

		// The address is the same, so links get a new `?v=` to keep CDNs and
		// browsers from sending what they kept from before.
		$map          = Equalify_Iris_Settings::link_map();
		$map[ $file ] = $file . '?v=' . time();
		Equalify_Iris_Settings::set_link_map( $map );

		self::set_filesize( $id );
		self::links_changed( $id );

		return true;
	}

	/** Keep the size the media library shows in step with the file. */
	private static function set_filesize( int $id ): void {
		$meta = wp_get_attachment_metadata( $id );
		$path = (string) get_attached_file( $id );

		if ( is_array( $meta ) && isset( $meta['filesize'] ) && is_file( $path ) ) {
			clearstatcache( true, $path );
			$meta['filesize'] = (int) filesize( $path );
			wp_update_attachment_metadata( $id, $meta );
		}
	}

	/**
	 * The full path of a stored file of ours, or '' when the stored value is not
	 * one this plugin could have written: anything outside the uploads folder, or
	 * not named `…-iris-original.pdf` (or 0.2.x's `…-accessible.pdf`). Nothing is
	 * ever deleted or moved from a path that fails.
	 */
	public static function stored_path( string $file ): string {
		if ( '' === $file || false !== strpos( $file, "\0" ) || ! preg_match( '#^(?:[^/\\\\]+/)*[^/\\\\]+-(?:iris-original|accessible)(?:-\d+)?\.pdf$#', $file ) ) {
			return '';
		}

		$base = wp_get_upload_dir()['basedir'];
		$path = trailingslashit( $base ) . $file;
		$real = realpath( dirname( $path ) );

		if ( false === $real || 0 !== strpos( trailingslashit( $real ), trailingslashit( (string) realpath( $base ) ) ) ) {
			return '';
		}

		return $real . '/' . basename( $path );
	}

	// -----------------------------------------------------------------------
	// Tagged copies from 0.2.x
	// -----------------------------------------------------------------------

	/** @return int[] PDFs with a 0.2.x copy still beside them. */
	private static function old_copies( int $limit ): array {
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
					'meta_key'       => self::META_FILE, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}

	/**
	 * Put 0.2.x's `name-accessible.pdf` copies at their originals' addresses,
	 * exactly as a newly tagged PDF would be, so old links get them too.
	 */
	private static function move_old_copies( int $deadline ): void {
		if ( (int) get_option( self::MOVE_PAUSED, 0 ) > time() ) {
			return;
		}

		foreach ( self::old_copies( self::MOVE_BATCH ) as $id ) {
			if ( $deadline - time() < 15 ) {
				return;
			}

			$old   = self::stored_path( (string) get_post_meta( $id, self::META_FILE, true ) );
			$pdf   = '' !== $old && is_file( $old ) ? file_get_contents( $old ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$saved = false === $pdf ? null : self::save( $id, $pdf );

			if ( true === $saved ) {
				continue;
			}

			// A full or read-only disk fails every PDF alike. The copy stays, and
			// its links still point at it, until an hour has passed.
			if ( is_wp_error( $saved ) && 'equalify_iris_write_failed' === $saved->get_error_code() ) {
				update_option( self::MOVE_PAUSED, time() + HOUR_IN_SECONDS, false );
				return;
			}

			// The copy or the original is gone, or the PDF was replaced. Forget the
			// copy, so the PDF is asked about again like any other.
			self::remove( $id, false );
		}

		delete_option( self::MOVE_PAUSED );
	}

	/**
	 * A PDF's links now point somewhere else. Ask page caches to drop the posts
	 * that link to it, so visitors get the new link without waiting for the cache
	 * to expire. A handful at a time, never in bulk: each one is a CDN purge on
	 * hosts like Pantheon. Menus and widgets show on every page and cannot be
	 * purged this way; those wait for the cache.
	 */
	private static function links_changed( int $attachment_id ): void {
		// Pantheon's CDN keeps files from the uploads folder too, and this one's
		// contents just changed. Elsewhere, the action below is for the same job.
		if ( function_exists( 'pantheon_wp_clear_edge_paths' ) ) {
			pantheon_wp_clear_edge_paths( array( (string) wp_parse_url( (string) wp_get_attachment_url( $attachment_id ), PHP_URL_PATH ) ) );
		}

		$posts = get_post_meta( $attachment_id, Equalify_Iris_Discovery::META );

		foreach ( array_slice( array_unique( array_map( 'intval', (array) $posts ) ), 0, 20 ) as $post_id ) {
			clean_post_cache( $post_id );
		}

		/**
		 * After a PDF's tagged version is saved or deleted, so the file at its
		 * address and the links to it changed. For purging a page cache or CDN
		 * that clean_post_cache() does not reach.
		 *
		 * @param int $attachment_id The PDF.
		 */
		do_action( 'equalify_iris_links_changed', $attachment_id );
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
				if ( self::has_version( $id ) ) {
					self::set_status( $id, self::TAGGED );
				} else {
					self::set_status( $id, '' );
				}

				delete_post_meta( $id, self::META_ERROR );
				delete_post_meta( $id, self::META_ATTEMPTS );
				continue;
			}

			$path  = self::source( $id );
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

			Equalify_Iris_Settings::clear_refused();

			// So save() can tell whether the file it would keep is the one tagged.
			update_post_meta( $id, self::META_SENT, (string) md5_file( $path ) );
			update_post_meta( $id, self::META_SESSION, (string) $session['session_id'] );
			self::set_status( $id, self::WORKING );

			// Counted now, so the next site in this run sees one fewer slot.
			Equalify_Iris_Runner::sync_at_iris( self::ids( self::WORKING ) );

			++$done['uploaded'];
		}
	}

	/** Stop on a permanent refusal; otherwise try again next run, up to a limit. */
	private static function retry_or_fail( int $id, WP_Error $error, array &$done ): void {
		// Not this PDF's fault, so it costs the PDF nothing: the whole network
		// pauses, and the screens say why (see work()).
		if ( Equalify_Iris_API_Client::is_refused( $error ) ) {
			Equalify_Iris_Settings::set_refused( $error->get_error_message() );
			return;
		}

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
