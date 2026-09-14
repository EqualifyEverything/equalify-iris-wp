<?php
/**
 * WHAT IS THIS FILE?
 *
 * The command line interface: `wp equalify-iris <command>`.
 *
 * WHY DOES IT EXIST?
 *
 * Because the dashboard is where you watch the plugin, and the command line is
 * where you diagnose it. Three things are much easier here than in a browser:
 *
 *   1. RUNNING A TICK AND SEEING EVERY DETAIL, with no page load in the way and no
 *      30-second web-server timeout to worry about.
 *   2. WORKING OUT WHY NOTHING IS HAPPENING. `wp equalify-iris doctor` checks every
 *      requirement in turn and says which one is not met. That question — "it says
 *      it is running but nothing is converting" — is the most common one this
 *      plugin will ever be asked, and it has about six possible answers.
 *   3. AUTOMATION. A real cron entry, a deploy script, a bulk retry after fixing a
 *      network problem.
 *
 * Every command here also works through exactly the same code as the dashboard.
 * Nothing is available only on the command line, so a fix applied here is a fix
 * everywhere.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_CLI {

	private static ?Equalify_Iris_Plugin $plugin = null;

	/** Register the command with WP-CLI. */
	public static function register( Equalify_Iris_Plugin $plugin ): void {
		self::$plugin = $plugin;

		WP_CLI::add_command( 'equalify-iris', __CLASS__ );
	}

	/**
	 * Show what the plugin is doing right now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris status
	 */
	public function status(): void {
		$counts = Equalify_Iris_Documents::counts_by_status();
		$cursor = Equalify_Iris_Sweeper::cursor();

		WP_CLI::line( '' );
		WP_CLI::line( 'Processing:  ' . ( Equalify_Iris_Settings::get( 'running' ) ? 'ON' : 'OFF' ) );
		WP_CLI::line( 'Iris:        ' . self::auth_summary() );

		$next = Equalify_Iris_Scheduler::next_run();
		WP_CLI::line( 'Next tick:   ' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled' ) );

		$last = (int) Equalify_Iris_Settings::get( 'last_tick' );
		WP_CLI::line( 'Last tick:   ' . ( $last ? gmdate( 'Y-m-d H:i:s', $last ) . ' UTC' : 'never' ) );

		$until = Equalify_Iris_API_Client::circuit_open_until();

		if ( $until ) {
			WP_CLI::line( 'Paused until: ' . gmdate( 'Y-m-d H:i:s', $until ) . ' UTC' );
		}

		WP_CLI::line( '' );
		WP_CLI::line( 'First search: ' . ( Equalify_Iris_Sweeper::is_complete()
			? 'complete'
			: sprintf( 'in progress (site %d, up to post %d)', $cursor['site_id'], $cursor['post_id'] ) ) );

		WP_CLI::line( '' );

		$rows = array();

		foreach ( Equalify_Iris_Documents::status_labels() as $status => $label ) {
			$rows[] = array(
				'status' => $status,
				'means'  => $label,
				'count'  => $counts[ $status ],
			);
		}

		$rows[] = array(
			'status' => 'TOTAL',
			'means'  => '',
			'count'  => $counts['total'],
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'means', 'count' ) );
	}

	/**
	 * Check every requirement and report what is not met.
	 *
	 * Run this first whenever the plugin appears to be doing nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris doctor
	 */
	public function doctor(): void {
		$problems = 0;

		$checks = array(
			array(
				'name' => 'Multisite',
				'ok'   => is_multisite(),
				'fix'  => 'This plugin only works on a multisite network.',
			),
			array(
				'name' => 'Database tables',
				'ok'   => Equalify_Iris_Database::tables_exist(),
				'fix'  => 'Deactivate and reactivate the plugin in Network Admin to create them.',
			),
			array(
				'name' => 'Equalify Iris will accept us',
				'ok'   => ! Equalify_Iris_Settings::blocked_by_auth(),
				'fix'  => 'This deployment is closed and needs a shared API token. Get it from whoever runs Equalify Iris, then run: wp equalify-iris connect --token=<secret>',
			),
			array(
				'name' => 'Processing turned on',
				'ok'   => (bool) Equalify_Iris_Settings::get( 'running' ),
				'fix'  => 'Run: wp equalify-iris start',
			),
			array(
				'name' => 'Background job scheduled',
				'ok'   => (bool) Equalify_Iris_Scheduler::next_run(),
				'fix'  => 'Visit any Network Admin page, which reschedules it.',
			),
			array(
				'name' => 'Equalify Iris reachable',
				'ok'   => ! Equalify_Iris_API_Client::circuit_is_open(),
				'fix'  => 'Paused after repeated failures. It will resume on its own; see the activity log for the reason.',
			),
			array(
				'name' => 'cURL available (for low-memory uploads)',
				'ok'   => function_exists( 'curl_file_create' ),
				'fix'  => 'Not fatal, but large PDFs will be skipped on low-memory hosts. Ask your host to enable the PHP cURL extension.',
			),
			array(
				'name' => 'Pretty permalinks',
				'ok'   => (bool) get_option( 'permalink_structure' ),
				'fix'  => 'Not fatal — document pages fall back to plain URLs — but pretty permalinks give them readable addresses.',
			),
		);

		$page_cap = self::server_page_cap();

		if ( null !== $page_cap ) {
			$checks[] = array(
				'name' => 'Our page limit matches the server\'s',
				'ok'   => $page_cap === Equalify_Iris_Settings::MAX_PDF_PAGES,
				'fix'  => sprintf(
					'Equalify Iris now converts up to %1$d pages; this plugin assumes %2$d. Update MAX_PDF_PAGES in includes/class-settings.php. Until then, PDFs between the two numbers are either rejected on upload or reported as too long when they are not.',
					$page_cap,
					Equalify_Iris_Settings::MAX_PDF_PAGES
				),
			);
		}

		WP_CLI::line( '' );

		foreach ( $checks as $check ) {
			if ( $check['ok'] ) {
				WP_CLI::line( '  OK    ' . $check['name'] );

				continue;
			}

			++$problems;
			WP_CLI::line( '  FIX   ' . $check['name'] );
			WP_CLI::line( '        ' . $check['fix'] );
		}

		WP_CLI::line( '' );

		// WP-Cron gets its own paragraph rather than a row above, because the answer
		// is not yes or no — a disabled WP-Cron is correct on a well-run server and
		// broken on a badly-run one, and only the person reading this knows which.
		if ( Equalify_Iris_Scheduler::wp_cron_is_disabled() ) {
			WP_CLI::line( 'NOTE: WP-Cron is turned off on this install (DISABLE_WP_CRON).' );
			WP_CLI::line( '      That is the recommended setup, but only if a real cron job runs' );
			WP_CLI::line( '      WordPress on a schedule. Confirm this exists in your crontab:' );
			WP_CLI::line( '' );
			WP_CLI::line( '        */5 * * * * cd ' . ABSPATH . ' && wp cron event run --due-now --url=' . home_url() );
			WP_CLI::line( '' );
		} else {
			WP_CLI::line( 'NOTE: WP-Cron runs on page loads, so a site with no visitors does no' );
			WP_CLI::line( '      work. For steady progress, add a real cron job:' );
			WP_CLI::line( '' );
			WP_CLI::line( '        */5 * * * * cd ' . ABSPATH . ' && wp cron event run --due-now --url=' . home_url() );
			WP_CLI::line( '' );
		}

		if ( $problems ) {
			WP_CLI::warning( sprintf( '%d thing(s) need attention.', $problems ) );

			return;
		}

		WP_CLI::success( 'Everything checks out.' );
	}

	/**
	 * The page limit this Iris deployment actually enforces, or null if it will not
	 * say.
	 *
	 * WHY ASK AT ALL?
	 *
	 * Because the plugin hard-codes 25 pages, and that number belongs to the Iris
	 * server rather than to us. If it ever moves, the symptom is confusing in both
	 * directions: a lower cap means uploads Iris rejects, and a higher one means
	 * documents reported as "too long" that Iris would happily have converted. This
	 * is a one-line answer to a question that would otherwise take an afternoon.
	 *
	 * Returns null rather than failing the check when the endpoint is missing or
	 * unreachable — an older deployment has no /limits at all, and "we could not
	 * ask" is not a problem to report to somebody running doctor to find out why
	 * nothing is happening.
	 */
	private static function server_page_cap(): ?int {
		$limits = Equalify_Iris_API_Client::limits();

		if ( is_wp_error( $limits ) || ! isset( $limits['max_pages'] ) ) {
			return null;
		}

		$cap = (int) $limits['max_pages'];

		return $cap > 0 ? $cap : null;
	}

	/**
	 * One line saying whether Iris will accept us, for status and the log.
	 */
	private static function auth_summary(): string {
		$repo = (string) Equalify_Iris_Settings::get( 'upstream_repo' );
		$repo = $repo ? ', contributions to ' . $repo : '';

		switch ( Equalify_Iris_Settings::auth_state() ) {
			case Equalify_Iris_Settings::AUTH_OK:
				return sprintf(
					'usable (%s deployment%s)',
					Equalify_Iris_Settings::has_api_token() ? 'closed, our token accepted' : 'open',
					$repo
				);

			case Equalify_Iris_Settings::AUTH_NEEDS_TOKEN:
				return 'REFUSING US — needs a shared API token. Run: wp equalify-iris connect --token=<secret>';

			case Equalify_Iris_Settings::AUTH_DEPLOYMENT_ERROR:
				return 'the Iris server cannot authenticate itself to GitHub. Not ours to fix; tell whoever runs it.';

			default:
				return 'not checked yet — run: wp equalify-iris connect';
		}
	}

	/**
	 * Check that Equalify Iris will accept us, and store a shared secret if this
	 * deployment needs one.
	 *
	 * THERE IS NO SIGN-IN. Iris v1 removed client authentication: it holds its own
	 * GitHub credential and you never see it. Most deployments, including the
	 * public one, are open and need nothing at all — so on a normal network this
	 * command takes no arguments and only confirms that things work.
	 *
	 * `--token` is for the other case: an operator who closed their deployment with
	 * `server.api_token` and gave you the secret. It is a door key, not an
	 * identity, and it is shared by everyone who has it.
	 *
	 * ## OPTIONS
	 *
	 * [--token=<secret>]
	 * : The shared secret, for a deployment that requires one.
	 *
	 * [--forget]
	 * : Delete the stored secret and everything the last check learned.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris connect
	 *     wp equalify-iris connect --token=the-secret-the-operator-gave-you
	 *     wp equalify-iris connect --forget
	 */
	public function connect( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['forget'] ) ) {
			Equalify_Iris_Settings::forget_api_token();
			WP_CLI::success( 'Forgot the stored token. Run this command again to re-check the deployment.' );

			return;
		}

		if ( isset( $assoc_args['token'] ) ) {
			if ( Equalify_Iris_Settings::token_is_from_constant() ) {
				WP_CLI::warning( 'EQUALIFY_IRIS_API_TOKEN is defined in wp-config.php and takes priority, so the token you just passed will be stored but not used.' );
			}

			Equalify_Iris_Settings::set( 'api_token', trim( (string) $assoc_args['token'] ) );
		}

		$result = Equalify_Iris_API_Client::check_connection();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::line( '' );
		WP_CLI::line( '  Deployment:    ' . ( 'gated' === $result['mode'] ? 'closed — our token was accepted' : 'open — no token needed' ) );
		WP_CLI::line( '  Files issues as: ' . ( $result['login'] ?: 'unknown' ) );
		WP_CLI::line( '  Contributions: ' . ( $result['upstream_repo'] ?: 'unknown' ) );

		if ( $result['max_review_iterations'] ) {
			WP_CLI::line( '  Review rounds: ' . $result['max_review_iterations'] );
		}

		WP_CLI::line( '' );
		WP_CLI::line( 'Note: document extracts are attributed to that account, not to this network.' );
		WP_CLI::line( '' );

		WP_CLI::success( 'Equalify Iris will accept us.' );
	}

	/**
	 * Turn processing on.
	 *
	 * ## OPTIONS
	 *
	 * [--restart-search]
	 * : Also start the search for existing PDFs again from the beginning.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris start
	 *     wp equalify-iris start --restart-search
	 */
	public function start( array $args, array $assoc_args ): void {
		if ( Equalify_Iris_Settings::blocked_by_auth() ) {
			WP_CLI::error( 'Equalify Iris is refusing us: this deployment needs a shared API token. Run: wp equalify-iris connect --token=<secret>' );
		}

		if ( ! empty( $assoc_args['restart-search'] ) ) {
			Equalify_Iris_Sweeper::reset();
			WP_CLI::line( 'The search for existing PDFs will start again from the beginning.' );
		}

		Equalify_Iris_Settings::set( 'running', true );
		Equalify_Iris_Scheduler::schedule();

		Equalify_Iris_Logger::log( __( 'Processing was turned on from the command line.', 'equalify-iris' ) );

		WP_CLI::success( 'Processing is on. The next tick runs within five minutes.' );
	}

	/**
	 * Turn processing off.
	 *
	 * Nothing is lost. Documents already at Iris finish and are collected when
	 * processing is turned back on.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris stop
	 */
	public function stop(): void {
		Equalify_Iris_Settings::set( 'running', false );

		Equalify_Iris_Logger::log( __( 'Processing was turned off from the command line.', 'equalify-iris' ) );

		WP_CLI::success( 'Processing is off. Nothing has been lost.' );
	}

	/**
	 * Run one background tick right now and report what it did.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<number>]
	 * : Run this many ticks in a row. Default 1.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris tick
	 *     wp equalify-iris tick --count=10
	 */
	public function tick( array $args, array $assoc_args ): void {
		$count = max( 1, (int) ( $assoc_args['count'] ?? 1 ) );

		for ( $i = 1; $i <= $count; $i++ ) {
			$result = self::$plugin->scheduler->run_now();

			if ( empty( $result['ran'] ) ) {
				WP_CLI::line( sprintf( 'Tick %d: did nothing (%s).', $i, $result['reason'] ?: 'nothing to do' ) );
			} else {
				WP_CLI::line(
					sprintf(
						'Tick %d: checked %d, imported %d, uploaded %d, retired %d, swept %d posts (found %d PDFs) in %ss.',
						$i,
						$result['checked'],
						$result['imported'],
						$result['uploaded'],
						$result['retired'],
						$result['swept'],
						$result['found'],
						$result['seconds']
					)
				);
			}

			// A pause between ticks, because back-to-back ticks would hammer Iris in
			// a way the real five-minute schedule never does.
			if ( $i < $count ) {
				sleep( 5 );
			}
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Search the network for PDFs on published content, without converting anything.
	 *
	 * Useful for seeing how much work there is before turning processing on.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Keep going until the whole network has been searched, rather than one batch.
	 *
	 * [--restart]
	 * : Start the search again from the beginning.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris sweep --all
	 */
	public function sweep( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['restart'] ) ) {
			Equalify_Iris_Sweeper::reset();
		}

		$scanned = 0;
		$found   = 0;

		do {
			$result   = self::$plugin->sweeper->run_batch();
			$scanned += $result['scanned'];
			$found   += $result['found'];

			if ( $result['scanned'] ) {
				WP_CLI::line(
					sprintf(
						'Site %d: searched %d posts so far, found %d PDFs.',
						$result['site_id'],
						$scanned,
						$found
					)
				);
			}

			$keep_going = ! empty( $assoc_args['all'] ) && ! $result['complete'] && $result['scanned'] > 0;
		} while ( $keep_going );

		if ( Equalify_Iris_Sweeper::is_complete() ) {
			WP_CLI::success( sprintf( 'Search complete. Searched %d posts, found %d PDFs.', $scanned, $found ) );

			return;
		}

		WP_CLI::success( sprintf( 'Searched %d posts, found %d PDFs. More to go.', $scanned, $found ) );
	}

	/**
	 * Put failed documents back in the queue.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Document ids to retry. Leave out to retry every failed document.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris retry
	 *     wp equalify-iris retry 12 34
	 */
	public function retry( array $args ): void {
		if ( $args ) {
			foreach ( $args as $id ) {
				Equalify_Iris_Documents::retry( (int) $id );
			}

			WP_CLI::success( sprintf( 'Queued %d document(s) for another try.', count( $args ) ) );

			return;
		}

		$failed = Equalify_Iris_Documents::query(
			array(
				'status'   => Equalify_Iris_Documents::FAILED,
				'per_page' => 1000,
			)
		);

		foreach ( $failed['items'] as $document ) {
			Equalify_Iris_Documents::retry( (int) $document->id );
		}

		WP_CLI::success( sprintf( 'Queued %d failed document(s) for another try.', count( $failed['items'] ) ) );
	}

	/**
	 * List documents.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only show documents in this status.
	 *
	 * [--site=<id>]
	 * : Only show documents from this site.
	 *
	 * [--limit=<number>]
	 * : How many to show. Default 25.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris list --status=failed
	 */
	public function list( array $args, array $assoc_args ): void {
		$result = Equalify_Iris_Documents::query(
			array(
				'status'   => (string) ( $assoc_args['status'] ?? '' ),
				'site_id'  => (int) ( $assoc_args['site'] ?? 0 ),
				'per_page' => max( 1, (int) ( $assoc_args['limit'] ?? 25 ) ),
			)
		);

		if ( ! $result['items'] ) {
			WP_CLI::line( 'No documents match.' );

			return;
		}

		$rows = array();

		foreach ( $result['items'] as $document ) {
			$rows[] = array(
				'id'     => $document->id,
				'site'   => $document->site_id,
				'file'   => Equalify_Iris_Documents::display_name( $document ),
				'pages'  => $document->page_count ?: '?',
				'status' => $document->status,
				'error'  => $document->last_error,
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'site', 'file', 'pages', 'status', 'error' ) );

		WP_CLI::line( sprintf( 'Showing %d of %d.', count( $rows ), $result['total'] ) );
	}

	/**
	 * Show the activity log.
	 *
	 * ## OPTIONS
	 *
	 * [--clear]
	 * : Empty the log instead of showing it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris log
	 */
	public function log( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['clear'] ) ) {
			Equalify_Iris_Logger::clear();
			WP_CLI::success( 'Log cleared.' );

			return;
		}

		$entries = Equalify_Iris_Logger::entries();

		if ( ! $entries ) {
			WP_CLI::line( 'The log is empty.' );

			return;
		}

		foreach ( array_reverse( $entries ) as $entry ) {
			WP_CLI::line(
				sprintf(
					'[%s] %-7s %s',
					gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ),
					strtoupper( (string) $entry['level'] ),
					(string) $entry['message']
				)
			);
		}
	}
}
