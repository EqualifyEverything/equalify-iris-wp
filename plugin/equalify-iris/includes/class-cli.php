<?php
/**
 * WHAT IS THIS FILE?
 *
 * `wp equalify-iris`, for checking on and driving the plugin from a terminal.
 * `run` works through the whole network; everything else acts on one site, so
 * pick which with WP-CLI's own --url.
 *
 * On a large network, or a host such as Pantheon where WP-Cron is not
 * dependable, a scheduler runs `wp equalify-iris run` every five minutes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_CLI {

	/**
	 * Show the settings and how far this site's PDFs have got.
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris status --url=example.org/research
	 */
	public function status(): void {
		WP_CLI::line( 'API address:          ' . Equalify_Iris_Settings::api_url() );

		if ( '' !== Equalify_Iris_Settings::refused() ) {
			WP_CLI::warning( 'Iris refused to let this network in, so nothing is sent until the settings change: ' . Equalify_Iris_Settings::refused() );
		}
		WP_CLI::line( 'Token:                ' . ( '' !== Equalify_Iris_Settings::api_token() ? 'set' : 'none' ) );
		WP_CLI::line( 'Automatic, network:   ' . ( Equalify_Iris_Settings::network_auto() ? 'on' : 'off' ) );
		WP_CLI::line( 'Automatic, this site: ' . ( Equalify_Iris_Settings::site_auto() ? 'on' : 'off' ) );

		$last = Equalify_Iris_Runner::last_run();
		WP_CLI::line( 'Last run:             ' . ( $last ? human_time_diff( $last ) . ' ago' : 'never' ) );
		WP_CLI::line( 'Sites waiting:        ' . Equalify_Iris_Runner::waiting() );
		WP_CLI::line( 'PDFs at Iris:         ' . Equalify_Iris_Runner::count_at_iris() . ' of at most ' . Equalify_Iris_Runner::max_at_iris() );

		$this->list_pdfs();
	}

	/**
	 * Ask Iris whether it can tag PDFs for us.
	 */
	public function check(): void {
		$check = Equalify_Iris_API_Client::check();

		$check['ok'] ? WP_CLI::success( $check['message'] ) : WP_CLI::error( $check['message'] );
	}

	/**
	 * Queue PDFs for tagging. Sends them to Equalify Iris on the next run.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment ids.
	 */
	public function tag( array $args ): void {
		foreach ( $args as $id ) {
			Equalify_Iris_Tagger::queue( (int) $id )
				? WP_CLI::log( "Queued {$id}." )
				: WP_CLI::warning( "{$id} is not a PDF that visitors can reach on this site." );
		}

		if ( ! Equalify_Iris_Discovery::indexed() ) {
			Equalify_Iris_Runner::wake();
			WP_CLI::warning( 'This site has not finished reading its content, so some PDFs are not known yet. Run `wp equalify-iris run`, then try again.' );
		}
	}

	/**
	 * Have sites read their content for PDF links on the next run.
	 *
	 * A site is otherwise only read once someone opens its Equalify Iris screen,
	 * tags a PDF, or turns on automatic tagging.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Every site on the network, not just the one --url picks.
	 */
	public function read( array $args, array $assoc ): void {
		if ( ! empty( $assoc['network'] ) ) {
			Equalify_Iris_Runner::wake_all();
			WP_CLI::success( 'Every site will read its content, a few at a time, over the next runs.' );
			return;
		}

		Equalify_Iris_Runner::wake();
		WP_CLI::success( 'This site will read its content on the next run.' );
	}

	/**
	 * Delete Iris-tagged versions, so links point at the originals again.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment ids.
	 */
	public function remove( array $args ): void {
		foreach ( $args as $arg ) {
			$id = absint( $arg );

			if ( ! $id || (string) $id !== (string) $arg || ! Equalify_Iris_Tagger::is_pdf( $id ) ) {
				WP_CLI::warning( "{$arg} is not a PDF in this site's media library." );
				continue;
			}

			if ( ! get_post_meta( $id, Equalify_Iris_Tagger::META_FILE, true ) ) {
				WP_CLI::warning( "{$id} has no Iris-tagged version." );
				continue;
			}

			Equalify_Iris_Tagger::remove( $id );
			WP_CLI::log( "Removed the Iris-tagged version of {$id}." );
		}
	}

	/**
	 * Run the background job for the whole network now.
	 *
	 * Works through the sites that have something to do until the time is up,
	 * then stops. Schedule it every five minutes on hosts where WP-Cron is not
	 * dependable. Pantheon, with Terminus:
	 *
	 *     terminus wp <site>.<env> -- equalify-iris run
	 *
	 * Talks to whichever Iris the API address points at. A real PDF sent to the
	 * public deployment may be quoted in a public GitHub issue.
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<n>]
	 * : How long a run may last. Keep it under the host's limit on PHP processes;
	 * on Pantheon that is 120 seconds. Defaults to EQUALIFY_IRIS_RUN_SECONDS, or 90.
	 *
	 * [--count=<n>]
	 * : How many runs, one after another.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp equalify-iris run --url=example.org
	 */
	public function run( array $args, array $assoc ): void {
		$count   = max( 1, (int) $assoc['count'] );
		$seconds = isset( $assoc['seconds'] ) ? max( 30, (int) $assoc['seconds'] ) : 0;

		for ( $i = 1; $i <= $count; $i++ ) {
			$done = Equalify_Iris_Runner::run( $seconds );

			if ( $done['locked'] ) {
				WP_CLI::warning( "Run {$i}: another run is still going. Nothing to do." );
				continue;
			}

			WP_CLI::log(
				sprintf( 'Run %d: %d sites, %d queued, %d uploaded, %d tagged, %d failed. %d sites still waiting.', $i, $done['sites'], $done['queued'], $done['uploaded'], $done['tagged'], $done['failed'], Equalify_Iris_Runner::waiting() )
			);
		}
	}

	private function list_pdfs(): void {
		$rows = array();

		WP_CLI::line( 'Content read:         ' . ( Equalify_Iris_Discovery::indexed() ? 'all of it' : ( Equalify_Iris_Discovery::started() ? 'not yet' : 'not started' ) ) );

		foreach ( Equalify_Iris_Discovery::find( 'listed' ) as $id ) {
			$rows[] = array(
				'id'     => $id,
				'file'   => get_post_meta( $id, '_wp_attached_file', true ),
				'public' => Equalify_Iris_Discovery::is_public( $id ) ? 'yes' : 'no',
				'status' => Equalify_Iris_Tagger::status( $id ) ? Equalify_Iris_Tagger::status( $id ) : '-',
				'iris'   => Equalify_Iris_Tagger::stage( $id ),
				'since'  => Equalify_Iris_Tagger::since( $id ) ? human_time_diff( Equalify_Iris_Tagger::since( $id ) ) . ' ago' : '',
				'tries'  => Equalify_Iris_Tagger::attempts( $id ) ? Equalify_Iris_Tagger::attempts( $id ) : '',
				'tagged' => Equalify_Iris_Tagger::tagged_url( $id ),
				'error'  => Equalify_Iris_Tagger::error( $id ),
			);
		}

		if ( ! $rows ) {
			WP_CLI::line( 'Nothing visitors can see on this site links to a PDF.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'file', 'public', 'status', 'iris', 'since', 'tries', 'tagged', 'error' ) );
	}
}
