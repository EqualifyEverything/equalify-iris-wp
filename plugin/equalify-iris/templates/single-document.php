<?php
/**
 * WHAT IS THIS FILE?
 *
 * The viewer: a whole HTML document whose job is to show one converted PDF and get out
 * of the way.
 *
 * WHY DOES IT EXIST?
 *
 * This is the page the entire plugin exists to produce. Everything else is plumbing.
 * If this page is not genuinely usable with a screen reader, none of the rest mattered.
 *
 * HOW IT IS ARRANGED, AND WHY
 *
 * A reader who arrives here wants the document. Everything the plugin has to say about
 * the document — what it is, where it came from, when it was made, what is in it — used
 * to be printed above the text, so the first screenful was a title, a paragraph of
 * explanation, a list of facts and a table of contents, and the document began somewhere
 * below the fold. All of that was worth saying and none of it was worth saying first.
 *
 * So the page is now three things, in this order:
 *
 *   1. A quiet bar: the document's title, and two closed panels.
 *   2. The document.
 *   3. Nothing.
 *
 * The panels are <details> elements, closed by default. Contents holds the table of
 * contents. "About this accessible version of a PDF" holds one sentence saying what the
 * page is and one saying what to do if it is wrong, then the link to the original, where
 * it appears on the site, and when it was converted.
 *
 * That note is deliberately two sentences. It was four, across two paragraphs at opposite
 * ends of the panel, and it also claimed the PDF was "the authoritative version" — which
 * is a thing for a publisher to decide and not for a plugin to announce on their behalf.
 *
 * WHY <details> AND NOT A SCRIPTED TOGGLE
 *
 * Because this plugin ships no JavaScript, and a viewer is the last place to start. The
 * page it replaces on equalify.app is a React app that renders the document client-side;
 * with scripting off, or before the bundle arrives, or if it fails, that page is blank.
 * A document that cannot be read without JavaScript running is not an accessible
 * version of anything.
 *
 * <details> is the platform's own disclosure widget. It needs no script, it is announced
 * as a collapsed or expanded group, it is keyboard operable, and it works in a text
 * browser. What it cannot do is close on Escape or on a click elsewhere — neither of
 * which a disclosure is required to do, unlike a menu or a dialog.
 *
 * WHY A CLOSED PANEL IS REALLY HIDDEN, AND WHAT THAT COST
 *
 * Content inside a closed <details> is removed from the accessibility tree, exactly like
 * display:none. That is the point — a screen reader user hears two collapsed panels
 * rather than four paragraphs of preamble — but it has two consequences that shaped the
 * markup:
 *
 *   THE TITLE STAYS VISIBLE, OUTSIDE THE PANELS.
 *   It is the page's only <h1> and the only thing naming the document as a whole. Put it
 *   inside a closed panel and the page has no heading at all for anyone navigating by
 *   heading, and what they would meet first is whatever heading the PDF happened to
 *   start with — which for a real document is as likely to be "1. Introduction" as
 *   anything useful. It is set small and quiet instead: present, not competing.
 *
 *   WHAT THIS PAGE IS GETS SAID TWICE, ONCE FOR PAPER.
 *   A closed panel does not print, and a printout that does not say where it came from
 *   will be filed as if it were the PDF. So there is one print-only line carrying that
 *   and the PDF's address. It is display:none on screen, which keeps it out of the
 *   accessibility tree, so nobody hears it twice. This is the only element on the page
 *   that exists for one medium.
 *
 * WHAT IS NOT HERE ANY MORE
 *
 * The site's name and a link home. They were one line in a masthead, and they were still
 * the site talking about itself above somebody else's document. The way back to the site
 * is inside the About panel, next to the page the PDF appears on, which is the more
 * useful destination anyway.
 *
 * WHAT STILL MAKES IT USABLE
 *
 *   THREE LANDMARKS, ALL MEANINGFUL. A banner holding the title and the panels, and the
 *   main region holding the document. Landmarks are only a shortcut when there are few
 *   enough of them to be one.
 *
 *   A LINK TO THE ORIGINAL PDF, WITH ITS SIZE AND PAGE COUNT. Never trap someone in our
 *   version — some people need the PDF, to print, to file, because it is the record of
 *   authority. "4 MB, 18 pages" lets someone on a metered connection decide first. One
 *   click behind a labelled panel is not a trap; it was the loudest thing on the page
 *   and it did not need to be.
 *
 *   A SKIP LINK, FIRST IN THE TAB ORDER. Worth little when both panels are closed and
 *   worth a lot when Contents is open and holds forty links.
 *
 *   A VIEWPORT THAT ALLOWS ZOOM. No maximum-scale, no user-scalable=no. Preventing pinch
 *   zoom on a page whose purpose is being readable would fail WCAG 1.4.4 and the point.
 *
 *   ONE <main>. Iris returns its own <main> inside the converted HTML, which used to
 *   nest inside ours and give the page two main landmarks. class-frontend.php unwraps it
 *   at render time and class-html-cleaner.php no longer lets it through.
 *
 * HOW A THEME OVERRIDES THIS
 *
 * Add `single-equalify_iris_doc.php` to the theme. That is the ordinary WordPress
 * template hierarchy and needs no special knowledge of this plugin. A theme doing that
 * takes on printing the whole document, <head> and all, exactly as this file does.
 *
 * To put the site's furniture back without replacing the template, return false from
 * `equalify_iris_document_standalone`; the theme's stylesheets are then left in the
 * queue. To change how it looks, every colour and size is a custom property — see the
 * top of assets/css/document.css.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class( 'equalify-iris-viewer' ); ?>>

<?php
// The documented hook for anything that needs to be first in the body. Skipping it
// would quietly break consent banners and analytics that rely on it.
wp_body_open();
?>

<a class="equalify-iris-skip-link" href="#equalify-iris-text">
	<?php esc_html_e( 'Skip to the document', 'equalify-iris' ); ?>
</a>

<?php
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

	<div class="equalify-iris-viewer-layout">

		<header class="equalify-iris-viewer-bar">

			<h1 class="equalify-iris-viewer-title"><?php the_title(); ?></h1>

			<?php
			// A table of contents, but only when there is enough to navigate. Three
			// headings is roughly where jumping starts to save more time than reading
			// the list costs.
			if ( count( $headings ) >= 3 ) :
				?>
				<details class="equalify-iris-panel equalify-iris-panel-contents">
					<summary><?php esc_html_e( 'Contents', 'equalify-iris' ); ?></summary>

					<?php // aria-label rather than a heading: the summary above already says "Contents", and a second one would add a heading that competes with the document's own. ?>
					<nav class="equalify-iris-toc" aria-label="<?php esc_attr_e( 'Contents', 'equalify-iris' ); ?>">
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
				</details>
			<?php endif; ?>

			<details class="equalify-iris-panel equalify-iris-panel-about">
				<summary><?php esc_html_e( 'About this accessible version of a PDF', 'equalify-iris' ); ?></summary>

				<div class="equalify-iris-panel-body">

					<p class="equalify-iris-document-note">
						<?php
						/**
						 * Where "Equalify Iris" in the note points.
						 *
						 * The project itself, so a reader who wants to know what made this page
						 * can find out. Filterable because a network running its own Iris would
						 * rather point at their own page about it, and returning an empty string
						 * leaves the name as plain text.
						 *
						 * @param string $url Project URL.
						 */
						$project_url = (string) apply_filters( 'equalify_iris_project_url', 'https://github.com/equalifyEverything/equalify-iris' );

						// One paragraph, two sentences: what this page is, and what to do if it
						// is wrong. It used to be two paragraphs at opposite ends of the panel
						// saying between them what this one says — and the more of it there was,
						// the less of it anybody read.
						printf(
							/* translators: %s: "Equalify Iris", linked to the project. */
							esc_html__(
								'%s made this page automatically from a PDF, so it can be read with a screen reader, resized, and searched. If something looks wrong, tell whoever runs this website.',
								'equalify-iris'
							),
							$project_url
								? '<a href="' . esc_url( $project_url ) . '">' . esc_html__( 'Equalify Iris', 'equalify-iris' ) . '</a>'
								: esc_html__( 'Equalify Iris', 'equalify-iris' )
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

						<li>
							<?php
							printf(
								/* translators: %s: a link to the website's home page, labelled with the site's name. */
								esc_html__( 'Part of %s', 'equalify-iris' ),
								'<a href="' . esc_url( home_url( '/' ) ) . '" rel="home">' . esc_html( get_bloginfo( 'name' ) ) . '</a>'
							);
							?>
						</li>

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

				</div>
			</details>

		</header>

		<main id="equalify-iris-text" class="equalify-iris-document" tabindex="-1">

			<article <?php post_class(); ?>>

				<?php
				// Print only. See "THE NOTE IS SAID TWICE, ONCE FOR PAPER" at the top of
				// this file: a closed <details> does not print, and a printed copy that
				// does not say what it is will be filed as the original.
				?>
				<p class="equalify-iris-print-note">
					<?php
					esc_html_e( 'Made automatically from a PDF by Equalify Iris.', 'equalify-iris' );

					if ( $pdf_url ) {
						echo ' ' . esc_html( $pdf_url );
					}
					?>
				</p>

				<div class="equalify-iris-document-body">
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

			</article>

		</main>

	</div>

	<?php
endwhile;

wp_footer();
?>

</body>
</html>
