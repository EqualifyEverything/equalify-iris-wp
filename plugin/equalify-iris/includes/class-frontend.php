<?php
/**
 * WHAT IS THIS FILE?
 *
 * The part visitors actually see: the small Equalify icon that appears next to a
 * PDF link and leads to the accessible version.
 *
 * WHY DOES IT EXIST?
 *
 * Everything else in this plugin is invisible. If this file does not work, we have
 * converted thousands of documents that nobody can reach. This is the whole point
 * of the product, so it is worth being careful about.
 *
 * THREE DECISIONS WORTH KNOWING ABOUT
 *
 * 1. THE ICON IS ADDED ON THE SERVER, NOT WITH JAVASCRIPT.
 *
 *    A JavaScript approach would mean the link does not exist until a script has
 *    downloaded and run. Assistive technology often builds its picture of a page
 *    from the HTML as delivered, and a link that appears late can be missed
 *    entirely. An accessibility feature that depends on JavaScript to be
 *    accessible has a hole in the middle of it.
 *
 *    So the icon is in the HTML from the moment it leaves the server. There is no
 *    JavaScript in this plugin's front end at all.
 *
 * 2. ONE DATABASE QUERY PER PAGE, NO MATTER HOW MANY PDFS.
 *
 *    See Documents::published_for_post(). The sightings table already records which
 *    PDFs are on which post, so we ask once and get everything.
 *
 * 3. IT IS A SEPARATE LINK, NOT A CHANGE TO THE EXISTING ONE.
 *
 *    The original PDF link keeps working exactly as it did. Some people want the
 *    PDF — to print it, to file it, because it is the legal record. We are adding a
 *    choice, not taking one away.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Frontend {

	/**
	 * Documents for the post we are currently rendering, so we look them up once.
	 *
	 * @var array<int, array>
	 */
	private array $cache = array();

	public function init(): void {
		// Priority 20, which is after wpautop (10) and after most content filters.
		// Running earlier would mean wpautop wrapping our icon markup in paragraph
		// tags of its own choosing, which moves the icon away from the link it
		// belongs to.
		add_filter( 'the_content', array( $this, 'add_icons' ), 20 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Add an icon after every PDF link that has an accessible version.
	 */
	public function add_icons( string $content ): string {
		// The cheapest possible early exit, and the one that matters most: almost
		// every page on the network has no PDF on it at all, and those pages should
		// cost us a single string search and nothing else.
		if ( false === stripos( $content, '.pdf' ) ) {
			return $content;
		}

		if ( is_admin() || is_feed() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return $content;
		}

		$documents = $this->documents_for_post( (int) $post_id );

		if ( ! $documents ) {
			return $content;
		}

		return $this->rewrite_links( $content, $documents );
	}

	/**
	 * The accessible versions available on one post, keyed by PDF URL.
	 *
	 * @return array<string, array{url: string, title: string}>
	 */
	private function documents_for_post( int $post_id ): array {
		if ( isset( $this->cache[ $post_id ] ) ) {
			return $this->cache[ $post_id ];
		}

		$map = array();

		if ( Equalify_Iris_Database::tables_exist() ) {
			$rows = Equalify_Iris_Documents::published_for_post( get_current_blog_id(), $post_id );

			foreach ( $rows as $row ) {
				$doc_post = get_post( (int) $row->doc_post_id );

				// The page may have been deleted or unpublished since we recorded it.
				// Checking is one cached lookup and saves us publishing a link to a
				// 404, which is worse than no link at all.
				if ( ! $doc_post || 'publish' !== $doc_post->post_status ) {
					continue;
				}

				$entry = array(
					'url'   => (string) get_permalink( $doc_post ),
					'title' => (string) $doc_post->post_title,
				);

				// Keyed on the URL as it appears in the content, so matching a link
				// is a plain array lookup rather than a search.
				$map[ $this->normalize_url( (string) $row->pdf_url ) ] = $entry;
			}
		}

		$this->cache[ $post_id ] = $map;

		return $map;
	}

	/**
	 * Reduce a URL to something two forms of the same link will agree on.
	 *
	 * The same PDF can be written several ways in stored content: with or without a
	 * scheme, with a query string, with an escaped ampersand. Comparing the path
	 * alone — lowercased, no query, no fragment — matches all of them and cannot
	 * accidentally match a different file, because the path is the file.
	 */
	private function normalize_url( string $url ): string {
		$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );

		$path = wp_parse_url( strtok( $url, '?#' ), PHP_URL_PATH );

		return $path ? strtolower( rtrim( $path, '/' ) ) : strtolower( $url );
	}

	/**
	 * Walk every link in the content and add an icon where one belongs.
	 *
	 * WHY A REGULAR EXPRESSION AND NOT AN HTML PARSER?
	 *
	 * Because this runs on every page view of every site in the network, and loading
	 * post content into a DOM parser and writing it back out is both slow and
	 * lossy — DOMDocument reformats markup it did not expect, which on a page built
	 * by a page builder can visibly break the layout.
	 *
	 * The pattern below only ever matches an opening `<a ...>` tag and only ever
	 * inserts text immediately after the matching `</a>`. It never rewrites anything
	 * it did not match, so content it does not understand passes through untouched.
	 */
	private function rewrite_links( string $content, array $documents ): string {
		return (string) preg_replace_callback(
			'#<a\s([^>]*)>(.*?)</a>#is',
			function ( array $match ) use ( $documents ) {
				$attributes = $match[1];
				$inner      = $match[2];
				$whole      = $match[0];

				// Already done. Content filters can run more than once on the same
				// string, and two icons on one link would be a bug a reader notices.
				if ( false !== stripos( $attributes, 'data-equalify-iris-url' ) ) {
					return $whole;
				}

				if ( ! preg_match( '#\bhref=(["\'])(.*?)\1#i', $attributes, $href_match ) ) {
					return $whole;
				}

				$key = $this->normalize_url( $href_match[2] );

				if ( ! isset( $documents[ $key ] ) ) {
					return $whole;
				}

				$document = $documents[ $key ];

				// Tag the original link too. It changes nothing on the page, but it
				// lets other tools — an editor plugin, a site audit, a reader app —
				// find PDF links that have an accessible version without having to
				// repeat any of the work above.
				$tagged = '<a ' . $attributes
					. ' data-equalify-iris-url="' . esc_url( $document['url'] ) . '"'
					. ' data-equalify-iris-title="' . esc_attr( $document['title'] ) . '"'
					. '>' . $inner . '</a>';

				return $tagged . ' ' . self::icon_link( $document['url'], $document['title'] );
			},
			$content
		);
	}

	/**
	 * The icon link markup.
	 *
	 * WHY EACH PIECE IS THERE — this is the accessibility-critical part of the file.
	 *
	 *   aria-label on the link, naming the document
	 *     Screen readers can list every link on a page out of context. "Open" on its
	 *     own tells someone nothing; "Open annual-report in Equalify Iris" tells them
	 *     which document and where it goes.
	 *
	 *   aria-hidden="true" and focusable="false" on the SVG
	 *     The icon is decoration; the link already has a name. Without these, some
	 *     screen readers announce the graphic as well, and in older browsers the SVG
	 *     itself becomes a tab stop — an extra thing to tab past that does nothing.
	 *
	 *   the visually hidden <span>
	 *     A belt-and-braces text label inside the link. If a stylesheet fails to
	 *     load, or a tool ignores aria-label, or the SVG does not render, there is
	 *     still real text in the link. A link whose only content is an image that
	 *     failed is an unlabelled link.
	 *
	 *   fill="currentColor"
	 *     The icon takes the colour of the surrounding link text, so it inherits
	 *     whatever contrast the theme has already got right, and it still shows up
	 *     in forced-colours and high-contrast modes.
	 *
	 *   the SVG is inline rather than an <img>
	 *     An <img> could not inherit the text colour, would be one more HTTP
	 *     request, and would show as a broken image if the file went missing.
	 */
	public static function icon_link( string $url, string $title ): string {
		/**
		 * The label a screen reader reads for the icon link.
		 *
		 * @param string $label
		 * @param string $title The document's title.
		 */
		$label = apply_filters(
			'equalify_iris_icon_label',
			sprintf(
				/* translators: %s: the document's title. */
				__( 'Open %s in Equalify Iris', 'equalify-iris' ),
				$title
			),
			$title
		);

		/** The short text shown on hover and inside the link. */
		$short_label = apply_filters(
			'equalify_iris_icon_short_label',
			__( 'Open in Equalify Iris', 'equalify-iris' )
		);

		$html = '<a href="' . esc_url( $url ) . '"'
			. ' class="equalify-iris-icon-link"'
			. ' aria-label="' . esc_attr( $label ) . '"'
			. ' data-tooltip="' . esc_attr( $short_label ) . '"'
			. ' data-equalify-iris-url="' . esc_url( $url ) . '"'
			. ' data-equalify-iris-title="' . esc_attr( $title ) . '">'
			. self::mark_svg()
			. '<span class="equalify-iris-icon-label screen-reader-text">' . esc_html( $short_label ) . '</span>'
			. '</a>';

		/**
		 * The complete icon link markup.
		 *
		 * @param string $html
		 * @param string $url   The accessible version's URL.
		 * @param string $title The document's title.
		 */
		return apply_filters( 'equalify_iris_icon_link', $html, $url, $title );
	}

	/**
	 * The Equalify mark, as inline SVG.
	 *
	 * Kept as a string in PHP rather than a .svg file on disk so that rendering the
	 * icon never depends on a file read succeeding, and so it cannot be affected by
	 * a server that refuses to serve SVG. It is long, but it is data, and it is the
	 * only long thing in this file.
	 */
	public static function mark_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2048 2048" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false">'
			. '<path d="M1646.58,1629.58c-38.13,40.66-87.09,67.68-141.15,81.35-50.43,12.74-103.12,11.16-153.19,25.81-78.56,22.98-128.47,89.14-198.36,126.64-93.97,50.43-188.85,45.28-278.4-11.36-49.38-31.23-91.41-76.34-144.29-101.71-60.89-29.21-129.13-24.03-193.19-40.81-98.59-25.81-171.31-95.5-201.01-192.99-22.32-73.25-11.59-152.3-47.46-222.54-20.96-41.05-55.45-72.79-82.34-109.66-54.68-74.99-74.31-158.09-40.93-247.56,31.47-84.34,106.53-135.77,144.61-215.39,30.2-63.16,30.62-131.32,49.64-197.36,26.39-91.6,87.92-161.49,177.25-195.75,67.48-25.88,139.11-24.34,204.92-54.08,55.65-25.14,99.94-69.6,155.65-95.35,108.02-49.93,207.91-32.48,304.99,30.37,59.89,38.77,93.24,69.63,166.68,86.32,87.01,19.77,153.99,21.62,226.02,82.98,85.46,72.79,92.84,150.66,113.28,253.72,18.71,94.34,50.96,130.05,108.68,201.32,66.11,81.63,101.46,162.9,67.31,269.26-29.04,90.45-114.04,139.59-142,229-14.91,47.69-14.49,99.49-23.6,148.4-10.56,56.67-33.62,107.3-73.1,149.4Z"></path>'
			. '<path fill="white" d="M1600.56,1584.59c-85.12,92.83-193.82,57.63-297.24,101.24-92.11,38.84-164.34,142.64-266.78,148.22-124.72,6.8-190.99-108.25-297.58-150.52-91.19-36.16-186.97-12.24-268.44-77.56-109.76-87.98-68-211.5-116.35-325.65-42.96-101.42-157.56-156.16-137.87-283.52,12.4-80.21,78.07-130.89,121.1-193.9,13.13-19.23,26.11-42.03,35.76-63.24,53.17-116.81,19.1-247.97,143.31-328.69,71.35-46.37,153.73-39.12,230.77-68.23,91.86-34.72,154.77-118.16,257.26-127.74,125.83-11.76,192.86,89.02,299.24,128.74,85.34,31.86,179.33,20.48,252.26,85.74,89.95,80.49,71.83,179.83,107.97,283.03,7.34,20.96,17.79,42.9,28.85,62.15,50.83,88.46,152.11,151.86,140.16,266.82-10.44,100.38-104.44,154.3-141.48,244.52-41.62,101.37-10.6,210.95-90.95,298.58Z"></path>'
			. '<path d="M1016.21,298.31c81.31-4.51,141.51,62.13,207.38,98.07,30.27,16.51,62.77,28.8,96.3,37.04,58.26,14.31,116.83,13.12,167.82,48.98,78.67,55.32,70.5,146.14,93.02,229.3,12.62,46.59,35.13,92.57,62.65,132.09,58.79,84.44,155.79,146.53,79.27,260.75-27.95,41.72-64.71,74.21-89.6,119.53-36.3,66.1-37.65,120.67-47.94,192.85-13.22,92.75-53.21,143.98-147.42,160.52-64.28,11.29-118.22,11.87-179.25,40.42-58.67,27.45-98.46,72.73-151.58,105.5-127.37,78.57-192.39-33.82-290.66-91.72-67.53-39.79-124.63-41.21-199.19-53.1-93.55-14.92-137.71-60.81-152.89-154.08-12.96-79.67-11.33-136.08-53.83-209.01-48.93-83.97-156.71-146.66-93.4-258.07,14.35-25.25,34.99-45.6,52.93-67.94,49.55-61.72,84.73-119.13,102.79-197.47,13.77-59.74,13.3-124.36,51.85-175.5,53.82-71.4,134.61-64.29,212.23-85.15,28.88-7.76,58.65-18.91,84.99-33,62.18-33.26,120.59-95.88,194.52-99.98Z"></path>'
			. '<path fill="white" d="M1140.49,902.61c-1.11,1.54-1.15,3.41-1.28,5.22-3.57,48.83,1.5,101.83-1,151.08,28.58,128.36,62.38,255.64,93.58,383.42,10.45,42.99-37.74,77.49-75.45,54.45-17.23-10.52-21.4-25.14-28.1-42.9-33.62-89.13-62.57-180.28-96.97-269.03-8.17-7.79-19.34-7.23-25.52,2.5-33.22,88.63-62.24,178.91-95.47,267.53-6.15,16.41-11.46,32.15-27.33,41.67-34.8,20.89-77.3-5.12-77.31-44.52,26.4-118.4,59.55-235.37,87.33-353.46,2.37-10.08,6.28-22.81,7.16-32.84,3.35-38.19-.05-86.01-.91-124.83-.27-12.29.41-24.62-.1-36.9l-1.53-1.47c-69.46-25.09-140.44-45.96-209.86-71.14-15.27-5.54-48-15.58-59.06-25.47-32.65-29.16-9.21-85.2,35.96-80.43,20.37,2.15,60.27,20.8,82.11,27.89,68.37,22.21,147.29,38.75,219.02,44.98,137.2,11.9,277.23-24.94,404.44-72.55,56.45-8.93,77.04,67.67,25.31,89.31-24.89,10.42-53.61,19.77-79.3,28.7-58.33,20.26-117.58,37.99-175.72,58.77Z"></path>'
			. '<path fill="white" d="M1016.09,535.62c83.81-3.33,139.5,88.53,99.96,162.06-41.75,77.64-155.66,76.02-194.66-2.95-35.72-72.33,14.56-155.93,94.7-159.11Z"></path>'
			. '</svg>';
	}

	/**
	 * Load the icon stylesheet.
	 *
	 * Small enough that it is enqueued on any front-end page rather than trying to
	 * work out in advance whether the page will contain an icon. Deciding that early
	 * is not possible — the content has not been rendered when scripts are enqueued —
	 * and the alternative, printing styles in the middle of the page, is worse.
	 */
	public function enqueue_styles(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'equalify-iris-icon',
			EQUALIFY_IRIS_URL . 'assets/css/icon.css',
			array(),
			EQUALIFY_IRIS_VERSION
		);

		if ( is_singular( Equalify_Iris_Post_Type::POST_TYPE ) ) {
			wp_enqueue_style(
				'equalify-iris-document',
				EQUALIFY_IRIS_URL . 'assets/css/document.css',
				array( 'equalify-iris-icon' ),
				EQUALIFY_IRIS_VERSION
			);
		}
	}
}
