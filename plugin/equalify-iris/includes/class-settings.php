<?php
/**
 * WHAT IS THIS FILE?
 *
 * One place to read and write every setting the plugin has.
 *
 * WHY DOES IT EXIST?
 *
 * Two reasons.
 *
 * First, this is a network-wide plugin, so every setting is stored with
 * get_site_option() / update_site_option() rather than get_option(). Those are
 * the network-wide versions: one value shared by every site, instead of one
 * value per site. Mixing the two up is the single easiest mistake to make in a
 * multisite plugin, and the bug it causes is nasty — settings that appear to
 * save but only apply to whichever site you happened to be on. Routing every
 * read and write through this class means that decision is made once, here,
 * instead of in forty places.
 *
 * Second, defaults. Every setting has a sensible default in one table below, so
 * the plugin works correctly the moment it is switched on and no other file has
 * to remember what a reasonable batch size is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Settings {

	/**
	 * Every setting name is prefixed with this, so our rows are easy to find in
	 * the database and can never collide with another plugin's.
	 */
	const PREFIX = 'equalify_iris_';

	/**
	 * The most pages Equalify Iris will convert in one PDF.
	 *
	 * This is NOT our number to choose. It mirrors MAX_PDF_PAGES in the Iris
	 * server (src/util/pdf.ts), which rejects anything longer outright. We check
	 * it here so a 60-page PDF is never uploaded, never occupies a conversion
	 * slot, and gets a clear "too long" explanation instead of a server error.
	 *
	 * Raising this number on its own does nothing except waste uploads. It can
	 * only change when the Iris server's own cap changes.
	 */
	const MAX_PDF_PAGES = 25;

	/**
	 * The largest PDF we will send, in bytes (50 MB).
	 *
	 * Unlike MAX_PDF_PAGES, this one is OURS rather than the server's. Iris
	 * refuses a whole request over `upload.max_request_bytes`, which is 128 MB on
	 * a default deployment — far more than a PHP worker on a shared host can
	 * comfortably push, and the fallback upload path holds the file in memory. So
	 * we stop well short deliberately, and an admin can lower it further.
	 *
	 * Iris also caps each *image* at about 3.75 MB, but a PDF is exempt: the file
	 * we send is not what reaches the vision model, since Iris rasterizes the
	 * pages itself. Each rendered page is measured instead, which is why a
	 * large-format PDF — a poster, an architectural drawing — can be refused with
	 * a 400 naming the page even though the file itself was small. See the
	 * permanent-rejection branch in class-api-client.php.
	 *
	 * `wp equalify-iris doctor` reads the live numbers from GET /v1/limits.
	 */
	const MAX_FILE_BYTES = 52428800;

	/**
	 * What the last connection check concluded. See check_connection() in
	 * class-api-client.php.
	 *
	 * NEEDS_TOKEN is the only one that stops work: it means Iris positively
	 * refused us and no amount of retrying will help until a super admin pastes
	 * in the deployment's shared secret. UNKNOWN is deliberately treated as
	 * workable — an open deployment needs no credential at all, so a network that
	 * has configured nothing must still be able to convert.
	 */
	const AUTH_UNKNOWN          = 'unknown';
	const AUTH_OK               = 'ok';
	const AUTH_NEEDS_TOKEN      = 'needs_token';
	const AUTH_DEPLOYMENT_ERROR = 'deployment_error';

	/**
	 * Default value for every setting.
	 *
	 * The comments here are the real documentation for what each knob does, so
	 * read this table as the settings reference.
	 */
	private static function defaults(): array {
		return array(
			// Where Iris lives. Include the /v1 — every documented endpoint is
			// under it, so putting it here keeps it out of every call site.
			'api_url'               => 'https://iris.equalify.uic.edu/v1',

			// The shared secret this deployment requires, if it requires one.
			//
			// EMPTY IS THE NORMAL CASE. Iris has no sign-in: it holds its own
			// GitHub credential server-side and callers send no identity at
			// all. An operator may optionally close their deployment with
			// `server.api_token`, and this is where that secret goes. It is a
			// door key, not an identity — every caller who presents it is the
			// same deployment account.
			'api_token'             => '',

			// What the last connection check found out. Cached because the
			// answer is a network round trip and the dashboard, the worker and
			// doctor all want it. See the AUTH_* constants above.
			'auth_state'            => self::AUTH_UNKNOWN,

			// The GitHub account the Iris deployment files contributions as, and
			// the repository it files them in, both read from GET /v1/me.
			//
			// Neither is us. Naming them matters anyway: this is the account
			// this network's document extracts get attributed to, and the
			// Settings screen can point at the actual repository instead of
			// saying "a public GitHub repository" and leaving an admin to guess.
			'deployment_login'      => '',
			'upstream_repo'         => '',

			// Is the whole process running? This is what the Start/Stop button
			// flips. When false, the background job still runs but does nothing,
			// which is simpler and safer than unscheduling and rescheduling it.
			'running'               => false,

			// Should publishing or updating a post queue any new PDFs on it?
			// Separate from `running` so an admin can pause the big backfill
			// while still keeping up with new content.
			'auto_process'          => true,

			// How many documents may be at Iris at once.
			//
			// The default of 2 matches `max_concurrent_runs` in the Iris server
			// config, which is the number of conversions that deployment runs at
			// a time ACROSS ALL of its users. Going higher does not make
			// anything faster: Iris accepts the upload and parks it in a FIFO
			// queue, so all we would achieve is more documents in a `converting`
			// state that we then have to keep polling. Slower and noisier for
			// no gain.
			'max_in_flight'         => 2,

			// Per-tick work limits. A "tick" is one run of the background job.
			// These four numbers are the plugin's entire resource contract: no
			// matter how many thousands of PDFs are queued, one tick does at
			// most this much.
			'uploads_per_tick'      => 1,
			'status_checks_per_tick' => 5,
			'imports_per_tick'      => 2,

			// How many posts the discovery sweep reads per tick. Reading a post
			// means rendering its content so PDFs inside blocks and shortcodes
			// are found, which costs real time — this is the number to lower
			// first if ticks are slow, and to raise on a fast server.
			'posts_per_tick'        => 20,

			// How long one tick may take, in seconds. Checked between
			// operations, never in the middle of one, so a tick that runs out of
			// time always stops somewhere safe.
			//
			// 20 seconds is well under the 30-second limit typical of shared
			// hosting, leaving room for the rest of the request.
			'tick_budget_seconds'   => 20,

			// Refuse files bigger than this before uploading. Defaults to the
			// Iris limit; an admin on a small host may want it lower, because
			// the file has to pass through PHP's memory on hosts without cURL.
			'max_file_bytes'        => self::MAX_FILE_BYTES,

			// Sites and post types to leave alone. Both are arrays of ids /
			// names. Empty means "everything", which is the useful default for
			// an accessibility tool.
			'excluded_sites'        => array(),
			'excluded_post_types'   => array(),

			// How far the one-time sweep has got. See class-sweeper.php.
			// `complete` flips to true when every site has been walked, which is
			// when the dashboard can stop calling its totals "estimated".
			'sweep_cursor'          => array(
				'site_id'  => 0,
				'post_id'  => 0,
				'complete' => false,
			),

			// The circuit breaker. After several failures in a row we stop
			// calling Iris until this timestamp passes. See class-api-client.php.
			'circuit_open_until'    => 0,
			'consecutive_failures'  => 0,

			// When the background job last ran, as a Unix timestamp. This is how
			// the dashboard can tell "nothing is happening because cron is
			// broken" apart from "nothing is happening because there is nothing
			// to do" — a distinction an admin cannot otherwise make.
			'last_tick'             => 0,
		);
	}

	/**
	 * Read one setting. Falls back to the default when it has never been saved.
	 */
	public static function get( string $key ) {
		$defaults = self::defaults();

		if ( ! array_key_exists( $key, $defaults ) ) {
			// A typo in a setting name would otherwise return null and behave
			// like a legitimately empty value, which is very hard to spot. Fail
			// loudly during development instead.
			_doing_it_wrong( __METHOD__, esc_html( "Unknown Equalify Iris setting: {$key}" ), '0.1.0' );
			return null;
		}

		return get_site_option( self::PREFIX . $key, $defaults[ $key ] );
	}

	/**
	 * Save one setting.
	 */
	public static function set( string $key, $value ): bool {
		return update_site_option( self::PREFIX . $key, $value );
	}

	/**
	 * The shared secret to send, or an empty string when there is none.
	 *
	 * An empty string is not a problem to solve. Most deployments — including the
	 * public one — are open, and an empty token there is the correct
	 * configuration: the plugin sends no Authorization header at all.
	 *
	 * A constant in wp-config.php wins over the stored value:
	 *
	 *     define( 'EQUALIFY_IRIS_API_TOKEN', 'the-secret-the-operator-gave-you' );
	 *
	 * That is the better setup for a gated deployment. A constant is not in a
	 * database backup, is not editable through the admin screens, and does not
	 * travel when a database is copied to a staging site — so a staging copy
	 * cannot start converting documents against the live deployment.
	 */
	public static function api_token(): string {
		if ( defined( 'EQUALIFY_IRIS_API_TOKEN' ) && EQUALIFY_IRIS_API_TOKEN ) {
			return (string) EQUALIFY_IRIS_API_TOKEN;
		}

		return (string) self::get( 'api_token' );
	}

	/** Is the token coming from wp-config.php rather than the database? */
	public static function token_is_from_constant(): bool {
		return defined( 'EQUALIFY_IRIS_API_TOKEN' ) && EQUALIFY_IRIS_API_TOKEN;
	}

	/** Are we holding a shared secret at all? */
	public static function has_api_token(): bool {
		return '' !== self::api_token();
	}

	/** What the last connection check concluded. One of the AUTH_* constants. */
	public static function auth_state(): string {
		return (string) self::get( 'auth_state' );
	}

	/** Record what a connection check, or a live 401, told us. */
	public static function set_auth_state( string $state ): void {
		self::set( 'auth_state', $state );
	}

	/**
	 * Is Iris refusing us in a way only a human can fix?
	 *
	 * This is the one auth question the worker asks, and it is deliberately
	 * pessimistic in only one direction: it says yes ONLY when Iris has actually
	 * answered 401 asking for a shared secret we do not have. Anything else —
	 * never checked, checked and fine, or a fault at the deployment's own end —
	 * is something to try, because an open deployment converts documents for a
	 * network that has configured nothing whatsoever.
	 *
	 * The old version of this method asked "do we hold a credential", which was
	 * the right question against an Iris that had a sign-in and is the wrong one
	 * now: it would refuse to convert anything, forever, on the deployments where
	 * no credential exists to hold.
	 */
	public static function blocked_by_auth(): bool {
		return self::AUTH_NEEDS_TOKEN === self::auth_state();
	}

	/**
	 * Forget the stored secret and everything the last check learned.
	 */
	public static function forget_api_token(): void {
		self::set( 'api_token', '' );
		self::set( 'auth_state', self::AUTH_UNKNOWN );
		self::set( 'deployment_login', '' );
		self::set( 'upstream_repo', '' );
	}

	/**
	 * Should this site be processed at all?
	 */
	public static function site_is_included( int $site_id ): bool {
		$excluded = (array) self::get( 'excluded_sites' );
		return ! in_array( $site_id, array_map( 'intval', $excluded ), true );
	}

	/**
	 * The post types discovery should look at on the current site.
	 *
	 * "Public" is the right test rather than a hardcoded list of `post` and
	 * `page`: a network will have custom post types we have never heard of, and
	 * a PDF linked from one of those is exactly as inaccessible as a PDF linked
	 * from a page. We exclude our own post type for the obvious reason, plus
	 * `attachment`, whose own pages are not where editors link to PDFs.
	 */
	public static function included_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );

		unset( $types[ Equalify_Iris_Post_Type::POST_TYPE ], $types['attachment'] );

		$excluded = (array) self::get( 'excluded_post_types' );

		return array_values( array_diff( $types, $excluded ) );
	}

	/**
	 * Remove every setting. Used by uninstall and by the test harness.
	 */
	public static function delete_all(): void {
		foreach ( array_keys( self::defaults() ) as $key ) {
			delete_site_option( self::PREFIX . $key );
		}
	}
}
