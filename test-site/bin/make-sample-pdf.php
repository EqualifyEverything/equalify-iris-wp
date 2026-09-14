<?php
/**
 * Make a sample PDF for testing, with no libraries.
 *
 * WHY IS THIS HERE?
 *
 * The test site needs PDFs to find, and committing binary sample files to a repo is
 * a habit worth avoiding. A PDF is a text format with a byte-offset index at the end,
 * so a usable one can be built in a page of PHP.
 *
 * WHAT IT IS GOOD FOR AND WHAT IT IS NOT
 *
 * These PDFs have real page objects and real text, which is exactly what the plugin's
 * page counter reads, so they are perfect for testing discovery, page counting, the
 * 25-page rule, and the whole queue.
 *
 * They are NOT good conversions. Equalify Iris will make something dull out of them,
 * because there is nothing in them but a heading and some lines. To test the quality
 * of a conversion, use a real document.
 *
 * USAGE
 *
 *     php make-sample-pdf.php <output-path> <pages> "<title>"
 *
 * EXAMPLES
 *
 *     php make-sample-pdf.php short.pdf 3 "Accessibility Report"
 *     php make-sample-pdf.php long.pdf 30 "Too Long To Convert"
 */

// phpcs:disable WordPress.Security.EscapeOutput, WordPress.PHP.DevelopmentFunctions

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$path  = $argv[1] ?? 'sample.pdf';
$pages = max( 1, (int) ( $argv[2] ?? 3 ) );
$title = $argv[3] ?? 'Sample Document';

/**
 * Escape a string for use inside a PDF text object.
 *
 * Parentheses delimit strings in PDF, so an unescaped one in the title would end the
 * string early and corrupt the file.
 */
function pdf_string( string $text ): string {
	return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
}

/**
 * The drawing instructions for one page.
 *
 * BT begins a text object, Tf picks a font and size, Td moves to a position measured
 * in points from the bottom left, Tj draws the string, ET ends the object.
 */
function page_content( int $page_number, int $total, string $title ): string {
	$heading = 1 === $page_number ? $title : sprintf( '%s — page %d', $title, $page_number );

	$lines = array(
		sprintf( 'This is page %d of %d in a sample PDF.', $page_number, $total ),
		'It was generated for testing the Equalify Iris WordPress plugin.',
		'',
		'Section ' . $page_number,
		'',
		'The text on this page exists so that a conversion has something to',
		'return. It is deliberately dull. If you are checking how good a real',
		'conversion looks, test with a real document instead.',
	);

	$stream = "BT\n/F1 20 Tf\n72 720 Td\n(" . pdf_string( $heading ) . ") Tj\nET\n";

	$y = 680;

	foreach ( $lines as $line ) {
		if ( '' !== $line ) {
			$stream .= "BT\n/F1 11 Tf\n72 {$y} Td\n(" . pdf_string( $line ) . ") Tj\nET\n";
		}

		$y -= 18;
	}

	return $stream;
}

/*
 * Build the objects.
 *
 * Object 1 is the catalog (the root), object 2 is the page tree, object 3 is the font.
 * After that come two objects per page: the page itself and its content stream. Object
 * numbers are worked out first because the page tree has to list them all.
 */
$objects   = array();
$page_refs = array();

for ( $i = 0; $i < $pages; $i++ ) {
	$page_refs[] = ( 4 + ( $i * 2 ) ) . ' 0 R';
}

$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$objects[2] = "<< /Type /Pages /Kids [" . implode( ' ', $page_refs ) . "] /Count {$pages} >>";
$objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";

for ( $i = 0; $i < $pages; $i++ ) {
	$page_object    = 4 + ( $i * 2 );
	$content_object = $page_object + 1;
	$content        = page_content( $i + 1, $pages, $title );

	$objects[ $page_object ] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
		. "/Resources << /Font << /F1 3 0 R >> >> /Contents {$content_object} 0 R >>";

	$objects[ $content_object ] = "<< /Length " . strlen( $content ) . " >>\nstream\n{$content}endstream";
}

/*
 * Write the file, recording where each object starts.
 *
 * Those byte offsets are the whole reason this cannot be a simple string of text: the
 * cross-reference table at the end lists the exact position of every object, and a
 * reader that finds the wrong offset rejects the file.
 */
$pdf     = "%PDF-1.4\n";
$offsets = array();

ksort( $objects );

foreach ( $objects as $number => $body ) {
	$offsets[ $number ] = strlen( $pdf );
	$pdf               .= "{$number} 0 obj\n{$body}\nendobj\n";
}

$xref_position = strlen( $pdf );
$object_count  = count( $objects ) + 1; // +1 for the required free entry 0.

$pdf .= "xref\n0 {$object_count}\n";
$pdf .= "0000000000 65535 f \n";

foreach ( $offsets as $offset ) {
	$pdf .= sprintf( "%010d 00000 n \n", $offset );
}

$pdf .= "trailer\n<< /Size {$object_count} /Root 1 0 R >>\n";
$pdf .= "startxref\n{$xref_position}\n%%EOF\n";

if ( false === file_put_contents( $path, $pdf ) ) {
	fwrite( STDERR, "Could not write {$path}\n" );

	exit( 1 );
}

printf( "Wrote %s — %d page(s), %s\n", $path, $pages, number_format( strlen( $pdf ) ) . ' bytes' );
