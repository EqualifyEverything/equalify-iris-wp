<?php
/**
 * WHAT IS THIS FILE?
 *
 * The Network Admin interface: the menu, the four screens, and every button.
 *
 * WHY DOES IT EXIST?
 *
 * Because a super admin needs to be able to start the process, stop it, see how it
 * is going, and find out why it is not going. Nothing else. There is no per-site
 * screen and no per-file control anywhere in this plugin — the whole thing is
 * operated from one place by one kind of person.
 *
 * WHERE THE SCREENS LIVE
 *
 * Network Admin → Equalify Iris
 *   Overview   Start, stop, and how it is going.
 *   Documents  Every PDF we know about and what happened to it.
 *   Settings   The connection, and the resource limits.
 *   Log        What happened recently, in sentences.
 *
 * TWO RULES THAT EVERY ACTION IN THIS FILE FOLLOWS
 *
 * 1. `manage_network_options` is checked on every single action. That is the
 *    capability only super admins have. Checking it once when drawing the menu is
 *    not enough — anyone can send a POST request to an admin URL without ever
 *    seeing the menu.
 *
 * 2. Every action carries a nonce, and every action verifies it. A nonce proves the
 *    request came from a form we drew, not from a link in an email that happens to
 *    stop the whole network's processing.
 *
 * POST-REDIRECT-GET
 *
 * Actions never draw a page. They do the work, then redirect back with a short
 * message code in the URL. That means refreshing the page after pressing a button
 * cannot repeat the action, and the browser's back button behaves normally.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Admin {

	/** The capability required for everything in this file. */
	const CAPABILITY = 'manage_network_options';

	/** The one nonce action name used by every form. */
	const NONCE = 'equalify_iris_admin';

	/** The top-level menu slug, which is also the Overview screen. */
	const SLUG = 'equalify-iris';

	private Equalify_Iris_Plugin $plugin;

	public function __construct( Equalify_Iris_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function init(): void {
		add_action( 'network_admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_equalify_iris_action', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Add the menu, in Network Admin only.
	 *
	 * `network_admin_menu` rather than `admin_menu` is the whole reason this appears
	 * where it does. Using `admin_menu` would put it on every site's dashboard, which
	 * is exactly what we do not want.
	 */
	public function add_menu(): void {
		$overview = add_menu_page(
			__( 'Equalify Iris', 'equalify-iris' ),
			__( 'Equalify Iris', 'equalify-iris' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_overview' ),
			'dashicons-universal-access-alt',
			30
		);

		add_submenu_page(
			self::SLUG,
			__( 'Overview', 'equalify-iris' ),
			__( 'Overview', 'equalify-iris' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_overview' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Documents', 'equalify-iris' ),
			__( 'Documents', 'equalify-iris' ),
			self::CAPABILITY,
			self::SLUG . '-documents',
			array( $this, 'render_documents' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'equalify-iris' ),
			__( 'Settings', 'equalify-iris' ),
			self::CAPABILITY,
			self::SLUG . '-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Activity Log', 'equalify-iris' ),
			__( 'Activity Log', 'equalify-iris' ),
			self::CAPABILITY,
			self::SLUG . '-log',
			array( $this, 'render_log' )
		);

		unset( $overview );
	}

	/** Load the admin stylesheet on our screens only. */
	public function enqueue_styles( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'equalify-iris-admin',
			EQUALIFY_IRIS_URL . 'assets/css/admin.css',
			array(),
			EQUALIFY_IRIS_VERSION
		);
	}

	// -----------------------------------------------------------------------
	// Screens
	// -----------------------------------------------------------------------

	public function render_overview(): void {
		$this->guard();
		( new Equalify_Iris_Admin_Overview( $this->plugin ) )->render();
	}

	public function render_documents(): void {
		$this->guard();
		( new Equalify_Iris_Admin_Documents() )->render();
	}

	public function render_settings(): void {
		$this->guard();
		( new Equalify_Iris_Admin_Settings() )->render();
	}

	public function render_log(): void {
		$this->guard();
		( new Equalify_Iris_Admin_Log() )->render();
	}

	/** Refuse to draw anything for someone who should not see it. */
	private function guard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Equalify Iris.', 'equalify-iris' ) );
		}
	}

	// -----------------------------------------------------------------------
	// Buttons
	// -----------------------------------------------------------------------

	/**
	 * Every button on every screen arrives here.
	 *
	 * One handler rather than one per action, so the permission check and the nonce
	 * check are written once and cannot be forgotten on a new button.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Equalify Iris.', 'equalify-iris' ) );
		}

		check_admin_referer( self::NONCE );

		$action = isset( $_POST['equalify_iris_action'] ) ? sanitize_key( wp_unslash( $_POST['equalify_iris_action'] ) ) : '';
		$screen = isset( $_POST['equalify_iris_screen'] ) ? sanitize_key( wp_unslash( $_POST['equalify_iris_screen'] ) ) : self::SLUG;

		$notice = '';

		switch ( $action ) {
			case 'start':
				$notice = $this->do_start();
				break;

			case 'stop':
				Equalify_Iris_Settings::set( 'running', false );
				Equalify_Iris_Logger::log( __( 'A super admin turned processing off.', 'equalify-iris' ) );
				$notice = 'stopped';
				break;

			case 'run_now':
				$this->plugin->scheduler->run_now();
				$notice = 'ran';
				break;

			case 'restart_search':
				Equalify_Iris_Sweeper::reset();
				Equalify_Iris_Logger::log( __( 'A super admin restarted the search for existing PDFs.', 'equalify-iris' ) );
				$notice = 'search_restarted';
				break;

			case 'check_connection':
				$notice = $this->do_check_connection();
				break;

			case 'save_api_token':
				$notice = $this->do_save_api_token();
				break;

			case 'forget_api_token':
				Equalify_Iris_Settings::forget_api_token();
				Equalify_Iris_Logger::log( __( 'A super admin removed the stored Equalify Iris API token.', 'equalify-iris' ) );
				$notice = 'token_forgotten';
				break;

			case 'save_settings':
				$notice = $this->do_save_settings();
				break;

			case 'retry':
				$notice = $this->do_retry();
				break;

			case 'retry_all_failed':
				$notice = $this->do_retry_all_failed();
				break;

			case 'clear_log':
				Equalify_Iris_Logger::clear();
				$notice = 'log_cleared';
				break;

			default:
				$notice = 'unknown';
				break;
		}

		$this->redirect_back( $screen, $notice );
	}

	/** Turn processing on, refusing only if Iris is actually shutting us out. */
	private function do_start(): string {
		if ( Equalify_Iris_Settings::blocked_by_auth() ) {
			return 'needs_token';
		}

		if ( ! Equalify_Iris_Database::tables_exist() ) {
			Equalify_Iris_Database::install();
		}

		Equalify_Iris_Settings::set( 'running', true );
		Equalify_Iris_Scheduler::schedule();

		Equalify_Iris_Logger::log( __( 'A super admin turned processing on.', 'equalify-iris' ) );

		return 'started';
	}

	/**
	 * Ask Iris whether it will accept us, and remember the answer.
	 *
	 * This is what the old two-step GitHub sign-in collapsed into. There is nothing
	 * to approve in a browser any more: one request either comes back 200, in which
	 * case we are usable, or 401, in which case handle() has already worked out
	 * which of the two kinds of 401 it was and said so in the log.
	 */
	private function do_check_connection(): string {
		$result = Equalify_Iris_API_Client::check_connection();

		if ( is_wp_error( $result ) ) {
			// Not logged here: handle() logs a 401 with the distinction intact, and
			// logging again would put two sentences about one event in the log.
			return 'equalify_iris_needs_token' === $result->get_error_code()
				? 'needs_token'
				: 'check_failed';
		}

		Equalify_Iris_Logger::log(
			sprintf(
				/* translators: 1: open or closed, 2: a GitHub repository URL. */
				__( 'Checked Equalify Iris: the deployment is %1$s and files contributions to %2$s.', 'equalify-iris' ),
				'gated' === $result['mode'] ? __( 'closed, and accepted our token', 'equalify-iris' ) : __( 'open', 'equalify-iris' ),
				$result['upstream_repo'] ?: __( 'an unnamed repository', 'equalify-iris' )
			)
		);

		return 'check_ok';
	}

	/**
	 * Store the shared secret for a closed deployment, then immediately test it.
	 *
	 * Testing straight away is the point. A secret that is wrong is indistinguishable
	 * from one that is right until something uses it, and the next thing to use it
	 * would otherwise be a background tick nobody is watching.
	 */
	private function do_save_api_token(): string {
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle_action().

		$token = isset( $post['api_token'] ) ? trim( (string) $post['api_token'] ) : '';

		Equalify_Iris_Settings::set( 'api_token', $token );

		// A new secret makes any previous refusal meaningless, so clear it before
		// testing — otherwise a correct token would be saved while the worker stayed
		// blocked by the verdict on the old one.
		Equalify_Iris_Settings::set_auth_state( Equalify_Iris_Settings::AUTH_UNKNOWN );

		Equalify_Iris_Logger::log( __( 'A super admin saved an Equalify Iris API token.', 'equalify-iris' ) );

		return $this->do_check_connection();
	}

	/**
	 * Save the settings form.
	 *
	 * Every number is clamped to a range rather than trusted. The limits here are
	 * the difference between a plugin a host tolerates and one they block, so a
	 * typed zero or a pasted 100000 must not become the live setting.
	 */
	private function do_save_settings(): string {
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle_action().

		$numbers = array(
			// key                    min  max
			'max_in_flight'         => array( 1, 10 ),
			'uploads_per_tick'      => array( 1, 10 ),
			'status_checks_per_tick' => array( 1, 50 ),
			'imports_per_tick'      => array( 1, 10 ),
			'posts_per_tick'        => array( 1, 200 ),
			'tick_budget_seconds'   => array( 5, 120 ),
		);

		foreach ( $numbers as $key => $range ) {
			if ( ! isset( $post[ $key ] ) ) {
				continue;
			}

			$value = (int) $post[ $key ];
			$value = max( $range[0], min( $range[1], $value ) );

			Equalify_Iris_Settings::set( $key, $value );
		}

		// An unchecked checkbox sends nothing at all, so "absent" and "off" look
		// identical. Without the marker below, saving the limits form — which has no
		// checkbox on it — would read auto_process as off and quietly stop the plugin
		// noticing new PDFs. The marker says "this form had that checkbox on it".
		if ( ! empty( $post['has_auto_process'] ) ) {
			Equalify_Iris_Settings::set( 'auto_process', ! empty( $post['auto_process'] ) );
		}

		if ( isset( $post['api_url'] ) ) {
			$url = esc_url_raw( trim( (string) $post['api_url'] ) );

			if ( $url ) {
				Equalify_Iris_Settings::set( 'api_url', untrailingslashit( $url ) );
			}
		}

		// Excluded sites arrive as a comma-separated list of ids, because a
		// multi-select of 200 sites is unusable and a checkbox per site is worse.
		if ( isset( $post['excluded_sites'] ) ) {
			$ids = array_filter( array_map( 'intval', preg_split( '#[^0-9]+#', (string) $post['excluded_sites'] ) ?: array() ) );

			Equalify_Iris_Settings::set( 'excluded_sites', array_values( array_unique( $ids ) ) );
		}

		if ( isset( $post['excluded_post_types'] ) ) {
			$types = array_filter( array_map( 'sanitize_key', preg_split( '#[\s,]+#', (string) $post['excluded_post_types'] ) ?: array() ) );

			Equalify_Iris_Settings::set( 'excluded_post_types', array_values( array_unique( $types ) ) );
		}

		return 'settings_saved';
	}

	/** Retry one document. */
	private function do_retry(): string {
		$id = isset( $_POST['document_id'] ) ? (int) $_POST['document_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle_action().

		if ( ! $id ) {
			return 'unknown';
		}

		Equalify_Iris_Documents::retry( $id );

		return 'retried';
	}

	/** Retry every failed document. */
	private function do_retry_all_failed(): string {
		$failed = Equalify_Iris_Documents::query(
			array(
				'status'   => Equalify_Iris_Documents::FAILED,
				'per_page' => 1000,
			)
		);

		foreach ( $failed['items'] as $document ) {
			Equalify_Iris_Documents::retry( (int) $document->id );
		}

		return 'retried_all';
	}

	/** Go back to the screen the button was on, carrying a message code. */
	private function redirect_back( string $screen, string $notice ): void {
		$url = add_query_arg(
			array(
				'page'                 => $screen,
				'equalify_iris_notice' => $notice,
			),
			network_admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	// -----------------------------------------------------------------------
	// Shared pieces the screens use
	// -----------------------------------------------------------------------

	/**
	 * Print the tab bar.
	 */
	public static function tabs( string $current ): void {
		$tabs = array(
			self::SLUG              => __( 'Overview', 'equalify-iris' ),
			self::SLUG . '-documents' => __( 'Documents', 'equalify-iris' ),
			self::SLUG . '-settings' => __( 'Settings', 'equalify-iris' ),
			self::SLUG . '-log'     => __( 'Activity Log', 'equalify-iris' ),
		);

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Equalify Iris screens', 'equalify-iris' ) . '">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( network_admin_url( 'admin.php?page=' . $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Print the message left by the last button press.
	 *
	 * WHY A LOOKUP TABLE AND NOT THE MESSAGE IN THE URL?
	 *
	 * Because anything in a URL can be edited. Putting the text in the URL would let
	 * anyone send a super admin a link that displays whatever official-looking
	 * message they liked inside the WordPress dashboard.
	 */
	public static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only display of a fixed message.
		$code = isset( $_GET['equalify_iris_notice'] ) ? sanitize_key( wp_unslash( $_GET['equalify_iris_notice'] ) ) : '';

		if ( ! $code ) {
			return;
		}

		$messages = array(
			'started'           => array( 'success', __( 'Processing is on. The first batch runs within five minutes.', 'equalify-iris' ) ),
			'stopped'           => array( 'success', __( 'Processing is off. Nothing has been lost — documents already with Equalify Iris will be collected when you start again.', 'equalify-iris' ) ),
			'ran'               => array( 'success', __( 'Ran one batch. See the activity log for what it did.', 'equalify-iris' ) ),
			'search_restarted'  => array( 'success', __( 'The search for existing PDFs will start again from the first site.', 'equalify-iris' ) ),
			'check_ok'          => array( 'success', __( 'Equalify Iris will accept us. Details are below and in the activity log.', 'equalify-iris' ) ),
			'check_failed'      => array( 'error', __( 'Could not check Equalify Iris. The activity log has the reason.', 'equalify-iris' ) ),
			'needs_token'       => array( 'error', __( 'This Equalify Iris deployment is closed and needs a shared API token. Ask whoever runs it for the token, then enter it below.', 'equalify-iris' ) ),
			'token_forgotten'   => array( 'success', __( 'The stored API token has been removed.', 'equalify-iris' ) ),
			'settings_saved'    => array( 'success', __( 'Settings saved.', 'equalify-iris' ) ),
			'retried'           => array( 'success', __( 'That document is back in the queue.', 'equalify-iris' ) ),
			'retried_all'       => array( 'success', __( 'Every failed document is back in the queue.', 'equalify-iris' ) ),
			'log_cleared'       => array( 'success', __( 'Activity log cleared.', 'equalify-iris' ) ),
			'unknown'           => array( 'error', __( 'That did not work. Nothing has been changed.', 'equalify-iris' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Open a form that posts one action.
	 *
	 * Every button in this plugin is a form, not a link. A link that changes
	 * something can be triggered by anything that fetches URLs — a browser
	 * prefetcher, a security scanner, an email preview. "Stop processing" firing
	 * because someone's mail client looked at a link is a real class of bug.
	 */
	public static function form_open( string $action, string $screen ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="equalify_iris_action">';
		echo '<input type="hidden" name="equalify_iris_action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="equalify_iris_screen" value="' . esc_attr( $screen ) . '">';

		wp_nonce_field( self::NONCE );
	}

	/** Close a form. */
	public static function form_close(): void {
		echo '</form>';
	}

	/** A one-button form. */
	public static function button( string $action, string $screen, string $label, string $class = 'button' ): void {
		self::form_open( $action, $screen );
		printf(
			'<button type="submit" class="%s">%s</button>',
			esc_attr( $class ),
			esc_html( $label )
		);
		self::form_close();
	}
}
