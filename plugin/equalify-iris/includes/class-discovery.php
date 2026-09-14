<?php
/**
 * WHAT IS THIS FILE?
 *
 * Finds the PDFs linked from a piece of published content.
 *
 * WHY DOES IT EXIST?
 *
 * It is the entry point to everything else. Nothing gets converted that this file
 * does not find first.
 *
 * There are two ways content reaches it, and they share all their logic:
 *
 *   1. The sweep (class-sweeper.php) walks every existing post once, when an
 *      admin first presses Start.
 *   2. The publish hook, below, catches every post published or updated from then
 *      on.
 *
 * WHY IS THIS SAFE TO RUN WHILE SOMEONE SAVES A POST?
 *
 * Because it never touches the network and never reads a file. It renders one
 * post's content, matches some strings, and writes at most a few small rows. The
 * expensive work — inspecting PDFs, uploading, polling — all happens later in a
 * background job. Editors should never wait on us.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Discovery {

	/**
	 * Guard against scanning a post while already scanning a post.
	 *
	 * Rendering content runs other plugins' code, which can save posts, which
	 * would call us again. Without this flag that becomes an infinite loop, and
	 * the symptom is a white screen on save with no useful error.
	 */
	private static bool $scanning = false;

	public function init(): void {
		// One hook covers publishing, updating, unpublishing and trashing, because
		// all four are status transitions. Using `save_post` instead would miss
		// posts that change status without being saved, such as a scheduled post
		// going live.
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );

		// A post being deleted outright never transitions status, so it needs its
		// own hook or its sightings would linger forever.
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
	}

	/**
	 * A post changed status. Work out what that means for us.
	 */
	public function on_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! Equalify_Iris_Settings::get( 'auto_process' ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, Equalify_Iris_Settings::included_post_types(), true ) ) {
			return;
		}

		if ( ! Equalify_Iris_Settings::site_is_included( get_current_blog_id() ) ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			// Published, or updated while published. Re-scan from scratch: clearing
			// the old sightings first is what makes a REMOVED link disappear. If we
			// only added what we found, a PDF unlinked from a post would keep its
			// sighting forever and never retire.
			$this->scan_post( $post );

			return;
		}

		if ( 'publish' === $old_status ) {
			// It was public and now is not: draft, pending, private or trashed.
			// Its PDFs may no longer be public anywhere.
			Equalify_Iris_Documents::clear_sightings_for_post( get_current_blog_id(), $post->ID );
		}
	}

	/**
	 * A post was deleted for good.
	 */
	public function on_delete( int $post_id ): void {
		Equalify_Iris_Documents::clear_sightings_for_post( get_current_blog_id(), $post_id );
	}

	/**
	 * Find every PDF on one post and record it.
	 *
	 * @return int How many PDFs were found, which the sweeper reports as progress.
	 */
	public function scan_post( WP_Post $post ): int {
		if ( self::$scanning ) {
			return 0;
		}

		if ( 'publish' !== $post->post_status ) {
			return 0;
		}

		// A password-protected post is not really public: only someone with the
		// password can read it, so its PDFs should not be converted and published
		// at an address with no password on it at all.
		if ( '' !== $post->post_password ) {
			return 0;
		}

		self::$scanning = true;

		$site_id = get_current_blog_id();

		// Start clean so links removed since the last scan lose their sighting.
		Equalify_Iris_Documents::clear_sightings_for_post( $site_id, $post->ID );

		$found = 0;

		foreach ( $this->find_pdf_urls( $post ) as $url ) {
			$attachment_id = $this->resolve_attachment( $url );

			if ( ! $attachment_id ) {
				// Not one of ours. Could be a PDF on another domain, or a link to a
				// file uploaded outside the Media Library. We only convert files we
				// own — following arbitrary URLs out of post content would let a
				// post author make our server fetch anything it can reach.
				continue;
			}

			$document_id = Equalify_Iris_Documents::add( $site_id, $attachment_id, $url );

			if ( $document_id ) {
				Equalify_Iris_Documents::record_sighting( $document_id, $site_id, $post->ID );
				++$found;
			}
		}

		self::$scanning = false;

		return $found;
	}

	/**
	 * Every distinct PDF URL in a post's rendered content.
	 *
	 * WHY RENDER THE CONTENT INSTEAD OF READING post_content?
	 *
	 * Because a great deal of real content is not in post_content as HTML. A file
	 * block stores a comment and some JSON; a shortcode stores `[my-docs]`; a page
	 * builder stores its own markup. Reading the raw field would find PDFs in
	 * hand-written links and miss everything else — which on a modern site is most
	 * of it.
	 *
	 * WHY NOT apply_filters( 'the_content' )?
	 *
	 * Because that would run OUR front-end filter too, which injects icons, and we
	 * would be asking the icon code to run during a save for no reason. Worse, it
	 * runs every other plugin's content filters, some of which do surprising
	 * things outside a real page view. do_blocks() and do_shortcode() are the two
	 * that actually turn stored content into links, so they are the two we call.
	 *
	 * @return string[] Distinct URLs.
	 */
	private function find_pdf_urls( WP_Post $post ): array {
		$content = $post->post_content;

		// Blocks first: do_blocks() turns block markup into HTML, which may
		// itself contain shortcodes.
		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}

		$content = do_shortcode( $content );

		if ( ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\']#i', $content, $matches ) ) {
			return array();
		}

		$urls = array();

		foreach ( $matches[1] as $url ) {
			$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

			if ( ! $this->looks_like_pdf( $url ) ) {
				continue;
			}

			// A post commonly links the same PDF more than once — WordPress's own
			// file block outputs both a filename link and a Download button. One
			// document, one entry.
			$urls[ $url ] = true;
		}

		return array_keys( $urls );
	}

	/**
	 * Does this URL point at a PDF?
	 *
	 * Checks the path only. A URL like `report.pdf?v=2` is still a PDF, and
	 * matching against the whole URL would miss it.
	 */
	private function looks_like_pdf( string $url ): bool {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! $path ) {
			return false;
		}

		return (bool) preg_match( '#\.pdf$#i', $path );
	}

	/**
	 * Turn a PDF URL into an attachment id on the current site, or 0.
	 *
	 * Returning 0 for anything we do not own is a security boundary, not just
	 * tidiness. If we converted any URL a post happened to link to, an author
	 * could point us at an internal address and use our server to fetch things
	 * from inside the network that they cannot reach themselves.
	 */
	private function resolve_attachment( string $url ): int {
		// Ignore the query string and fragment when matching against uploads.
		$clean_url = strtok( $url, '?#' );

		if ( ! $clean_url ) {
			return 0;
		}

		// Protocol-relative and root-relative URLs are common in stored content.
		// Make them absolute against this site before comparing.
		if ( 0 === strpos( $clean_url, '//' ) ) {
			$clean_url = ( is_ssl() ? 'https:' : 'http:' ) . $clean_url;
		} elseif ( 0 === strpos( $clean_url, '/' ) ) {
			$clean_url = home_url( $clean_url );
		}

		$attachment_id = attachment_url_to_postid( $clean_url );

		if ( ! $attachment_id ) {
			$attachment_id = $this->resolve_attachment_by_path( $clean_url );
		}

		if ( ! $attachment_id ) {
			return 0;
		}

		// Confirm it really is a PDF, not something with a misleading name.
		if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			return 0;
		}

		return $attachment_id;
	}

	/**
	 * The same lookup as attachment_url_to_postid(), but tolerant of which form of
	 * the uploads URL the link happens to be written in.
	 *
	 * WHY IS THIS NEEDED AT ALL?
	 *
	 * Because on a subdirectory multisite, the uploads URL WordPress reports depends
	 * on which site the request was bootstrapped on — and our sweep runs switched
	 * into other sites, where it gets the wrong one.
	 *
	 * wp_get_upload_dir() builds its baseurl from WP_CONTENT_URL, and WP_CONTENT_URL
	 * is a constant, fixed once from the siteurl of whichever site the request came
	 * in on. switch_to_blog() cannot change a constant. So during a background tick,
	 * which starts on the main site and switches:
	 *
	 *   what the editor stored, on site 2   /research/wp-content/uploads/sites/2/…
	 *   what we are told the baseurl is     /wp-content/uploads/sites/2
	 *
	 * Both address the same file — wp-content is shared at the network root — but
	 * core's function only strips a prefix it matches exactly, so it strips nothing,
	 * looks up the whole URL as a filename, and returns 0. Every PDF on every site
	 * except the main one becomes invisible: found, never converted, and then
	 * retired for having no sighting. That is the bug this exists to close, and it is
	 * silent, which is what makes it worth this much comment.
	 *
	 * WHY MATCH SEVERAL PREFIXES INSTEAD OF JUST ADDING THE PATH?
	 *
	 * Because the mismatch runs both ways. Content saved while switched, or by a
	 * CLI import, holds the network-root form; content saved by an editor holds the
	 * path-prefixed one. A network has both, so both have to resolve.
	 *
	 * Each candidate is still anchored at the start of the path and the host must be
	 * this site's, so the security boundary above is unchanged: this widens which of
	 * OUR OWN uploads URLs we recognise, and nothing else.
	 */
	private function resolve_attachment_by_path( string $url ): int {
		global $wpdb;

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return 0;
		}

		// A path is only evidence of ownership if the host is ours too. Without
		// this, anyone could link example.com/wp-content/uploads/sites/2/x.pdf and
		// have us treat it as a local file.
		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( strtolower( $parts['host'] ) !== strtolower( $home_host ) ) {
			return 0;
		}

		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['baseurl'] ) ) {
			return 0;
		}

		$base_path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );

		if ( '' === $base_path ) {
			return 0;
		}

		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$home_path = untrailingslashit( $home_path );

		// The reported form, plus the same thing with this site's own path segment
		// added and removed — the two ways the two forms can differ.
		$candidates = array( $base_path );

		if ( '' !== $home_path ) {
			$candidates[] = $home_path . $base_path;

			if ( 0 === strpos( $base_path, $home_path . '/' ) ) {
				$candidates[] = substr( $base_path, strlen( $home_path ) );
			}
		}

		$file = '';

		foreach ( array_unique( $candidates ) as $candidate ) {
			$prefix = trailingslashit( $candidate );

			if ( 0 === strpos( $parts['path'], $prefix ) ) {
				$file = substr( $parts['path'], strlen( $prefix ) );

				break;
			}
		}

		if ( '' === $file ) {
			return 0;
		}

		// _wp_attached_file holds the real filename, so a link with %20 in it has
		// to be decoded before it will match.
		$file = rawurldecode( $file );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				  WHERE meta_key = '_wp_attached_file'
				    AND meta_value = %s
				  LIMIT 1",
				$file
			)
		);
	}
}
