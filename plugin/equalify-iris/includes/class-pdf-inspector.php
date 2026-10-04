<?php
/**
 * WHAT IS THIS FILE?
 *
 * Checks a PDF's size and page count before it is uploaded.
 *
 * WHY?
 *
 * Iris refuses PDFs longer than 25 pages, and we refuse files over 50 MB. Checking
 * here turns a wasted upload into a sentence a site admin can act on: "This PDF
 * has 60 pages. Equalify Iris tags up to 25."
 *
 * HOW DO YOU COUNT PAGES WITHOUT A PDF LIBRARY?
 *
 * Every page in a PDF is an object marked `/Type /Page`, and the page tree usually
 * states the total as `/Count N`. Neither is readable when the PDF packs its
 * objects into compressed streams, so "I don't know" is a real answer: the file is
 * uploaded anyway and Iris decides. Wrongly refusing a PDF is worse than a wasted
 * upload.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_PDF_Inspector {

	/** Read the file a megabyte at a time, so a 50 MB PDF never sits in memory. */
	const CHUNK_BYTES = 1048576;

	/** Bytes carried between chunks, so a marker split across two is not missed. */
	const OVERLAP_BYTES = 64;

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

		$pages = self::count_pages( $file_path );

		if ( null !== $pages && $pages > Equalify_Iris_Settings::MAX_PDF_PAGES ) {
			return new WP_Error(
				'equalify_iris_too_long',
				sprintf(
					/* translators: 1: this PDF's page count, 2: the most pages allowed. */
					__( 'This PDF has %1$d pages. Equalify Iris tags up to %2$d. Splitting it into smaller files would let it be tagged.', 'equalify-iris' ),
					$pages,
					Equalify_Iris_Settings::MAX_PDF_PAGES
				)
			);
		}

		return true;
	}

	/**
	 * Count the pages, or return null if the file does not say in plain text.
	 *
	 * The larger of the two signals wins. `/Type /Page(?![a-zA-Z])` skips the
	 * `/Type /Pages` tree nodes, and the largest `/Count` is taken because an
	 * incrementally updated PDF can carry more than one page tree.
	 */
	public static function count_pages( string $file_path ): ?int {
		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			return null;
		}

		$markers  = 0;
		$declared = 0;
		$carry    = '';

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$haystack = $carry . $chunk;

			// Only count markers that start in this chunk, not in the carried tail,
			// so a marker near the boundary is not counted twice.
			$markers += preg_match_all( '#/Type\s*/Page(?![a-zA-Z])#', $haystack, $found, PREG_OFFSET_CAPTURE ) ? count(
				array_filter(
					$found[0],
					static fn( $match ) => $match[1] + strlen( $match[0] ) > strlen( $carry )
				)
			) : 0;

			if ( preg_match_all( '#/Count\s+(\d+)#', $haystack, $matches ) ) {
				$declared = max( $declared, ...array_map( 'intval', $matches[1] ) );
			}

			$carry = substr( $haystack, -self::OVERLAP_BYTES );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$best = max( $markers, $declared );

		return $best > 0 ? $best : null;
	}
}
