<?php
/**
 * WHAT IS THIS FILE?
 *
 * Every conversation this plugin has with the Equalify Iris server.
 *
 * WHY DOES IT EXIST?
 *
 * To keep all knowledge of the Iris API in one place, and to be the only file
 * that has to be changed when that API changes. Nothing else in the plugin knows
 * what an endpoint or a bearer token is.
 *
 * THE API IN BRIEF
 *
 * Iris converts asynchronously, which shapes everything here. You do not send a
 * PDF and get HTML back — you create a "session", then check on it, and collect
 * the HTML when it is ready. Minutes, not seconds. There are no webhooks in Iris
 * v1, so checking back is the only option available.
 *
 *   POST /sessions             upload the PDF, get a session id
 *   GET  /sessions/{id}        queued | running | ready_for_review | closed | failed
 *   GET  /sessions/{id}/output the finished HTML
 *   POST /sessions/{id}/close  finish up, free the server's disk
 *   GET  /limits               what this deployment accepts (never gated)
 *   GET  /me                   which deployment this is, and whether we may use it
 *
 * HOW OFTEN WE MAY ASK
 *
 * Iris limits requests per minute — 240 in general, 12 uploads — and answers 429
 * with a Retry-After header when a budget runs out. One tick sends at most a
 * handful of requests, so the plugin is nowhere near either; the handling exists
 * because the budget is counted per address, so somebody else's busy afternoon
 * behind the same NAT can still spend ours. See back_off().
 *
 * AUTHENTICATION — READ THIS BEFORE CHANGING ANYTHING HERE
 *
 * There is no sign-in. We do not hold a GitHub token, and no endpoint accepts
 * one. Iris holds a single GitHub credential of its own, server-side, and uses it
 * for every session; nothing we send is ever a GitHub credential.
 *
 * What a caller may have to send is a shared secret, and only when the operator
 * chose to require one:
 *
 *   OPEN    `server.api_token` is unset. Send no Authorization header. This is
 *           the default, and it is what the public deployment does.
 *   GATED   the operator set a secret and gave it to us. Send it as a bearer
 *           token on /me and /sessions.
 *
 * That secret answers one question — may this caller use the API? — and makes us
 * nobody in particular, because every caller who presents it is the same
 * deployment account. It is a door key, not an identity.
 *
 * Which kind we are talking to is discoverable rather than configured: call
 * GET /me and read the status. 200 means open, 401 means gated. check_connection()
 * below is that call, and it is the only thing that ever needs to know.
 *
 * TWO KINDS OF 401, AND WHY THE DIFFERENCE MATTERS
 *
 * A 401 is no longer "our credential was revoked, sign in again", because there
 * is no credential of ours to revoke. It is one of:
 *
 *   "requires a shared API token"       the deployment is gated and we lack the
 *                                       secret. A human has to paste it in;
 *                                       retrying is pointless.
 *   "could not authenticate to GitHub"  the OPERATOR's credential is wrong,
 *                                       revoked, or GitHub is down. Not ours to
 *                                       fix, and Iris retries GitHub after 30
 *                                       seconds, so a transient one clears
 *                                       itself and this IS worth retrying.
 *
 * Telling an admin to reconnect would be a dead end in both cases. See handle().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_API_Client {

	/**
	 * How many failures in a row before we stop calling Iris for a while.
	 */
	const FAILURES_BEFORE_BREAK = 5;

	/**
	 * How long to stop calling Iris after that, in seconds.
	 *
	 * A service that is down should cost us one request every quarter hour, not
	 * one request per document every five minutes. Without this, an Iris outage
	 * turns into thousands of failed HTTP calls, every one of them holding a PHP
	 * worker for its full timeout.
	 */
	const BREAK_DURATION = 900;

	/** Seconds to wait for a status check. Short: these are tiny responses. */
	const TIMEOUT_STATUS = 10;

	/** Seconds to wait for an upload. */
	const TIMEOUT_UPLOAD = 30;

	/** Seconds to wait for the HTML download. */
	const TIMEOUT_OUTPUT = 60;

	// -----------------------------------------------------------------------
	// The circuit breaker
	// -----------------------------------------------------------------------

	/**
	 * Are we currently refusing to call Iris because it kept failing?
	 *
	 * This pattern is called a "circuit breaker": after enough failures it
	 * "opens" and stops letting calls through, then closes again after a delay.
	 */
	public static function circuit_is_open(): bool {
		return time() < (int) Equalify_Iris_Settings::get( 'circuit_open_until' );
	}

	/** When the breaker will close, as a Unix timestamp. 0 when it is closed. */
	public static function circuit_open_until(): int {
		return self::circuit_is_open() ? (int) Equalify_Iris_Settings::get( 'circuit_open_until' ) : 0;
	}

	/**
	 * Note that a call failed at the network level, and open the breaker if this
	 * has now happened too many times in a row.
	 *
	 * Only connection-level problems count. An Iris response saying one document
	 * failed to convert is Iris working correctly, and must not trip the breaker
	 * for everything else.
	 */
	private static function record_failure(): void {
		$failures = (int) Equalify_Iris_Settings::get( 'consecutive_failures' ) + 1;

		Equalify_Iris_Settings::set( 'consecutive_failures', $failures );

		if ( $failures < self::FAILURES_BEFORE_BREAK ) {
			return;
		}

		Equalify_Iris_Settings::set( 'circuit_open_until', time() + self::BREAK_DURATION );
		Equalify_Iris_Settings::set( 'consecutive_failures', 0 );

		Equalify_Iris_Logger::warning(
			sprintf(
				/* translators: %d: number of minutes. */
				__( 'Cannot reach Equalify Iris. Pausing for %d minutes before trying again.', 'equalify-iris' ),
				(int) ( self::BREAK_DURATION / 60 )
			)
		);
	}

	/** Note that a call worked, which resets the failure count. */
	private static function record_success(): void {
		if ( (int) Equalify_Iris_Settings::get( 'consecutive_failures' ) > 0 ) {
			Equalify_Iris_Settings::set( 'consecutive_failures', 0 );
		}
	}

	/**
	 * Pause everything for a while because Iris told us to slow down.
	 *
	 * Reuses the circuit breaker rather than inventing a second mechanism: the
	 * behaviour we want for "rate limited" is identical to "unreachable" — stop
	 * calling for a bit.
	 */
	private static function back_off( int $seconds ): void {
		Equalify_Iris_Settings::set( 'circuit_open_until', time() + max( 60, $seconds ) );

		Equalify_Iris_Logger::warning(
			__( 'Equalify Iris asked us to slow down. Waiting before sending more documents.', 'equalify-iris' )
		);
	}

	// -----------------------------------------------------------------------
	// Making requests
	// -----------------------------------------------------------------------

	/** The full URL for one endpoint. */
	private static function url( string $path ): string {
		$base = untrailingslashit( (string) Equalify_Iris_Settings::get( 'api_url' ) );

		return $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * The headers a gated call needs, plus whatever else the caller asked for.
	 *
	 * The Authorization header is present only when we actually hold a secret. On
	 * an open deployment there is nothing to send, and sending an empty bearer
	 * would be worse than sending none: a gated deployment compares what arrives
	 * against its secret, so `Bearer ` would be a wrong answer rather than no
	 * answer, and the two produce the same 401 with different meanings to anyone
	 * reading a server log.
	 *
	 * @param string $accept What to ask for back.
	 */
	private static function auth_headers( string $accept = 'application/json' ): array {
		$headers = array( 'Accept' => $accept );

		$token = Equalify_Iris_Settings::api_token();

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * The same thing as a list of raw header lines, for the cURL upload path.
	 */
	private static function auth_header_lines(): array {
		$lines = array();

		foreach ( self::auth_headers() as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		return $lines;
	}

	/**
	 * Turn a WordPress HTTP response into either data or a WP_Error, and keep the
	 * circuit breaker informed.
	 *
	 * Every response the plugin gets goes through here, so this is the one place
	 * that decides what counts as a failure. That matters: getting it wrong in
	 * either direction is bad. Too eager, and one unconvertible PDF stops the
	 * whole network. Too reluctant, and an Iris outage costs thousands of
	 * pointless requests.
	 *
	 * @param bool $expect_json Whether to decode the body. The HTML output
	 *                          endpoint returns text/html, not JSON.
	 */
	private static function handle( $response, bool $expect_json = true ) {
		// A WP_Error here means the request never completed — DNS failure,
		// refused connection, timeout. Nothing to do with what Iris thinks.
		if ( is_wp_error( $response ) ) {
			self::record_failure();

			return new WP_Error(
				'equalify_iris_unreachable',
				sprintf(
					/* translators: %s: the underlying network error. */
					__( 'Could not reach Equalify Iris: %s', 'equalify-iris' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// We got an answer, so the server is up. Even a 500 proves reachability,
		// so reset the failure count before looking at what the answer says.
		self::record_success();

		if ( 429 === $code ) {
			// Honour Retry-After when Iris sends it, since it knows better than
			// we do how long to wait.
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			self::back_off( $retry_after > 0 ? $retry_after : self::BREAK_DURATION );

			return new WP_Error( 'equalify_iris_rate_limited', __( 'Equalify Iris is busy. We will try again shortly.', 'equalify-iris' ) );
		}

		if ( 401 === $code || 403 === $code ) {
			return self::handle_unauthorized( $code, $body );
		}

		// Iris looked at what we sent and said no. Retrying an identical request
		// cannot change the answer, so these must not go round the retry loop:
		//
		//   400 invalid_request        not a PDF; more than 25 pages; a rendered
		//                              page too large for the vision model (which
		//                              happens with big-format pages — a poster or
		//                              an architectural drawing)
		//   413 upload_too_large       the request exceeded the server's ceiling
		//   422 pdf_conversion_failed  the PDF could not be rasterized at all
		//
		// The distinction is worth the extra branch: without it a PDF Iris will
		// never accept costs five uploads over two and a half hours before it
		// settles, and reports "gave up after several attempts" rather than the one
		// sentence Iris already wrote explaining why.
		if ( in_array( $code, array( 400, 413, 422 ), true ) ) {
			return new WP_Error(
				'equalify_iris_rejected',
				self::readable_error( $code, $body ),
				array(
					'status'    => $code,
					'permanent' => true,
				)
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'equalify_iris_http_error',
				self::readable_error( $code, $body ),
				array( 'status' => $code )
			);
		}

		// Getting this far proves the gate let us through, so any refusal recorded
		// earlier is stale. Clearing it here rather than only in check_connection()
		// means an admin who fixes the secret does not also have to find a button:
		// the next tick's first successful call unblocks the worker by itself.
		self::note_authorized();

		if ( ! $expect_json ) {
			return $body;
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'equalify_iris_bad_response',
				__( 'Equalify Iris sent a response we could not understand.', 'equalify-iris' )
			);
		}

		return $data;
	}

	/**
	 * Turn an Iris error response into a sentence an admin can act on.
	 *
	 * Iris uses one error shape everywhere:
	 *   { "error": { "code": "...", "message": "...", "details": {} } }
	 *
	 * Its messages are written for humans, so we prefer Iris's own wording over
	 * anything we would invent. The HTTP code is a last resort, because "HTTP
	 * 409" tells an admin nothing at all.
	 */
	private static function readable_error( int $code, string $body ): string {
		$data = json_decode( $body, true );

		if ( isset( $data['error']['message'] ) ) {
			return (string) $data['error']['message'];
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Equalify Iris returned an unexpected response (status %d).', 'equalify-iris' ),
			$code
		);
	}

	/**
	 * Work out which kind of 401 this is, log the right sentence, and record it.
	 *
	 * Both kinds arrive as `unauthorized` with the same HTTP status, so the message
	 * is the only thing that separates them. Sniffing text is not lovely, but the
	 * alternative — treating them alike — is what produces the bug worth avoiding:
	 * a fault at the deployment's own end that clears itself in thirty seconds
	 * would otherwise leave the plugin permanently halted, waiting for a super
	 * admin to paste in a secret that does not exist.
	 *
	 * When the message matches neither, the fallback leans on what we sent. If we
	 * hold no secret, a 401 almost certainly means the deployment wants one.
	 */
	private static function handle_unauthorized( int $code, string $body ) {
		$message = self::readable_error( $code, $body );

		$is_github_side = false !== stripos( $message, 'authenticate to GitHub' );

		if ( $is_github_side ) {
			Equalify_Iris_Settings::set_auth_state( Equalify_Iris_Settings::AUTH_DEPLOYMENT_ERROR );

			Equalify_Iris_Logger::error(
				sprintf(
					/* translators: %s: the message Equalify Iris returned. */
					__( 'Equalify Iris could not authenticate itself to GitHub, so it cannot convert anything right now. This is a problem with the Equalify Iris server, not with this network — tell whoever runs it. It says: %s', 'equalify-iris' ),
					$message
				)
			);

			// Deliberately NOT permanent. Iris retries GitHub after thirty
			// seconds, so the next tick may well succeed, and marking documents
			// permanently failed for somebody else's transient outage would throw
			// away work that was about to start.
			return new WP_Error(
				'equalify_iris_deployment_unauthorized',
				$message,
				array( 'status' => $code )
			);
		}

		Equalify_Iris_Settings::set_auth_state( Equalify_Iris_Settings::AUTH_NEEDS_TOKEN );

		Equalify_Iris_Logger::error(
			__( 'Equalify Iris refused us: this deployment is closed and needs a shared API token. Ask whoever runs it for the token, then enter it on the Settings screen. Nothing will convert until then.', 'equalify-iris' )
		);

		return new WP_Error(
			'equalify_iris_needs_token',
			__( 'Equalify Iris needs a shared API token for this deployment. Enter it on the Settings screen.', 'equalify-iris' ),
			array(
				'status'    => $code,
				'permanent' => true,
			)
		);
	}

	/**
	 * Remember that Iris let us in, if we were not already sure of it.
	 *
	 * Guarded rather than written unconditionally because this runs on every
	 * successful response, and update_site_option() on an unchanged value is a
	 * database round trip we take several times a tick for nothing.
	 */
	private static function note_authorized(): void {
		if ( Equalify_Iris_Settings::AUTH_OK !== Equalify_Iris_Settings::auth_state() ) {
			Equalify_Iris_Settings::set_auth_state( Equalify_Iris_Settings::AUTH_OK );
		}
	}

	/**
	 * Did Iris refuse this request in a way that retrying cannot fix?
	 *
	 * Callers use this to choose between "try again later" and "stop and say why".
	 * See handle() for which responses count.
	 *
	 * @param mixed $error Anything; only a WP_Error can be a rejection.
	 */
	public static function is_permanent_rejection( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();

		return is_array( $data ) && ! empty( $data['permanent'] );
	}

	// -----------------------------------------------------------------------
	// Asking Iris what it accepts
	// -----------------------------------------------------------------------

	/**
	 * What this Iris deployment will accept for an upload.
	 *
	 * `GET /v1/limits` needs no token, because someone deciding whether a scan is
	 * small enough should not have to sign in to find out.
	 *
	 * We do not convert from this at upload time, deliberately. The worker has to
	 * decide "is this PDF too long?" while looking at a file, and making that
	 * decision depend on a network call would mean a document that cannot be
	 * inspected when Iris is unreachable. So the caps stay as constants in
	 * class-settings.php and this is how `wp equalify-iris doctor` checks that
	 * those constants still match the server — which is the failure worth
	 * catching, since a mismatch shows up as uploads Iris rejects.
	 *
	 * @return array{max_pages?: int, pdf?: array, upload?: array, rate_limits?: array|null}|WP_Error
	 */
	public static function limits() {
		$response = wp_remote_get(
			self::url( '/limits' ),
			array(
				'timeout' => self::TIMEOUT_STATUS,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		return self::handle( $response );
	}

	// -----------------------------------------------------------------------
	// Finding out whether we may use this deployment
	// -----------------------------------------------------------------------

	/**
	 * Ask which Iris this is and whether it will let us in.
	 *
	 * This replaces what used to be a GitHub device-flow sign-in. Iris v1 removed
	 * client authentication altogether, so there is nothing to sign into: the
	 * question is no longer "who are we" but "is this deployment open, and if not,
	 * does the secret we hold open it".
	 *
	 * GET /me answers both in one round trip, and it is the right call to ask
	 * because it runs exactly the check an upload runs — so an answer here cannot
	 * be more optimistic than what conversion will actually do.
	 *
	 *   200  usable. `mode` says whether it was open or our secret was accepted.
	 *   401  gated and we cannot open it, or the deployment's own GitHub
	 *        credential is broken. handle() has already told them apart, logged
	 *        the difference and recorded it.
	 *
	 * The identity that comes back is the DEPLOYMENT's, not ours, and is stored so
	 * the Settings screen can name the account and repository this network's
	 * document extracts will be attributed to.
	 *
	 * @return array{mode: string, login: string, upstream_repo: string, max_review_iterations: int}|WP_Error
	 */
	public static function check_connection() {
		$response = wp_remote_get(
			self::url( '/me' ),
			array(
				'timeout' => self::TIMEOUT_STATUS,
				'headers' => self::auth_headers(),
			)
		);

		$data = self::handle( $response );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$login = isset( $data['github_login'] ) ? (string) $data['github_login'] : '';
		$repo  = isset( $data['upstream_repo'] ) ? (string) $data['upstream_repo'] : '';

		Equalify_Iris_Settings::set( 'deployment_login', $login );
		Equalify_Iris_Settings::set( 'upstream_repo', $repo );

		return array(
			// "Gated" is inferred from what we sent, not from anything in the
			// response: a 200 on a request carrying our secret means the secret was
			// checked and accepted, and a 200 on a request carrying nothing means
			// there was no gate to check. Iris does not otherwise say which it is,
			// and does not need to.
			'mode'                  => Equalify_Iris_Settings::has_api_token() ? 'gated' : 'open',
			'login'                 => $login,
			'upstream_repo'         => $repo,
			'max_review_iterations' => isset( $data['defaults']['max_review_iterations'] )
				? (int) $data['defaults']['max_review_iterations']
				: 0,
		);
	}


	// -----------------------------------------------------------------------
	// Converting a document
	// -----------------------------------------------------------------------

	/**
	 * Upload one PDF and start converting it.
	 *
	 * Note the form field name is `images`, even for a PDF. That is what the Iris
	 * API expects — it accepts page images or a PDF through the same field, and
	 * rasterizes a PDF into one image per page on the server.
	 *
	 * @return array{session_id: string, status: string}|WP_Error
	 */
	public static function create_session( string $file_path ) {
		if ( self::circuit_is_open() ) {
			return new WP_Error(
				'equalify_iris_paused',
				__( 'Paused after repeated problems reaching Equalify Iris. Will resume automatically.', 'equalify-iris' )
			);
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'equalify_iris_file_missing',
				__( 'The PDF file could not be read on the server.', 'equalify-iris' )
			);
		}

		// Prefer cURL, which streams the file straight from disk. See
		// upload_with_curl() for why this matters so much.
		if ( function_exists( 'curl_file_create' ) && function_exists( 'curl_init' ) ) {
			return self::upload_with_curl( $file_path );
		}

		return self::upload_with_wp_http( $file_path );
	}

	/**
	 * Upload using cURL directly, streaming from disk.
	 *
	 * WHY NOT wp_remote_post() HERE?
	 *
	 * Because WordPress's HTTP functions want the request body as a string, which
	 * means reading the whole PDF into memory and then holding a second copy
	 * inside the multipart body — roughly 2x the file size in PHP memory. A 40 MB
	 * PDF becomes 80 MB+ of memory and dies on a typical shared host, taking the
	 * whole cron run with it.
	 *
	 * cURL's CURLFile hands the file to cURL by path and lets it stream from disk,
	 * so memory stays flat no matter how big the PDF is. Using cURL directly is
	 * unusual in a WordPress plugin and this is the reason it is worth it.
	 */
	private static function upload_with_curl( string $file_path ) {
		$handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$post_fields = array(
			// The uploaded name matters: Iris decides how to treat a file by its
			// extension, so it must end in .pdf.
			'images' => curl_file_create( $file_path, 'application/pdf', basename( $file_path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions
		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => self::url( '/sessions' ),
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $post_fields,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => self::TIMEOUT_UPLOAD,
				CURLOPT_HTTPHEADER     => self::auth_header_lines(),
			)
		);

		$body  = curl_exec( $handle );
		$code  = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error = curl_error( $handle );

		curl_close( $handle );
		// phpcs:enable

		if ( false === $body ) {
			self::record_failure();

			return new WP_Error(
				'equalify_iris_unreachable',
				sprintf(
					/* translators: %s: the underlying network error. */
					__( 'Could not reach Equalify Iris: %s', 'equalify-iris' ),
					$error
				)
			);
		}

		// Rebuild the shape handle() expects, so both upload paths share one set
		// of response rules rather than each inventing its own.
		return self::handle(
			array(
				'response' => array( 'code' => $code ),
				'body'     => $body,
				'headers'  => array(),
			)
		);
	}

	/**
	 * Upload using WordPress's HTTP functions, building the body in memory.
	 *
	 * The fallback for the rare host with no cURL. Only safe for files that fit
	 * comfortably in the memory PHP has left, which the caller checks before
	 * getting here.
	 */
	private static function upload_with_wp_http( string $file_path ) {
		$boundary = wp_generate_password( 24, false );
		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $contents ) {
			return new WP_Error(
				'equalify_iris_file_missing',
				__( 'The PDF file could not be read on the server.', 'equalify-iris' )
			);
		}

		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="images"; filename="' . basename( $file_path ) . '"' . "\r\n";
		$body .= 'Content-Type: application/pdf' . "\r\n\r\n";
		$body .= $contents . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		// Let the string go as soon as it is copied into the body, so we hold one
		// copy rather than two while the request runs.
		unset( $contents );

		$response = wp_remote_post(
			self::url( '/sessions' ),
			array(
				'timeout' => self::TIMEOUT_UPLOAD,
				'headers' => self::auth_headers() + array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		return self::handle( $response );
	}

	/**
	 * How is a conversion going?
	 *
	 * `status` comes back as one of queued, running, ready_for_review, closed or
	 * failed. Treat that list as open rather than closed — the Iris docs say a
	 * client should show an unrecognised value rather than nothing, so the worker
	 * keeps waiting on anything it does not know instead of failing the document.
	 *
	 * @return array{status: string, phase?: string, error?: string}|WP_Error
	 */
	public static function get_session( string $session_id ) {
		if ( self::circuit_is_open() ) {
			return new WP_Error(
				'equalify_iris_paused',
				__( 'Paused after repeated problems reaching Equalify Iris. Will resume automatically.', 'equalify-iris' )
			);
		}

		$response = wp_remote_get(
			self::url( '/sessions/' . rawurlencode( $session_id ) ),
			array(
				'timeout' => self::TIMEOUT_STATUS,
				'headers' => self::auth_headers(),
			)
		);

		return self::handle( $response );
	}

	/**
	 * Download the finished HTML.
	 *
	 * Returns a 409 if the session is not actually ready, which the worker treats
	 * as "keep waiting" rather than as a failure.
	 *
	 * @return string|WP_Error The HTML.
	 */
	public static function get_output( string $session_id ) {
		if ( self::circuit_is_open() ) {
			return new WP_Error(
				'equalify_iris_paused',
				__( 'Paused after repeated problems reaching Equalify Iris. Will resume automatically.', 'equalify-iris' )
			);
		}

		$response = wp_remote_get(
			self::url( '/sessions/' . rawurlencode( $session_id ) . '/output' ),
			array(
				'timeout' => self::TIMEOUT_OUTPUT,
				'headers' => self::auth_headers( 'text/html' ),
			)
		);

		return self::handle( $response, false );
	}

	/**
	 * Tell Iris we are done, so it can delete its working files.
	 *
	 * Best effort by design. We already have the HTML by the time we call this,
	 * so a failure here costs Iris some disk space and costs us nothing. It must
	 * never be allowed to fail a document we have successfully converted.
	 */
	public static function close_session( string $session_id ): void {
		$response = wp_remote_post(
			self::url( '/sessions/' . rawurlencode( $session_id ) . '/close' ),
			array(
				'timeout' => self::TIMEOUT_STATUS,
				'headers' => self::auth_headers(),
			)
		);

		$result = self::handle( $response );

		if ( is_wp_error( $result ) ) {
			Equalify_Iris_Logger::log(
				sprintf(
					/* translators: %s: the Iris session id. */
					__( 'Could not close Equalify Iris session %s. The document was saved successfully; only the server-side cleanup failed.', 'equalify-iris' ),
					$session_id
				)
			);
		}
	}
}
