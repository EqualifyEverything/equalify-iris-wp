<?php
/**
 * WHAT IS THIS FILE?
 *
 * The Documents screen: every PDF the plugin knows about, and what happened to it.
 *
 * WHY DOES IT EXIST?
 *
 * The Overview screen answers "how is it going?". This one answers "what happened
 * to THAT file?" — which is the question that arrives by email from someone who
 * cares about one specific document.
 *
 * WHY NOT WP_List_Table?
 *
 * WordPress has a class for admin tables, and it would give us sortable columns and
 * bulk actions for free. It is also officially undocumented, marked as internal, and
 * changes between releases. This table is a filter, a list, and a Retry button.
 * Writing those three things plainly is less code than configuring WP_List_Table to
 * do them, and a reader of any experience level can follow it — which matters more
 * here than saving a few lines.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Admin_Documents {

	/** Rows per page. */
	const PER_PAGE = 50;

	public function render(): void {
		$screen = Equalify_Iris_Admin::SLUG . '-documents';

		// phpcs:disable WordPress.Security.NonceVerification -- these are read-only filters, not actions.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$site_id = isset( $_GET['site'] ) ? (int) $_GET['site'] : 0;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		$result = Equalify_Iris_Documents::query(
			array(
				'status'   => $status,
				'site_id'  => $site_id,
				'search'   => $search,
				'per_page' => self::PER_PAGE,
				'page'     => $page,
			)
		);

		echo '<div class="wrap equalify-iris">';
		echo '<h1>' . esc_html__( 'Documents', 'equalify-iris' ) . '</h1>';

		Equalify_Iris_Admin::tabs( $screen );
		Equalify_Iris_Admin::notice();

		$this->render_filters( $status, $site_id, $search );

		if ( ! $result['items'] ) {
			echo '<p>' . esc_html__( 'No PDFs match. If the queue is empty altogether, processing may not have run yet — check the Overview screen.', 'equalify-iris' ) . '</p>';
			echo '</div>';

			return;
		}

		$this->render_table( $result['items'], $screen );
		$this->render_pagination( $result['total'], $page, $status, $site_id, $search );

		if ( Equalify_Iris_Documents::FAILED === $status ) {
			echo '<h2>' . esc_html__( 'Retry everything that failed', 'equalify-iris' ) . '</h2>';
			echo '<p>' . esc_html__( 'Puts every failed PDF back in the queue with a fresh set of attempts. Sensible after fixing whatever caused the failures.', 'equalify-iris' ) . '</p>';

			Equalify_Iris_Admin::button( 'retry_all_failed', $screen, __( 'Retry all failed PDFs', 'equalify-iris' ), 'button' );
		}

		echo '</div>';
	}

	/**
	 * The filter bar.
	 *
	 * A plain GET form, so the filtered view has a URL that can be bookmarked and
	 * shared. "Send me a link to the failures" is a reasonable thing to ask for.
	 */
	private function render_filters( string $status, int $site_id, string $search ): void {
		$counts = Equalify_Iris_Documents::counts_by_status();

		echo '<form method="get" action="' . esc_url( network_admin_url( 'admin.php' ) ) . '" class="equalify-iris-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Equalify_Iris_Admin::SLUG . '-documents' ) . '">';

		echo '<label for="equalify-iris-status">' . esc_html__( 'Stage', 'equalify-iris' ) . '</label> ';
		echo '<select name="status" id="equalify-iris-status">';
		echo '<option value="">' . esc_html__( 'All stages', 'equalify-iris' ) . '</option>';

		foreach ( Equalify_Iris_Documents::status_labels() as $value => $label ) {
			printf(
				'<option value="%s"%s>%s (%s)</option>',
				esc_attr( $value ),
				selected( $status, $value, false ),
				esc_html( $label ),
				esc_html( number_format_i18n( (int) $counts[ $value ] ) )
			);
		}

		echo '</select> ';

		echo '<label for="equalify-iris-site">' . esc_html__( 'Site', 'equalify-iris' ) . '</label> ';
		echo '<select name="site" id="equalify-iris-site">';
		echo '<option value="0">' . esc_html__( 'All sites', 'equalify-iris' ) . '</option>';

		// Bounded at 200 sites on purpose: a select with a thousand options is not
		// usable, and building it means a query per site for the name. Above that,
		// filter by URL search instead.
		$sites = get_sites(
			array(
				'number'  => 200,
				'orderby' => 'id',
			)
		);

		foreach ( $sites as $site ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $site->blog_id,
				selected( $site_id, (int) $site->blog_id, false ),
				esc_html( untrailingslashit( $site->domain . $site->path ) )
			);
		}

		echo '</select> ';

		echo '<label for="equalify-iris-search">' . esc_html__( 'File name contains', 'equalify-iris' ) . '</label> ';
		echo '<input type="search" name="s" id="equalify-iris-search" value="' . esc_attr( $search ) . '"> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'equalify-iris' ) . '</button>';
		echo '</form>';
	}

	/**
	 * The table of documents.
	 */
	private function render_table( array $items, string $screen ): void {
		echo '<table class="widefat striped equalify-iris-documents">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'PDFs found across the network', 'equalify-iris' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'PDF', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Site', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Pages', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Stage', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Appears on', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Accessible version', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'equalify-iris' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $items as $document ) {
			$this->render_row( $document, $screen );
		}

		echo '</tbody></table>';
	}

	/**
	 * One row.
	 */
	private function render_row( object $document, string $screen ): void {
		$name = Equalify_Iris_Documents::display_name( $document );

		echo '<tr>';

		// The PDF itself, and any error underneath it. Putting the error here rather
		// than in its own column means it gets the width it needs to be a sentence.
		echo '<th scope="row">';
		printf(
			'<a href="%s">%s</a>',
			esc_url( (string) $document->pdf_url ),
			esc_html( $name )
		);

		if ( $document->last_error ) {
			echo '<br><span class="equalify-iris-error">' . esc_html( (string) $document->last_error ) . '</span>';
		}

		if ( (int) $document->attempts > 0 ) {
			echo '<br><span class="description">';
			printf(
				/* translators: %d: number of attempts. */
				esc_html__( 'Attempts: %d', 'equalify-iris' ),
				(int) $document->attempts
			);
			echo '</span>';
		}

		echo '</th>';

		echo '<td>' . esc_html( $this->site_name( (int) $document->site_id ) ) . '</td>';

		echo '<td>' . esc_html( $document->page_count ? number_format_i18n( (int) $document->page_count ) : '?' ) . '</td>';

		echo '<td>' . esc_html( Equalify_Iris_Documents::status_label( (string) $document->status ) ) . '</td>';

		echo '<td>' . esc_html( number_format_i18n( Equalify_Iris_Documents::sighting_count( (int) $document->id ) ) ) . '</td>';

		echo '<td>';

		if ( $document->doc_post_id ) {
			switch_to_blog( (int) $document->site_id );
			$permalink = get_permalink( (int) $document->doc_post_id );
			restore_current_blog();

			if ( $permalink ) {
				printf(
					'<a href="%s">%s</a>',
					esc_url( $permalink ),
					esc_html__( 'View', 'equalify-iris' )
				);
			} else {
				echo '&mdash;';
			}
		} else {
			echo '&mdash;';
		}

		echo '</td>';

		echo '<td>';

		// Retry is offered for anything that could plausibly succeed on another go.
		// Deliberately not offered for too_long or too_big: retrying a 60-page PDF
		// will fail in exactly the same way, and a button that cannot work is worse
		// than no button.
		$retryable = in_array(
			(string) $document->status,
			array(
				Equalify_Iris_Documents::FAILED,
				Equalify_Iris_Documents::PUBLISHED,
				Equalify_Iris_Documents::RETIRED,
			),
			true
		);

		if ( $retryable ) {
			Equalify_Iris_Admin::form_open( 'retry', $screen );
			echo '<input type="hidden" name="document_id" value="' . esc_attr( (string) $document->id ) . '">';
			printf(
				'<button type="submit" class="button button-small">%s</button>',
				esc_html(
					Equalify_Iris_Documents::PUBLISHED === $document->status
						? __( 'Convert again', 'equalify-iris' )
						: __( 'Retry', 'equalify-iris' )
				)
			);
			Equalify_Iris_Admin::form_close();
		} else {
			echo '&mdash;';
		}

		echo '</td>';

		echo '</tr>';
	}

	/**
	 * A site's address, looked up once per site rather than once per row.
	 */
	private function site_name( int $site_id ): string {
		static $names = array();

		if ( isset( $names[ $site_id ] ) ) {
			return $names[ $site_id ];
		}

		$site = get_site( $site_id );

		$names[ $site_id ] = $site
			? untrailingslashit( $site->domain . $site->path )
			: sprintf(
				/* translators: %d: a site id. */
				__( 'site %d (deleted)', 'equalify-iris' ),
				$site_id
			);

		return $names[ $site_id ];
	}

	/**
	 * Page links.
	 */
	private function render_pagination( int $total, int $page, string $status, int $site_id, string $search ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		echo '<p class="equalify-iris-pagination">';

		printf(
			/* translators: 1: number shown, 2: total. */
			esc_html__( 'Showing %1$s of %2$s PDFs.', 'equalify-iris' ),
			esc_html( number_format_i18n( min( self::PER_PAGE, $total - ( ( $page - 1 ) * self::PER_PAGE ) ) ) ),
			esc_html( number_format_i18n( $total ) )
		);

		if ( $pages > 1 ) {
			$base = add_query_arg(
				array(
					'page'   => Equalify_Iris_Admin::SLUG . '-documents',
					'status' => $status,
					'site'   => $site_id ?: null,
					's'      => $search ?: null,
				),
				network_admin_url( 'admin.php' )
			);

			echo ' ';

			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', $base ),
						'format'    => '',
						'current'   => $page,
						'total'     => $pages,
						'prev_text' => __( '&laquo; Previous', 'equalify-iris' ),
						'next_text' => __( 'Next &raquo;', 'equalify-iris' ),
					)
				)
			);
		}

		echo '</p>';
	}
}
