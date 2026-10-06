<?php
/**
 * WHAT IS THIS FILE?
 *
 * Every setting the plugin has, and where each one is stored.
 *
 * TWO KINDS OF SETTING
 *
 *   NETWORK  get_site_option(): one value for the whole network, changed by a
 *            super admin. Where Iris lives, its token if it needs one, and whether
 *            every site is tagged automatically.
 *
 *   SITE     get_option(): one value per site, changed by that site's admin.
 *            Whether this site tags its own PDFs automatically, and the map of
 *            PDFs that have a tagged version.
 *
 * Mixing the two up is the easiest mistake to make in a multisite plugin, so
 * nothing outside this class calls either function with one of our keys.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Settings {

	const PREFIX = 'equalify_iris_';

	const DEFAULT_API_URL = 'https://iris.equalify.uic.edu/v1';

	/** Above this, the link map is no longer autoloaded (see set_link_map()). */
	const MAP_AUTOLOAD_BYTES = 65536;

	/**
	 * The largest PDF we send, in bytes (50 MB). Ours rather than the server's:
	 * a shared host struggles to push more than this in one request.
	 */
	const MAX_FILE_BYTES = 52428800;

	// -----------------------------------------------------------------------
	// Network settings
	// -----------------------------------------------------------------------

	public static function api_url(): string {
		$url = (string) get_site_option( self::PREFIX . 'api_url', '' );

		return '' !== $url ? untrailingslashit( $url ) : self::DEFAULT_API_URL;
	}

	public static function set_api_url( string $url ): void {
		update_site_option( self::PREFIX . 'api_url', untrailingslashit( $url ) );
		self::clear_refused();
	}

	/**
	 * https, or http to this server itself: loopback addresses, `localhost`, and
	 * Docker's `host.docker.internal`, for a copy of Iris running alongside. Any
	 * other http address only with this in wp-config.php:
	 *
	 *     define( 'EQUALIFY_IRIS_ALLOW_HTTP', true );
	 */
	public static function url_is_allowed( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' ) );

		if ( '' === $host ) {
			return false;
		}

		if ( 'https' === $scheme ) {
			return true;
		}

		if ( 'http' !== $scheme ) {
			return false;
		}

		if ( defined( 'EQUALIFY_IRIS_ALLOW_HTTP' ) && EQUALIFY_IRIS_ALLOW_HTTP ) {
			return true;
		}

		return in_array( $host, array( 'localhost', '::1', 'host.docker.internal' ), true )
			|| str_ends_with( $host, '.localhost' )
			|| 1 === preg_match( '/^127\.\d+\.\d+\.\d+$/', $host );
	}

	/** Seconds the tagger waits before trying again after Iris refused to let us in. */
	const REFUSED_PAUSE = 15 * MINUTE_IN_SECONDS;

	/**
	 * Why Iris last refused to let us in (a 401 or 403, or an address that is not
	 * allowed), or '' when it has not since the settings last changed. While it is
	 * recent, no site sends Iris anything.
	 */
	public static function refused(): string {
		$refused = get_site_option( self::PREFIX . 'refused' );

		return is_array( $refused ) ? (string) ( $refused['message'] ?? '' ) : '';
	}

	public static function refused_recently(): bool {
		$refused = get_site_option( self::PREFIX . 'refused' );

		return is_array( $refused ) && time() - (int) ( $refused['at'] ?? 0 ) < self::REFUSED_PAUSE;
	}

	public static function set_refused( string $message ): void {
		update_site_option( self::PREFIX . 'refused', array( 'message' => $message, 'at' => time() ) );
	}

	public static function clear_refused(): void {
		if ( false !== get_site_option( self::PREFIX . 'refused' ) ) {
			delete_site_option( self::PREFIX . 'refused' );
		}
	}

	/**
	 * The shared secret to send, or '' when there is none, which is normal: most
	 * deployments, including the public one, need no token at all.
	 *
	 * A constant in wp-config.php wins over the stored value. A constant is not in
	 * a database backup and does not travel to a staging copy of the database.
	 *
	 *     define( 'EQUALIFY_IRIS_API_TOKEN', 'the-secret-the-operator-gave-you' );
	 */
	public static function api_token(): string {
		if ( self::token_is_from_constant() ) {
			return (string) EQUALIFY_IRIS_API_TOKEN;
		}

		return (string) get_site_option( self::PREFIX . 'api_token', '' );
	}

	public static function token_is_from_constant(): bool {
		return defined( 'EQUALIFY_IRIS_API_TOKEN' ) && EQUALIFY_IRIS_API_TOKEN;
	}

	public static function set_api_token( string $token ): void {
		update_site_option( self::PREFIX . 'api_token', $token );
		self::clear_refused();
	}

	/** Has a super admin turned on automatic tagging for every site? */
	public static function network_auto(): bool {
		return (bool) get_site_option( self::PREFIX . 'network_auto', false );
	}

	public static function set_network_auto( bool $on ): void {
		if ( $on && ! self::network_auto() ) {
			// Every site has to look through its media library again. Bumping one
			// number does that without visiting 100,000 sites here, and the job
			// gets to each in turn.
			update_site_option( self::PREFIX . 'generation', self::generation() + 1 );
			update_site_option( self::PREFIX . 'network_auto', $on );
			Equalify_Iris_Runner::wake_all();
			return;
		}

		update_site_option( self::PREFIX . 'network_auto', $on );
	}

	/**
	 * Where the job runs: the network's address, as the database has it, and the
	 * environment type wp-config.php gives (production when it gives none).
	 *
	 * Read from the database, not through WP_HOME or WP_SITEURL, which some hosts
	 * set from whichever address the request came in on.
	 *
	 * @return array{address: string, environment: string}
	 */
	public static function home(): array {
		global $wpdb;

		if ( is_multisite() ) {
			$network = get_network();
			$address = $network ? $network->domain . $network->path : '';
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$address = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl' LIMIT 1" );
		}

		return array(
			// http and https, and a trailing slash, are the same place.
			'address'     => untrailingslashit( strtolower( (string) preg_replace( '#^[a-z]+://#i', '', $address ) ) ),
			'environment' => wp_get_environment_type(),
		);
	}

	/**
	 * Is this a copy of the network the job was set up on — a staging site, say,
	 * made from a copy of the live database?
	 *
	 * A copy has the live site's PDFs that are at Iris, by their session ids. If
	 * it carried on, it would fetch and close them before the live site could,
	 * and send Iris its own PDFs as well. So it does nothing until someone says
	 * it should (see Equalify_Iris_Runner::resume_here()).
	 */
	public static function is_copy(): bool {
		$home = get_site_option( self::PREFIX . 'home' );

		return is_array( $home ) && $home !== self::home();
	}

	/** @return array{address: string, environment: string}|null Where the job was set up. */
	public static function original_home(): ?array {
		$home = get_site_option( self::PREFIX . 'home' );

		return is_array( $home ) ? $home : null;
	}

	/** The job belongs here from now on. */
	public static function remember_home(): void {
		update_site_option( self::PREFIX . 'home', self::home() );
	}

	/** Goes up each time automatic tagging is switched on network-wide. */
	public static function generation(): int {
		return (int) get_site_option( self::PREFIX . 'generation', 0 );
	}

	// -----------------------------------------------------------------------
	// Site settings
	// -----------------------------------------------------------------------

	/** Has this site's admin turned on automatic tagging? */
	public static function site_auto(): bool {
		return (bool) get_option( self::PREFIX . 'auto_tag', false );
	}

	public static function set_site_auto( bool $on ): void {
		if ( $on && ! self::site_auto() ) {
			delete_option( self::PREFIX . 'scanned' );
			Equalify_Iris_Runner::wake();
		}

		update_option( self::PREFIX . 'auto_tag', $on );
		Equalify_Iris_Runner::set_site_auto_flag( $on );
	}

	/** Is every PDF on the current site tagged without anybody asking? */
	public static function auto_enabled(): bool {
		return self::network_auto() || self::site_auto();
	}

	/**
	 * Does the current site still have to look for public PDFs that were there
	 * before automatic tagging was switched on?
	 */
	public static function needs_scan(): bool {
		return self::auto_enabled()
			&& (int) get_option( self::PREFIX . 'scanned', -1 ) !== self::generation();
	}

	public static function mark_scanned(): void {
		update_option( self::PREFIX . 'scanned', self::generation() );
	}

	/**
	 * Every PDF on this site that has a tagged version, as original file =>
	 * tagged file, both relative to the uploads folder. The tagged file may end
	 * in `?v=` and when it was saved (see Equalify_Iris_Links::url()).
	 *
	 * Kept as one option, rather than looked up per link, so that swapping the
	 * links on a page costs no database queries at all.
	 */
	public static function link_map(): array {
		return (array) get_option( self::PREFIX . 'map', array() );
	}

	/**
	 * Autoloaded, because every page visitors load reads it, unless it grows
	 * large enough (thousands of tagged PDFs) that loading it with every other
	 * request costs more than the one query it saves.
	 */
	public static function set_link_map( array $map ): void {
		update_option( self::PREFIX . 'map', $map, strlen( serialize( $map ) ) < self::MAP_AUTOLOAD_BYTES ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}
}
