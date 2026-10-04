<?php
/**
 * WHAT IS THIS FILE?
 *
 * What happens when the plugin is deleted, not just deactivated. WordPress runs it
 * without loading the rest of the plugin, so names are repeated here.
 *
 * DELETED: the tagged copies, their post meta, every setting on every site, and
 * the job. Links already went back to the original PDFs when the plugin stopped
 * running; the original files are never touched.
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
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$files = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$prefix}postmeta WHERE meta_key = %s", '_equalify_iris_file' ) );

	foreach ( $files as $file ) {
		if ( '' !== $file && false === strpos( $file, '..' ) ) {
			wp_delete_file( trailingslashit( $basedir ) . $file );
		}
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}postmeta WHERE meta_key LIKE %s", $wpdb->esc_like( '_equalify_iris_' ) . '%' ) );

	// Every option of ours on this site, including any left by 0.1.x.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}options WHERE option_name LIKE %s", $wpdb->esc_like( 'equalify_iris_' ) . '%' ) );
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
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'equalify_iris_' ) . '%' ) );
	// phpcs:enable
} else {
	$equalify_iris_clean_site( $wpdb->prefix, wp_get_upload_dir()['basedir'] );
}

wp_clear_scheduled_hook( 'equalify_iris_run' );
wp_cache_flush();

// The tables 0.1.x kept its documents in.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}equalify_iris_sightings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}equalify_iris_documents" );
// phpcs:enable
