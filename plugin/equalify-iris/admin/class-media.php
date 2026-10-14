<?php
/**
 * WHAT IS THIS FILE?
 *
 * What the media library says about the PDFs Equalify Iris has tagged: a column
 * in the list view, and a line in each PDF's details, in the grid's pop-up and on
 * its edit screen.
 *
 * The File URL is left as it is, because it is already right: the tagged
 * version is at the PDF's own address (see Equalify_Iris_Tagger). Editors who
 * copy it get the tagged version, and so does anyone they send it to.
 *
 * Both read post meta WordPress loads with the attachments anyway, so they add
 * no queries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Media {

	const COLUMN = 'equalify_iris';

	public static function init(): void {
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'field' ), 10, 2 );
	}

	public static function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Equalify Iris', 'equalify-iris' );

		return $columns;
	}

	public static function column( $column, $attachment_id ): void {
		if ( self::COLUMN === $column && Equalify_Iris_Tagger::is_pdf( (int) $attachment_id ) ) {
			echo esc_html( self::summary( (int) $attachment_id ) );
		}
	}

	/** One sentence on where a PDF is up to, or '' for one Iris was never asked about. */
	public static function summary( int $attachment_id ): string {
		$status = Equalify_Iris_Tagger::status( $attachment_id );
		$since  = Equalify_Iris_Tagger::since( $attachment_id );

		if ( Equalify_Iris_Tagger::TAGGED === $status && $since ) {
			/* translators: %s: a date. */
			return sprintf( __( 'Tagged by Equalify Iris on %s.', 'equalify-iris' ), date_i18n( (string) get_option( 'date_format' ), $since ) );
		}

		if ( Equalify_Iris_Tagger::has_version( $attachment_id ) ) {
			// Being tagged again, or it failed this time: the last version stays.
			return __( 'Tagged by Equalify Iris.', 'equalify-iris' );
		}

		switch ( $status ) {
			case Equalify_Iris_Tagger::QUEUED:
			case Equalify_Iris_Tagger::WORKING:
				return __( 'Being tagged by Equalify Iris.', 'equalify-iris' );

			case Equalify_Iris_Tagger::FAILED:
				return __( 'Equalify Iris could not tag it.', 'equalify-iris' );

			case Equalify_Iris_Tagger::REMOVED:
				return __( 'Its Iris-tagged version was deleted. This is the original.', 'equalify-iris' );

			default:
				return '';
		}
	}

	/**
	 * @param array   $fields The attachment's fields.
	 * @param WP_Post $post   The attachment.
	 */
	public static function field( $fields, $post ) {
		if ( ! is_array( $fields ) || ! $post instanceof WP_Post || ! Equalify_Iris_Tagger::is_pdf( $post->ID ) ) {
			return $fields;
		}

		$summary = self::summary( $post->ID );

		if ( '' === $summary ) {
			return $fields;
		}

		$html = esc_html( $summary );

		if ( Equalify_Iris_Tagger::has_version( $post->ID ) ) {
			$html .= ' ' . esc_html__( 'Its File URL opens the tagged version. The original is kept, and goes back at that address if the tagged version is deleted.', 'equalify-iris' );
		}

		if ( current_user_can( 'manage_options' ) ) {
			$html .= sprintf( ' <a href="%s">%s</a>', esc_url( Equalify_Iris_Admin::site_page_url() ), esc_html__( 'Manage on the Equalify Iris screen', 'equalify-iris' ) );
		}

		$fields[ self::COLUMN ] = array(
			'label' => __( 'Equalify Iris', 'equalify-iris' ),
			'input' => 'html',
			'html'  => '<p class="description">' . $html . '</p>',
		);

		return $fields;
	}
}
