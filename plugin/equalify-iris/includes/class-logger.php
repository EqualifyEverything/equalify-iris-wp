<?php
/**
 * WHAT IS THIS FILE?
 *
 * A short activity log the admin can read in the dashboard.
 *
 * WHY DOES IT EXIST?
 *
 * Because the first question anyone asks about a background process is "what is
 * it actually doing?", and the honest answer has to come from somewhere. Without
 * this, a stalled queue and a healthy-but-idle queue look identical.
 *
 * It is deliberately tiny: the last 200 events, kept in a single network option,
 * oldest dropped as new ones arrive. That is called a ring buffer. It cannot
 * grow without bound, cannot need its own database table, and cannot become the
 * reason a site runs out of disk — all of which are real risks for a logger that
 * writes a row per event forever on a network converting tens of thousands of
 * documents.
 *
 * This is a log for humans, not a debugging trace. Each entry is a full sentence
 * an admin can act on. Detailed per-document errors live on the document row
 * itself, where the retry button is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Logger {

	/** The network option the log lives in. */
	const OPTION = 'equalify_iris_activity_log';

	/**
	 * How many entries to keep.
	 *
	 * 200 is enough to cover a day of normal activity, and small enough that the
	 * whole option stays well under the size where storing it becomes slow.
	 */
	const MAX_ENTRIES = 200;

	/** Something worth knowing happened. */
	const INFO = 'info';

	/** Something went wrong that an admin may need to act on. */
	const ERROR = 'error';

	/** Something is off but the plugin is handling it, e.g. backing off. */
	const WARNING = 'warning';

	/**
	 * Record one event.
	 *
	 * @param string $message A complete sentence in plain language. This goes on
	 *                        screen unedited, so write it for an admin who has
	 *                        never read the code — no error codes, no jargon.
	 * @param string $level   One of the INFO / WARNING / ERROR constants above.
	 * @param array  $context Optional extra detail shown alongside the message,
	 *                        such as which site or document it concerns.
	 */
	public static function log( string $message, string $level = self::INFO, array $context = array() ): void {
		$entries = self::entries();

		$entries[] = array(
			'time'    => time(),
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);

		// Keep only the newest MAX_ENTRIES. array_slice with a negative length
		// counted from the end is the whole ring buffer.
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		update_site_option( self::OPTION, $entries );
	}

	/** Shorthand for an error-level entry. */
	public static function error( string $message, array $context = array() ): void {
		self::log( $message, self::ERROR, $context );
	}

	/** Shorthand for a warning-level entry. */
	public static function warning( string $message, array $context = array() ): void {
		self::log( $message, self::WARNING, $context );
	}

	/**
	 * Every entry, oldest first.
	 */
	public static function entries(): array {
		$entries = get_site_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Every entry, newest first — which is the order the dashboard shows them in.
	 */
	public static function recent(): array {
		return array_reverse( self::entries() );
	}

	/** Empty the log. */
	public static function clear(): void {
		update_site_option( self::OPTION, array() );
	}
}
