<?php
/**
 * WHAT IS THIS FILE?
 *
 * The hidden post type that holds each converted document, and the URL it lives at.
 *
 * WHY DOES IT EXIST?
 *
 * The converted HTML needs a permanent public address, a title, and a slug. In
 * WordPress, a thing with an address, a title and a slug is a post. So rather
 * than inventing a storage mechanism and a routing mechanism, we register a post
 * type and get both for free — along with permalinks, sitemaps, and every theme's
 * existing styling.
 *
 * WHAT "HIDDEN" MEANS, EXACTLY
 *
 * This is the part to read carefully, because getting one flag wrong either
 * breaks the feature or leaks content.
 *
 * Hidden from EDITORS: no admin menu, no list table, not in the block editor, not
 * in the REST API, not in site search results, no archive page. Editors have no
 * job to do with these posts, so putting them in the admin would only be clutter
 * and a chance to break something.
 *
 * NOT hidden from VISITORS. The page must be publicly reachable — that is the
 * entire point of the plugin. `public` is therefore true. If anyone ever sets it
 * to false to make the post type feel more private, every accessible document on
 * the network goes offline at once.
 *
 * SHOULD SEARCH ENGINES INDEX THESE?
 *
 * Yes, and we do nothing to stop them. An accessible HTML version of a document
 * is more useful in search results than the PDF it came from, and indexing it
 * helps people who would never see the icon on the original page. It is excluded
 * from a site's OWN search only so an editor searching for their content is not
 * wading through converted documents.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Post_Type {

	/** The post type name. Kept short because it appears in every query. */
	const POST_TYPE = 'equalify_iris_doc';

	/** The first segment of the public URL. */
	const URL_BASE = 'equalify-iris';

	/** Meta key: which PDF attachment this page was made from. */
	const META_ATTACHMENT = '_equalify_iris_attachment_id';

	/** Meta key: the Iris session that produced it, for tracing a problem back. */
	const META_SESSION = '_equalify_iris_session_id';

	/** Meta key: the SHA-256 of the PDF this was converted from. */
	const META_HASH = '_equalify_iris_source_hash';

	/** Meta key: when it was converted, as a Unix timestamp. */
	const META_CONVERTED_AT = '_equalify_iris_converted_at';

	/** Meta key: the table of contents, stored so we do not rebuild it per view. */
	const META_HEADINGS = '_equalify_iris_headings';

	/** Meta key: the original PDF's page count, shown on the page. */
	const META_PAGE_COUNT = '_equalify_iris_page_count';

	/** Meta key: the original PDF's size in bytes, shown on the page. */
	const META_FILE_BYTES = '_equalify_iris_file_bytes';

	/**
	 * Meta key: how many of the PDF's pages Equalify Iris could not read.
	 *
	 * Nearly always 0. When it is not, this page is a real improvement on the PDF
	 * and still missing part of it, and that is a difference worth being able to
	 * find later — by eye in the activity log, or by query across the network.
	 */
	const META_PAGES_MISSING = '_equalify_iris_pages_missing';

	public function init(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'redirect_to_canonical_url' ) );
		add_filter( 'template_include', array( $this, 'use_our_template' ) );

		// Converted documents are not something an editor should be able to edit,
		// delete, or find. Belt and braces on top of `show_ui => false`.
		add_filter( 'map_meta_cap', array( $this, 'block_editing' ), 10, 4 );

		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
	}

	/**
	 * A converted page went live or stopped being live.
	 *
	 * The icon on every page linking to this PDF has just appeared or just become a
	 * link to a 404. The icons themselves are worked out as each page renders, so
	 * they are right on the next uncached view — but a page cache may be holding a
	 * copy rendered before the change. See refresh_linking_pages().
	 */
	public function on_status_change( string $new_status, string $old_status, WP_Post $post ): void {
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( ( 'publish' === $new_status ) !== ( 'publish' === $old_status ) ) {
			self::refresh_linking_pages( $post );
		}
	}

	/**
	 * A converted page is being deleted outright, which skips the status change.
	 */
	public function on_delete( int $post_id ): void {
		$post = get_post( $post_id );

		if ( $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
			self::refresh_linking_pages( $post );
		}
	}

	/**
	 * Tell caches that the pages linking to this document have changed.
	 *
	 * WHY clean_post_cache()
	 *
	 * It is what WordPress itself calls when a post changes, and it is the hook the
	 * common page-cache plugins already listen on to drop that post's cached copy.
	 * Calling it for each linking page means those plugins purge the right pages
	 * without us knowing which one is installed. A cache that does not listen — a CDN,
	 * Varnish — can use the equalify_iris_linking_pages_changed action instead.
	 */
	private static function refresh_linking_pages( WP_Post $doc_post ): void {
		if ( ! Equalify_Iris_Database::tables_exist() ) {
			return;
		}

		$attachment_id = (int) get_post_meta( $doc_post->ID, self::META_ATTACHMENT, true );
		$document      = $attachment_id ? Equalify_Iris_Documents::find_by_attachment( get_current_blog_id(), $attachment_id ) : null;

		if ( ! $document ) {
			return;
		}

		$by_site = array();

		foreach ( Equalify_Iris_Documents::sightings_for( (int) $document->id ) as $sighting ) {
			$by_site[ (int) $sighting->site_id ][] = (int) $sighting->post_id;
		}

		foreach ( $by_site as $site_id => $post_ids ) {
			$switched = get_current_blog_id() !== $site_id;

			if ( $switched ) {
				switch_to_blog( $site_id );
			}

			foreach ( $post_ids as $post_id ) {
				clean_post_cache( $post_id );
			}

			/**
			 * The icons on these posts have changed: an accessible version they link to
			 * has been published or unpublished. For purging a cache WordPress does not
			 * know about. Runs on the linking posts' own site.
			 *
			 * @param int[]   $post_ids The posts linking to the document.
			 * @param WP_Post $doc_post The accessible version's page.
			 */
			do_action( 'equalify_iris_linking_pages_changed', $post_ids, $doc_post );

			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Register the post type.
	 */
	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Accessible Documents', 'equalify-iris' ),
					'singular_name' => __( 'Accessible Document', 'equalify-iris' ),
				),

				// Visitors: yes. Editors: no. See the note at the top of the file.
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'has_archive'         => false,

				// We add our own rule in add_rewrite_rule() to get the two-segment
				// URL, so WordPress should not also add a one-segment rule that
				// would resolve the same content at a second address.
				'rewrite'             => false,

				// `title` and `editor` because we store a title and HTML content.
				// `custom-fields` because everything else about the document lives
				// in post meta.
				'supports'            => array( 'title', 'editor', 'custom-fields' ),

				// Not hierarchical: these are flat documents with no parents.
				'hierarchical'        => false,

				// Let the sitemap include them. They are real, useful pages.
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Teach WordPress the two-segment URL.
	 *
	 * The public address of a document is:
	 *
	 *     /equalify-iris/1184/annual-report/
	 *                    ^^^^  ^^^^^^^^^^^^
	 *            attachment id   title slug
	 *
	 * A post type's built-in rewrite can only give us ONE segment after the base,
	 * so a two-segment URL needs a rule of our own.
	 *
	 * THE TRICK THAT MAKES THIS SIMPLE
	 *
	 * The post's real slug is the two segments joined with a hyphen —
	 * `1184-annual-report`. So this rule does not have to look anything up in the
	 * database: it just glues the two captured segments back together with a
	 * hyphen and hands WordPress a slug. No meta query, no extra work per request,
	 * and no chance of two documents colliding, because attachment ids are unique
	 * within a site.
	 */
	public function add_rewrite_rule(): void {
		add_rewrite_rule(
			self::rewrite_pattern(),
			'index.php?' . self::POST_TYPE . '=$matches[1]-$matches[2]',
			'top'
		);
	}

	/**
	 * The pattern our URL rule matches. One definition, because deactivation has to
	 * find this exact rule again to take it out — see Plugin::on_deactivate().
	 */
	public static function rewrite_pattern(): string {
		return '^' . self::URL_BASE . '/([0-9]+)/([^/]+)/?$';
	}

	/**
	 * Build the pretty URL for a document.
	 *
	 * Without this, get_permalink() would return the ugly `?equalify_iris_doc=...`
	 * form, and every place in the plugin that needs a URL would have to assemble
	 * the pretty one by hand. Doing it here means the rest of the code just calls
	 * get_permalink() like it would for any post.
	 */
	public function filter_permalink( string $url, WP_Post $post ): string {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $url;
		}

		$attachment_id = (int) get_post_meta( $post->ID, self::META_ATTACHMENT, true );

		if ( ! $attachment_id ) {
			return $url;
		}

		// The slug is "1184-annual-report"; strip the leading id to get the title
		// part back, then write it out as two segments.
		$slug = preg_replace( '#^' . $attachment_id . '-#', '', $post->post_name );

		if ( ! $slug ) {
			return $url;
		}

		// Sites without pretty permalinks have no rewrite rules at all, so the
		// two-segment URL would 404. Fall back to the query form for them.
		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::POST_TYPE, $post->post_name, home_url( '/' ) );
		}

		return home_url( '/' . self::URL_BASE . '/' . $attachment_id . '/' . $slug . '/' );
	}

	/**
	 * Send visitors to the pretty URL when they arrive by any other route.
	 *
	 * In practice this means the query form, `?equalify_iris_doc=1184-annual-report`,
	 * which WordPress resolves happily and which is a second working address for a
	 * page that already has one. One canonical URL per document is worth having, so
	 * we redirect permanently to it.
	 *
	 * WHAT THIS DOES NOT COVER
	 *
	 * Not old slugs. If a PDF is retitled and reconverted, the document's slug
	 * changes and previously published links 404 rather than landing here — the
	 * rewrite rule in add_rewrite_rule() turns the two URL segments into an exact
	 * post_name, so an address whose slug no longer exists never resolves to a post
	 * at all. Core's wp_old_slug_redirect() does not help either: it reads the
	 * `name` query var, and our rule sets `equalify_iris_doc`. Worth fixing, but it
	 * is a separate job from this one.
	 */
	public function redirect_to_canonical_url(): void {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$canonical = get_permalink( $post );

		// Compare paths only. Comparing whole URLs would fire a redirect on a
		// harmless difference like http vs https behind a proxy, which is a
		// redirect loop waiting to happen.
		//
		// add_query_arg( array() ) is WordPress's way of saying "the current
		// request path". It is already absolute and already includes the site's
		// own path segment, so it must NOT be passed through home_url(): on a
		// subdirectory multisite that prepends `/research` to a path that starts
		// with `/research`, the doubled path never matches the canonical one, and
		// every document page on every sub-site 301s to itself for ever. The main
		// site is unaffected because its home path is `/`, which is exactly what
		// makes this the kind of bug that ships.
		$canonical_path = wp_parse_url( $canonical, PHP_URL_PATH );
		$requested_path = wp_parse_url( add_query_arg( array() ), PHP_URL_PATH );

		if ( $canonical_path && $requested_path && untrailingslashit( $canonical_path ) !== untrailingslashit( $requested_path ) ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}
	}

	/**
	 * Use the plugin's template for a document page, unless the theme has its own.
	 *
	 * A theme can override this simply by adding `single-equalify_iris_doc.php`,
	 * which is the standard WordPress way and needs no documentation of ours.
	 */
	public function use_our_template( string $template ): string {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $template;
		}

		$theme_template = locate_template( array( 'single-' . self::POST_TYPE . '.php' ) );

		if ( $theme_template ) {
			return $theme_template;
		}

		return EQUALIFY_IRIS_PATH . 'templates/single-document.php';
	}

	/**
	 * Refuse edit and delete permissions on converted documents, for everyone.
	 *
	 * `show_ui => false` hides these posts from the admin, but hiding is not the
	 * same as preventing. This closes the gap: nobody edits a converted document
	 * by hand, because the next conversion would overwrite their changes anyway
	 * and they would rightly consider that a bug.
	 */
	public function block_editing( array $caps, string $cap, int $user_id, array $args ): array {
		$editing_caps = array( 'edit_post', 'delete_post' );

		if ( ! in_array( $cap, $editing_caps, true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( $args[0] );

		if ( $post && self::POST_TYPE === $post->post_type ) {
			// `do_not_allow` is the capability nobody has, including super admins.
			return array( 'do_not_allow' );
		}

		return $caps;
	}

	/**
	 * The slug a document should have.
	 *
	 * Putting the attachment id first is what makes the two-segment URL work
	 * without a database lookup, and what guarantees uniqueness.
	 */
	public static function build_slug( int $attachment_id, string $title ): string {
		$title_slug = sanitize_title( $title );

		if ( '' === $title_slug ) {
			$title_slug = 'document';
		}

		return $attachment_id . '-' . $title_slug;
	}

	/**
	 * Find the document page for a PDF on the current site, if there is one.
	 *
	 * @return WP_Post|null
	 */
	public static function find_for_attachment( int $attachment_id ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => 1,
				'meta_key'         => self::META_ATTACHMENT, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $attachment_id,        // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => false,
				'no_found_rows'    => true,
			)
		);

		return $posts ? $posts[0] : null;
	}

	/**
	 * Ask every site in the network to rebuild its URL rules.
	 *
	 * Rewrite rules are cached, so our rule does nothing at all until they are
	 * regenerated — and until then every converted page is a 404 while the icon
	 * next to the PDF links confidently at it. That is the worst possible failure
	 * for this plugin, so the mechanism below is worth understanding before
	 * changing it.
	 *
	 * WHY A TOKEN AND NOT A FLAG?
	 *
	 * Because rewrite rules are PER SITE and this plugin is network-activated.
	 * flush_rewrite_rules() rebuilds the `rewrite_rules` option of the one site
	 * it is called on, and nothing else. A single network-wide "please flush"
	 * flag therefore cannot work: whichever site loads first deletes the flag,
	 * flushes itself, and every other site in the network keeps its stale rules
	 * forever. On a 200-site network that is 199 sites of 404s.
	 *
	 * So instead: this bumps a token stored network-wide, and each site records
	 * the token it last flushed for in its own option. Every site notices
	 * independently that it is behind, once, on its next request. New sites added
	 * later are covered for free, because a site that has never flushed has no
	 * recorded token at all.
	 */
	public static function schedule_rewrite_flush(): void {
		// A UUID rather than the time, because deactivating and reactivating within
		// the same second is a thing WP-CLI does happily, and a token equal to the
		// last one is a flush that silently never happens.
		update_site_option( 'equalify_iris_rewrite_token', wp_generate_uuid4() );
	}

	/**
	 * Take our URL rule out of every site in the network.
	 *
	 * WHY flush_rewrite_rules() IS NOT ENOUGH
	 *
	 * It was what deactivation used to call, and it did nothing useful, twice over.
	 * It runs in the same request that is deactivating the plugin — the plugin is
	 * still loaded, our rule is still registered in memory, and the rules were
	 * faithfully rebuilt with it in. And it only rebuilds the one site it runs on.
	 *
	 * The result was worse than a 404. With the plugin gone nothing registers the
	 * `equalify_iris_doc` query variable, so WordPress matched the stale rule, dropped
	 * the variable it did not recognise, was left with an empty query, and served the
	 * site's front page — HTTP 200 — at every accessible document's address, on every
	 * site. A soft 404 like that tells a search engine the page is still there and
	 * tells a reader following a saved link nothing at all.
	 *
	 * So: take the rule out of memory first, so nothing later in this request can
	 * write it back, then delete each site's stored rules. WordPress rebuilds them on
	 * that site's next request, from whatever plugins are active by then. Deleting
	 * rather than flushing also means no site pays for a rebuild it does not need.
	 *
	 * Each site's flush token goes too, so reactivating flushes every site again even
	 * if a later request had already rebuilt its rules without us.
	 */
	public static function remove_rewrite_rule_everywhere(): void {
		global $wp_rewrite;

		if ( $wp_rewrite instanceof WP_Rewrite ) {
			unset( $wp_rewrite->extra_rules_top[ self::rewrite_pattern() ] );
		}

		// get_sites() only exists on multisite. The plugin is no use on a single site,
		// but WordPress will still activate it there, and a deactivation that fatals
		// is a plugin nobody can switch off.
		$site_ids = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );

			delete_option( 'rewrite_rules' );
			delete_option( 'equalify_iris_rewrite_token' );

			restore_current_blog();
		}
	}

	/**
	 * Rebuild this site's URL rules if it has not caught up with the token yet.
	 *
	 * Runs on `init` at priority 99 — after register() and add_rewrite_rule() have
	 * put our rule in place, because flushing before that would faithfully cache
	 * a rule set without it.
	 */
	public static function maybe_flush_rewrites(): void {
		$wanted = (string) get_site_option( 'equalify_iris_rewrite_token', '' );

		if ( '' === $wanted ) {
			return;
		}

		if ( (string) get_option( 'equalify_iris_rewrite_token', '' ) === $wanted ) {
			return;
		}

		// Recorded before flushing, not after. If the flush itself fails or the
		// request dies halfway through, this site has still had its one attempt —
		// the alternative is an expensive rebuild on every single page load.
		update_option( 'equalify_iris_rewrite_token', $wanted );

		flush_rewrite_rules( false );
	}
}
