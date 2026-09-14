<?php
/**
 * WHAT IS THIS FILE?
 *
 * The Settings screen: the connection to Equalify Iris, and how hard the plugin is
 * allowed to work.
 *
 * WHY DOES IT EXIST?
 *
 * Two things belong here and nothing else does.
 *
 *   THE CONNECTION. Usually nothing to configure: Iris has no sign-in, and most
 *   deployments are open to any caller. The panel exists to say so plainly, to
 *   test it, and to hold a shared secret for the minority of deployments that
 *   are closed.
 *
 *   THE RESOURCE LIMITS. The defaults are deliberately gentle — gentle enough that
 *   the plugin is invisible on a shared host. On a network with its own servers, a
 *   super admin can safely turn them up and go several times faster. Both audiences
 *   are real, so the numbers are settings rather than constants.
 *
 * WHAT IS NOT HERE
 *
 * There is no per-site configuration and no per-file configuration anywhere in this
 * plugin. One network, one set of settings, one person who changes them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Admin_Settings {

	public function render(): void {
		$screen = Equalify_Iris_Admin::SLUG . '-settings';

		echo '<div class="wrap equalify-iris">';
		echo '<h1>' . esc_html__( 'Settings', 'equalify-iris' ) . '</h1>';

		Equalify_Iris_Admin::tabs( $screen );
		Equalify_Iris_Admin::notice();

		$this->render_connection( $screen );
		$this->render_limits( $screen );
		$this->render_what_gets_converted( $screen );
		$this->render_contributions();

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// The connection
	// -----------------------------------------------------------------------

	private function render_connection( string $screen ): void {
		echo '<div class="equalify-iris-panel">';
		echo '<h2>' . esc_html__( 'Connection to Equalify Iris', 'equalify-iris' ) . '</h2>';

		echo '<p>' . esc_html__( 'There is nothing to sign into. Equalify Iris has no accounts and no sign-in: it holds its own credentials, and this plugin sends none. On most deployments — including the public one — that means there is nothing to set up here at all.', 'equalify-iris' ) . '</p>';

		$this->render_state( $screen );
		$this->render_api_token( $screen );
		$this->render_api_url( $screen );

		echo '</div>';
	}

	/**
	 * What the last check found, and a button to check again.
	 */
	private function render_state( string $screen ): void {
		$state = Equalify_Iris_Settings::auth_state();
		$login = (string) Equalify_Iris_Settings::get( 'deployment_login' );
		$repo  = (string) Equalify_Iris_Settings::get( 'upstream_repo' );

		echo '<p>';

		switch ( $state ) {
			case Equalify_Iris_Settings::AUTH_OK:
				echo '<span class="equalify-iris-badge equalify-iris-badge-on">' . esc_html__( 'Working', 'equalify-iris' ) . '</span> ';

				echo esc_html(
					Equalify_Iris_Settings::has_api_token()
						? __( 'This deployment is closed, and the token below was accepted.', 'equalify-iris' )
						: __( 'This deployment is open, so no token is needed.', 'equalify-iris' )
				);
				break;

			case Equalify_Iris_Settings::AUTH_NEEDS_TOKEN:
				echo '<span class="equalify-iris-badge equalify-iris-badge-off">' . esc_html__( 'Refusing us', 'equalify-iris' ) . '</span> ';
				echo esc_html__( 'This deployment is closed and needs a shared API token. Nothing will convert until one is entered below.', 'equalify-iris' );
				break;

			case Equalify_Iris_Settings::AUTH_DEPLOYMENT_ERROR:
				echo '<span class="equalify-iris-badge equalify-iris-badge-off">' . esc_html__( 'Server problem', 'equalify-iris' ) . '</span> ';
				echo esc_html__( 'Equalify Iris cannot authenticate itself to GitHub, so it cannot convert anything for anyone right now. This is not a problem with this network and there is nothing to fix here — tell whoever runs Equalify Iris. It often clears up on its own.', 'equalify-iris' );
				break;

			default:
				echo esc_html__( 'Not checked yet.', 'equalify-iris' );
				break;
		}

		echo '</p>';

		if ( $login ) {
			echo '<p class="description">';
			printf(
				/* translators: 1: a GitHub username, 2: a GitHub repository URL. */
				esc_html__( 'Equalify Iris files what it learns as %1$s, in %2$s. That account is the service\'s, not yours — see below.', 'equalify-iris' ),
				'<strong>' . esc_html( $login ) . '</strong>',
				$repo
					? '<a href="' . esc_url( $repo ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $repo ) . '</a>'
					: esc_html__( 'a repository it did not name', 'equalify-iris' )
			);
			echo '</p>';
		}

		Equalify_Iris_Admin::button( 'check_connection', $screen, __( 'Check the connection', 'equalify-iris' ), 'button button-primary' );
	}

	/**
	 * The shared secret, for the minority of deployments that require one.
	 *
	 * Collapsed by default, and deliberately not the first thing on the screen: an
	 * empty field here is the correct configuration for nearly everybody, and a
	 * prominent empty credential box invites an admin to go looking for a
	 * credential that does not exist.
	 */
	private function render_api_token( string $screen ): void {
		$open = Equalify_Iris_Settings::has_api_token()
			|| Equalify_Iris_Settings::AUTH_NEEDS_TOKEN === Equalify_Iris_Settings::auth_state();

		echo '<details class="equalify-iris-advanced"' . ( $open ? ' open' : '' ) . '>';
		echo '<summary>' . esc_html__( 'Shared API token, if this deployment needs one', 'equalify-iris' ) . '</summary>';

		echo '<p>' . esc_html__( 'Some operators close their deployment so that only callers holding a secret may use it. If yours did, they will have given you that secret. It is not a GitHub token and it identifies nobody — everyone who has it is the same caller as far as Equalify Iris is concerned.', 'equalify-iris' ) . '</p>';

		if ( Equalify_Iris_Settings::token_is_from_constant() ) {
			echo '<p class="description">';
			echo esc_html__(
				'The token comes from the EQUALIFY_IRIS_API_TOKEN constant in wp-config.php, which takes priority over anything stored here. That is the better arrangement: the secret lives in a file outside the database, is never shown in the dashboard, and does not travel when the database is copied to a staging site. To change it, edit wp-config.php.',
				'equalify-iris'
			);
			echo '</p>';
			echo '</details>';

			return;
		}

		Equalify_Iris_Admin::form_open( 'save_api_token', $screen );

		echo '<p>';
		echo '<label for="equalify-iris-api-token">' . esc_html__( 'Shared API token', 'equalify-iris' ) . '</label><br>';

		// `password` rather than `text` so it is not readable over a shoulder or in
		// a screen recording. The stored value is deliberately re-rendered so an
		// admin can correct a typo rather than having to retype the whole secret.
		printf(
			'<input type="password" class="regular-text code" id="equalify-iris-api-token" name="api_token" value="%s" autocomplete="off" spellcheck="false">',
			esc_attr( Equalify_Iris_Settings::api_token() )
		);

		echo '</p>';

		echo '<p class="description">' . esc_html__( 'Leave this empty unless you were given a token. Saving it also tests it straight away.', 'equalify-iris' ) . '</p>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save and test token', 'equalify-iris' ) . '</button></p>';

		Equalify_Iris_Admin::form_close();

		if ( Equalify_Iris_Settings::has_api_token() ) {
			echo '<p class="description">' . esc_html__( 'For a more secure setup, put the token in wp-config.php as EQUALIFY_IRIS_API_TOKEN instead — the plugin will use that in preference and stop reading it from the database.', 'equalify-iris' ) . '</p>';

			Equalify_Iris_Admin::button( 'forget_api_token', $screen, __( 'Remove the stored token', 'equalify-iris' ), 'button' );
		}

		echo '</details>';
	}

	/**
	 * The API address.
	 *
	 * Almost nobody needs to change this, so it is inside a collapsed section rather
	 * than in the middle of the screen. It matters for anyone running their own Iris
	 * instance, and for testing against a local one.
	 */
	private function render_api_url( string $screen ): void {
		echo '<details class="equalify-iris-advanced">';
		echo '<summary>' . esc_html__( 'Advanced: where Equalify Iris lives', 'equalify-iris' ) . '</summary>';

		Equalify_Iris_Admin::form_open( 'save_settings', $screen );

		echo '<p>';
		echo '<label for="equalify-iris-api-url">' . esc_html__( 'API address', 'equalify-iris' ) . '</label><br>';
		echo '<input type="url" class="regular-text code" id="equalify-iris-api-url" name="api_url" value="' . esc_attr( (string) Equalify_Iris_Settings::get( 'api_url' ) ) . '">';
		echo '</p>';

		echo '<p class="description">';
		echo esc_html__( 'Only change this if you run your own Equalify Iris. Include the version, for example https://iris.equalify.uic.edu/v1', 'equalify-iris' );
		echo '</p>';

		echo '<p><button type="submit" class="button">' . esc_html__( 'Save address', 'equalify-iris' ) . '</button></p>';

		Equalify_Iris_Admin::form_close();

		echo '</details>';
	}

	// -----------------------------------------------------------------------
	// The resource limits
	// -----------------------------------------------------------------------

	private function render_limits( string $screen ): void {
		echo '<div class="equalify-iris-panel">';
		echo '<h2>' . esc_html__( 'How hard to work', 'equalify-iris' ) . '</h2>';

		echo '<p>';
		echo esc_html__(
			'The plugin works in short bursts a few minutes apart. These numbers cap each burst. The defaults are set low enough that the plugin is invisible on a shared host — if the network has its own servers, you can raise them and go several times faster.',
			'equalify-iris'
		);
		echo '</p>';

		echo '<p class="description">';
		echo esc_html__(
			'Raising these will not always help. Equalify Iris converts two documents at a time for everyone using it, so sending more at once mostly just makes documents queue there instead of here.',
			'equalify-iris'
		);
		echo '</p>';

		Equalify_Iris_Admin::form_open( 'save_settings', $screen );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->number_field(
			'tick_budget_seconds',
			__( 'Seconds of work per burst', 'equalify-iris' ),
			__( 'A burst stops when it runs out of time, always between operations rather than in the middle of one. Keep this well under the server\'s PHP time limit.', 'equalify-iris' ),
			5,
			120
		);

		$this->number_field(
			'max_in_flight',
			__( 'Documents at Equalify Iris at once', 'equalify-iris' ),
			__( 'Equalify Iris converts two at a time, so sending more than two mostly means more documents for us to keep checking on, with nothing finishing any sooner.', 'equalify-iris' ),
			1,
			10
		);

		$this->number_field(
			'uploads_per_tick',
			__( 'Uploads per burst', 'equalify-iris' ),
			__( 'Uploading is the most expensive thing we do. One at a time keeps memory and bandwidth predictable.', 'equalify-iris' ),
			1,
			10
		);

		$this->number_field(
			'status_checks_per_tick',
			__( 'Status checks per burst', 'equalify-iris' ),
			__( 'Cheap requests that ask Equalify Iris how a conversion is going. Checks get further apart the longer a document takes.', 'equalify-iris' ),
			1,
			50
		);

		$this->number_field(
			'imports_per_tick',
			__( 'Pages saved per burst', 'equalify-iris' ),
			__( 'Downloading finished HTML and saving it as a page. Higher means finished documents go live sooner.', 'equalify-iris' ),
			1,
			10
		);

		$this->number_field(
			'posts_per_tick',
			__( 'Posts searched per burst', 'equalify-iris' ),
			__( 'How much of the first search through the network happens each burst. Raising this finishes the search sooner and has no effect on conversion speed.', 'equalify-iris' ),
			1,
			200
		);

		echo '</tbody></table>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save limits', 'equalify-iris' ) . '</button></p>';

		Equalify_Iris_Admin::form_close();

		echo '</div>';
	}

	/** One number input with its explanation. */
	private function number_field( string $key, string $label, string $description, int $min, int $max ): void {
		$id = 'equalify-iris-' . str_replace( '_', '-', $key );

		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td>';

		printf(
			'<input type="number" class="small-text" id="%s" name="%s" value="%s" min="%d" max="%d" step="1">',
			esc_attr( $id ),
			esc_attr( $key ),
			esc_attr( (string) Equalify_Iris_Settings::get( $key ) ),
			$min,
			$max
		);

		printf(
			' <span class="description">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: minimum, 2: maximum. */
					__( '(between %1$d and %2$d)', 'equalify-iris' ),
					$min,
					$max
				)
			)
		);

		echo '<p class="description">' . esc_html( $description ) . '</p>';
		echo '</td>';
		echo '</tr>';
	}

	// -----------------------------------------------------------------------
	// What gets converted
	// -----------------------------------------------------------------------

	private function render_what_gets_converted( string $screen ): void {
		echo '<div class="equalify-iris-panel">';
		echo '<h2>' . esc_html__( 'What gets converted', 'equalify-iris' ) . '</h2>';

		echo '<p>' . esc_html__( 'A PDF is converted when all of these are true:', 'equalify-iris' ) . '</p>';

		echo '<ul class="equalify-iris-rules">';
		echo '<li>' . esc_html__( 'It is linked from a page or post whose status is Published — not draft, not private, not scheduled, and not password-protected.', 'equalify-iris' ) . '</li>';
		echo '<li>' . esc_html__( 'It is in the Media Library of a site on this network. PDFs hosted somewhere else are left alone, because fetching arbitrary addresses from post content would be a security hole.', 'equalify-iris' ) . '</li>';
		echo '<li>' . esc_html__( 'It is 25 pages or fewer, which is the limit Equalify Iris can convert.', 'equalify-iris' ) . '</li>';
		echo '<li>' . esc_html__( 'Its site is not excluded below.', 'equalify-iris' ) . '</li>';
		echo '</ul>';

		echo '<p>' . esc_html__( 'When a PDF stops being linked from anywhere published, its accessible version is unpublished automatically. Republish the page and it comes straight back at the same address.', 'equalify-iris' ) . '</p>';

		Equalify_Iris_Admin::form_open( 'save_settings', $screen );

		// Tells the handler that this particular form contains the checkbox below.
		// See the comment in Equalify_Iris_Admin::do_save_settings().
		echo '<input type="hidden" name="has_auto_process" value="1">';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Watch for new PDFs', 'equalify-iris' ) . '</th>';
		echo '<td>';
		echo '<label for="equalify-iris-auto-process">';
		printf(
			'<input type="checkbox" id="equalify-iris-auto-process" name="auto_process" value="1"%s> ',
			checked( (bool) Equalify_Iris_Settings::get( 'auto_process' ), true, false )
		);
		echo esc_html__( 'Check content for PDFs whenever anyone publishes or updates it', 'equalify-iris' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'This is how PDFs are found after the first search finishes. Turning it off means only the first search ever finds anything, which is almost never what you want.', 'equalify-iris' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="equalify-iris-excluded-sites">' . esc_html__( 'Skip these sites', 'equalify-iris' ) . '</label></th>';
		echo '<td>';
		printf(
			'<input type="text" class="regular-text code" id="equalify-iris-excluded-sites" name="excluded_sites" value="%s">',
			esc_attr( implode( ', ', array_map( 'intval', (array) Equalify_Iris_Settings::get( 'excluded_sites' ) ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Site ID numbers, separated by commas. Find a site\'s ID in Network Admin → Sites. Leave empty to include every site.', 'equalify-iris' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="equalify-iris-excluded-post-types">' . esc_html__( 'Skip these content types', 'equalify-iris' ) . '</label></th>';
		echo '<td>';
		printf(
			'<input type="text" class="regular-text code" id="equalify-iris-excluded-post-types" name="excluded_post_types" value="%s">',
			esc_attr( implode( ', ', array_map( 'sanitize_key', (array) Equalify_Iris_Settings::get( 'excluded_post_types' ) ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Post type names, separated by commas — for example: product, event. Leave empty to search every public content type.', 'equalify-iris' ) . '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</tbody></table>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'equalify-iris' ) . '</button></p>';

		Equalify_Iris_Admin::form_close();

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// The thing everyone should know before switching this on
	// -----------------------------------------------------------------------

	/**
	 * Say plainly that Iris files public GitHub issues about the documents it
	 * converts.
	 *
	 * WHY THIS SECTION EXISTS
	 *
	 * Equalify Iris improves itself by recording what it learns from the documents
	 * it converts, as issues on a public GitHub repository. There is no setting to
	 * turn that off — it is how the service works.
	 *
	 * That is a reasonable design for a service built in the open, and it is also
	 * exactly the sort of thing that must never be a surprise. Someone converting
	 * internal documents deserves to read this before they press Start, not
	 * afterwards. So it is on the screen, in plain words, next to the switch.
	 */
	private function render_contributions(): void {
		echo '<div class="equalify-iris-panel equalify-iris-panel-notice">';
		echo '<h2>' . esc_html__( 'Before you start: what Equalify Iris shares', 'equalify-iris' ) . '</h2>';

		$repo  = (string) Equalify_Iris_Settings::get( 'upstream_repo' );
		$login = (string) Equalify_Iris_Settings::get( 'deployment_login' );

		echo '<p>';

		if ( $repo ) {
			printf(
				/* translators: %s: a GitHub repository URL. */
				esc_html__( 'Equalify Iris learns from the documents it converts, and records what it learns as issues on %s. Those issues can include short extracts from the documents processed. This cannot be turned off — it is part of how the service is built.', 'equalify-iris' ),
				'<a href="' . esc_url( $repo ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $repo ) . '</a>'
			);
		} else {
			echo esc_html__(
				'Equalify Iris learns from the documents it converts, and records what it learns as issues on a public GitHub repository. Those issues can include short extracts from the documents processed. This cannot be turned off — it is part of how the service is built.',
				'equalify-iris'
			);
		}

		echo '</p>';

		// Worth its own paragraph because it cuts both ways, and an admin weighing
		// this up should have both halves. Nothing identifies this network in what
		// Iris files — which is a privacy gain — but it also means the institution
		// gets no credit for what it contributes back.
		echo '<p>';

		if ( $login ) {
			printf(
				/* translators: %s: a GitHub username. */
				esc_html__( 'Those issues are filed under the Equalify Iris deployment\'s own account, %s, not under this network\'s name or the name of whoever set this up. Nothing in them identifies this site as the source, and equally, this institution is not credited for what it contributes.', 'equalify-iris' ),
				'<strong>' . esc_html( $login ) . '</strong>'
			);
		} else {
			echo esc_html__( 'Those issues are filed under the Equalify Iris deployment\'s own account, not under this network\'s name. Nothing in them identifies this site as the source, and equally, this institution is not credited for what it contributes.', 'equalify-iris' );
		}

		echo '</p>';

		echo '<p>';
		echo esc_html__(
			'For public documents on a public website, which is what this plugin is for, that is usually fine. If any PDF on this network is confidential, exclude its site above, or ask whoever runs Equalify Iris about a private deployment.',
			'equalify-iris'
		);
		echo '</p>';

		echo '</div>';
	}
}
