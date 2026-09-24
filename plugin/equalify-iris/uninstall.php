<?php
/**
 * WHAT IS THIS FILE?
 *
 * What happens when someone deletes the plugin — not deactivates it, deletes it.
 *
 * WHY IS IT A SEPARATE FILE?
 *
 * WordPress runs this file on its own, without loading the rest of the plugin. That
 * is why it repeats a couple of table names instead of calling our own classes:
 * nothing else is loaded, so there is nothing to call.
 *
 * WHAT IT DOES AND DOES NOT DELETE
 *
 * DELETED: our two tables, our settings, the activity log. Those are ours, they are
 * meaningless without the plugin, and leaving them behind is the kind of litter that
 * accumulates in a network's database for years.
 *
 * NOT DELETED: the converted document pages. Those are real published content with
 * real URLs that people have bookmarked, shared, and linked to. Deleting a thousand
 * live pages because someone removed a plugin would be a shock, not a cleanup — and
 * it is not recoverable. They become ordinary orphaned posts of an unregistered post
 * type: invisible, harmless, and still there if the plugin comes back.
 *
 * Nor are the original PDFs touched, obviously. We never owned those.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Only ever run network-wide, because that is the only way this plugin can be
// activated. base_prefix rather than prefix: our tables are shared by every site.
$documents_table = $wpdb->base_prefix . 'equalify_iris_documents';
$sightings_table = $wpdb->base_prefix . 'equalify_iris_sightings';

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$sightings_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$documents_table}" );
// phpcs:enable

// Every option this plugin ever wrote. Listed explicitly rather than matched with a
// LIKE query, so it is obvious what is being removed and impossible to catch
// somebody else's option by accident.
$options = array(
	'equalify_iris_api_url',
	'equalify_iris_api_token',
	'equalify_iris_auth_state',
	'equalify_iris_deployment_login',
	'equalify_iris_upstream_repo',
	'equalify_iris_running',
	'equalify_iris_auto_process',
	'equalify_iris_max_in_flight',
	'equalify_iris_uploads_per_tick',
	'equalify_iris_status_checks_per_tick',
	'equalify_iris_imports_per_tick',
	'equalify_iris_posts_per_tick',
	'equalify_iris_tick_budget_seconds',
	'equalify_iris_max_file_bytes',
	'equalify_iris_excluded_sites',
	'equalify_iris_excluded_post_types',
	'equalify_iris_sweep_cursor',
	'equalify_iris_circuit_open_until',
	'equalify_iris_consecutive_failures',
	'equalify_iris_last_tick',
	'equalify_iris_activity_log',
	'equalify_iris_schema_version',
	'equalify_iris_rewrite_token',
	'equalify_iris_deactivated_at',

	// Written by builds before the rewrite flush became per-site. A single
	// network-wide flag could only ever flush one site's rules.
	'equalify_iris_needs_rewrite_flush',

	// Written by builds from before Iris v1 removed its sign-in, when the plugin
	// held a GitHub token of its own. Nothing writes these any more, and they are
	// listed here rather than dropped because the old one held a live credential:
	// an install that was set up before the change still has it sitting in
	// wp_sitemeta, and uninstall is the last chance to take it out.
	'equalify_iris_token',
	'equalify_iris_github_login',
);

foreach ( $options as $option ) {
	delete_site_option( $option );

	// Also as a normal option, in case the plugin was ever run on a single site
	// during development. delete_option() on a key that does not exist is harmless.
	delete_option( $option );
}

// One option really is per site: each site records which rewrite token it last
// flushed for, in its own wp_options. The loop above only reached the site this
// happens to be running on, so the rest need visiting.
if ( function_exists( 'get_sites' ) ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( 'equalify_iris_rewrite_token' );
		restore_current_blog();
	}
}

// Also from before the sign-in was removed: a half-finished GitHub device flow.
delete_site_transient( 'equalify_iris_device_flow' );
delete_site_transient( 'equalify_iris_post_total' );
delete_site_transient( 'equalify_iris_tick_lock' );

wp_clear_scheduled_hook( 'equalify_iris_tick' );
