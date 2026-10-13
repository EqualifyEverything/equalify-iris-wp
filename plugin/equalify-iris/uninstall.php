<?php
/**
 * WHAT IS THIS FILE?
 *
 * What happens when the plugin is deleted, not just deactivated. WordPress runs it
 * without loading the rest of the plugin, so names are repeated here.
 *
 * PUT BACK: each original PDF, at its own address, in place of its tagged
 * version. Unless something else replaced the file since it was tagged: then
 * that file stays, and the kept original is deleted.
 *
 * DELETED: 0.2.x's separate tagged copies, our post meta, every setting on every
 * site, and the job.
 *
 * ON A LARGE NETWORK
 *
 * Only sites that used the plugin have anything to delete, and they are marked in
 * the network's blogmeta table, so the other sites are never visited. Each is
 * cleaned with direct queries rather than switch_to_blog(). If the host stops the
 * request partway, what is left is inert: no code reads it once the plugin is gone.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * @param string $prefix  The site's table prefix.
 * @param string $basedir The site's uploads folder.
 */
$equalify_iris_clean_site = static function ( string $prefix, string $basedir ) use ( $wpdb ) {
	$base = (string) realpath( $basedir );

	// The full path of a file inside this site's uploads folder, or ''. Ours must
	// also be named like ours: the same test as Equalify_Iris_Tagger::stored_path(),
	// which is not loaded here.
	$inside = static function ( string $file, bool $ours ) use ( $base ): string {
		if ( '' === $base || '' === $file || false !== strpos( $file, "\0" ) || ( $ours && ! preg_match( '#^(?:[^/\\\\]+/)*[^/\\\\]+-(?:iris-original|accessible)(?:-\d+)?\.pdf$#', $file ) ) ) {
			return '';
		}

		$dir = realpath( dirname( $base . '/' . $file ) );

		return $dir && ( $dir === $base || str_starts_with( $dir, $base . DIRECTORY_SEPARATOR ) ) ? $dir . '/' . basename( $file ) : '';
	};

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$kept = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT o.meta_value AS original, h.meta_value AS hash, f.meta_value AS file FROM {$prefix}postmeta o
			LEFT JOIN {$prefix}postmeta h ON h.post_id = o.post_id AND h.meta_key = %s
			LEFT JOIN {$prefix}postmeta f ON f.post_id = o.post_id AND f.meta_key = %s
			WHERE o.meta_key = %s",
			'_equalify_iris_hash',
			'_wp_attached_file',
			'_equalify_iris_original'
		)
	);

	foreach ( $kept as $row ) {
		$original = $inside( (string) $row->original, true );
		$path     = $inside( (string) $row->file, false );

		if ( '' === $original || ! is_file( $original ) ) {
			continue;
		}

		// Unless something else replaced the tagged version since. A file that is
		// gone altogether is better off with its original back.
		if ( '' !== $path && ( ! is_file( $path ) || md5_file( $path ) === (string) $row->hash ) ) {
			rename( $original, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			wp_delete_file( $original );
		}
	}

	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = %s", '_equalify_iris_file' ) ) as $file ) {
		$old = $inside( (string) $file, true );

		if ( '' !== $old ) {
			wp_delete_file( $old );
		}
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}postmeta WHERE meta_key LIKE %s", $wpdb->esc_like( '_equalify_iris_' ) . '%' ) );

	// Every option of ours on this site, including any left by 0.1.x, and its
	// transients (on a single site, site transients are here too).
	foreach ( array( 'equalify_iris_', '_transient_equalify_iris_', '_transient_timeout_equalify_iris_', '_site_transient_equalify_iris_', '_site_transient_timeout_equalify_iris_' ) as $like ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}options WHERE option_name LIKE %s", $wpdb->esc_like( $like ) . '%' ) );
	}
	// phpcs:enable
};

if ( is_multisite() ) {
	// The uploads folder is the main site's plus `sites/<id>`, unless a site has
	// its own. Worked out once, not per site.
	$equalify_iris_main_uploads = wp_get_upload_dir()['basedir'];

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$equalify_iris_sites = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT blog_id FROM {$wpdb->blogmeta} WHERE meta_key = %s", 'equalify_iris_used' ) ) );

	foreach ( array_unique( array_merge( array( get_main_site_id() ), $equalify_iris_sites ) ) as $equalify_iris_site ) {
		$equalify_iris_clean_site(
			$wpdb->get_blog_prefix( $equalify_iris_site ),
			is_main_site( $equalify_iris_site ) ? $equalify_iris_main_uploads : $equalify_iris_main_uploads . '/sites/' . $equalify_iris_site
		);
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->blogmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'equalify_iris_' ) . '%' ) );

	// Every network setting, including those left by 0.1.x. One of those,
	// `equalify_iris_token`, held a live GitHub credential in builds from before
	// Iris removed its sign-in, so it must not outlive the plugin.
	foreach ( array( 'equalify_iris_', '_site_transient_equalify_iris_', '_site_transient_timeout_equalify_iris_' ) as $equalify_iris_like ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( $equalify_iris_like ) . '%' ) );
	}
	// phpcs:enable
} else {
	$equalify_iris_clean_site( $wpdb->prefix, wp_get_upload_dir()['basedir'] );
}

wp_clear_scheduled_hook( 'equalify_iris_run' );

// No wp_cache_flush(): on a shared object cache it would empty every site's
// cache at once. Copies of our options left in it are read by nothing now the
// plugin is gone, and expire. This request's own are dropped.
wp_cache_delete( 'alloptions', 'options' );

// The tables 0.1.x kept its documents in.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}equalify_iris_sightings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}equalify_iris_documents" );
// phpcs:enable
