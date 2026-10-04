<?php
/**
 * WHAT IS THIS FILE?
 *
 * The Equalify Iris screens, and the notice that sends a site admin to them.
 *
 *   Network Admin → Equalify Iris   (super admins)
 *     Where Iris lives, its token if it needs one, a switch that tags every
 *     site's PDFs automatically, whether the background job is keeping up, and
 *     every site's progress, with a way into each site's list and a button to
 *     tag a site's PDFs.
 *
 *   Equalify Iris                   (each site's admins)
 *     Whether this site's PDFs are tagged automatically, and the list of PDFs
 *     visitors can reach from its published content, each of which can be
 *     tagged, viewed or have its tagged copy deleted. With the network switch
 *     on, the site switch is not shown: a super admin has already decided.
 *
 * A site's content is only read once someone opens its screen, tags a PDF, or
 * turns on automatic tagging, so the sites of a large network that nobody
 * looks at cost nothing.
 *
 * Forms and links post to admin-post.php and network/edit.php, with a nonce and a
 * capability check each, and redirect back with a short code saying what
 * happened.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Admin {

	const SLUG = 'equalify-iris';

	/** Sites per page on the network screen. */
	const SITES_PER_PAGE = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_site_page' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'add_network_page' ) );

		add_action( 'admin_post_equalify_iris_save_site', array( __CLASS__, 'save_site' ) );
		add_action( 'admin_post_equalify_iris_tag', array( __CLASS__, 'handle_tag' ) );
		add_action( 'admin_post_equalify_iris_remove', array( __CLASS__, 'handle_remove' ) );
		add_action( 'admin_post_equalify_iris_tag_all', array( __CLASS__, 'handle_tag_all' ) );
		add_action( 'network_admin_edit_equalify_iris_save_network', array( __CLASS__, 'save_network' ) );
		add_action( 'network_admin_edit_equalify_iris_tag_site', array( __CLASS__, 'handle_tag_site' ) );
		add_action( 'network_admin_edit_equalify_iris_read_site', array( __CLASS__, 'handle_read_site' ) );

		add_action( 'admin_notices', array( __CLASS__, 'untagged_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'result_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'result_notice' ) );
	}

	/** A site's Equalify Iris screen: the current site's, or another's. */
	public static function site_page_url( int $site_id = 0 ): string {
		return $site_id
			? get_admin_url( $site_id, 'admin.php?page=' . self::SLUG )
			: admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** A count on the network screen, linked to that site's list showing just those PDFs. */
	private static function count_link( int $count, int $site_id, string $filter, string $site_name ): string {
		if ( ! $count ) {
			return esc_html( number_format_i18n( 0 ) );
		}

		return sprintf(
			'<a href="%s">%s<span class="screen-reader-text"> %s</span></a>',
			esc_url( add_query_arg( Equalify_Iris_List_Table::FILTER, $filter, self::site_page_url( $site_id ) ) ),
			esc_html( number_format_i18n( $count ) ),
			/* translators: 1: a status such as "Could not be tagged", 2: a site's name. */
			esc_html( sprintf( __( '%1$s on %2$s', 'equalify-iris' ), Equalify_Iris_List_Table::filters()[ $filter ][0], $site_name ) )
		);
	}

	public static function network_page_url(): string {
		return network_admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Send the browser back with a code result_notice() turns into a sentence. */
	public static function redirect( string $url, string $notice ): void {
		wp_safe_redirect( add_query_arg( 'equalify-iris', $notice, $url ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// Notices
	// -----------------------------------------------------------------------

	/**
	 * Tell a site admin when visitors are getting untagged PDFs.
	 *
	 * Only when automatic tagging is off and a published page links to a PDF with
	 * no tagged copy, and not on the Equalify Iris screen, which already says so.
	 * The answer is kept for a few minutes: this runs on every admin page.
	 */
	public static function untagged_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || Equalify_Iris_Settings::auto_enabled() ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && 'toplevel_page_' . self::SLUG === $screen->id ) {
			return;
		}

		if ( ! Equalify_Iris_Discovery::indexed() ) {
			return;
		}

		$untagged = wp_cache_get( 'untagged', 'equalify_iris' );

		if ( false === $untagged ) {
			$untagged = Equalify_Iris_Discovery::find( 'untagged', 1 ) ? 1 : 0;
			wp_cache_set( 'untagged', $untagged, 'equalify_iris', 5 * MINUTE_IN_SECONDS );
		}

		if ( ! $untagged ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: %s: a link to the Equalify Iris settings screen. */
				esc_html__( 'You are currently displaying inaccessible PDFs. Visit %s to turn on automatic PDF tagging.', 'equalify-iris' ),
				'<a href="' . esc_url( self::site_page_url() ) . '">' . esc_html__( 'Equalify Iris settings', 'equalify-iris' ) . '</a>'
			)
		);
	}

	/** Say what the last action did. */
	public static function result_notice(): void {
		$code = isset( $_GET['equalify-iris'] ) ? sanitize_key( wp_unslash( $_GET['equalify-iris'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$messages = array(
			'saved'      => array( 'success', __( 'Settings saved.', 'equalify-iris' ) ),
			'connected'  => array( 'success', __( 'Connected. This Equalify Iris deployment can tag PDFs.', 'equalify-iris' ) ),
			'queued'     => array( 'success', __( 'Equalify Iris will add accessibility tags to this PDF. It usually takes a few minutes; links switch to the tagged version once it is ready.', 'equalify-iris' ) ),
			'queued-all' => array( 'success', __( 'Equalify Iris will add accessibility tags to these PDFs. It usually takes a few minutes each.', 'equalify-iris' ) ),
			'removed'    => array( 'success', __( 'The Iris-tagged version was deleted. Links point at the original PDF again.', 'equalify-iris' ) ),
			'reading'    => array( 'success', __( 'Equalify Iris will look for PDFs on this site in the next few minutes.', 'equalify-iris' ) ),
			'not-public' => array( 'error', __( 'Only PDFs that visitors can reach from this site’s published content can be sent to Iris.', 'equalify-iris' ) ),
		);

		if ( 'check-failed' === $code ) {
			$check = get_site_transient( 'equalify_iris_check' );
			$text  = is_string( $check ) ? $check : __( 'Could not connect to Equalify Iris.', 'equalify-iris' );

			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $text ) );
			return;
		}

		if ( isset( $messages[ $code ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $messages[ $code ][0] ),
				esc_html( $messages[ $code ][1] )
			);
		}
	}

	// -----------------------------------------------------------------------
	// The site screen
	// -----------------------------------------------------------------------

	public static function add_site_page(): void {
		$hook = add_menu_page(
			__( 'Equalify Iris', 'equalify-iris' ),
			__( 'Equalify Iris', 'equalify-iris' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_site_page' ),
			'dashicons-media-document',
			81
		);

		add_action( 'load-' . $hook, array( __CLASS__, 'handle_bulk' ) );
	}

	public static function render_site_page(): void {
		// Opening the screen is what starts a site reading its content.
		if ( ! Equalify_Iris_Discovery::indexed() ) {
			Equalify_Iris_Runner::wake();
		}

		$table = new Equalify_Iris_List_Table();
		$table->prepare_items();

		$untagged = Equalify_Iris_Discovery::count( 'untagged' );
		$working  = Equalify_Iris_Discovery::count( 'listed', array( Equalify_Iris_Tagger::QUEUED, Equalify_Iris_Tagger::WORKING ) );
		$last_run = Equalify_Iris_Runner::last_run();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Equalify Iris', 'equalify-iris' ); ?></h1>

			<p><?php esc_html_e( 'Equalify Iris adds accessibility tags to the PDFs linked from this site’s published pages, posts, menus and widgets, so screen readers can read them in order, with headings, lists and tables. The original file is kept; links on this site point at the tagged copy instead. Only public PDFs are sent to Iris.', 'equalify-iris' ); ?></p>

			<?php if ( ! Equalify_Iris_Settings::auto_enabled() && $untagged ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'You are currently displaying inaccessible PDFs. Turn on automatic PDF tagging below, or tag them one by one.', 'equalify-iris' ); ?></p></div>
			<?php endif; ?>

			<?php if ( Equalify_Iris_Settings::network_auto() ) : ?>
				<p><?php esc_html_e( 'A network administrator has turned on automatic PDF tagging for every site, so every PDF below is tagged without anyone asking.', 'equalify-iris' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="equalify_iris_save_site">
					<?php wp_nonce_field( 'equalify_iris_save_site' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Automatic PDF tagging', 'equalify-iris' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="auto_tag" value="1" <?php checked( Equalify_Iris_Settings::site_auto() ); ?> aria-describedby="equalify-iris-auto-help">
									<?php esc_html_e( 'Add accessibility tags to every PDF visitors can reach', 'equalify-iris' ); ?>
								</label>
								<p class="description" id="equalify-iris-auto-help">
									<?php esc_html_e( 'The PDFs below are tagged a few at a time, and new ones as soon as a page linking to them is published.', 'equalify-iris' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button(); ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'PDFs visitors can reach', 'equalify-iris' ); ?></h2>

			<?php if ( ! Equalify_Iris_Discovery::indexed() ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Equalify Iris is still reading this site’s content for PDF links. More may appear here over the next few minutes.', 'equalify-iris' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! Equalify_Iris_Settings::auto_enabled() && $untagged ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="equalify_iris_tag_all">
					<?php wp_nonce_field( 'equalify_iris_tag_all' ); ?>
					<?php
					submit_button(
						/* translators: %d: how many PDFs have no tagged copy. */
						sprintf( _n( 'Send the %d untagged PDF to Iris for Tagging', 'Send all %d untagged PDFs to Iris for Tagging', $untagged, 'equalify-iris' ), $untagged ),
						'secondary',
						'submit',
						false
					);
					?>
				</form>
			<?php endif; ?>

			<?php if ( $working && $last_run && time() - $last_run > 15 * MINUTE_IN_SECONDS ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: a length of time, such as "2 hours". */
							__( 'Nothing is moving: the background job last ran %s ago. A network administrator can see why on Network Admin → Equalify Iris.', 'equalify-iris' ),
							human_time_diff( $last_run )
						)
					);
					?>
				</p></div>
			<?php elseif ( $working ) : ?>
				<p>
					<?php
					echo esc_html(
						$last_run
							? sprintf(
								/* translators: 1: how many PDFs, 2: a length of time, such as "2 minutes". */
								_n( '%1$d PDF is being tagged. Equalify Iris last checked on it %2$s ago, and checks every few minutes. Reload this page to see the latest.', '%1$d PDFs are being tagged. Equalify Iris last checked on them %2$s ago, and checks every few minutes. Reload this page to see the latest.', $working, 'equalify-iris' ),
								$working,
								human_time_diff( $last_run )
							)
							: sprintf(
								/* translators: %d: how many PDFs. */
								_n( '%d PDF is being tagged. Reload this page to see the latest.', '%d PDFs are being tagged. Reload this page to see the latest.', $working, 'equalify-iris' ),
								$working
							)
					);
					?>
				</p>
			<?php endif; ?>

			<?php $table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( '' !== Equalify_Iris_List_Table::current_filter() ) : ?>
					<input type="hidden" name="<?php echo esc_attr( Equalify_Iris_List_Table::FILTER ); ?>" value="<?php echo esc_attr( Equalify_Iris_List_Table::current_filter() ); ?>">
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}


	public static function save_site(): void {
		self::require_cap( 'manage_options' );
		check_admin_referer( 'equalify_iris_save_site' );

		Equalify_Iris_Settings::set_site_auto( ! empty( $_POST['auto_tag'] ) );
		wp_cache_delete( 'untagged', 'equalify_iris' );

		self::redirect( self::site_page_url(), 'saved' );
	}

	// -----------------------------------------------------------------------
	// What the list's actions do
	// -----------------------------------------------------------------------

	private static function require_cap( string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'equalify-iris' ), 403 );
		}
	}

	/** Check the permission and nonce on a single-PDF link, and return its id. */
	private static function verify( string $action ): int {
		self::require_cap( 'manage_options' );

		$attachment_id = isset( $_GET['attachment'] ) ? absint( $_GET['attachment'] ) : 0;

		check_admin_referer( $action . '_' . $attachment_id );

		return $attachment_id;
	}

	/** Back to the page of the list the admin was on. */
	private static function back(): string {
		$referer = wp_get_referer();

		return $referer ? remove_query_arg( array( 'equalify-iris', '_wpnonce', 'action', 'action2', 'attachment' ), $referer ) : self::site_page_url();
	}

	public static function handle_tag(): void {
		$attachment_id = self::verify( 'equalify_iris_tag' );

		self::redirect( self::back(), Equalify_Iris_Tagger::queue( $attachment_id ) ? 'queued' : 'not-public' );
	}

	public static function handle_remove(): void {
		$attachment_id = self::verify( 'equalify_iris_remove' );

		if ( Equalify_Iris_Tagger::is_pdf( $attachment_id ) ) {
			Equalify_Iris_Tagger::remove( $attachment_id );
		}

		self::redirect( self::back(), 'removed' );
	}

	public static function handle_tag_all(): void {
		self::require_cap( 'manage_options' );
		check_admin_referer( 'equalify_iris_tag_all' );

		Equalify_Iris_Tagger::queue_all();

		self::redirect( self::site_page_url(), 'queued-all' );
	}

	/** The list's bulk actions, before the screen is drawn. */
	public static function handle_bulk(): void {
		$table  = new Equalify_Iris_List_Table();
		$action = $table->current_action();

		if ( ! in_array( $action, array( 'equalify_iris_tag', 'equalify_iris_remove' ), true ) ) {
			return;
		}

		self::require_cap( 'manage_options' );
		check_admin_referer( 'bulk-pdfs' );

		$ids = isset( $_REQUEST['attachment'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['attachment'] ) ) : array();

		foreach ( $ids as $id ) {
			if ( 'equalify_iris_tag' === $action ) {
				Equalify_Iris_Tagger::queue( $id );
			} elseif ( Equalify_Iris_Tagger::is_pdf( $id ) ) {
				Equalify_Iris_Tagger::remove( $id );
			}
		}

		self::redirect( self::back(), 'equalify_iris_tag' === $action ? 'queued-all' : 'removed' );
	}

	// -----------------------------------------------------------------------
	// The network screen
	// -----------------------------------------------------------------------

	public static function add_network_page(): void {
		add_menu_page(
			__( 'Equalify Iris', 'equalify-iris' ),
			__( 'Equalify Iris', 'equalify-iris' ),
			'manage_network_options',
			self::SLUG,
			array( __CLASS__, 'render_network_page' ),
			'dashicons-media-document',
			22
		);
	}

	public static function render_network_page(): void {
		$has_token = '' !== Equalify_Iris_Settings::api_token();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Equalify Iris', 'equalify-iris' ); ?></h1>

			<p><?php esc_html_e( 'Equalify Iris adds accessibility tags to the PDFs linked from each site’s published pages, posts, menus and widgets. Links point at the tagged copy; the original file is kept. Only public PDFs are sent to Iris.', 'equalify-iris' ); ?></p>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=equalify_iris_save_network' ) ); ?>">
				<?php wp_nonce_field( 'equalify_iris_save_network' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic PDF tagging', 'equalify-iris' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="network_auto" value="1" <?php checked( Equalify_Iris_Settings::network_auto() ); ?> aria-describedby="equalify-iris-network-auto-help">
								<?php esc_html_e( 'Turn on automatic PDF tagging for every site', 'equalify-iris' ); ?>
							</label>
							<p class="description" id="equalify-iris-network-auto-help">
								<?php esc_html_e( 'Every public PDF on every site is tagged, and site admins no longer see the setting or the notice asking them to turn it on. When this is off, each site decides for itself.', 'equalify-iris' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="equalify-iris-api-url"><?php esc_html_e( 'API address', 'equalify-iris' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="equalify-iris-api-url" name="api_url" value="<?php echo esc_attr( Equalify_Iris_Settings::api_url() ); ?>" aria-describedby="equalify-iris-api-url-help">
							<p class="description" id="equalify-iris-api-url-help">
								<?php
								printf(
									/* translators: %s: the default API address. */
									esc_html__( 'Only change this if you run your own Equalify Iris. Include the version, for example %s', 'equalify-iris' ),
									'<code>' . esc_html( Equalify_Iris_Settings::DEFAULT_API_URL ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="equalify-iris-api-token"><?php esc_html_e( 'Shared API token', 'equalify-iris' ); ?></label></th>
						<td>
							<?php if ( Equalify_Iris_Settings::token_is_from_constant() ) : ?>
								<p><?php esc_html_e( 'Set by EQUALIFY_IRIS_API_TOKEN in wp-config.php, which takes priority over anything entered here.', 'equalify-iris' ); ?></p>
							<?php else : ?>
								<input type="password" class="regular-text code" id="equalify-iris-api-token" name="api_token" value="" autocomplete="off" spellcheck="false" aria-describedby="equalify-iris-api-token-help">
								<p class="description" id="equalify-iris-api-token-help">
									<?php
									echo esc_html(
										$has_token
											? __( 'A token is saved. Enter a new one to replace it, or leave this empty to keep it.', 'equalify-iris' )
											: __( 'Leave this empty unless whoever runs your Equalify Iris gave you a token. The public deployment needs none.', 'equalify-iris' )
									);
									?>
								</p>
								<?php if ( $has_token ) : ?>
									<p><label><input type="checkbox" name="forget_token" value="1"> <?php esc_html_e( 'Remove the saved token', 'equalify-iris' ); ?></label></p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<p class="submit">
					<?php submit_button( __( 'Save Changes', 'equalify-iris' ), 'primary', 'submit', false ); ?>
					<?php submit_button( __( 'Save and check the connection', 'equalify-iris' ), 'secondary', 'check', false ); ?>
				</p>
			</form>

			<?php self::render_job(); ?>

			<?php self::render_sites(); ?>
		</div>
		<?php
	}

	/** Every site's progress, a page at a time. */
	private static function render_sites(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		$query = array( 'network_id' => get_current_network_id() );

		if ( '' !== $search ) {
			$query['search'] = '*' . $search . '*';
		}

		$total = (int) get_sites( $query + array( 'count' => true ) );
		$sites = get_sites(
			$query + array(
				'number' => self::SITES_PER_PAGE,
				'offset' => ( $page - 1 ) * self::SITES_PER_PAGE,
			)
		);
		?>
		<h2><?php esc_html_e( 'Sites', 'equalify-iris' ); ?></h2>

		<form method="get" action="<?php echo esc_url( network_admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<p class="search-box">
				<label class="screen-reader-text" for="equalify-iris-site-search"><?php esc_html_e( 'Search sites', 'equalify-iris' ); ?></label>
				<input type="search" id="equalify-iris-site-search" name="s" value="<?php echo esc_attr( $search ); ?>">
				<?php submit_button( __( 'Search Sites', 'equalify-iris' ), '', '', false ); ?>
			</p>
		</form>

		<?php if ( ! $sites ) : ?>
			<p><?php esc_html_e( 'No sites found.', 'equalify-iris' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Site', 'equalify-iris' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Automatic tagging', 'equalify-iris' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Public PDFs', 'equalify-iris' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tagged', 'equalify-iris' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Being tagged', 'equalify-iris' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Could not be tagged', 'equalify-iris' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sites as $site ) : ?>
					<?php
					switch_to_blog( (int) $site->blog_id );

					$name     = get_bloginfo( 'name' );
					$public   = Equalify_Iris_Discovery::count( 'public' );
					$untagged = Equalify_Iris_Discovery::count( 'untagged' );
					$tagged   = Equalify_Iris_Discovery::count( 'public', Equalify_Iris_Tagger::TAGGED );
					$working  = Equalify_Iris_Discovery::count( 'public', Equalify_Iris_Tagger::QUEUED ) + Equalify_Iris_Discovery::count( 'public', Equalify_Iris_Tagger::WORKING );
					$failed   = Equalify_Iris_Discovery::count( 'public', Equalify_Iris_Tagger::FAILED );
					$auto     = Equalify_Iris_Settings::auto_enabled();
					$indexed  = Equalify_Iris_Discovery::indexed();
					$started  = Equalify_Iris_Discovery::started();

					restore_current_blog();

					$actions = array(
						sprintf(
							'<a href="%s">%s<span class="screen-reader-text"> %s</span></a>',
							esc_url( self::site_page_url( (int) $site->blog_id ) ),
							esc_html__( 'Manage PDFs', 'equalify-iris' ),
							/* translators: %s: a site's name. */
							esc_html( sprintf( __( 'on %s', 'equalify-iris' ), $name ) )
						),
					);

					if ( ! $started ) {
						$actions[] = sprintf(
							'<a href="%s">%s<span class="screen-reader-text"> %s</span></a>',
							esc_url( wp_nonce_url( network_admin_url( 'edit.php?action=equalify_iris_read_site&site=' . (int) $site->blog_id ), 'equalify_iris_read_site_' . (int) $site->blog_id ) ),
							esc_html__( 'Look for PDFs', 'equalify-iris' ),
							/* translators: %s: a site's name. */
							esc_html( sprintf( __( 'on %s', 'equalify-iris' ), $name ) )
						);
					}

					if ( $started && ! $auto && $untagged ) {
						$actions[] = sprintf(
							'<a href="%s">%s<span class="screen-reader-text"> %s</span></a>',
							esc_url( wp_nonce_url( network_admin_url( 'edit.php?action=equalify_iris_tag_site&site=' . (int) $site->blog_id ), 'equalify_iris_tag_site_' . (int) $site->blog_id ) ),
							/* translators: %d: how many PDFs have no tagged copy. */
							esc_html( sprintf( _n( 'Send %d untagged PDF to Iris for Tagging', 'Send %d untagged PDFs to Iris for Tagging', $untagged, 'equalify-iris' ), $untagged ) ),
							/* translators: %s: a site's name. */
							esc_html( sprintf( __( 'on %s', 'equalify-iris' ), $name ) )
						);
					}
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $name ); ?></strong><br>
							<span class="description"><?php echo esc_html( untrailingslashit( $site->domain . $site->path ) ); ?></span><br>
							<?php echo implode( ' | ', $actions ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>
						</td>
						<td><?php echo esc_html( $auto ? __( 'On', 'equalify-iris' ) : __( 'Off', 'equalify-iris' ) ); ?></td>
						<td>
							<?php
							if ( ! $started ) {
								esc_html_e( 'Not read yet', 'equalify-iris' );
							} elseif ( ! $indexed ) {
								/* translators: %s: PDFs found so far. */
								echo esc_html( sprintf( __( '%s so far', 'equalify-iris' ), number_format_i18n( $public ) ) );
							} else {
								echo esc_html( number_format_i18n( $public ) );
							}
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $tagged ) ); ?></td>
						<td><?php echo self::count_link( $working, (int) $site->blog_id, 'working', $name ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></td>
						<td><?php echo self::count_link( $failed, (int) $site->blog_id, 'failed', $name ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$links = paginate_links(
			array(
				'base'    => add_query_arg( array( 'paged' => '%#%', 's' => '' !== $search ? rawurlencode( $search ) : false ), self::network_page_url() ),
				'format'  => '',
				'current' => $page,
				'total'   => (int) ceil( $total / self::SITES_PER_PAGE ),
			)
		);

		if ( $links ) {
			printf(
				'<nav class="tablenav" aria-label="%s"><div class="tablenav-pages">%s</div></nav>',
				esc_attr__( 'Sites pages', 'equalify-iris' ),
				wp_kses_post( $links )
			);
		}
	}

	public static function handle_tag_site(): void {
		self::require_cap( 'manage_network_options' );

		$site_id = isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0;

		check_admin_referer( 'equalify_iris_tag_site_' . $site_id );

		if ( get_site( $site_id ) ) {
			switch_to_blog( $site_id );
			Equalify_Iris_Tagger::queue_all();
			restore_current_blog();
			Equalify_Iris_Runner::wake( $site_id );
		}

		self::redirect( self::network_page_url(), 'queued-all' );
	}

	public static function handle_read_site(): void {
		self::require_cap( 'manage_network_options' );

		$site_id = isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0;

		check_admin_referer( 'equalify_iris_read_site_' . $site_id );

		if ( get_site( $site_id ) ) {
			Equalify_Iris_Runner::wake( $site_id );
		}

		self::redirect( self::network_page_url(), 'reading' );
	}

	/** Whether the background job is running, and keeping up. */
	private static function render_job(): void {
		$last    = Equalify_Iris_Runner::last_run();
		$waiting = Equalify_Iris_Runner::waiting();
		$late    = $waiting && time() - $last > 15 * MINUTE_IN_SECONDS;
		?>
		<h2><?php esc_html_e( 'Background job', 'equalify-iris' ); ?></h2>

		<?php if ( $late ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'The background job has not run for more than 15 minutes, and sites are waiting for it. WP-Cron only runs when the main site is visited; on a large network, or on hosts such as Pantheon, run this every five minutes from a scheduler instead:', 'equalify-iris' ); ?></p>
				<p><code>wp equalify-iris run --url=<?php echo esc_html( untrailingslashit( preg_replace( '#^https?://#', '', network_home_url() ) ) ); ?></code></p>
			</div>
		<?php endif; ?>

		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last run', 'equalify-iris' ); ?></th>
					<td>
						<?php
						echo esc_html(
							$last
								/* translators: %s: how long ago, such as "3 mins". */
								? sprintf( __( '%s ago', 'equalify-iris' ), human_time_diff( $last ) )
								: __( 'Never', 'equalify-iris' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sites waiting for it', 'equalify-iris' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $waiting ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'PDFs at Iris now', 'equalify-iris' ); ?></th>
					<td>
						<?php
						/* translators: 1: PDFs being tagged now, 2: the most at once. */
						echo esc_html( sprintf( __( '%1$s of at most %2$s', 'equalify-iris' ), number_format_i18n( Equalify_Iris_Runner::count_at_iris() ), number_format_i18n( Equalify_Iris_Runner::max_at_iris() ) ) );
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public static function save_network(): void {
		self::require_cap( 'manage_network_options' );
		check_admin_referer( 'equalify_iris_save_network' );

		$url = isset( $_POST['api_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['api_url'] ) ) ) : '';
		Equalify_Iris_Settings::set_api_url( '' !== $url ? $url : Equalify_Iris_Settings::DEFAULT_API_URL );

		if ( ! empty( $_POST['forget_token'] ) ) {
			Equalify_Iris_Settings::set_api_token( '' );
		} elseif ( ! empty( $_POST['api_token'] ) ) {
			Equalify_Iris_Settings::set_api_token( trim( wp_unslash( $_POST['api_token'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		Equalify_Iris_Settings::set_network_auto( ! empty( $_POST['network_auto'] ) );

		$back = self::network_page_url();

		if ( ! empty( $_POST['check'] ) ) {
			$check = Equalify_Iris_API_Client::check();

			if ( ! $check['ok'] ) {
				set_site_transient( 'equalify_iris_check', $check['message'], MINUTE_IN_SECONDS );
				self::redirect( $back, 'check-failed' );
			}

			self::redirect( $back, 'connected' );
		}

		self::redirect( $back, 'saved' );
	}
}
