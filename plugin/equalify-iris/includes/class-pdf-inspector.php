<?php
/**
 * WHAT IS THIS FILE?
 *
 * Reads a PDF's page count and size before we upload it.
 *
 * WHY DOES IT EXIST?
 *
 * Equalify Iris refuses PDFs longer than 25 pages, and refuses files over 50 MB.
 * Those are hard limits in the server, not preferences.
 *
 * We could just upload everything and let Iris say no. That would be worse in
 * three ways: we would spend the bandwidth of a 50 MB upload to be told no, we
 * would occupy one of the deployment's two conversion slots while doing it, and
 * the admin would see a server error instead of "this PDF has 60 pages, and the
 * limit is 25". Checking here turns a wasted round trip into a clear sentence.
 *
 * HOW DO YOU COUNT PAGES WITHOUT A PDF LIBRARY?
 *
 * A PDF is mostly text, and every page in it is an object marked `/Type /Page`.
 * Counting those markers gives the page count. There is also usually a `/Count N`
 * entry on the page tree that states it directly.
 *
 * This is not a complete PDF parser and does not try to be. It is deliberately
 * conservative: when it cannot tell, it says so, and the caller uploads the file
 * anyway and lets Iris decide. A file we wrongly refuse is an accessibility gap
 * we created ourselves, which is worse than a wasted upload — so "I don't know"
 * has to be a real answer, not a guess.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_PDF_Inspector {

	/**
	 * How much of the file to read at a time, in bytes.
	 *
	 * We stream the file in 1 MB pieces rather than reading it all at once,
	 * because the whole reason this class exists is to handle files up to 50 MB on
	 * hosts with modest memory limits. Reading a 50 MB file into a string to
	 * count its pages would be its own bug.
	 */
	const CHUNK_BYTES = 1048576;

	/**
	 * How many bytes to carry over between chunks.
	 *
	 * A marker like "/Type /Page" could land exactly on the boundary between two
	 * chunks, with half in one read and half in the next — and would then be
	 * missed by both. So each chunk keeps its last few bytes and prepends them to
	 * the next one. 64 bytes is far more than the longest pattern we search for.
	 */
	const OVERLAP_BYTES = 64;

	/**
	 * Look at a PDF and report what we found.
	 *
	 * @return array{
	 *     ok: bool,          Whether this file can be sent to Iris.
	 *     bytes: int,        Size on disk.
	 *     pages: int|null,   Page count, or null if we could not tell.
	 *     hash: string,      SHA-256 of the file, for spotting a replaced PDF.
	 *     status: string,    The document status to use when ok is false.
	 *     message: string,   A full sentence for the admin.
	 * }
	 */
	public static function inspect( string $file_path ): array {
		$result = array(
			'ok'      => false,
			'bytes'   => 0,
			'pages'   => null,
			'hash'    => '',
			'status'  => Equalify_Iris_Documents::FAILED,
			'message' => '',
		);

		if ( ! is_readable( $file_path ) ) {
			$result['message'] = __( 'The PDF file could not be found on the server.', 'equalify-iris' );

			return $result;
		}

		$bytes          = (int) filesize( $file_path );
		$result['bytes'] = $bytes;

		$max_bytes = (int) Equalify_Iris_Settings::get( 'max_file_bytes' );

		if ( $bytes > $max_bytes ) {
			$result['status']  = Equalify_Iris_Documents::TOO_BIG;
			$result['message'] = sprintf(
				/* translators: 1: this file's size, 2: the maximum allowed size. */
				__( 'This file is %1$s. The largest Equalify Iris accepts is %2$s.', 'equalify-iris' ),
				size_format( $bytes ),
				size_format( $max_bytes )
			);

			return $result;
		}

		// Hashing streams the file, so this is memory-safe on a large PDF.
		$hash           = hash_file( 'sha256', $file_path );
		$result['hash'] = is_string( $hash ) ? $hash : '';

		$pages           = self::count_pages( $file_path );
		$result['pages'] = $pages;

		if ( null !== $pages && $pages > Equalify_Iris_Settings::MAX_PDF_PAGES ) {
			$result['status']  = Equalify_Iris_Documents::TOO_LONG;
			$result['message'] = sprintf(
				/* translators: 1: this PDF's page count, 2: the maximum. */
				__( 'This PDF has %1$d pages. Equalify Iris converts up to %2$d. Splitting it into smaller files would let it be converted.', 'equalify-iris' ),
				$pages,
				Equalify_Iris_Settings::MAX_PDF_PAGES
			);

			return $result;
		}

		// Either we counted pages and it is within the limit, or we could not
		// count them and we let Iris be the judge.
		$result['ok'] = true;

		return $result;
	}

	/**
	 * Count the pages in a PDF, or return null if we cannot tell.
	 *
	 * Two signals are collected and the larger is used:
	 *
	 *   1. `/Type /Page` markers — one per page object.
	 *   2. `/Count N` on the page tree — the count the file states itself.
	 *
	 * Taking the larger of the two is the safe direction. If we undercount, we
	 * upload something Iris will refuse, which costs a round trip. There is no
	 * version of this where an undercount lets a 60-page PDF through as
	 * convertible, because Iris checks again on its side.
	 *
	 * WHEN THIS RETURNS NULL
	 *
	 * PDF 1.5 and later can pack objects into compressed streams, in which case
	 * the markers are not readable as plain text and neither signal appears. That
	 * is a real and fairly common case, and it is exactly why null is a supported
	 * answer rather than a failure.
	 */
	public static function count_pages( string $file_path ): ?int {
		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			return null;
		}

		$page_markers = 0;
		$declared     = 0;
		$carry        = '';

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, self::CHUNK_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			// Prepend the tail of the previous chunk so a marker split across the
			// boundary is still found.
			$haystack = $carry . $chunk;

			// "/Type" then optional whitespace then "/Page" NOT followed by a
			// letter. The negative lookahead matters: without it, every
			// "/Type /Pages" node — the page TREE, not a page — would be counted
			// as a page, inflating the count on every PDF.
			$page_markers += preg_match_all( '#/Type\s*/Page(?![a-zA-Z])#', $haystack );

			// The page tree states the total. Take the largest one seen: linearized
			// and incrementally-updated PDFs can carry more than one page tree, and
			// the real total is the biggest.
			if ( preg_match_all( '#/Count\s+(\d+)#', $haystack, $matches ) ) {
				foreach ( $matches[1] as $count ) {
					$declared = max( $declared, (int) $count );
				}
			}

			$carry = substr( $haystack, -self::OVERLAP_BYTES );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$best = max( $page_markers, $declared );

		// Zero means neither signal was readable — almost certainly a PDF with
		// compressed object streams. Say "unknown" rather than "no pages".
		return $best > 0 ? $best : null;
	}

	/**
	 * Is there enough memory to build this file into a request body in memory?
	 *
	 * Only asked when cURL is unavailable, which is rare. Building a multipart
	 * body needs roughly twice the file size, and we insist on a further 25%
	 * headroom for everything else PHP is doing.
	 */
	public static function fits_in_memory( int $bytes ): bool {
		$limit = self::memory_limit_bytes();

		// No limit set at all: PHP will use whatever it needs and we cannot
		// reason about it. Allow it.
		if ( $limit <= 0 ) {
			return true;
		}

		$available = $limit - memory_get_usage( true );
		$needed    = (int) ( $bytes * 2.25 );

		return $available > $needed;
	}

	/**
	 * PHP's memory limit in bytes, or 0 if there is none.
	 *
	 * The ini value is a string like "256M" or "-1", so it needs converting.
	 */
	private static function memory_limit_bytes(): int {
		$raw = ini_get( 'memory_limit' );

		if ( false === $raw || '' === $raw || '-1' === $raw ) {
			return 0;
		}

		return (int) wp_convert_hr_to_bytes( $raw );
	}
}
