<?php
/**
 * A stand-in for Equalify Iris, for testing the plugin without sending anything
 * to a real deployment.
 *
 * It answers the endpoints the plugin uses. A session is "running" on its first
 * status check and "ready_for_review" after that, and the "tagged" PDF is the
 * uploaded file with a comment appended, so it is a real PDF that differs from the
 * original. A file whose name contains "fail" fails to convert, one whose name
 * contains "encrypted" is refused by the tagger with a 422, and one whose name
 * contains "slow" takes 45 seconds to hand back its tagged PDF, longer than a
 * short run can wait.
 *
 * Run it inside the web container (./bin/start-mock-iris.sh does this):
 *
 *     php -S 0.0.0.0:8099 /var/www/html/bin/mock-iris.php
 */

$dir = sys_get_temp_dir() . '/mock-iris';

if ( ! is_dir( $dir ) ) {
	mkdir( $dir );
}

header( 'Content-Type: application/json' );

$path   = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$method = $_SERVER['REQUEST_METHOD'];

function reply( int $code, array $body ): void {
	http_response_code( $code );
	echo json_encode( $body );
	exit;
}

function fail( int $code, string $error, string $message ): void {
	reply( $code, array( 'error' => array( 'code' => $error, 'message' => $message ) ) );
}

if ( '/v1/limits' === $path ) {
	reply( 200, array( 'max_pages' => 25, 'tagged_pdf' => true ) );
}

if ( '/v1/me' === $path ) {
	reply( 200, array( 'github_login' => 'mock-iris', 'upstream_repo' => 'https://example.org/mock' ) );
}

if ( '/v1/sessions' === $path && 'POST' === $method ) {
	if ( empty( $_FILES['images']['tmp_name'] ) ) {
		fail( 400, 'invalid_request', 'No file was uploaded.' );
	}

	// Like Iris, which counts with pdfinfo before anything else. The samples are
	// uncompressed, so their page objects can simply be counted.
	$pages = preg_match_all( '#/Type\s*/Page(?![a-zA-Z])#', (string) file_get_contents( $_FILES['images']['tmp_name'] ) );

	if ( $pages > 25 ) {
		fail( 400, 'invalid_request', "This PDF has {$pages} pages; the maximum supported is 25. Please split it." );
	}

	$id = bin2hex( random_bytes( 8 ) );
	move_uploaded_file( $_FILES['images']['tmp_name'], "$dir/$id.pdf" );
	file_put_contents( "$dir/$id.json", json_encode( array( 'name' => $_FILES['images']['name'], 'checks' => 0 ) ) );

	reply( 202, array( 'session_id' => $id, 'status' => 'queued' ) );
}

if ( ! preg_match( '#^/v1/sessions/([a-f0-9]+)(/pdf|/close)?$#', $path, $m ) || ! is_file( "$dir/{$m[1]}.json" ) ) {
	fail( 404, 'not_found', 'No such session.' );
}

$id      = $m[1];
$session = json_decode( file_get_contents( "$dir/$id.json" ), true );
$action  = $m[2] ?? '';

if ( '' === $action ) {
	$session['checks']++;
	file_put_contents( "$dir/$id.json", json_encode( $session ) );

	if ( false !== stripos( $session['name'], 'fail' ) ) {
		reply( 200, array( 'status' => 'failed', 'error' => 'The mock was told to fail this one.' ) );
	}

	reply( 200, array( 'status' => $session['checks'] > 1 ? 'ready_for_review' : 'running' ) );
}

if ( '/close' === $action ) {
	reply( 200, array( 'status' => 'closed' ) );
}

if ( false !== stripos( $session['name'], 'slow' ) ) {
	sleep( 45 );
}

if ( false !== stripos( $session['name'], 'encrypted' ) ) {
	fail( 422, 'encrypted', 'The PDF is encrypted, so it cannot be tagged.' );
}

$pdf = file_get_contents( "$dir/$id.pdf" ) . "\n% tagged by mock-iris\n";

reply(
	200,
	array(
		'filename' => $session['name'],
		'pdf'      => base64_encode( $pdf ),
		'report'   => array( 'warnings' => array( array( 'code' => 'retagged' ), array( 'code' => 'missing_alt' ) ) ),
	)
);
