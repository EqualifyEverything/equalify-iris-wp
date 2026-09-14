<?php
/**
 * WHAT IS THIS FILE?
 *
 * The Overview screen: the one place a super admin starts and stops the process and
 * sees how it is going.
 *
 * WHY DOES IT EXIST?
 *
 * Because this plugin does its work over days and weeks, out of sight. A screen
 * that only said "running" would be useless — the question a super admin actually
 * has is "is it making progress, and if not, why not?"
 *
 * So this screen is built around answering that. The order of what you see is
 * deliberate:
 *
 *   1. Anything that is wrong, at the top, in a sentence, with what to do about it.
 *   2. The switch.
 *   3. Progress, with real numbers.
 *   4. The detail, for when someone wants it.
 *
 * WHY DOES IT MAKE SUCH A FUSS ABOUT WP-CRON?
 *
 * Because "nothing is happening" has one overwhelmingly common cause on WordPress:
 * nothing is triggering the background job. From inside the dashboard, a network
 * with no visitors and a network with a broken cron look identical, and both look
 * identical to a plugin that is silently failing. Naming the cause and printing the
 * exact line of cron to add is the single most useful thing this screen does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Admin_Overview {

	private Equalify_Iris_Plugin $plugin;

	public function __construct( Equalify_Iris_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function render(): void {
		$running = (bool) Equalify_Iris_Settings::get( 'running' );
		$blocked = Equalify_Iris_Settings::blocked_by_auth();
		$counts  = Equalify_Iris_Documents::counts_by_status();

		echo '<div class="wrap equalify-iris">';
		echo '<h1>' . esc_html__( 'Equalify Iris', 'equalify-iris' ) . '</h1>';

		Equalify_Iris_Admin::tabs( Equalify_Iris_Admin::SLUG );
		Equalify_Iris_Admin::notice();

		echo '<p class="equalify-iris-intro">';
		echo esc_html__(
			'This plugin finds every PDF linked from published pages across the network, converts each one into an accessible HTML page, and puts a link to that page next to the original PDF. People who use screen readers get a version they can actually read.',
			'equalify-iris'
		);
		echo '</p>';

		$this->render_problems( $running, $blocked );
		$this->render_switch( $running, $blocked );
		$this->render_progress( $counts );
		$this->render_details();

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// Anything that is wrong
	// -----------------------------------------------------------------------

	/**
	 * Print every problem we can detect, each with the thing to do about it.
	 */
	private function render_problems( bool $running, bool $blocked ): void {
		$problems = array();

		if ( ! is_multisite() ) {
			$problems[] = __( 'This plugin needs a multisite network. It has nothing to manage on a single site.', 'equalify-iris' );
		}

		if ( ! Equalify_Iris_Database::tables_exist() ) {
			$problems[] = __( 'The plugin\'s database tables are missing. Deactivate and reactivate the plugin in Network Admin → Plugins to create them.', 'equalify-iris' );
		}

		if ( $blocked ) {
			$problems[] = sprintf(
				/* translators: %s: a link to the Settings screen. */
				__( 'Equalify Iris is refusing us: this deployment is closed and needs a shared API token. Ask whoever runs it for the token, then %s to enter it.', 'equalify-iris' ),
				'<a href="' . esc_url( network_admin_url( 'admin.php?page=' . Equalify_Iris_Admin::SLUG . '-settings' ) ) . '">' . esc_html__( 'go to Settings', 'equalify-iris' ) . '</a>'
			);
		}

		// A fault at the deployment's own end. Said plainly, and said as somebody
		// else's problem, because the worst outcome here is a super admin spending
		// an afternoon looking for a misconfiguration on their own network.
		if ( Equalify_Iris_Settings::AUTH_DEPLOYMENT_ERROR === Equalify_Iris_Settings::auth_state() ) {
			$problems[] = __( 'Equalify Iris cannot authenticate itself to GitHub, so it is not converting anything for anyone. Nothing on this network needs fixing — tell whoever runs Equalify Iris. It often clears up by itself.', 'equalify-iris' );
		}

		$until = Equalify_Iris_API_Client::circuit_open_until();

		if ( $until ) {
			$problems[] = sprintf(
				/* translators: %s: a human-readable time difference, such as "12 minutes". */
				__( 'Paused after repeated problems reaching Equalify Iris. It will try again in about %s. The activity log has the reason.', 'equalify-iris' ),
				human_time_diff( time(), $until )
			);
		}

		// The cron check only applies when we are supposed to be working. Warning
		// about a stalled background job while processing is deliberately off would
		// be noise, and noise is how real warnings get ignored.
		if ( $running ) {
			$last = (int) Equalify_Iris_Settings::get( 'last_tick' );

			if ( ! $last ) {
				$problems[] = __( 'Processing is on, but the background job has never run. See "If nothing is happening" below.', 'equalify-iris' );
			} elseif ( ( time() - $last ) > 3 * HOUR_IN_SECONDS ) {
				$problems[] = sprintf(
					/* translators: %s: a human-readable time difference, such as "2 days". */
					__( 'Processing is on, but the background job has not run for %s. See "If nothing is happening" below.', 'equalify-iris' ),
					human_time_diff( $last )
				);
			}
		}

		if ( ! $problems ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>';
		echo esc_html__( 'Needs attention', 'equalify-iris' );
		echo '</strong></p><ul class="equalify-iris-problems">';

		foreach ( $problems as $problem ) {
			// wp_kses_post because a couple of these sentences contain a link we
			// built ourselves. Everything variable inside them was escaped above.
			echo '<li>' . wp_kses_post( $problem ) . '</li>';
		}

		echo '</ul></div>';
	}

	// -----------------------------------------------------------------------
	// The switch
	// -----------------------------------------------------------------------

	private function render_switch( bool $running, bool $blocked ): void {
		echo '<div class="equalify-iris-panel">';
		echo '<h2>' . esc_html__( 'Processing', 'equalify-iris' ) . '</h2>';

		echo '<p class="equalify-iris-state">';

		if ( $running ) {
			echo '<span class="equalify-iris-badge equalify-iris-badge-on">' . esc_html__( 'ON', 'equalify-iris' ) . '</span> ';
			echo esc_html__( 'Finding and converting PDFs in small batches, a few minutes apart.', 'equalify-iris' );
		} else {
			echo '<span class="equalify-iris-badge equalify-iris-badge-off">' . esc_html__( 'OFF', 'equalify-iris' ) . '</span> ';
			echo esc_html__( 'Nothing is being converted. Pages already published stay published.', 'equalify-iris' );
		}

		echo '</p>';

		echo '<div class="equalify-iris-buttons">';

		if ( $running ) {
			Equalify_Iris_Admin::button(
				'stop',
				Equalify_Iris_Admin::SLUG,
				__( 'Stop processing', 'equalify-iris' ),
				'button button-secondary'
			);
		} else {
			Equalify_Iris_Admin::button(
				'start',
				Equalify_Iris_Admin::SLUG,
				$blocked ? __( 'Start processing (needs a token first)', 'equalify-iris' ) : __( 'Start processing', 'equalify-iris' ),
				'button button-primary'
			);
		}

		Equalify_Iris_Admin::button(
			'run_now',
			Equalify_Iris_Admin::SLUG,
			__( 'Run one batch now', 'equalify-iris' ),
			'button'
		);

		echo '</div>';

		echo '<p class="description">';
		echo esc_html__(
			'Stopping is always safe. Documents already with Equalify Iris keep converting there, and are collected the next time you start. Nothing in the queue is lost, and no converted page is unpublished.',
			'equalify-iris'
		);
		echo '</p>';

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// Progress
	// -----------------------------------------------------------------------

	private function render_progress( array $counts ): void {
		echo '<div class="equalify-iris-panel">';
		echo '<h2>' . esc_html__( 'Progress', 'equalify-iris' ) . '</h2>';

		$this->render_sweep_progress();
		$this->render_counts( $counts );
		$this->render_estimate( $counts );

		echo '</div>';
	}

	/**
	 * How far through the first search of the network we are.
	 */
	private function render_sweep_progress(): void {
		if ( Equalify_Iris_Sweeper::is_complete() ) {
			echo '<p><strong>' . esc_html__( 'First search: complete.', 'equalify-iris' ) . '</strong> ';
			echo esc_html__( 'Every site has been searched once. New PDFs are now picked up automatically whenever anyone publishes or updates content.', 'equalify-iris' );
			echo '</p>';

			return;
		}

		$cursor = Equalify_Iris_Sweeper::cursor();

		echo '<p><strong>' . esc_html__( 'First search: in progress.', 'equalify-iris' ) . '</strong> ';

		if ( $cursor['site_id'] ) {
			printf(
				/* translators: 1: site id, 2: post id. */
				esc_html__( 'Currently on site %1$d, up to post %2$d.', 'equalify-iris' ),
				(int) $cursor['site_id'],
				(int) $cursor['post_id']
			);
		} else {
			echo esc_html__( 'Not started yet — it begins on the next batch.', 'equalify-iris' );
		}

		echo '</p>';

		echo '<p class="description">';
		echo esc_html__(
			'The search walks every site in order, a few posts at a time, and remembers where it got to. It is safe to interrupt: it always resumes from where it stopped, and never searches the same post twice or skips one.',
			'equalify-iris'
		);
		echo '</p>';
	}

	/**
	 * The count in each status, as a table.
	 */
	private function render_counts( array $counts ): void {
		echo '<table class="widefat striped equalify-iris-counts">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'How many PDFs are in each stage', 'equalify-iris' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Stage', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'PDFs', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What it means', 'equalify-iris' ) . '</th>';
		echo '</tr></thead><tbody>';

		$explanations = array(
			Equalify_Iris_Documents::PENDING    => __( 'Found, waiting their turn.', 'equalify-iris' ),
			Equalify_Iris_Documents::CHECKING   => __( 'Being measured before upload.', 'equalify-iris' ),
			Equalify_Iris_Documents::UPLOADING  => __( 'Being sent to Equalify Iris.', 'equalify-iris' ),
			Equalify_Iris_Documents::CONVERTING => __( 'Equalify Iris is working on these. This is the slow part — minutes each.', 'equalify-iris' ),
			Equalify_Iris_Documents::READY      => __( 'Converted, waiting to be saved as a page.', 'equalify-iris' ),
			Equalify_Iris_Documents::IMPORTING  => __( 'Being saved as a page right now.', 'equalify-iris' ),
			Equalify_Iris_Documents::PUBLISHED  => __( 'Done. An accessible version is live and the icon appears next to the PDF.', 'equalify-iris' ),
			Equalify_Iris_Documents::RETIRED    => __( 'No longer linked from any published page, so the accessible version was unpublished. It comes straight back if the page is republished.', 'equalify-iris' ),
			Equalify_Iris_Documents::TOO_LONG   => __( 'Longer than 25 pages, which is more than Equalify Iris can convert. These need splitting by hand, or another approach.', 'equalify-iris' ),
			Equalify_Iris_Documents::TOO_BIG    => __( 'Larger than the file size limit.', 'equalify-iris' ),
			Equalify_Iris_Documents::FAILED     => __( 'Something went wrong several times. Worth retrying — see the Documents screen for the reason.', 'equalify-iris' ),
			Equalify_Iris_Documents::SKIPPED    => __( 'Deliberately excluded by a setting.', 'equalify-iris' ),
		);

		foreach ( Equalify_Iris_Documents::status_labels() as $status => $label ) {
			$count = (int) $counts[ $status ];

			// Zero rows are hidden unless they matter. A table with eleven rows of
			// "0" makes the two numbers that are not zero much harder to find.
			$always_show = in_array(
				$status,
				array(
					Equalify_Iris_Documents::PENDING,
					Equalify_Iris_Documents::CONVERTING,
					Equalify_Iris_Documents::PUBLISHED,
					Equalify_Iris_Documents::FAILED,
				),
				true
			);

			if ( 0 === $count && ! $always_show ) {
				continue;
			}

			echo '<tr>';
			echo '<th scope="row">' . esc_html( $label ) . '</th>';
			echo '<td><strong>' . esc_html( number_format_i18n( $count ) ) . '</strong></td>';
			echo '<td>' . esc_html( $explanations[ $status ] ?? '' ) . '</td>';
			echo '</tr>';
		}

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Total PDFs found', 'equalify-iris' ) . '</th>';
		echo '<td><strong>' . esc_html( number_format_i18n( (int) $counts['total'] ) ) . '</strong></td>';
		echo '<td></td>';
		echo '</tr>';

		echo '</tbody></table>';
	}

	/**
	 * How long the rest is likely to take.
	 *
	 * MEASURED, NOT CALCULATED.
	 *
	 * A calculated estimate would need to know each PDF's page count, how busy the
	 * shared Iris deployment is, and how often cron actually fires — none of which we
	 * can know. So we count what really finished in the last day and divide. That is
	 * a genuinely useful number, and it is honest about being a rough one.
	 */
	private function render_estimate( array $counts ): void {
		$remaining = (int) $counts[ Equalify_Iris_Documents::PENDING ]
			+ (int) $counts[ Equalify_Iris_Documents::CONVERTING ]
			+ (int) $counts[ Equalify_Iris_Documents::READY ];

		if ( ! $remaining ) {
			return;
		}

		$per_day = Equalify_Iris_Documents::published_in_last_day();

		if ( $per_day < 1 ) {
			echo '<p>';
			printf(
				/* translators: %s: a formatted number. */
				esc_html__( '%s PDFs still to convert. Nothing has finished in the last day, so there is no rate to estimate from yet.', 'equalify-iris' ),
				esc_html( number_format_i18n( $remaining ) )
			);
			echo '</p>';

			return;
		}

		$days = (int) ceil( $remaining / $per_day );

		echo '<p>';
		printf(
			/* translators: 1: number of PDFs, 2: number finished per day, 3: a rough number of days. */
			esc_html__( '%1$s PDFs still to convert. At the recent rate of %2$s a day, that is roughly %3$s more days.', 'equalify-iris' ),
			esc_html( number_format_i18n( $remaining ) ),
			esc_html( number_format_i18n( $per_day ) ),
			esc_html( number_format_i18n( $days ) )
		);
		echo '</p>';

		echo '<p class="description">';
		echo esc_html__(
			'This is measured from what actually finished recently, not calculated, so it moves around. Equalify Iris converts two documents at a time for everyone using it, which is the real limit on speed.',
			'equalify-iris'
		);
		echo '</p>';
	}

	// -----------------------------------------------------------------------
	// Detail
	// -----------------------------------------------------------------------

	private function render_details(): void {
		echo '<div class="equalify-iris-panel">';
		echo '<h2>' . esc_html__( 'The background job', 'equalify-iris' ) . '</h2>';

		$next = Equalify_Iris_Scheduler::next_run();
		$last = (int) Equalify_Iris_Settings::get( 'last_tick' );

		echo '<table class="widefat striped">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Background job details', 'equalify-iris' ) . '</caption>';
		echo '<tbody>';

		$this->row(
			__( 'Last run', 'equalify-iris' ),
			$last
				? sprintf(
					/* translators: %s: a human-readable time difference. */
					__( '%s ago', 'equalify-iris' ),
					human_time_diff( $last )
				)
				: __( 'Never', 'equalify-iris' )
		);

		$this->row(
			__( 'Next run', 'equalify-iris' ),
			$next
				? sprintf(
					/* translators: %s: a human-readable time difference. */
					__( 'in %s', 'equalify-iris' ),
					human_time_diff( time(), $next )
				)
				: __( 'Not scheduled', 'equalify-iris' )
		);

		$this->row(
			__( 'Running right now', 'equalify-iris' ),
			Equalify_Iris_Scheduler::is_locked() ? __( 'Yes', 'equalify-iris' ) : __( 'No', 'equalify-iris' )
		);

		$this->row(
			__( 'Each run does at most', 'equalify-iris' ),
			sprintf(
				/* translators: 1: seconds, 2: uploads, 3: status checks, 4: imports, 5: posts. */
				__( '%1$d seconds of work: %2$d upload, %3$d status checks, %4$d saved pages, %5$d posts searched.', 'equalify-iris' ),
				(int) Equalify_Iris_Settings::get( 'tick_budget_seconds' ),
				(int) Equalify_Iris_Settings::get( 'uploads_per_tick' ),
				(int) Equalify_Iris_Settings::get( 'status_checks_per_tick' ),
				(int) Equalify_Iris_Settings::get( 'imports_per_tick' ),
				(int) Equalify_Iris_Settings::get( 'posts_per_tick' )
			)
		);

		echo '</tbody></table>';

		$this->render_cron_help();

		echo '<h3>' . esc_html__( 'Start the first search again', 'equalify-iris' ) . '</h3>';
		echo '<p>';
		echo esc_html__(
			'Searches every site from the beginning. Useful after adding sites, or if you think something was missed. PDFs already converted are not converted again — they are recognised and left alone.',
			'equalify-iris'
		);
		echo '</p>';

		Equalify_Iris_Admin::button(
			'restart_search',
			Equalify_Iris_Admin::SLUG,
			__( 'Search the whole network again', 'equalify-iris' ),
			'button'
		);

		echo '</div>';
	}

	/**
	 * The "if nothing is happening" section.
	 */
	private function render_cron_help(): void {
		echo '<h3 id="nothing-happening">' . esc_html__( 'If nothing is happening', 'equalify-iris' ) . '</h3>';

		echo '<p>';
		echo esc_html__(
			'WordPress has no clock of its own. Its scheduled jobs run when somebody visits the site, so a quiet network does very little work — and a network where scheduled jobs are broken does none at all, while still looking perfectly normal.',
			'equalify-iris'
		);
		echo '</p>';

		if ( Equalify_Iris_Scheduler::wp_cron_is_disabled() ) {
			echo '<p>';
			echo esc_html__(
				'WordPress\'s own scheduler is turned off on this install (DISABLE_WP_CRON is set). That is the better setup — but only if a real cron job runs WordPress on a schedule. If the background job has never run, that cron job is probably missing. It should look like this:',
				'equalify-iris'
			);
			echo '</p>';
		} else {
			echo '<p>';
			echo esc_html__(
				'For steady progress, ask whoever runs the server to add a real cron job. This is the single biggest thing that makes a large conversion finish in weeks instead of months:',
				'equalify-iris'
			);
			echo '</p>';
		}

		printf(
			'<pre class="equalify-iris-code">%s</pre>',
			esc_html( '*/5 * * * * cd ' . ABSPATH . ' && wp cron event run --due-now --url=' . network_site_url() )
		);

		echo '<p>';
		echo esc_html__( 'You can also check everything at once from the command line:', 'equalify-iris' );
		echo '</p>';

		printf( '<pre class="equalify-iris-code">%s</pre>', esc_html( 'wp equalify-iris doctor' ) );
	}

	/** One label-and-value row. */
	private function row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
