<?php
/**
 * WHAT IS THIS FILE?
 *
 * The two database tables this plugin owns, and the code that creates them.
 *
 * WHY DOES IT EXIST?
 *
 * Because the to-do list of PDFs is the wrong shape for anything WordPress gives
 * us for free.
 *
 * We considered the obvious alternatives first:
 *   - Post meta on each PDF attachment. But attachments live on individual
 *     sites, and the whole point is one network-wide queue an admin can see in
 *     one table. Answering "what should I convert next, across 40 sites?" would
 *     mean querying 40 sites' meta tables and sorting in PHP.
 *   - The options table. But we need indexed lookups by status and by "what is
 *     due now" over tens of thousands of rows, and options are not indexed for
 *     that. Worse, big options bloat the cache that loads on every page request
 *     of every site.
 *
 * So: two small purpose-built tables, in the network's base database, with the
 * two indexes the background job actually uses.
 *
 * THE TWO TABLES, IN PLAIN WORDS
 *
 *   documents — one row per PDF we know about. "Things to convert."
 *   sightings — one row per place a PDF appears. "Where we saw it."
 *
 * The second table exists for one reason worth stating plainly: we need to know
 * whether a PDF is still on a published page. If every post linking to a PDF is
 * unpublished, its accessible HTML version must be unpublished too, or we would
 * leave the full text of a withdrawn document readable at a public URL. Asking
 * that question by searching every post's content for a URL is far too slow to
 * do on a hook; asking it from this table is one indexed query.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Database {

	/**
	 * Bump this when a table changes shape, and add the change to
	 * maybe_upgrade(). Stored in a network option so we know when to run
	 * dbDelta() again.
	 */
	const SCHEMA_VERSION = 1;

	/** The network option holding the installed schema version. */
	const VERSION_OPTION = 'equalify_iris_schema_version';

	/**
	 * Full name of the documents table.
	 *
	 * We use $wpdb->base_prefix, not $wpdb->prefix. That is the difference
	 * between one table for the whole network (`wp_equalify_iris_documents`) and
	 * one table per site (`wp_3_equalify_iris_documents`). We want one shared
	 * table, because the queue and the dashboard are network-wide.
	 */
	public static function documents_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'equalify_iris_documents';
	}

	/** Full name of the sightings table. */
	public static function sightings_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'equalify_iris_sightings';
	}

	/**
	 * Runs when the plugin is activated.
	 */
	public static function on_activate(): void {
		self::install();

		// Our rule for /equalify-iris/{id}/{slug}/ is not in any site's cached
		// rewrite rules yet, so without this every converted page is a 404 — while
		// the icon beside each PDF links straight at it. See schedule_rewrite_flush().
		Equalify_Iris_Post_Type::schedule_rewrite_flush();
	}

	/**
	 * Create or update the tables.
	 *
	 * dbDelta() compares the SQL below against what is really in the database
	 * and issues only the changes needed, so this is safe to call repeatedly.
	 * It is also fussy about formatting — two spaces after PRIMARY KEY, one
	 * field per line, lowercase types — so resist tidying the string below.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$docs     = self::documents_table();
		$sightings = self::sightings_table();

		// Note on lengths: `pdf_url` is a TEXT column because URLs can exceed
		// the 191-character limit a utf8mb4 index allows, and we never look a
		// document up by URL — we look it up by (site_id, attachment_id), which
		// is what the unique key covers.
		$documents_sql = "CREATE TABLE {$docs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL,
			pdf_url text NOT NULL,
			file_hash char(64) DEFAULT '' NOT NULL,
			page_count smallint(5) unsigned DEFAULT NULL,
			file_bytes bigint(20) unsigned DEFAULT NULL,
			status varchar(20) DEFAULT 'pending' NOT NULL,
			iris_session_id varchar(64) DEFAULT '' NOT NULL,
			doc_post_id bigint(20) unsigned DEFAULT NULL,
			attempts smallint(5) unsigned DEFAULT 0 NOT NULL,
			checks smallint(5) unsigned DEFAULT 0 NOT NULL,
			next_action_at datetime DEFAULT '1970-01-01 00:00:00' NOT NULL,
			started_at datetime DEFAULT NULL,
			last_error text NULL,
			created_at datetime DEFAULT '1970-01-01 00:00:00' NOT NULL,
			updated_at datetime DEFAULT '1970-01-01 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_attachment (site_id,attachment_id),
			KEY status_due (status,next_action_at),
			KEY site_status (site_id,status)
		) {$charset};";

		$sightings_sql = "CREATE TABLE {$sightings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			document_id bigint(20) unsigned NOT NULL,
			site_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			last_seen_at datetime DEFAULT '1970-01-01 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY document_post (document_id,post_id),
			KEY site_post (site_id,post_id),
			KEY document (document_id)
		) {$charset};";

		dbDelta( $documents_sql );
		dbDelta( $sightings_sql );

		update_site_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Create the tables if a newer schema has shipped since they were made.
	 *
	 * Called on every boot. This is belt and braces for the case that matters in
	 * practice: a plugin updated by copying files over the old ones, where the
	 * activation hook never runs again and the tables silently stay old.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_site_option( self::VERSION_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Do the tables exist?
	 *
	 * Used by the dashboard's health check and by `wp equalify-iris doctor`, so
	 * "the tables were never created" shows up as a clear message instead of as
	 * a database error in a log nobody reads.
	 *
	 * It is also asked on the front end, several times over on a page with PDFs on it,
	 * which is why a yes is remembered for the rest of the request. Only a yes: tables
	 * can be created part-way through a request — pressing Start does exactly that —
	 * and a remembered no would then be wrong for everything after it. Tables do not
	 * disappear part-way through a request, so a remembered yes cannot go stale.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		static $exists = false;

		if ( $exists ) {
			return true;
		}

		foreach ( array( self::documents_table(), self::sightings_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		$exists = true;

		return true;
	}

	/**
	 * Empty both tables, keeping them in place.
	 *
	 * For `wp equalify-iris purge`, where the point is to leave no record of what
	 * was converted while keeping a working plugin behind. drop() would leave the
	 * plugin unable to do anything until the tables were recreated.
	 *
	 * Sightings go first: they reference documents, and a sightings row whose
	 * document has gone is a row that will never be read again.
	 *
	 * @return int How many document rows were cleared.
	 */
	public static function empty_tables(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$documents = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::documents_table() );

		$wpdb->query( 'DELETE FROM ' . self::sightings_table() );
		$wpdb->query( 'DELETE FROM ' . self::documents_table() );
		// phpcs:enable

		return $documents;
	}

	/**
	 * Delete both tables. Only for uninstall and tests — never called in normal
	 * operation, because deactivating a plugin should not throw away the record
	 * of thousands of conversions.
	 */
	public static function drop(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::sightings_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::documents_table() );
		// phpcs:enable

		delete_site_option( self::VERSION_OPTION );
	}
}
