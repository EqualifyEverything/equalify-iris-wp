<?php
/**
 * WHAT IS THIS FILE?
 *
 * Checks a PDF is there, and not too big to send, before it is uploaded.
 *
 * WHY NOT COUNT ITS PAGES TOO?
 *
 * Iris does, properly, with a real PDF parser, the moment the file arrives: a PDF
 * over its limit is answered at once with a 400 and Iris's own sentence ("This
 * PDF has 30 pages; the maximum supported is 25. Please split it."), before any
 * session, conversion or GitHub issue exists. The tagger records that as a
 * failure like any other refusal. Guessing here from the raw bytes, without a
 * parser, could refuse a short PDF that Iris would have tagged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_PDF_Inspector {

	/**
	 * Can this file be sent to Iris?
	 *
	 * @return true|WP_Error True, or a permanent reason it cannot.
	 */
	public static function check( string $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'equalify_iris_file_missing', __( 'The PDF file could not be found on the server.', 'equalify-iris' ) );
		}

		$bytes = (int) filesize( $file_path );

		if ( $bytes > Equalify_Iris_Settings::MAX_FILE_BYTES ) {
			return new WP_Error(
				'equalify_iris_too_big',
				sprintf(
					/* translators: 1: this file's size, 2: the largest size allowed. */
					__( 'This file is %1$s. The largest Equalify Iris accepts is %2$s.', 'equalify-iris' ),
					size_format( $bytes ),
					size_format( Equalify_Iris_Settings::MAX_FILE_BYTES )
				)
			);
		}

		return true;
	}
}
