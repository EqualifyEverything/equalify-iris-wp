<?php
/**
 * WHAT IS THIS FILE?
 *
 * Works out which PDFs in a site's media library are public: linked from
 * something on the site that anybody can see. Only those are listed, and only
 * those are ever sent to Equalify Iris.
 *
 * WHY IT MATTERS
 *
 * Iris reports problems as public GitHub issues that can quote the document. A
 * PDF sitting in the media library is not necessarily meant for the public; a
 * PDF linked from a published page already is.
 *
 * WHERE IT LOOKS
 *
 * Published posts, pages and custom post types that visitors can read: their
 * content, excerpt, custom fields (including ACF file fields), and any synced
 * patterns they show. Then the site as a whole: menus in a theme location or a
 * widget, widgets in an active sidebar, a block theme's templates and template
 * parts and the navigation menus they show, category and tag descriptions, and
 * ACF options pages.
 *
 * Not covered: links written into a theme's PHP files, and page builders that
 * keep their layout outside the post content and custom fields.
 *
 * HOW
 *
 * Each PDF a post links to gets one `_equalify_iris_linked_from` row with the
 * post's id. Whether that post is still published is checked when asked, not
 * when saved, so unpublishing or password-protecting a page takes its PDFs off
 * the list at once. PDFs linked from the site as a whole get one
 * `_equalify_iris_shown_in` row per place; those are worked out again whenever a
 * menu, widget, template, term or the theme changes.
 *
 * Content written before the plugin was active is read by the background job,
 * a few hundred posts at a time, but only on sites that use the plugin: one
 * whose admin opens the Equalify Iris screen or turns on automatic tagging, or
 * every site once a super admin turns it on for the network.
 *
 * WHICH TYPES ARE PUBLIC, FROM THE BACKGROUND JOB
 *
 * The job reads every site from the main one, with switch_to_blog(), which does
 * not load that site's own plugins and theme. So each site writes down its
 * public post types, taxonomies and sidebars whenever it loads itself (the
 * profile), and the job goes by that. Until a site has one, the job counts only
 * posts and pages, which every site has; when the profile changes, the site is
 * read again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Discovery {

	/** On a PDF: the id of a post that links to it. One row per post. */
	const META = '_equalify_iris_linked_from';

	/** On a PDF: a site-wide place that links to it (see places()). */
	const META_SITE = '_equalify_iris_shown_in';

	/** On a post: the PDFs it links to, so they can be forgotten cheaply. */
	const META_LINKS = '_equalify_iris_links';

	/** Site option: "generation:last post id read", or "generation:done". */
	const OPTION = 'equalify_iris_indexed';

	/** Site option: the site-wide places need reading again. */
	const STALE = 'equalify_iris_places_stale';

	/** Network option: bumped to make every site read its content again. */
	const GENERATION = 'equalify_iris_read_generation';

	/** Posts read per query when catching up. */
	const BATCH = 100;

	/** A bare URL ending in .pdf, in HTML, JSON or plain text. */
	const URL = '#[^\s"\'<>()\[\]{}\\\\,;&=|]+\.pdf(?![\w-])#i';

	/** Site option: this site's public post types, taxonomies and sidebars. */
	const PROFILE = 'equalify_iris_profile';

	/** Posts whose custom fields changed in one request, above which the site is read again instead. */
	const MAX_CHANGED_POSTS = 200;

	/** Post types whose saving changes a site-wide place. */
	const PLACE_TYPES = array( 'nav_menu_item', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_block' );

	/** @var array<int, array<int, true>> Site => posts whose custom fields changed this request. */
	private static $changed_posts = array();

	/** @var array<int, true> Sites whose site-wide places changed this request. */
	private static $changed = array();

	public static function init(): void {
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_save' ), 10, 2 );
		add_action( 'delete_post', array( __CLASS__, 'on_delete' ), 10, 2 );

		foreach ( array( 'wp_update_nav_menu', 'wp_delete_nav_menu', 'switch_theme', 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'places_changed' ) );
		}

		add_action( 'added_option', array( __CLASS__, 'on_option' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_option' ) );

		// Custom fields saved without saving the post: imports, scripts, some plugins.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_meta' ), 10, 3 );
		}

		add_action( 'wp_loaded', array( __CLASS__, 'keep_profile' ) );
	}

	// -----------------------------------------------------------------------
	// What this site counts as public
	// -----------------------------------------------------------------------

	/** Whether this request loaded the current site itself, rather than switching to it. */
	private static function native(): bool {
		return ! ms_is_switched() || get_current_blog_id() === (int) ( $GLOBALS['_wp_switched_stack'][0] ?? 0 );
	}

	/** @return array{types: string[], taxonomies: string[], sidebars: string[]} */
	private static function registered(): array {
		global $wp_registered_sidebars;

		$types      = array_values( array_diff( array_filter( get_post_types(), 'is_post_type_viewable' ), array( 'attachment' ) ) );
		$taxonomies = array_values( array_filter( get_taxonomies(), 'is_taxonomy_viewable' ) );
		$sidebars   = array_keys( (array) $wp_registered_sidebars );

		sort( $types );
		sort( $taxonomies );
		sort( $sidebars );

		return array( 'types' => $types, 'taxonomies' => $taxonomies, 'sidebars' => $sidebars );
	}

	/** What the current site counts as public: as registered, or as it last wrote down. */
	private static function profile(): array {
		if ( self::native() ) {
			return self::registered();
		}

		$saved = get_option( self::PROFILE );

		return is_array( $saved ) && isset( $saved['types'], $saved['taxonomies'], $saved['sidebars'] )
			? $saved
			: array( 'types' => array( 'page', 'post' ), 'taxonomies' => array( 'category', 'post_tag' ), 'sidebars' => array() );
	}

	/**
	 * Write down what this site counts as public, for the background job.
	 *
	 * From pages visitors load, since they decide what is public, and from any
	 * request while there is no profile yet. Only on sites using the plugin, and
	 * only when it changed: one autoloaded option compared per page.
	 */
	public static function keep_profile(): void {
		if ( ! self::native() || '' === self::cursor() ) {
			return;
		}

		$saved = get_option( self::PROFILE );
		$front = ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'WP_CLI' ) && WP_CLI );

		if ( is_array( $saved ) && ! $front ) {
			return;
		}

		$now = self::registered();

		if ( $now === $saved ) {
			return;
		}

		update_option( self::PROFILE, $now, true );

		$saved = is_array( $saved ) ? $saved : array();

		// Read with the wrong types until now: read the posts, or just the
		// site-wide places, again.
		if ( ( $saved['types'] ?? null ) !== $now['types'] || ( $saved['taxonomies'] ?? null ) !== $now['taxonomies'] ) {
			update_option( self::OPTION, self::generation() . ':0', false );
		}

		update_option( self::STALE, 1, false );
		Equalify_Iris_Runner::wake();
	}

	// -----------------------------------------------------------------------
	// Posts
	// -----------------------------------------------------------------------

	public static function on_save( int $post_id, WP_Post $post ): void {
		// A site that has not started using the plugin keeps no records at all. It
		// reads everything when it starts, so nothing is missed.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || '' === self::cursor() ) {
			return;
		}

		if ( in_array( $post->post_type, self::PLACE_TYPES, true ) ) {
			self::places_changed();

			if ( 'wp_block' === $post->post_type ) {
				self::reindex_pattern_users( $post->ID );
			}

			return;
		}

		$found = self::index_post( $post );

		// A page just published with a PDF on it: tag the PDF now, rather than
		// wait for the next look through the library.
		if ( $found && self::post_is_public( $post ) ) {
			self::queue_new( $found );
		}
	}

	public static function on_delete( int $post_id, $post = null ): void {
		if ( $post instanceof WP_Post && in_array( $post->post_type, self::PLACE_TYPES, true ) ) {
			self::places_changed();
			return;
		}

		self::forget_post( $post_id );
	}

	/** A custom field changed: read its post again when this request ends. */
	public static function on_meta( $meta_ids, $object_id, $meta_key ): void {
		if ( '_' === substr( (string) $meta_key, 0, 1 ) || '' === self::cursor() ) {
			return;
		}

		if ( ! self::$changed_posts ) {
			add_action( 'shutdown', array( __CLASS__, 'read_changed_posts' ) );
		}

		self::$changed_posts[ get_current_blog_id() ][ (int) $object_id ] = true;
	}

	/** A few posts are read now; a bulk change has the whole site read again in the background. */
	public static function read_changed_posts(): void {
		$sites               = self::$changed_posts;
		self::$changed_posts = array();

		foreach ( $sites as $site_id => $posts ) {
			$switch = is_multisite() && get_current_blog_id() !== $site_id;

			if ( $switch ) {
				switch_to_blog( $site_id );
			}

			if ( count( $posts ) > self::MAX_CHANGED_POSTS ) {
				update_option( self::OPTION, self::generation() . ':0', false );
				Equalify_Iris_Runner::wake();
			} else {
				foreach ( array_keys( $posts ) as $post_id ) {
					$post = get_post( $post_id );

					if ( $post && ! in_array( $post->post_type, self::PLACE_TYPES, true ) ) {
						$found = self::index_post( $post );

						if ( $found && self::post_is_public( $post ) ) {
							self::queue_new( $found );
						}
					}
				}
			}

			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/** With automatic tagging on, queue PDFs that have just become public. */
	private static function queue_new( array $ids ): void {
		if ( ! Equalify_Iris_Settings::auto_enabled() ) {
			return;
		}

		foreach ( $ids as $id ) {
			if ( '' === Equalify_Iris_Tagger::status( $id ) ) {
				Equalify_Iris_Tagger::queue( $id );
			}
		}
	}

	/**
	 * Record which PDFs a post links to.
	 *
	 * @return int[] Their attachment ids.
	 */
	public static function index_post( WP_Post $post ): array {
		self::forget_post( $post->ID );

		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return array();
		}

		$meta = array();

		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			$meta[ $key ] = array_map( 'maybe_unserialize', (array) $values );
		}

		$found = array_values(
			array_unique(
				array_merge(
					self::pdfs_in( array( self::with_patterns( $post->post_content ), $post->post_excerpt, self::public_fields( $meta ) ) ),
					self::acf_files( $meta )
				)
			)
		);

		foreach ( $found as $id ) {
			// Never two rows for one post, whatever came before.
			delete_post_meta( $id, self::META, (string) $post->ID );
			add_post_meta( $id, self::META, $post->ID );
		}

		if ( $found ) {
			update_post_meta( $post->ID, self::META_LINKS, $found );
		}

		return $found;
	}

	public static function forget_post( int $post_id ): void {
		$links = (array) get_post_meta( $post_id, self::META_LINKS, true );

		foreach ( array_filter( array_map( 'intval', $links ) ) as $id ) {
			delete_post_meta( $id, self::META, (string) $post_id );
		}

		if ( $links ) {
			delete_post_meta( $post_id, self::META_LINKS );
		}
	}

	/**
	 * Custom fields a theme may show. Fields starting with an underscore are
	 * WordPress's and plugins' own bookkeeping, not content.
	 */
	private static function public_fields( array $meta ): array {
		return array_filter(
			$meta,
			static function ( $key ) {
				return '_' !== substr( (string) $key, 0, 1 );
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * PDFs held by ACF file fields, which store an attachment id rather than a
	 * URL. ACF keeps the field's key in a matching `_name` entry.
	 *
	 * @param array<string, array> $meta Name => values.
	 * @return int[]
	 */
	private static function acf_files( array $meta ): array {
		$ids = array();

		foreach ( self::public_fields( $meta ) as $key => $values ) {
			$field_key = $meta[ '_' . $key ][0] ?? '';

			if ( ! is_string( $field_key ) || ! str_starts_with( $field_key, 'field_' ) ) {
				continue;
			}

			if ( 'file' === self::acf_type( $field_key ) ) {
				foreach ( $values as $value ) {
					if ( is_numeric( $value ) ) {
						$ids[] = (int) $value;
					}
				}
			}
		}

		return array_values( array_filter( array_unique( $ids ), array( 'Equalify_Iris_Tagger', 'is_pdf' ) ) );
	}

	/**
	 * An ACF field's type. From ACF when it is loaded here; otherwise from the
	 * field's own post, since the background job may be reading a site whose ACF
	 * is not loaded on the main site.
	 */
	private static function acf_type( string $field_key ): string {
		global $wpdb;

		if ( function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $field_key );

			if ( is_array( $field ) ) {
				return (string) ( $field['type'] ?? '' );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE post_type = 'acf-field' AND post_name = %s LIMIT 1", $field_key ) );
		$field   = is_string( $content ) ? maybe_unserialize( $content ) : null;

		return is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
	}

	/** Add the content of the synced patterns some block HTML shows. */
	private static function with_patterns( string $html, int $depth = 0 ): string {
		if ( $depth > 3 || ! preg_match_all( '#<!--\s+wp:block\s+\{[^}]*"ref":(\d+)#', $html, $matches ) ) {
			return $html;
		}

		foreach ( array_unique( $matches[1] ) as $ref ) {
			$block = get_post( (int) $ref );

			if ( $block && 'wp_block' === $block->post_type && 'publish' === $block->post_status ) {
				$html .= "\n" . self::with_patterns( $block->post_content, $depth + 1 );
			}
		}

		return $html;
	}

	/** A synced pattern changed: read again the posts that show it. */
	private static function reindex_pattern_users( int $pattern_id ): void {
		global $wpdb;

		$types = self::post_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$like  = '%' . $wpdb->esc_like( '"ref":' . $pattern_id ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'publish' AND post_content LIKE %s LIMIT 500", array_merge( $types, array( $like ) ) ) );

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );

			if ( $post ) {
				self::queue_new( self::index_post( $post ) );
			}
		}
	}

	/**
	 * The PDF attachments some content links to: HTML, a URL, or arrays and
	 * objects of them, as custom fields and widgets store them.
	 *
	 * @param mixed $value
	 * @return int[]
	 */
	public static function pdfs_in( $value ): array {
		$text = self::text( $value );

		// JSON escapes its slashes.
		if ( false === stripos( $text, '.pdf' ) || ! preg_match_all( self::URL, str_replace( '\\/', '/', $text ), $matches ) ) {
			return array();
		}

		$files = array_values( array_unique( array_filter( array_map( array( 'Equalify_Iris_Links', 'upload_file' ), $matches[0] ) ) ) );

		if ( ! $files ) {
			return array();
		}

		global $wpdb;

		$in = implode( ',', array_fill( 0, count( $files ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($in)", $files ) );

		return array_values( array_filter( array_map( 'intval', $ids ), array( 'Equalify_Iris_Tagger', 'is_pdf' ) ) );
	}

	/** @param mixed $value */
	private static function text( $value ): string {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			return implode( "\n", array_map( array( __CLASS__, 'text' ), $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Post types whose published posts visitors can read. */
	public static function post_types(): array {
		$types = self::profile()['types'];

		return $types ? $types : array( 'post', 'page' );
	}

	public static function post_is_public( WP_Post $post ): bool {
		return 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, self::post_types(), true );
	}

	// -----------------------------------------------------------------------
	// The site as a whole
	// -----------------------------------------------------------------------

	/** @return array<string, string> Place => what the list calls it. */
	public static function places(): array {
		return array(
			'menu'     => __( 'A menu', 'equalify-iris' ),
			'widget'   => __( 'A widget', 'equalify-iris' ),
			'template' => __( 'The theme’s templates', 'equalify-iris' ),
			'term'     => __( 'A category or tag description', 'equalify-iris' ),
			'options'  => __( 'Site-wide custom fields', 'equalify-iris' ),
		);
	}

	public static function on_option( $name ): void {
		$name = (string) $name;

		if ( 'sidebars_widgets' === $name || str_starts_with( $name, 'widget_' ) || str_starts_with( $name, 'theme_mods_' ) || str_starts_with( $name, 'options_' ) ) {
			self::places_changed();
		}
	}

	/** Read the site-wide places again when this request ends. */
	public static function places_changed(): void {
		if ( '' === self::cursor() ) {
			return;
		}

		if ( ! self::$changed ) {
			add_action( 'shutdown', array( __CLASS__, 'read_changed_places' ) );
		}

		self::$changed[ get_current_blog_id() ] = true;
	}

	/**
	 * One site, read now. More than one (a script changing every site's theme, say)
	 * is left to the background job rather than slow this request down.
	 */
	public static function read_changed_places(): void {
		$sites         = array_keys( self::$changed );
		self::$changed = array();

		foreach ( $sites as $site_id ) {
			$switch = is_multisite() && get_current_blog_id() !== $site_id;

			if ( $switch ) {
				switch_to_blog( $site_id );
			}

			if ( 1 === count( $sites ) ) {
				self::queue_new( self::index_places() );
			} else {
				update_option( self::STALE, 1, false );
				Equalify_Iris_Runner::wake( $site_id );
			}

			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Work out which PDFs the site as a whole links to.
	 *
	 * @return int[] All of them.
	 */
	public static function index_places(): array {
		$found = array(
			'menu'     => self::in_menus(),
			'widget'   => self::pdfs_in( self::active_widgets() ),
			'template' => self::in_templates(),
			'term'     => self::in_terms(),
			'options'  => self::in_acf_options(),
		);

		delete_metadata( 'post', 0, self::META_SITE, '', true );
		delete_option( self::STALE );

		$all = array();

		foreach ( $found as $place => $ids ) {
			foreach ( array_unique( $ids ) as $id ) {
				add_post_meta( $id, self::META_SITE, $place );
				$all[] = $id;
			}
		}

		return array_values( array_unique( $all ) );
	}

	/** Widget settings in sidebars the theme shows. */
	private static function active_widgets(): array {
		$widgets  = array();
		$sidebars = self::profile()['sidebars'];

		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $sidebar => $ids ) {
			if ( ! is_array( $ids ) || 'wp_inactive_widgets' === $sidebar || ! in_array( $sidebar, $sidebars, true ) ) {
				continue;
			}

			foreach ( $ids as $widget_id ) {
				if ( preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
					$all = get_option( 'widget_' . $m[1] );

					if ( is_array( $all ) && isset( $all[ (int) $m[2] ] ) ) {
						$widgets[] = $all[ (int) $m[2] ];
					}
				}
			}
		}

		return $widgets;
	}

	/** @return int[] */
	private static function in_menus(): array {
		$menus = array_values( array_filter( array_map( 'intval', (array) get_nav_menu_locations() ) ) );

		foreach ( self::active_widgets() as $widget ) {
			if ( is_array( $widget ) && ! empty( $widget['nav_menu'] ) ) {
				$menus[] = (int) $widget['nav_menu'];
			}
		}

		$ids  = array();
		$urls = array();

		foreach ( array_unique( $menus ) as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu, array( 'update_post_term_cache' => false ) ) as $item ) {
				if ( 'post_type' === ( $item->type ?? '' ) && 'attachment' === ( $item->object ?? '' ) ) {
					$ids[] = (int) $item->object_id;
				} else {
					$urls[] = (string) ( $item->url ?? '' );
				}
			}
		}

		return array_merge( array_filter( $ids, array( 'Equalify_Iris_Tagger', 'is_pdf' ) ), self::pdfs_in( $urls ) );
	}

	/** @return int[] */
	private static function in_templates(): array {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return array();
		}

		$html = '';

		foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
			foreach ( get_block_templates( array(), $type ) as $template ) {
				$html .= "\n" . $template->content;
			}
		}

		$html = self::with_theme_patterns( $html );

		$fallback = false;

		foreach ( array_unique( self::navigation_refs( parse_blocks( $html ), $fallback ) ) as $ref ) {
			$menu = get_post( $ref );

			if ( $menu && 'wp_navigation' === $menu->post_type && 'publish' === $menu->post_status ) {
				$html .= "\n" . $menu->post_content;
			}
		}

		// A Navigation block with nothing chosen shows the newest navigation menu.
		if ( $fallback ) {
			$newest = get_posts( array( 'post_type' => 'wp_navigation', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
			$html  .= $newest ? "\n" . $newest[0]->post_content : '';
		}

		return self::pdfs_in( self::with_patterns( $html ) );
	}

	/** Add the content of the theme patterns some block HTML includes, as a header usually does. */
	private static function with_theme_patterns( string $html, int $depth = 0 ): string {
		if ( $depth > 3 || ! preg_match_all( '#<!--\s+wp:pattern\s+(\{.*?\})\s+/-->#', $html, $matches ) ) {
			return $html;
		}

		foreach ( array_unique( $matches[1] ) as $attrs ) {
			$slug = (string) ( json_decode( $attrs, true )['slug'] ?? '' );

			if ( '' !== $slug ) {
				$html .= "\n" . self::with_theme_patterns( self::theme_pattern( $slug ), $depth + 1 );
			}
		}

		return $html;
	}

	/**
	 * A theme pattern's content. The registry only holds the main site's theme's
	 * patterns while the background job reads another site, so then it comes
	 * from that site's theme's own pattern files.
	 */
	private static function theme_pattern( string $slug ): string {
		if ( self::native() ) {
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );

			return is_array( $pattern ) ? (string) ( $pattern['content'] ?? '' ) : '';
		}

		foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $stylesheet ) {
			$theme = wp_get_theme( $stylesheet );

			if ( ! method_exists( $theme, 'get_block_patterns' ) ) {
				continue;
			}

			foreach ( $theme->get_block_patterns() as $file => $data ) {
				$path = $theme->get_stylesheet_directory() . '/patterns/' . $file;

				if ( ( $data['slug'] ?? '' ) !== $slug || ! is_file( $path ) ) {
					continue;
				}

				ob_start();

				try {
					include $path;
				} catch ( Throwable $e ) {
					// A pattern that will not load shows nothing.
					unset( $e );
				}

				return (string) ob_get_clean();
			}
		}

		return '';
	}

	/**
	 * The navigation menus some blocks show.
	 *
	 * @param bool $fallback Set when a Navigation block has none chosen.
	 * @return int[]
	 */
	private static function navigation_refs( array $blocks, bool &$fallback ): array {
		$refs = array();

		foreach ( $blocks as $block ) {
			if ( 'core/navigation' === ( $block['blockName'] ?? '' ) ) {
				if ( ! empty( $block['attrs']['ref'] ) ) {
					$refs[] = (int) $block['attrs']['ref'];
				} elseif ( empty( $block['innerBlocks'] ) ) {
					$fallback = true;
				}
			}

			$refs = array_merge( $refs, self::navigation_refs( (array) ( $block['innerBlocks'] ?? array() ), $fallback ) );
		}

		return $refs;
	}

	/** @return int[] */
	private static function in_terms(): array {
		global $wpdb;

		$taxonomies = self::profile()['taxonomies'];

		if ( ! $taxonomies ) {
			return array();
		}

		$in = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$descriptions = $wpdb->get_col( $wpdb->prepare( "SELECT description FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($in) AND description LIKE %s", array_merge( $taxonomies, array( '%' . $wpdb->esc_like( '.pdf' ) . '%' ) ) ) );

		return self::pdfs_in( $descriptions );
	}

	/** ACF options pages, which ACF keeps in options named `options_…`. */
	private static function in_acf_options(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'options_' ) . '%', $wpdb->esc_like( '_options_' ) . '%' ) );

		$meta = array();

		foreach ( $rows as $row ) {
			$meta[ $row->option_name ][] = maybe_unserialize( $row->option_value );
		}

		return array_merge( self::pdfs_in( self::public_fields( $meta ) ), self::acf_files( $meta ) );
	}

	// -----------------------------------------------------------------------
	// Catching up on content written before the plugin was active
	// -----------------------------------------------------------------------

	private static function generation(): int {
		return (int) get_site_option( self::GENERATION, 0 );
	}

	/** '' when this site has not started reading, a post id, or 'done'. */
	private static function cursor(): string {
		$parts = explode( ':', (string) get_option( self::OPTION, '' ), 2 );

		return 2 === count( $parts ) && (int) $parts[0] === self::generation() ? $parts[1] : '';
	}

	public static function indexed(): bool {
		return 'done' === self::cursor();
	}

	/** Whether this site has started reading its content, since the last restart. */
	public static function started(): bool {
		return '' !== self::cursor();
	}

	/** Make every site read its content again, when it next runs. */
	public static function restart_everywhere(): void {
		update_site_option( self::GENERATION, self::generation() + 1 );
	}

	/** Read published posts until the time is up. */
	public static function catch_up( int $until ): void {
		global $wpdb;

		if ( '' === self::cursor() ) {
			Equalify_Iris_Runner::mark_used();
			update_option( self::OPTION, self::generation() . ':0', false );
			self::index_places();
		} elseif ( get_option( self::STALE ) ) {
			self::index_places();
		}

		$types = self::post_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		while ( ! self::indexed() && time() < $until ) {
			$after = (int) self::cursor();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_type IN ($in) ORDER BY ID LIMIT %d", array_merge( array( $after ), $types, array( self::BATCH ) ) ) ) );

			// Posts and their custom fields in two queries, not two per post.
			_prime_post_caches( $ids, false, true );

			foreach ( $ids as $id ) {
				$post = get_post( $id );

				if ( $post ) {
					self::index_post( $post );
				}
			}

			update_option( self::OPTION, self::generation() . ':' . ( count( $ids ) < self::BATCH ? 'done' : end( $ids ) ), false );
		}
	}

	// -----------------------------------------------------------------------
	// Asking
	// -----------------------------------------------------------------------

	public static function is_public( int $attachment_id ): bool {
		return (bool) self::find( 'public', 1, 0, $attachment_id );
	}

	/**
	 * Published posts that link to a PDF.
	 *
	 * @return array{posts: WP_Post[], total: int}
	 */
	public static function linked_from( int $attachment_id, int $limit = 3 ): array {
		global $wpdb;

		$types = self::post_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$from  = "FROM {$wpdb->postmeta} l JOIN {$wpdb->posts} p ON p.ID = CAST( l.meta_value AS UNSIGNED )
			WHERE l.post_id = %d AND l.meta_key = %s
			AND p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ($in)";
		$args  = array_merge( array( $attachment_id, self::META ), $types );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID $from ORDER BY p.ID LIMIT %d", array_merge( $args, array( $limit ) ) ) );
		$total = count( $ids ) < $limit ? count( $ids ) : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT p.ID ) $from", $args ) );
		// phpcs:enable

		return array(
			'posts' => array_values( array_filter( array_map( 'get_post', array_map( 'intval', $ids ) ) ) ),
			'total' => $total,
		);
	}

	/** @return string[] The site-wide places that link to a PDF, as the list names them. */
	public static function shown_in( int $attachment_id ): array {
		$names = self::places();

		return array_values( array_intersect_key( $names, array_flip( (array) get_post_meta( $attachment_id, self::META_SITE ) ) ) );
	}

	/**
	 * PDF attachments on the current site, newest first.
	 *
	 * @param string $which  `listed`    public, or with a tagged copy to manage
	 *                       `public`    linked from something visitors can see
	 *                       `untracked` public, and never asked about
	 *                       `untagged`  public, and with no tagged copy
	 * @param int      $limit  0 for all of them.
	 * @param int      $only   Restrict to one attachment.
	 * @param string[] $status Only those with one of these statuses, as well.
	 * @return int[]
	 */
	public static function find( string $which, int $limit = 0, int $offset = 0, int $only = 0, array $status = array() ): array {
		global $wpdb;

		list( $where, $args ) = self::where( $which, $only, $status );

		$sql = "SELECT a.ID FROM {$wpdb->posts} a WHERE $where ORDER BY a.ID DESC";

		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d OFFSET %d';
			$args[] = $limit;
			$args[] = $offset;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/** @param string|string[] $status Only those with this status, or one of these, as well. */
	public static function count( string $which, $status = array() ): int {
		global $wpdb;

		list( $where, $args ) = self::where( $which, 0, (array) $status );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} a WHERE $where", $args ) );
	}

	/** @return array{0: string, 1: array} A WHERE clause on `a`, and its values. */
	private static function where( string $which, int $only = 0, array $status = array() ): array {
		global $wpdb;

		$types = self::post_types();
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$public = "( EXISTS ( SELECT 1 FROM {$wpdb->postmeta} l
				JOIN {$wpdb->posts} p ON p.ID = CAST( l.meta_value AS UNSIGNED )
				WHERE l.post_id = a.ID AND l.meta_key = %s
				AND p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ($in) )
			OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} w WHERE w.post_id = a.ID AND w.meta_key = %s ) )";

		$public_args = array_merge( array( self::META ), $types, array( self::META_SITE ) );

		$where = "a.post_type = 'attachment' AND a.post_mime_type = 'application/pdf'";
		$args  = array();

		if ( $only ) {
			$where .= ' AND a.ID = %d';
			$args[] = $only;
		}

		$status = array_values( array_filter( array_map( 'strval', $status ) ) );

		if ( $status ) {
			$where .= " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} t WHERE t.post_id = a.ID AND t.meta_key = %s AND t.meta_value IN (" . implode( ',', array_fill( 0, count( $status ), '%s' ) ) . ') )';
			$args   = array_merge( $args, array( Equalify_Iris_Tagger::META_STATUS ), $status );
		}

		if ( 'listed' === $which ) {
			$where .= " AND ( $public OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = a.ID AND f.meta_key = %s ) )";
			$args   = array_merge( $args, $public_args, array( Equalify_Iris_Tagger::META_FILE ) );

			return array( $where, $args );
		}

		$where .= " AND $public";
		$args   = array_merge( $args, $public_args );

		if ( 'untracked' === $which ) {
			$where .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = a.ID AND s.meta_key = %s )";
			$args[] = Equalify_Iris_Tagger::META_STATUS;
		} elseif ( 'untagged' === $which ) {
			$where .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = a.ID AND s.meta_key = %s AND s.meta_value = %s )";
			$args[] = Equalify_Iris_Tagger::META_STATUS;
			$args[] = Equalify_Iris_Tagger::TAGGED;
		}

		return array( $where, $args );
	}
}
