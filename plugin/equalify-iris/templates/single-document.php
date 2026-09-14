<?php
/**
 * WHAT IS THIS FILE?
 *
 * The page a visitor lands on when they follow the icon: the accessible version of
 * one PDF.
 *
 * WHY DOES IT EXIST?
 *
 * This is the page the entire plugin exists to produce. Everything else is
 * plumbing. If this page is not genuinely usable with a screen reader, none of the
 * rest mattered.
 *
 * WHAT MAKES IT USABLE — every decision below is here for a reason:
 *
 *   ONE <h1>, WHICH IS THE DOCUMENT'S TITLE.
 *   Screen reader users navigate by heading. The document's own headings come back
 *   from Equalify Iris starting at <h2>, so they slot underneath this one and the
 *   page has a single, correct outline.
 *
 *   IT SAYS WHAT IT IS, IMMEDIATELY.
 *   Someone arriving here needs to know they are reading a machine-made version of
 *   a PDF, not the original. Being straightforward about that is both honest and
 *   useful: it explains any oddity they run into, and it tells them the original is
 *   available.
 *
 *   A LINK TO THE ORIGINAL PDF, WITH ITS SIZE AND PAGE COUNT.
 *   Never trap someone in our version. Some people need the PDF — to print, to
 *   file, because it is the record of authority. The size and page count are there
 *   because "PDF, 4 MB, 18 pages" lets someone on a phone or a metered connection
 *   decide before they download.
 *
 *   A LINK BACK TO A PAGE THE PDF APPEARS ON.
 *   People arrive here from search engines with no idea what site they are on or
 *   what this document is part of. That link is their way into the context.
 *
 *   A TABLE OF CONTENTS, WHEN THERE ARE ENOUGH HEADINGS TO NEED ONE.
 *   Real documents are long. Jumping to section four should not mean reading
 *   sections one to three.
 *
 *   A SKIP LINK STRAIGHT TO THE DOCUMENT TEXT.
 *   Everything above the text is ours, not the document's. Somebody who has read it
 *   once should be able to get past it with one keystroke.
 *
 * HOW A THEME OVERRIDES THIS
 *
 * Add `single-equalify_iris_doc.php` to the theme. That is the ordinary WordPress
 * template hierarchy and needs no special knowledge of this plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();

	$doc_post_id   = get_the_ID();
	$attachment_id = (int) get_post_meta( $doc_post_id, Equalify_Iris_Post_Type::META_ATTACHMENT, true );
	$headings      = (array) get_post_meta( $doc_post_id, Equalify_Iris_Post_Type::META_HEADINGS, true );
	$page_count    = (int) get_post_meta( $doc_post_id, Equalify_Iris_Post_Type::META_PAGE_COUNT, true );
	$file_bytes    = (int) get_post_meta( $doc_post_id, Equalify_Iris_Post_Type::META_FILE_BYTES, true );
	$converted_at  = (int) get_post_meta( $doc_post_id, Equalify_Iris_Post_Type::META_CONVERTED_AT, true );

	$pdf_url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';

	// Where does this PDF appear? Used for the "part of" link. One query, and only
	// on this page.
	$appears_on = array();

	if ( $attachment_id && Equalify_Iris_Database::tables_exist() ) {
		$document = Equalify_Iris_Documents::find_by_attachment( get_current_blog_id(), $attachment_id );

		if ( $document ) {
			foreach ( Equalify_Iris_Documents::sightings_for( (int) $document->id ) as $sighting ) {
				$sighting_post = get_post( (int) $sighting->post_id );

				if ( $sighting_post && 'publish' === $sighting_post->post_status ) {
					$appears_on[] = $sighting_post;
				}

				// One is enough for a "you came from here" link. Listing every page a
				// document appears on would be a long list of near-duplicates that
				// nobody asked for.
				if ( $appears_on ) {
					break;
				}
			}
		}
	}
	?>

	<main id="equalify-iris-main" class="equalify-iris-document">

		<a class="equalify-iris-skip-link" href="#equalify-iris-text">
			<?php esc_html_e( 'Skip to the document text', 'equalify-iris' ); ?>
		</a>

		<article <?php post_class(); ?>>

			<header class="equalify-iris-document-header">

				<h1 class="equalify-iris-document-title"><?php the_title(); ?></h1>

				<p class="equalify-iris-document-note">
					<?php
					esc_html_e(
						'This is an accessible HTML version of a PDF, made automatically by Equalify Iris so that it can be read with a screen reader, resized, and searched. It is a machine-made copy: the original PDF is linked below and remains the authoritative version.',
						'equalify-iris'
					);
					?>
				</p>

				<ul class="equalify-iris-document-facts">

					<?php if ( $pdf_url ) : ?>
						<li>
							<a class="equalify-iris-original-link" href="<?php echo esc_url( $pdf_url ); ?>">
								<?php
								if ( $page_count && $file_bytes ) {
									printf(
										/* translators: 1: number of pages, 2: file size such as "4 MB". */
										esc_html__( 'Download the original PDF (%1$s pages, %2$s)', 'equalify-iris' ),
										esc_html( number_format_i18n( $page_count ) ),
										esc_html( size_format( $file_bytes ) )
									);
								} elseif ( $file_bytes ) {
									printf(
										/* translators: %s: file size such as "4 MB". */
										esc_html__( 'Download the original PDF (%s)', 'equalify-iris' ),
										esc_html( size_format( $file_bytes ) )
									);
								} else {
									esc_html_e( 'Download the original PDF', 'equalify-iris' );
								}
								?>
							</a>
						</li>
					<?php endif; ?>

					<?php foreach ( $appears_on as $context_post ) : ?>
						<li>
							<?php
							printf(
								/* translators: %s: a link to the page the PDF appears on. */
								esc_html__( 'Appears on: %s', 'equalify-iris' ),
								'<a href="' . esc_url( (string) get_permalink( $context_post ) ) . '">' . esc_html( get_the_title( $context_post ) ) . '</a>'
							);
							?>
						</li>
					<?php endforeach; ?>

					<?php if ( $converted_at ) : ?>
						<li>
							<?php
							printf(
								/* translators: %s: a date. */
								esc_html__( 'Converted on %s', 'equalify-iris' ),
								esc_html( wp_date( get_option( 'date_format' ), $converted_at ) )
							);
							?>
						</li>
					<?php endif; ?>

				</ul>

			</header>

			<?php
			// A table of contents, but only when there is enough to navigate. Three
			// headings is roughly where jumping starts to save more time than reading
			// the list costs.
			if ( count( $headings ) >= 3 ) :
				?>
				<nav class="equalify-iris-toc" aria-labelledby="equalify-iris-toc-heading">
					<h2 id="equalify-iris-toc-heading"><?php esc_html_e( 'Contents', 'equalify-iris' ); ?></h2>
					<ol>
						<?php foreach ( $headings as $heading ) : ?>
							<?php
							if ( empty( $heading['id'] ) || empty( $heading['text'] ) ) {
								continue;
							}
							?>
							<li class="equalify-iris-toc-level-<?php echo esc_attr( (string) ( $heading['level'] ?? 2 ) ); ?>">
								<a href="#<?php echo esc_attr( (string) $heading['id'] ); ?>">
									<?php echo esc_html( (string) $heading['text'] ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ol>
				</nav>
			<?php endif; ?>

			<div id="equalify-iris-text" class="equalify-iris-document-body" tabindex="-1">
				<?php
				// The content was cleaned against a strict allowlist when it was
				// saved (see class-html-cleaner.php), which is the right moment to do
				// it: once, on the way in, rather than on every page view.
				//
				// the_content() rather than echoing the raw post content, so themes
				// and other plugins can still filter it — and so our own icon filter
				// works if this document links to another PDF.
				the_content();
				?>
			</div>

			<footer class="equalify-iris-document-footer">
				<p>
					<?php
					esc_html_e(
						'Something wrong with this version? The original PDF above is the authoritative document. Please tell whoever runs this website, so the conversion can be looked at.',
						'equalify-iris'
					);
					?>
				</p>
			</footer>

		</article>

	</main>

	<?php
endwhile;

get_footer();
