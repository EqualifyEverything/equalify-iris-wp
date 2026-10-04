<?php
/**
 * WHAT IS THIS FILE?
 *
 * The list of a site's public PDFs on the Equalify Iris screen: every PDF linked
 * from published content, a menu, a widget or the theme's templates, plus any
 * that still has a tagged copy to delete.
 * Each one can be given accessibility tags, have its tagged copy opened, or have
 * it deleted, one at a time or in bulk.
 *
 * Each row says where its PDF is up to: waiting, at Iris and what Iris is doing
 * with it, and how long ago that started. When something goes wrong, Iris's own
 * reason is shown, whether it will be tried again, and how often it has been.
 * The links above the list show only the PDFs being tagged, or only the ones
 * that could not be.
 *
 * It is a WP_List_Table, so it looks, paginates and reads with a screen reader
 * like every other list in the WordPress admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Equalify_Iris_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/** The query argument for the links above the list. */
	const FILTER = 'pdf_status';

	/** @return array<string, array{0: string, 1: string[]}> Filter => label, statuses. */
	public static function filters(): array {
		return array(
			'working' => array( __( 'Being tagged', 'equalify-iris' ), array( Equalify_Iris_Tagger::QUEUED, Equalify_Iris_Tagger::WORKING ) ),
			'failed'  => array( __( 'Could not be tagged', 'equalify-iris' ), array( Equalify_Iris_Tagger::FAILED ) ),
			'tagged'  => array( __( 'Tagged', 'equalify-iris' ), array( Equalify_Iris_Tagger::TAGGED ) ),
		);
	}

	public static function current_filter(): string {
		$filter = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		return isset( self::filters()[ $filter ] ) ? $filter : '';
	}

	private static function current_statuses(): array {
		$filter = self::current_filter();

		return '' !== $filter ? self::filters()[ $filter ][1] : array();
	}

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'pdf',
				'plural'   => 'pdfs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox">',
			'document'    => __( 'PDF', 'equalify-iris' ),
			'linked_from' => __( 'Linked from', 'equalify-iris' ),
			'status'      => __( 'Accessibility tags', 'equalify-iris' ),
		);
	}

	protected function get_primary_column_name(): string {
		return 'document';
	}

	protected function get_bulk_actions(): array {
		return array(
			'equalify_iris_tag'    => __( 'Send to Iris for Tagging', 'equalify-iris' ),
			'equalify_iris_remove' => __( 'Delete Iris-Tagged Versions', 'equalify-iris' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_primary_column_name() );

		$total = Equalify_Iris_Discovery::count( 'listed', self::current_statuses() );

		$this->items = Equalify_Iris_Discovery::find( 'listed', self::PER_PAGE, ( $this->get_pagenum() - 1 ) * self::PER_PAGE, 0, self::current_statuses() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	protected function get_views(): array {
		$base    = remove_query_arg( array( self::FILTER, 'paged', 'equalify-iris' ) );
		$current = self::current_filter();
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'equalify-iris' ),
				esc_html( number_format_i18n( Equalify_Iris_Discovery::count( 'listed' ) ) )
			),
		);

		foreach ( self::filters() as $key => list( $label, $statuses ) ) {
			$count = Equalify_Iris_Discovery::count( 'listed', $statuses );

			if ( ! $count && $key !== $current ) {
				continue;
			}

			$views[ $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( self::FILTER, $key, $base ) ),
				$key === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	public function no_items(): void {
		if ( '' !== self::current_filter() ) {
			esc_html_e( 'No PDFs here right now.', 'equalify-iris' );
			return;
		}

		echo esc_html(
			Equalify_Iris_Discovery::indexed()
				? __( 'Nothing visitors can see on this site links to a PDF in its media library.', 'equalify-iris' )
				: __( 'Still reading this site’s content for PDF links. Check back in a few minutes.', 'equalify-iris' )
		);
	}

	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="equalify-iris-pdf-%1$d">%2$s</label><input type="checkbox" id="equalify-iris-pdf-%1$d" name="attachment[]" value="%1$d">',
			(int) $item,
			/* translators: %s: a PDF's title. */
			esc_html( sprintf( __( 'Select %s', 'equalify-iris' ), get_the_title( $item ) ) )
		);
	}

	protected function column_document( $item ): string {
		$file = wp_basename( (string) get_attached_file( $item ) );

		return sprintf(
			'<strong><a href="%s">%s</a></strong><br><span class="description">%s</span>',
			esc_url( (string) wp_get_attachment_url( $item ) ),
			esc_html( get_the_title( $item ) ),
			esc_html( $file )
		);
	}

	/** The row's actions, under the PDF's name. They include the small-screen details button. */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		$actions = $column_name === $primary ? self::actions( $item ) : array();

		return $actions ? $this->row_actions( $actions ) : parent::handle_row_actions( $item, $column_name, $primary );
	}

	protected function column_linked_from( $item ): string {
		$found = Equalify_Iris_Discovery::linked_from( $item );
		$links = array_map( 'esc_html', Equalify_Iris_Discovery::shown_in( $item ) );

		foreach ( $found['posts'] as $post ) {
			$title   = get_the_title( $post );
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $post ) ), esc_html( '' !== $title ? $title : __( '(no title)', 'equalify-iris' ) ) );
		}

		if ( ! $links ) {
			return esc_html__( 'Nothing visitors can see links to it any more.', 'equalify-iris' );
		}

		$more = $found['total'] - count( $found['posts'] );

		if ( $more > 0 ) {
			/* translators: %d: how many more pages link to the PDF. */
			$links[] = esc_html( sprintf( _n( 'and %d more', 'and %d more', $more, 'equalify-iris' ), $more ) );
		}

		return implode( ', ', $links );
	}

	protected function column_status( $item ): string {
		$status = Equalify_Iris_Tagger::status( $item );
		$labels = array(
			Equalify_Iris_Tagger::TAGGED  => array( 'yes-alt', __( 'Tagged', 'equalify-iris' ), '' ),
			Equalify_Iris_Tagger::QUEUED  => array( 'update', __( 'Being tagged', 'equalify-iris' ), '' ),
			Equalify_Iris_Tagger::WORKING => array( 'update', __( 'Being tagged', 'equalify-iris' ), '' ),
			Equalify_Iris_Tagger::FAILED  => array( 'warning', __( 'Could not be tagged', 'equalify-iris' ), '#b32d2e' ),
		);

		list( $icon, $label, $color ) = $labels[ $status ] ?? array( '', __( 'Not tagged', 'equalify-iris' ), '' );

		$html = sprintf(
			'<strong%s>%s%s</strong>',
			$color ? ' style="color:' . esc_attr( $color ) . '"' : '',
			$icon ? '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span> ' : '',
			esc_html( $label )
		);

		foreach ( self::details( $item ) as $line ) {
			$html .= '<br>' . esc_html( $line );
		}

		$problem = self::problem( $item );

		if ( '' !== $problem ) {
			$html .= '<br><span style="color:#b32d2e">' . esc_html( $problem ) . '</span>';
		}

		foreach ( self::notes( $item ) as $note ) {
			$html .= '<br><span class="description">' . esc_html( $note ) . '</span>';
		}

		return $html;
	}

	/**
	 * Where this PDF is up to, and since when, as sentences.
	 *
	 * @return string[]
	 */
	public static function details( int $attachment_id ): array {
		$since = Equalify_Iris_Tagger::since( $attachment_id );
		/* translators: %s: a length of time, such as "3 minutes". */
		$ago = $since ? sprintf( __( '%s ago', 'equalify-iris' ), human_time_diff( $since ) ) : '';

		switch ( Equalify_Iris_Tagger::status( $attachment_id ) ) {
			case Equalify_Iris_Tagger::TAGGED:
				return array( __( 'Links on this site open the Iris-tagged version.', 'equalify-iris' ) );

			case Equalify_Iris_Tagger::QUEUED:
				$lines = array(
					Equalify_Iris_Runner::slots() > 0
						? __( 'Waiting to be sent to Iris. This happens within a few minutes.', 'equalify-iris' )
						: sprintf(
							/* translators: %d: PDFs at Iris across the network. */
							_n( 'Waiting its turn: Iris is working on %d PDF from this network, the most it takes at once.', 'Waiting its turn: Iris is working on %d PDFs from this network, the most it takes at once.', Equalify_Iris_Runner::count_at_iris(), 'equalify-iris' ),
							Equalify_Iris_Runner::count_at_iris()
						),
				);

				if ( $ago ) {
					/* translators: %s: how long ago, such as "3 minutes ago". */
					$lines[] = sprintf( __( 'Asked for %s.', 'equalify-iris' ), $ago );
				}

				return $lines;

			case Equalify_Iris_Tagger::WORKING:
				$stages = array(
					'running'          => __( 'Iris is converting it.', 'equalify-iris' ),
					'ready_for_review' => __( 'Iris has converted it, and is adding the tags.', 'equalify-iris' ),
					'closed'           => __( 'Iris has converted it, and is adding the tags.', 'equalify-iris' ),
				);

				$lines = array( $stages[ Equalify_Iris_Tagger::stage( $attachment_id ) ] ?? __( 'Sent to Iris, which will start on it shortly.', 'equalify-iris' ) );

				if ( $ago ) {
					/* translators: %s: how long ago, such as "3 minutes ago". */
					$lines[] = sprintf( __( 'Sent %s. This usually takes a few minutes.', 'equalify-iris' ), $ago );
				}

				return $lines;

			case Equalify_Iris_Tagger::FAILED:
				$lines = array( Equalify_Iris_Tagger::error( $attachment_id ) );

				if ( $ago ) {
					/* translators: %s: how long ago, such as "3 minutes ago". */
					$lines[] = sprintf( __( 'Stopped %s. Send it to Iris again to retry.', 'equalify-iris' ), $ago );
				}

				return array_filter( $lines );

			case Equalify_Iris_Tagger::REMOVED:
				return array( __( 'The Iris-tagged version was deleted, so links open the original.', 'equalify-iris' ) );

			default:
				return array();
		}
	}

	/** The last thing that went wrong on a PDF still being tagged, or ''. */
	public static function problem( int $attachment_id ): string {
		$error = Equalify_Iris_Tagger::error( $attachment_id );

		if ( '' === $error || ! in_array( Equalify_Iris_Tagger::status( $attachment_id ), array( Equalify_Iris_Tagger::QUEUED, Equalify_Iris_Tagger::WORKING ), true ) ) {
			return '';
		}

		$tries = max( 1, Equalify_Iris_Tagger::attempts( $attachment_id ) );

		return sprintf(
			/* translators: 1: tries so far, 2: the problem Equalify Iris reported. */
			_n( 'Problem on the last try (%1$d so far), so it will be tried again: %2$s', 'Problem on the last try (%1$d so far), so it will be tried again: %2$s', $tries, 'equalify-iris' ),
			$tries,
			$error
		);
	}

	/**
	 * What the tagger could not do cleanly, as sentences. Codes this list does not
	 * know are shown as they are rather than hidden.
	 *
	 * @return string[]
	 */
	private static function notes( int $attachment_id ): array {
		if ( Equalify_Iris_Tagger::TAGGED !== Equalify_Iris_Tagger::status( $attachment_id ) ) {
			return array();
		}

		$say = array(
			'missing_alt'           => __( 'Some images have no description.', 'equalify-iris' ),
			'page_not_tagged'       => __( 'Some pages were left untagged.', 'equalify-iris' ),
			'page_not_in_html'      => __( 'Some pages could not be read and were left untagged.', 'equalify-iris' ),
			'no_text_positions'     => __( 'Some pages have no text the tagger could find, so they were left untagged.', 'equalify-iris' ),
			'field_not_in_html'     => __( 'Some form fields were not found, so a screen reader reaches them at the end of their page.', 'equalify-iris' ),
			'unmatched_link'        => __( 'Some links were not found, so a screen reader reaches them at the end of their page.', 'equalify-iris' ),
			'font_not_embedded'     => __( 'The PDF uses fonts it does not include, so it cannot claim to meet PDF/UA.', 'equalify-iris' ),
			'missing_glyph'         => __( 'Some characters could not be written in the PDF’s fonts, so they may read wrongly.', 'equalify-iris' ),
			'repaired'              => __( 'The PDF was damaged. The tagged file is a repaired copy.', 'equalify-iris' ),
			'signature_invalidated' => __( 'The PDF was signed. The signature no longer holds, because the file changed.', 'equalify-iris' ),
		);

		$notes = array();

		foreach ( Equalify_Iris_Tagger::warnings( $attachment_id ) as $code ) {
			if ( in_array( $code, array( 'retagged', 'no_title' ), true ) ) {
				continue;
			}

			/* translators: %s: a warning code from the tagger. */
			$notes[] = $say[ $code ] ?? sprintf( __( 'Note from the tagger: %s', 'equalify-iris' ), $code );
		}

		return $notes;
	}

	/**
	 * The actions this PDF has right now, as HTML links.
	 *
	 * @return array<string, string>
	 */
	public static function actions( int $attachment_id ): array {
		$status = Equalify_Iris_Tagger::status( $attachment_id );

		if ( in_array( $status, array( Equalify_Iris_Tagger::QUEUED, Equalify_Iris_Tagger::WORKING ), true ) ) {
			return array();
		}

		$actions = array();

		if ( Equalify_Iris_Tagger::TAGGED !== $status && Equalify_Iris_Discovery::is_public( $attachment_id ) ) {
			$actions['tag'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::action_url( 'equalify_iris_tag', $attachment_id ) ),
				esc_html__( 'Send to Iris for Tagging', 'equalify-iris' )
			);
		}

		if ( '' !== Equalify_Iris_Tagger::tagged_url( $attachment_id ) ) {
			$actions['view']   = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s<span class="screen-reader-text"> %s</span></a>',
				esc_url( Equalify_Iris_Tagger::tagged_url( $attachment_id ) ),
				esc_html__( 'View Iris-Tagged Version', 'equalify-iris' ),
				esc_html__( '(opens in a new tab)', 'equalify-iris' )
			);
			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm( %s );">%s</a>',
				esc_url( self::action_url( 'equalify_iris_remove', $attachment_id ) ),
				esc_attr( wp_json_encode( __( 'Delete the Iris-tagged version? Links will point at the original PDF again.', 'equalify-iris' ) ) ),
				esc_html__( 'Delete Iris-Tagged Version', 'equalify-iris' )
			);
		}

		return $actions;
	}

	private static function action_url( string $action, int $attachment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => $action,
					'attachment' => $attachment_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $attachment_id
		);
	}
}
