<?php
/**
 * WHAT IS THIS FILE?
 *
 * The Activity Log screen: what the plugin has done and what went wrong, newest
 * first.
 *
 * WHY DOES IT EXIST?
 *
 * Because the work happens while nobody is watching. Without a record, a super admin
 * coming back after a weekend has no way to tell "it worked steadily" from "it broke
 * on Friday night".
 *
 * WHAT MAKES A GOOD ENTRY HERE
 *
 * Every message is a complete sentence written for someone who has never read the
 * code. No error codes, no jargon, no stack traces. Compare:
 *
 *   BAD:  ERR_CONVERSION_FAILED doc=4821 code=422
 *   GOOD: Gave up on "annual-report.pdf" after several attempts. Equalify Iris
 *         could not read the text layer in this file.
 *
 * The second one tells an admin what happened, to what, and gives them something to
 * do about it. The first tells them to go and find a developer.
 *
 * WHY ONLY 200 ENTRIES?
 *
 * The log lives in a single network option, which WordPress loads on every request.
 * An unbounded log in an option is a well-known way to make a whole site slow, so it
 * keeps the newest 200 and drops the rest. It is a record of what is happening now,
 * not an audit trail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Admin_Log {

	public function render(): void {
		$screen  = Equalify_Iris_Admin::SLUG . '-log';
		$entries = Equalify_Iris_Logger::recent();

		echo '<div class="wrap equalify-iris">';
		echo '<h1>' . esc_html__( 'Activity Log', 'equalify-iris' ) . '</h1>';

		Equalify_Iris_Admin::tabs( $screen );
		Equalify_Iris_Admin::notice();

		echo '<p>';
		printf(
			/* translators: %d: the maximum number of log entries kept. */
			esc_html__( 'The most recent %d things that happened, newest first. Times are in your local timezone.', 'equalify-iris' ),
			(int) Equalify_Iris_Logger::MAX_ENTRIES
		);
		echo '</p>';

		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'Nothing has happened yet.', 'equalify-iris' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<table class="widefat striped equalify-iris-log">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Recent activity', 'equalify-iris' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'When', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Kind', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What happened', 'equalify-iris' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$level = isset( $entry['level'] ) ? (string) $entry['level'] : Equalify_Iris_Logger::INFO;
			$time  = isset( $entry['time'] ) ? (int) $entry['time'] : 0;

			echo '<tr class="equalify-iris-log-' . esc_attr( $level ) . '">';

			echo '<td>';
			if ( $time ) {
				echo esc_html( wp_date( 'Y-m-d H:i', $time ) );
				echo '<br><span class="description">';
				printf(
					/* translators: %s: a human-readable time difference. */
					esc_html__( '%s ago', 'equalify-iris' ),
					esc_html( human_time_diff( $time ) )
				);
				echo '</span>';
			} else {
				echo '&mdash;';
			}
			echo '</td>';

			echo '<td>' . esc_html( $this->level_label( $level ) ) . '</td>';

			echo '<td>';
			echo esc_html( isset( $entry['message'] ) ? (string) $entry['message'] : '' );

			// Context is developer detail, so it goes in small print underneath
			// rather than in the sentence. An admin can ignore it; a developer
			// chasing one document needs it.
			if ( ! empty( $entry['context'] ) && is_array( $entry['context'] ) ) {
				echo '<br><span class="description">' . esc_html( $this->format_context( $entry['context'] ) ) . '</span>';
			}

			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Clear the log', 'equalify-iris' ) . '</h2>';
		echo '<p>' . esc_html__( 'Empties the list. Nothing else is affected — no document changes, and nothing stops.', 'equalify-iris' ) . '</p>';

		Equalify_Iris_Admin::button( 'clear_log', $screen, __( 'Clear the log', 'equalify-iris' ), 'button' );

		echo '</div>';
	}

	/** A plain word for each level. */
	private function level_label( string $level ): string {
		switch ( $level ) {
			case Equalify_Iris_Logger::ERROR:
				return __( 'Problem', 'equalify-iris' );

			case Equalify_Iris_Logger::WARNING:
				return __( 'Warning', 'equalify-iris' );

			default:
				return __( 'Note', 'equalify-iris' );
		}
	}

	/** Turn the context array into one readable line. */
	private function format_context( array $context ): string {
		$parts = array();

		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$parts[] = $key . ': ' . $value;
			}
		}

		return implode( ' · ', $parts );
	}
}
