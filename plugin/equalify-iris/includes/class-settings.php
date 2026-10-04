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

	/**
	 * The most pages Iris converts in one PDF. This mirrors the server's own cap,
	 * so a longer PDF is refused here with a clear reason instead of uploaded.
	 */
	const MAX_PDF_PAGES = 25;

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
	 * tagged file, both relative to the uploads folder.
	 *
	 * Kept as one option, rather than looked up per link, so that swapping the
	 * links on a page costs no database queries at all.
	 */
	public static function link_map(): array {
		return (array) get_option( self::PREFIX . 'map', array() );
	}

	public static function set_link_map( array $map ): void {
		update_option( self::PREFIX . 'map', $map, true );
	}
}
