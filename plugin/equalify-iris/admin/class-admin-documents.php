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
 * changes between releases. This table is a filter, a list, and one bulk action bar.
 * Writing those three things plainly is less code than configuring WP_List_Table to
 * do them, and a reader of any experience level can follow it — which matters more
 * here than saving a few lines.
 *
 * WHY THE ACTIONS ARE AT THE TOP AND NOT IN THE ROWS
 *
 * There were three buttons in every row once, then a dropdown and an Apply button in
 * every row. Both are the same mistake at different sizes: fifty rows of controls,
 * every one of them a decision, and the destructive ones sitting beside the harmless
 * one in a column too narrow to read.
 *
 * Now a row has one control — a checkbox — and the actions live once, above the table.
 * Tick what you mean and say what to do with it. That also makes "delete the pages for
 * these six documents" a single action rather than six, which is how the job actually
 * arrives.
 *
 * WHY THERE IS NO "SELECT ALL" BOX
 *
 * Because it cannot work without JavaScript, and this plugin has none. A checkbox that
 * looks like it selects everything and does nothing is worse than its absence.
 * Emptying the whole network is what `wp equalify-iris purge` is for.
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
		$confirm = isset( $_GET['confirm'] ) ? sanitize_key( wp_unslash( $_GET['confirm'] ) ) : '';
		$subjects = isset( $_GET['documents'] ) ? self::ids_from( (string) wp_unslash( $_GET['documents'] ) ) : array();
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

		if ( $confirm && $subjects ) {
			$this->render_confirm( $confirm, $subjects, $screen );
		}

		$this->render_filters( $status, $site_id, $search );

		if ( ! $result['items'] ) {
			echo '<p>' . esc_html__( 'No PDFs match. If the queue is empty altogether, processing may not have run yet — check the Overview screen.', 'equalify-iris' ) . '</p>';
			echo '</div>';

			return;
		}

		// The bulk bar and the table are one form: the checkboxes in the rows are what
		// the dropdown at the top acts on, so they cannot be in separate forms.
		Equalify_Iris_Admin::form_open( 'bulk_action', $screen );
		$this->render_bulk_bar();
		$this->render_table( $result['items'] );
		Equalify_Iris_Admin::form_close();

		$this->render_pagination( $result['total'], $page, $status, $site_id, $search );

		if ( Equalify_Iris_Documents::FAILED === $status ) {
			echo '<p class="equalify-iris-aside">' . esc_html__( 'Or put every failed PDF back in the queue at once, with a fresh set of attempts. Sensible after fixing whatever caused the failures.', 'equalify-iris' ) . '</p>';

			Equalify_Iris_Admin::button( 'retry_all_failed', $screen, __( 'Retry all failed PDFs', 'equalify-iris' ), 'button' );
		}

		echo '</div>';
	}

	/**
	 * The bulk action bar: one dropdown, one button, above the table.
	 *
	 * Not every action suits every row — a PDF that was never converted has no page to
	 * delete. Rather than work out which of fifty rows each action applies to and offer
	 * a different list depending on the selection (which needs JavaScript, and is a
	 * guess before the boxes are ticked), all four are always offered and the handler
	 * quietly skips the rows an action cannot apply to, then says how many it did.
	 *
	 * This used to carry core's `.tablenav top` as well, which was pointless: every
	 * property it brought — the fixed height for a floated layout, the margins, the
	 * clearing — had to be overridden to lay two controls out as a flex row.
	 */
	private function render_bulk_bar(): void {
		echo '<div class="equalify-iris-bulk">';

		echo '<label for="equalify-iris-bulk-action" class="screen-reader-text">'
			. esc_html__( 'Action to apply to the PDFs you have ticked', 'equalify-iris' )
			. '</label>';

		echo '<select name="bulk_action" id="equalify-iris-bulk-action">';
		echo '<option value="">' . esc_html__( 'With the ticked PDFs…', 'equalify-iris' ) . '</option>';

		foreach ( self::bulk_actions() as $value => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $label ) );
		}

		echo '</select> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Apply', 'equalify-iris' ) . '</button>';

		echo '</div>';
	}

	/**
	 * The four things that can be done to a document.
	 *
	 * Public because the action handler validates against exactly this list, so there
	 * is one place a fifth action would have to be added.
	 *
	 * @return array<string,string>
	 */
	public static function bulk_actions(): array {
		return array(
			'retry'   => __( 'Convert again', 'equalify-iris' ),
			'delete'  => __( 'Delete the accessible page', 'equalify-iris' ),
			'exclude' => __( 'Delete it and never convert again', 'equalify-iris' ),
			'include' => __( 'Convert after all', 'equalify-iris' ),
		);
	}

	/**
	 * Document ids out of a comma-separated list in a URL.
	 *
	 * @return array<int>
	 */
	public static function ids_from( string $list ): array {
		$ids = array_filter( array_map( 'intval', preg_split( '#[^0-9]+#', $list ) ?: array() ) );

		return array_slice( array_values( array_unique( $ids ) ), 0, self::PER_PAGE );
	}

	/**
	 * Ask before deleting a page or excluding a PDF.
	 *
	 * WHY A WHOLE PANEL AND NOT A JAVASCRIPT CONFIRM BOX
	 *
	 * Because this plugin ships no JavaScript, and an `onclick="return confirm()"`
	 * would be the only script in it — one that silently stops protecting anything
	 * the moment a security header or an extension blocks inline script, which is
	 * exactly when you would not notice.
	 *
	 * So Apply on a destructive action LINKS here, and the panel holds the real form.
	 * A link is safe here for the same reason buttons are forms everywhere else in
	 * this plugin: following it changes nothing at all.
	 *
	 * @param array<int> $ids The documents the admin ticked.
	 */
	private function render_confirm( string $action, array $ids, string $screen ): void {
		if ( ! in_array( $action, array( 'delete', 'exclude' ), true ) ) {
			return;
		}

		$documents = array();

		foreach ( $ids as $id ) {
			$document = Equalify_Iris_Documents::find( $id );

			if ( $document ) {
				$documents[] = $document;
			}
		}

		if ( ! $documents ) {
			return;
		}

		$count = count( $documents );

		echo '<div class="notice notice-warning equalify-iris-confirm"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		echo '<input type="hidden" name="action" value="equalify_iris_action">';
		echo '<input type="hidden" name="equalify_iris_action" value="' . esc_attr( 'delete' === $action ? 'delete_page' : 'exclude' ) . '">';
		echo '<input type="hidden" name="equalify_iris_screen" value="' . esc_attr( $screen ) . '">';

		foreach ( $documents as $document ) {
			echo '<input type="hidden" name="documents[]" value="' . esc_attr( (string) $document->id ) . '">';
		}

		wp_nonce_field( Equalify_Iris_Admin::NONCE );

		if ( 'delete' === $action ) {
			printf(
				'<h2>%s</h2>',
				esc_html(
					sprintf(
						/* translators: %s: how many PDFs. */
						_n( 'Delete the accessible page for %s PDF?', 'Delete the accessible pages for %s PDFs?', $count, 'equalify-iris' ),
						number_format_i18n( $count )
					)
				)
			);
		} else {
			printf(
				'<h2>%s</h2>',
				esc_html(
					sprintf(
						/* translators: %s: how many PDFs. */
						_n( 'Never convert %s PDF again?', 'Never convert %s PDFs again?', $count, 'equalify-iris' ),
						number_format_i18n( $count )
					)
				)
			);
		}

		$this->render_confirm_list( $documents );

		if ( 'delete' === $action ) {
			echo '<p>' . esc_html__( 'The pages go, and the icon stops appearing beside those PDFs. Because the PDFs are still linked from published pages they go back in the queue, so fresh pages will be converted and published at the same addresses, usually within the hour.', 'equalify-iris' ) . '</p>';
			echo '<p>' . esc_html__( 'If they should not come back, cancel and choose “Delete it and never convert again” instead.', 'equalify-iris' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'The pages go and Equalify Iris leaves those PDFs alone from now on. This is the one to use for a takedown or a records request. It can be undone with “Convert after all”.', 'equalify-iris' ) . '</p>';

			echo '<p><label for="equalify-iris-reason">' . esc_html__( 'Why (optional, kept in the activity log)', 'equalify-iris' ) . '</label><br>';
			echo '<input type="text" id="equalify-iris-reason" name="reason" class="regular-text" autocomplete="off"></p>';

			echo '<p>' . esc_html__( 'The PDFs themselves are not touched. If a PDF should not be public either, remove it in the media library of the site that owns it.', 'equalify-iris' ) . '</p>';
		}

		printf(
			'<p><button type="submit" class="button button-primary">%s</button> <a href="%s" class="button">%s</a></p>',
			esc_html( 'delete' === $action ? __( 'Delete the pages', 'equalify-iris' ) : __( 'Delete and never convert', 'equalify-iris' ) ),
			esc_url( network_admin_url( 'admin.php?page=' . $screen ) ),
			esc_html__( 'Cancel', 'equalify-iris' )
		);

		echo '</form></div>';
	}

	/**
	 * Name what is about to happen to what.
	 *
	 * Capped, because a confirmation nobody reads to the end confirms nothing. Ten
	 * names is enough to spot the one that should not be in the list, which is the only
	 * job this list has.
	 *
	 * @param array<object> $documents
	 */
	private function render_confirm_list( array $documents ): void {
		$shown = array_slice( $documents, 0, 10 );

		echo '<ul class="equalify-iris-confirm-list">';

		foreach ( $shown as $document ) {
			echo '<li>' . esc_html( Equalify_Iris_Documents::display_name( $document ) ) . '</li>';
		}

		$hidden = count( $documents ) - count( $shown );

		if ( $hidden > 0 ) {
			printf(
				'<li>%s</li>',
				esc_html(
					sprintf(
						/* translators: %s: how many more PDFs. */
						_n( 'and %s more', 'and %s more', $hidden, 'equalify-iris' ),
						number_format_i18n( $hidden )
					)
				)
			);
		}

		echo '</ul>';
	}

	/**
	 * The filter bar.
	 *
	 * A plain GET form, so the filtered view has a URL that can be bookmarked and
	 * shared. "Send me a link to the failures" is a reasonable thing to ask for.
	 *
	 * WHY THE LABELS ARE NOT VISIBLE
	 *
	 * They are still there, and a screen reader still reads them; they are just not
	 * drawn. A bold "Stage" beside a control whose first option reads "All stages" says
	 * the same thing twice, and three of those turned this bar into a wall of words
	 * above the thing you came to read. WordPress's own list tables hide the label on
	 * their search box for exactly this reason.
	 *
	 * The rule being followed: never remove a control's accessible name to tidy a
	 * screen — only stop repeating it where the control already carries it visually.
	 */
	private function render_filters( string $status, int $site_id, string $search ): void {
		$counts = Equalify_Iris_Documents::counts_by_status();

		echo '<form method="get" action="' . esc_url( network_admin_url( 'admin.php' ) ) . '" class="equalify-iris-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Equalify_Iris_Admin::SLUG . '-documents' ) . '">';

		echo '<label for="equalify-iris-status" class="screen-reader-text">' . esc_html__( 'Show only one stage', 'equalify-iris' ) . '</label>';
		echo '<select name="status" id="equalify-iris-status">';
		echo '<option value="">' . esc_html__( 'All stages', 'equalify-iris' ) . '</option>';

		foreach ( Equalify_Iris_Documents::status_labels() as $value => $label ) {
			$count = (int) $counts[ $value ];

			// A count, but only when there is one. Most stages are empty most of the
			// time, and thirteen options each ending in "(0)" is thirteen numbers to
			// read past to find the one that is not zero.
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $status, $value, false ),
				esc_html( $count ? $label . ' (' . number_format_i18n( $count ) . ')' : $label )
			);
		}

		echo '</select> ';

		$this->render_site_filter( $site_id );

		echo '<label for="equalify-iris-search" class="screen-reader-text">' . esc_html__( 'File name contains', 'equalify-iris' ) . '</label>';
		printf(
			'<input type="search" name="s" id="equalify-iris-search" value="%s" placeholder="%s"> ',
			esc_attr( $search ),
			esc_attr__( 'File name contains…', 'equalify-iris' )
		);

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'equalify-iris' ) . '</button>';
		echo '</form>';
	}

	/**
	 * The "which site?" dropdown — on a network with more than one site.
	 *
	 * A network of one is a real and common way to run multisite, and there the control
	 * offers a choice between "all sites" and the only site. It is rendered anyway if a
	 * site filter is somehow already applied, so a URL never leaves someone looking at a
	 * filtered list with no way to clear it.
	 */
	private function render_site_filter( int $site_id ): void {
		// Bounded at 200 sites on purpose: a select with a thousand options is not
		// usable, and building it means a query per site for the name. Above that,
		// filter by URL search instead.
		$sites = get_sites(
			array(
				'number'  => 200,
				'orderby' => 'id',
			)
		);

		if ( count( $sites ) < 2 && ! $site_id ) {
			return;
		}

		echo '<label for="equalify-iris-site" class="screen-reader-text">' . esc_html__( 'Show only one site', 'equalify-iris' ) . '</label>';
		echo '<select name="site" id="equalify-iris-site">';
		echo '<option value="0">' . esc_html__( 'All sites', 'equalify-iris' ) . '</option>';

		foreach ( $sites as $site ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $site->blog_id,
				selected( $site_id, (int) $site->blog_id, false ),
				esc_html( untrailingslashit( $site->domain . $site->path ) )
			);
		}

		echo '</select> ';
	}

	/**
	 * The table of documents.
	 *
	 * Five columns, where there were seven. Page count and how many pages link to the
	 * PDF used to have a column each and a one-word heading apiece; as a line under the
	 * file name they take no width, need no heading, and read as a sentence about that
	 * file. Fewer columns is not a cosmetic win here — it is the difference between a
	 * table a screen reader user can hold in their head and one they cannot.
	 */
	private function render_table( array $items ): void {
		echo '<table class="widefat striped equalify-iris-documents">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'PDFs found across the network. Tick a row to act on it with the dropdown above.', 'equalify-iris' ) . '</caption>';
		echo '<thead><tr>';

		// A real <th scope="col"> rather than core's empty <td>, so the column has a
		// name for anyone navigating the table cell by cell.
		echo '<th scope="col" class="check-column"><span class="screen-reader-text">' . esc_html__( 'Ticked', 'equalify-iris' ) . '</span></th>';

		echo '<th scope="col">' . esc_html__( 'PDF', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Site', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Stage', 'equalify-iris' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Accessible version', 'equalify-iris' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $items as $document ) {
			$this->render_row( $document );
		}

		echo '</tbody></table>';
	}

	/**
	 * One row.
	 *
	 * The checkbox is in a <td> and the file name is the <th scope="row">, which is the
	 * other way round from core's list tables. Core makes the checkbox the row header,
	 * so a screen reader announces "checkbox" as the thing every cell in the row belongs
	 * to. The file name is what the row is about, and it is what should be read back
	 * when someone lands in the Stage column wondering whose stage this is.
	 */
	private function render_row( object $document ): void {
		$name     = Equalify_Iris_Documents::display_name( $document );
		$status   = (string) $document->status;
		$excluded = Equalify_Iris_Documents::EXCLUDED === $status;
		$field    = 'equalify-iris-tick-' . (int) $document->id;

		echo '<tr>';

		echo '<td class="check-column">';
		printf(
			'<label for="%1$s" class="screen-reader-text">%2$s</label><input type="checkbox" id="%1$s" name="documents[]" value="%3$s">',
			esc_attr( $field ),
			esc_html(
				sprintf(
					/* translators: %s: a PDF's name. */
					__( 'Tick %s', 'equalify-iris' ),
					$name
				)
			),
			esc_attr( (string) $document->id )
		);
		echo '</td>';

		// The PDF itself, then everything else known about the file on one quiet line
		// beneath it, then any error. Putting the error here rather than in its own
		// column means it gets the width it needs to be a sentence.
		echo '<th scope="row">';
		printf(
			'<a href="%s">%s</a>',
			esc_url( (string) $document->pdf_url ),
			esc_html( $name )
		);

		$facts = $this->facts_for( $document );

		if ( $facts ) {
			echo '<br><span class="description">' . esc_html( implode( ' · ', $facts ) ) . '</span>';
		}

		// An excluded document keeps its reason in the same field a failure uses, and
		// a reason is not a failure — so it is shown under the stage instead, in plain
		// grey rather than in red.
		if ( $document->last_error && ! $excluded ) {
			echo '<br><span class="equalify-iris-error">' . esc_html( (string) $document->last_error ) . '</span>';
		}

		echo '</th>';

		echo '<td>' . esc_html( $this->site_name( (int) $document->site_id ) ) . '</td>';

		echo '<td>' . esc_html( Equalify_Iris_Documents::status_label( $status ) );

		if ( $excluded && $document->last_error ) {
			echo '<br><span class="description">';
			printf(
				/* translators: %s: the reason a PDF was excluded. */
				esc_html__( 'Reason: %s', 'equalify-iris' ),
				esc_html( (string) $document->last_error )
			);
			echo '</span>';
		}

		echo '</td>';

		echo '<td>';

		if ( $document->doc_post_id ) {
			switch_to_blog( (int) $document->site_id );
			$permalink = get_permalink( (int) $document->doc_post_id );
			restore_current_blog();

			if ( $permalink ) {
				// "View" fifty times over is fifty identical links in a screen reader's
				// list of links. Naming the file makes each one say where it goes.
				printf(
					'<a href="%s">%s<span class="screen-reader-text"> %s</span></a>',
					esc_url( $permalink ),
					esc_html__( 'View', 'equalify-iris' ),
					esc_html( $name )
				);
			} else {
				echo '&mdash;';
			}
		} else {
			echo '&mdash;';
		}

		echo '</td>';

		echo '</tr>';
	}

	/**
	 * The quiet line under a file name: how long the PDF is, how many pages link to
	 * it, how many goes it has had.
	 *
	 * Each part is left out when it has nothing to say, so a freshly found PDF gets one
	 * short phrase rather than three placeholders and two question marks.
	 *
	 * @return array<string>
	 */
	private function facts_for( object $document ): array {
		$facts = array();

		$pages = (int) $document->page_count;

		if ( $pages > 0 ) {
			$facts[] = sprintf(
				/* translators: %s: number of pages in the PDF. */
				_n( '%s page', '%s pages', $pages, 'equalify-iris' ),
				number_format_i18n( $pages )
			);
		}

		$sightings = Equalify_Iris_Documents::sighting_count( (int) $document->id );

		if ( $sightings > 0 ) {
			$facts[] = sprintf(
				/* translators: %s: how many pages link to the PDF. */
				_n( 'linked from %s page', 'linked from %s pages', $sightings, 'equalify-iris' ),
				number_format_i18n( $sightings )
			);
		}

		$attempts = (int) $document->attempts;

		if ( $attempts > 0 ) {
			$facts[] = sprintf(
				/* translators: %s: how many conversion attempts have been made. */
				_n( '%s attempt', '%s attempts', $attempts, 'equalify-iris' ),
				number_format_i18n( $attempts )
			);
		}

		return $facts;
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
