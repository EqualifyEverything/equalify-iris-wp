<?php
/**
 * WHAT IS THIS FILE?
 *
 * Every conversation this plugin has with the Equalify Iris server, and the only
 * file that knows what an endpoint is.
 *
 * THE API IN BRIEF
 *
 * Iris works in minutes, not seconds, and has no webhooks, so a PDF goes through
 * several requests spread across several runs of the background job:
 *
 *   POST /sessions             upload the PDF, get a session id
 *   GET  /sessions/{id}        queued | running | ready_for_review | closed | failed
 *   POST /sessions/{id}/pdf    the original PDF back, tagged from Iris's HTML
 *   POST /sessions/{id}/close  finished; Iris may delete its working files
 *   GET  /limits               what this deployment accepts (never gated)
 *   GET  /me                   whether this deployment will let us in
 *
 * AUTHENTICATION
 *
 * There is no sign-in. Most deployments are open and want no Authorization header
 * at all. An operator may close theirs with a shared secret, which we then send as
 * a bearer token. It is a door key, not an identity.
 *
 * ERRORS
 *
 * Every error comes back as a WP_Error. Those Iris will refuse again however often
 * we ask — not a PDF, too many pages, encrypted, no tagger — carry
 * `permanent => true`, so the tagger stops and says why instead of retrying.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_API_Client {

	/** Seconds to wait for a status check. */
	const TIMEOUT_STATUS = 15;

	/** Seconds to wait for an upload. */
	const TIMEOUT_UPLOAD = 60;

	/**
	 * Most seconds to wait for a tagged PDF. Iris gives its tagger 300 seconds
	 * before answering 504, so a little longer than that. In practice the wait is
	 * cut to what is left of the run, which on most hosts is far less.
	 */
	const TIMEOUT_TAG = 320;

	// -----------------------------------------------------------------------
	// Requests
	// -----------------------------------------------------------------------

	private static function url( string $path ): string {
		return Equalify_Iris_Settings::api_url() . '/' . ltrim( $path, '/' );
	}

	/**
	 * Headers for a request. No Authorization header at all when we hold no
	 * secret: an empty bearer is a wrong answer to a gated deployment, not none.
	 */
	private static function headers( array $extra = array() ): array {
		$headers = array( 'Accept' => 'application/json' ) + $extra;
		$token   = Equalify_Iris_Settings::api_token();

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Turn a response into decoded JSON or a WP_Error.
	 *
	 * @param array|WP_Error $response From wp_remote_*(), or the same shape.
	 */
	private static function handle( $response, int $timeout = 0 ) {
		if ( is_wp_error( $response ) && $timeout && false !== stripos( $response->get_error_message(), 'timed out' ) ) {
			return new WP_Error(
				'equalify_iris_timeout',
				sprintf(
					/* translators: %d: seconds. */
					__( 'Equalify Iris took longer than the %d seconds this server lets the job wait.', 'equalify-iris' ),
					$timeout
				)
			);
		}

		if ( is_wp_error( $response ) ) {
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
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 200 && $code < 300 ) {
			if ( ! is_array( $data ) ) {
				return new WP_Error( 'equalify_iris_bad_response', __( 'Equalify Iris sent a response we could not understand.', 'equalify-iris' ) );
			}

			return $data;
		}

		$error_code = isset( $data['error']['code'] ) ? (string) $data['error']['code'] : '';
		$message    = isset( $data['error']['message'] )
			? (string) $data['error']['message']
			: sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Equalify Iris returned an unexpected response (status %d).', 'equalify-iris' ),
				$code
			);

		// Iris looked at what we sent and said no. Asking again cannot change that:
		// 400 not a PDF or too many pages, 401 a gated deployment we hold no secret
		// for, 413 too big, 422 unconvertible or refused by the tagger (encrypted,
		// say), 409 no_source_pdf, 404 tagged_pdf_unavailable.
		$permanent = in_array( $code, array( 400, 401, 403, 413, 422 ), true )
			|| in_array( $error_code, array( 'no_source_pdf', 'tagged_pdf_unavailable' ), true );

		// Iris's own GitHub credential failing is also a 401, but it is the
		// deployment's fault and clears itself, so it is worth retrying.
		if ( 401 === $code && false !== stripos( $message, 'authenticate to GitHub' ) ) {
			$permanent = false;
		}

		return new WP_Error(
			'equalify_iris_http_error',
			$message,
			array(
				'status'    => $code,
				'code'      => $error_code,
				'permanent' => $permanent,
			)
		);
	}

	/** Did Iris refuse in a way that retrying cannot fix? */
	public static function is_permanent( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();

		return is_array( $data ) && ! empty( $data['permanent'] );
	}

	/** The Iris error code (`invalid_state`, `busy`…) inside a WP_Error, if any. */
	public static function error_code( $error ): string {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;

		return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
	}

	/** The HTTP status inside a WP_Error, or 0 when the request never completed. */
	public static function status( $error ): int {
		$data = is_wp_error( $error ) ? $error->get_error_data() : null;

		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	// -----------------------------------------------------------------------
	// Asking about the deployment
	// -----------------------------------------------------------------------

	/** @return array|WP_Error GET /limits, which includes `tagged_pdf: bool`. */
	public static function limits() {
		return self::handle(
			wp_remote_get(
				self::url( '/limits' ),
				array(
					'timeout' => self::TIMEOUT_STATUS,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			)
		);
	}

	/** @return array|WP_Error GET /me, which fails with a 401 if we may not use it. */
	public static function me() {
		return self::handle(
			wp_remote_get(
				self::url( '/me' ),
				array(
					'timeout' => self::TIMEOUT_STATUS,
					'headers' => self::headers(),
				)
			)
		);
	}

	/**
	 * Can this deployment tag PDFs for us? A sentence for the settings screen and
	 * the CLI, and whether the answer is good.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function check(): array {
		$limits = self::limits();

		if ( is_wp_error( $limits ) ) {
			return array( 'ok' => false, 'message' => $limits->get_error_message() );
		}

		if ( empty( $limits['tagged_pdf'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This Equalify Iris deployment does not make tagged PDFs. Ask whoever runs it to turn the feature on, or use a deployment that has it.', 'equalify-iris' ),
			);
		}

		$me = self::me();

		if ( is_wp_error( $me ) ) {
			return array( 'ok' => false, 'message' => $me->get_error_message() );
		}

		return array( 'ok' => true, 'message' => __( 'Connected. This Equalify Iris deployment can tag PDFs.', 'equalify-iris' ) );
	}

	// -----------------------------------------------------------------------
	// Tagging one PDF
	// -----------------------------------------------------------------------

	/**
	 * Upload a PDF and start converting it.
	 *
	 * The form field is `images` even for a PDF; that is the Iris API. cURL is
	 * used when it is there because it streams the file from disk, where
	 * wp_remote_post() would hold the whole PDF in memory twice.
	 *
	 * @return array{session_id: string, status: string}|WP_Error
	 */
	public static function create_session( string $file_path ) {
		if ( function_exists( 'curl_init' ) && function_exists( 'curl_file_create' ) ) {
			return self::upload_with_curl( $file_path );
		}

		return self::upload_with_wp_http( $file_path );
	}

	private static function upload_with_curl( string $file_path ) {
		$lines = array();

		foreach ( self::headers() as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions
		$handle = curl_init();

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => self::url( '/sessions' ),
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => array(
					'images' => curl_file_create( $file_path, 'application/pdf', basename( $file_path ) ),
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => self::TIMEOUT_UPLOAD,
				CURLOPT_HTTPHEADER     => $lines,
			)
		);

		$body  = curl_exec( $handle );
		$code  = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error = curl_error( $handle );

		curl_close( $handle );
		// phpcs:enable

		if ( false === $body ) {
			return self::handle( new WP_Error( 'http_request_failed', $error ) );
		}

		return self::handle(
			array(
				'response' => array( 'code' => $code ),
				'body'     => $body,
				'headers'  => array(),
			)
		);
	}

	private static function upload_with_wp_http( string $file_path ) {
		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $contents ) {
			return new WP_Error( 'equalify_iris_file_missing', __( 'The PDF file could not be read on the server.', 'equalify-iris' ) );
		}

		$boundary = wp_generate_password( 24, false );

		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="images"; filename="' . basename( $file_path ) . '"' . "\r\n";
		$body .= "Content-Type: application/pdf\r\n\r\n";
		$body .= $contents . "\r\n";
		$body .= '--' . $boundary . "--\r\n";

		unset( $contents );

		return self::handle(
			wp_remote_post(
				self::url( '/sessions' ),
				array(
					'timeout' => self::TIMEOUT_UPLOAD,
					'headers' => self::headers( array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) ),
					'body'    => $body,
				)
			)
		);
	}

	/** @return array{status: string, error?: string}|WP_Error */
	public static function get_session( string $session_id ) {
		return self::handle(
			wp_remote_get(
				self::url( '/sessions/' . rawurlencode( $session_id ) ),
				array(
					'timeout' => self::TIMEOUT_STATUS,
					'headers' => self::headers(),
				)
			)
		);
	}

	/**
	 * Get the original PDF back with tags built from the HTML.
	 *
	 * `retag` is always sent. Without it a PDF that already has tags is refused,
	 * and a PDF uploaded to a website with tags almost always has the empty or
	 * broken ones an authoring tool added on export — replacing them is the point.
	 *
	 * @return array{pdf: string, warnings: string[]}|WP_Error The PDF as bytes, and
	 *                                                         the tagger's warning codes.
	 */
	public static function tagged_pdf( string $session_id, int $timeout = self::TIMEOUT_TAG ) {
		$data = self::handle(
			wp_remote_post(
				self::url( '/sessions/' . rawurlencode( $session_id ) . '/pdf' ),
				array(
					'timeout' => $timeout,
					'headers' => self::headers( array( 'Content-Type' => 'application/json' ) ),
					'body'    => wp_json_encode( array( 'retag' => true ) ),
				)
			),
			$timeout
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$pdf = isset( $data['pdf'] ) ? base64_decode( (string) $data['pdf'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		if ( false === $pdf || ! str_starts_with( $pdf, '%PDF' ) ) {
			return new WP_Error( 'equalify_iris_bad_response', __( 'Equalify Iris sent back something that is not a PDF.', 'equalify-iris' ) );
		}

		$warnings = array();

		foreach ( (array) ( $data['report']['warnings'] ?? array() ) as $warning ) {
			if ( isset( $warning['code'] ) ) {
				$warnings[] = (string) $warning['code'];
			}
		}

		return array(
			'pdf'      => $pdf,
			'warnings' => $warnings,
		);
	}

	/**
	 * Tell Iris we are done so it can delete its working files. Best effort: we
	 * already have the PDF, so a failure here costs us nothing.
	 */
	public static function close_session( string $session_id ): void {
		wp_remote_post(
			self::url( '/sessions/' . rawurlencode( $session_id ) . '/close' ),
			array(
				'timeout' => self::TIMEOUT_STATUS,
				'headers' => self::headers(),
			)
		);
	}
}
