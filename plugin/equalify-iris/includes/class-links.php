<?php
/**
 * WHAT IS THIS FILE?
 *
 * Points every link to a PDF at its tagged copy, as the page is shown.
 *
 * WHY AT DISPLAY TIME?
 *
 * Because nothing is rewritten in the database. Content keeps linking to the
 * original PDF, so deactivating the plugin, or deleting a tagged copy, puts every
 * link back the way it was without touching a single post.
 *
 * WHAT IT CHANGES
 *
 * The whole page, once it is finished: content, menus, widgets, templates and
 * custom fields alike, however the theme prints them. `href` and `data`
 * attributes (the File block's inline preview uses `data`) whose URL is a file
 * in this site's uploads folder with an entry in the link map. Links to other
 * sites, and to PDFs with no tagged copy, are left exactly as they were. The map
 * is one autoloaded option, so a page costs no extra queries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Links {

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 0 );
	}

	/** Buffer the page, if this site has any tagged PDFs to point at. */
	public static function start(): void {
		if ( Equalify_Iris_Settings::link_map() ) {
			ob_start( array( __CLASS__, 'swap' ) );
		}
	}

	/**
	 * `href`, `data` and `src` attributes ending in .pdf (links, objects, and
	 * embeds or iframes showing the PDF), with any query string or fragment after
	 * it. Groups: 1 attribute, 2 `=`, 3 quote, 4 URL, 5 query.
	 */
	const PATTERN = '#\b(href|data|src)(\s*=\s*)(["\'])([^"\']+?\.pdf)((?:[?\#][^"\']*)?)\3#i';

	/**
	 * The file a URL points at, relative to this site's uploads folder, or ''
	 * when it points anywhere else.
	 */
	public static function upload_file( string $url ): string {
		$base   = wp_get_upload_dir()['baseurl'];
		$link   = wp_parse_url( html_entity_decode( $url, ENT_QUOTES ) );
		$hosts  = array( wp_parse_url( $base, PHP_URL_HOST ), wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) );
		$hosts  = array_map( 'strtolower', array_filter( array_map( 'strval', $hosts ) ) );
		$prefix = is_multisite() ? untrailingslashit( (string) get_site()->path ) : '';

		// This site's host, or a link with no host at all (`/wp-content/...`).
		if ( ! is_array( $link ) || ( ! empty( $link['host'] ) && ! in_array( strtolower( $link['host'] ), $hosts, true ) ) ) {
			return '';
		}

		// The background job reads every site from the main one, where WordPress
		// gives a subdirectory site's uploads as /wp-content/uploads/sites/3 rather
		// than the /library/wp-content/uploads/sites/3 its own pages use, and a
		// subdomain site's on the main site's host. Both are the same folder, so
		// compare without the site's path.
		$base_path = self::without_prefix( untrailingslashit( (string) wp_parse_url( $base, PHP_URL_PATH ) ) . '/', $prefix );
		$path      = self::without_prefix( rawurldecode( (string) ( $link['path'] ?? '' ) ), $prefix );

		return str_starts_with( $path, $base_path ) ? substr( $path, strlen( $base_path ) ) : '';
	}

	private static function without_prefix( string $path, string $prefix ): string {
		return '' !== $prefix && str_starts_with( $path, $prefix . '/' ) ? substr( $path, strlen( $prefix ) ) : $path;
	}

	public static function swap( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '.pdf' ) ) {
			return $html;
		}

		$map = Equalify_Iris_Settings::link_map();

		if ( ! $map ) {
			return $html;
		}

		$base = wp_get_upload_dir()['baseurl'];

		$swapped = preg_replace_callback(
			self::PATTERN,
			static function ( array $m ) use ( $map, $base ) {
				$file = self::upload_file( $m[4] );

				if ( '' === $file || ! isset( $map[ $file ] ) ) {
					return $m[0];
				}

				$tagged = esc_url( self::url( $base, $map[ $file ] ) );
				$rest   = $m[5];

				// The link's own query string joins ours: one `?` only.
				if ( str_starts_with( $rest, '?' ) && false !== strpos( $tagged, '?' ) ) {
					$rest = '&#038;' . substr( $rest, 1 );
				}

				return $m[1] . $m[2] . $m[3] . $tagged . $rest . $m[3];
			},
			$html
		);

		// null when PCRE gives up, on a very large page, say. The page as it was
		// beats an empty one.
		return null === $swapped ? $html : $swapped;
	}

	/**
	 * A tagged copy's address, from its entry in the link map.
	 *
	 * Tagging a PDF again keeps the file's name, and CDNs and browsers keep
	 * files from the uploads folder for days. `?v=` with when it was saved gives
	 * each copy an address of its own, so nobody is sent the one it replaced.
	 */
	public static function url( string $base, string $entry ): string {
		list( $file, $version ) = explode( '?v=', $entry, 2 ) + array( '', '' );

		$url = untrailingslashit( $base ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $file ) ) );

		return '' !== $version ? $url . '?v=' . (int) $version : $url;
	}
}
