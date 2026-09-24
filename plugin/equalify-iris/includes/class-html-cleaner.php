<?php
/**
 * WHAT IS THIS FILE?
 *
 * Cleans the HTML that comes back from Equalify Iris before we store it.
 *
 * WHY DOES IT EXIST?
 *
 * Two jobs, pulling in opposite directions.
 *
 *   1. SAFETY. Never store HTML from another service without checking it. Even a
 *      service we run and trust could be misconfigured, and stored HTML is served
 *      to every visitor from our own domain.
 *
 *   2. FIDELITY. Every accessibility feature in that HTML must survive. This is
 *      the whole product. A `scope` attribute stripped from a table header is
 *      exactly the damage this plugin exists to undo — we would have taken an
 *      inaccessible PDF, converted it beautifully, and then broken it ourselves
 *      on the last step.
 *
 * WordPress's own wp_kses_post() gets (1) right and (2) badly wrong. Its default
 * allowlist drops attributes that carry meaning for screen readers, and it does
 * not know about form elements at all — and Iris renders signature blocks and
 * fill-in fields as real forms, on purpose, because that is what they are.
 *
 * So this file starts from the WordPress allowlist and adds back everything Iris
 * legitimately produces. Every addition below is there because a real
 * accessibility feature depends on it.
 *
 * ALLOWLIST, NOT BLOCKLIST
 *
 * We list what is permitted, and everything else goes. The opposite approach —
 * listing what is forbidden — fails the moment someone invents a new way to
 * write a script tag, and history is full of blocklists that were bypassed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_HTML_Cleaner {

	/**
	 * Clean one document's HTML.
	 *
	 * @return array{html: string, removed_bytes: int}
	 *         The cleaned HTML, plus how many bytes cleaning removed. The caller
	 *         records that number so heavy stripping shows up as a figure in the
	 *         dashboard rather than as silently worse output nobody notices.
	 */
	public static function clean( string $html ): array {
		$before = strlen( $html );

		// Drop images first. Doing this before kses means we deal with the whole
		// <img> tag rather than whatever kses leaves of it.
		$html = self::remove_unresolvable_images( $html );

		// Then the page's own furniture, for the same reason: before kses, while the
		// tags are still whole.
		$html = self::remove_page_furniture( $html );

		$html = wp_kses( $html, self::allowed_html() );

		return array(
			'html'          => $html,
			'removed_bytes' => max( 0, $before - strlen( $html ) ),
		);
	}

	/**
	 * Remove <img> tags, keeping their captions.
	 *
	 * WHY REMOVE IMAGES AT ALL?
	 *
	 * Because there is nothing behind them. Iris returns content-only HTML and has
	 * no endpoint that serves extracted image files — there is no URL we could
	 * point an <img> at. Any src in the output refers to something on the Iris
	 * server we cannot fetch, so keeping the tag would give every visitor a broken
	 * image, and give a screen reader user an announcement for a picture that is
	 * not there.
	 *
	 * The meaning of a figure in an Iris document lives in its <figcaption>, which
	 * is left completely alone. Iris describes charts as data rather than pictures
	 * for the same reason, so in practice very little is lost.
	 */
	private static function remove_unresolvable_images( string $html ): string {
		return (string) preg_replace( '#<img\b[^>]*>#i', '', $html );
	}

	/**
	 * Remove the wrapper of the page Iris thought it was making.
	 *
	 * WHY THIS IS NEEDED
	 *
	 * What Iris converts a PDF into is a whole HTML document, so its output opens with a
	 * <title> and a <main>. We are storing that output as the body of a WordPress post,
	 * inside a page that has a <title> and a <main> of its own, and two of each is a
	 * problem in both cases:
	 *
	 *   <main>  — two main landmarks. "Jump to main" stops being one destination and
	 *             becomes a choice between two, one of which is the whole document and
	 *             one of which is also the whole document.
	 *   <title> — the document's title comes from the first <title> in the tree, so a
	 *             stray one in the body can decide what the browser tab, the bookmark
	 *             and the shared link all say. What it says is the source filename.
	 *
	 * WHY <main> IS UNWRAPPED BUT <title> IS DELETED WITH ITS CONTENTS
	 *
	 * Because <main> holds the document and <title> holds a filename we already know.
	 * Removing the tags and keeping the text is right for the first and wrong for the
	 * second — a deleted <title> tag with its text left behind is a stray line of
	 * "annual-report-1" above the document, which is how this was noticed.
	 *
	 * WHY NOT DOMDocument
	 *
	 * It would be more precise, and it also rewrites the whole document on the way out:
	 * it moves stray content into a <body> it invents, changes how entities are written,
	 * and needs libxml built in. Two named tags at the outside of the document do not
	 * justify that. kses runs immediately afterwards and is the actual safety boundary.
	 */
	private static function remove_page_furniture( string $html ): string {
		$html = (string) preg_replace( '#<title\b[^>]*>.*?</title\s*>#is', '', $html );

		return (string) preg_replace( '#</?main\b[^>]*>#i', '', $html );
	}

	/**
	 * The tags and attributes we allow.
	 *
	 * Built from the WordPress post allowlist, then extended. Each block below
	 * says why it is needed.
	 */
	public static function allowed_html(): array {
		$allowed = wp_kses_allowed_html( 'post' );

		// Attributes allowed on absolutely everything.
		//
		// `id` is essential and easy to overlook: Iris links footnote markers to
		// footnote bodies with `<sup><a href="#fn-1" id="fnref-1">`, and a table
		// cell can point at its headers with `headers="h1 h2"`. Strip ids and
		// every one of those relationships silently breaks — the links still look
		// like links and go nowhere.
		//
		// `lang` and `dir` matter for a document that quotes another language: a
		// screen reader switches pronunciation on them, and without them it reads
		// French with an English voice.
		$global = array(
			'id'               => true,
			'class'            => true,
			'lang'             => true,
			'dir'              => true,
			'title'            => true,
			'role'             => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'aria-hidden'      => true,
			'aria-live'        => true,
			'aria-current'     => true,
			'aria-required'    => true,
			'aria-expanded'    => true,
			'aria-controls'    => true,
			'aria-details'     => true,
			'aria-level'       => true,
			'aria-sort'        => true,
		);

		// Tags Iris uses that the WordPress list does not cover, or covers too
		// thinly. The values here are merged with whatever WordPress already
		// allowed for that tag rather than replacing it.
		$additions = array(

			// --- Tables -----------------------------------------------------
			// The single most important block in this file. A data table is only
			// usable with a screen reader if the header cells are marked as
			// headers and their direction is stated. `scope="col"` and
			// `scope="row"` are how that is done, and `headers` is how an
			// irregular table does it when scope is not enough. Without these a
			// converted table is a grid of unlabelled numbers.
			'table'      => array( 'summary' => true ),
			'caption'    => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tfoot'      => array(),
			'tr'         => array(),
			'th'         => array(
				'scope'   => true,
				'colspan' => true,
				'rowspan' => true,
				'abbr'    => true,
				'headers' => true,
			),
			'td'         => array(
				'colspan' => true,
				'rowspan' => true,
				'headers' => true,
			),
			'colgroup'   => array( 'span' => true ),
			'col'        => array( 'span' => true ),

			// --- Forms ------------------------------------------------------
			// Iris renders a signature block or a set of fill-in lines as a real
			// form, deliberately: a screen reader user meeting a form control
			// learns "this is a field for someone to complete", which is exactly
			// what that part of the page is. Drop these and the same content
			// arrives as unlabelled runs of text.
			//
			// These are inert here — a <form> with no action does nothing when
			// submitted — so allowing them adds structure, not behaviour.
			'form'       => array(),
			'fieldset'   => array(),
			'legend'     => array(),
			'label'      => array( 'for' => true ),
			'input'      => array(
				'type'     => true,
				'value'    => true,
				'readonly' => true,
				'checked'  => true,
				'disabled' => true,
				'name'     => true,
				'size'     => true,
				'maxlength' => true,
				'placeholder' => true,
			),
			'textarea'   => array(
				'readonly' => true,
				'rows'     => true,
				'cols'     => true,
				'name'     => true,
			),
			'select'     => array( 'name' => true, 'multiple' => true, 'disabled' => true ),
			'option'     => array( 'value' => true, 'selected' => true ),
			'optgroup'   => array( 'label' => true ),
			'output'     => array( 'for' => true ),

			// --- Lists ------------------------------------------------------
			// `start` and `reversed` keep a list numbered the way the source
			// document numbered it. A list that visibly began at 14 in the PDF and
			// begins at 1 in our HTML is a factual error about the document.
			'ol'         => array( 'start' => true, 'reversed' => true, 'type' => true ),
			'ul'         => array(),
			'li'         => array( 'value' => true ),
			'dl'         => array(),
			'dt'         => array(),
			'dd'         => array(),

			// --- Figures and quotations -------------------------------------
			'figure'     => array(),
			'figcaption' => array(),
			'blockquote' => array( 'cite' => true ),
			'q'          => array( 'cite' => true ),
			'cite'       => array(),

			// --- Footnotes and inline semantics -----------------------------
			// <sup>/<sub> carry the footnote markers. <abbr title> expands an
			// abbreviation, which a screen reader can read out.
			'sup'        => array(),
			'sub'        => array(),
			'abbr'       => array(),
			'time'       => array( 'datetime' => true ),
			'mark'       => array(),
			'small'      => array(),
			'em'         => array(),
			'strong'     => array(),
			'code'       => array(),
			'pre'        => array(),
			'kbd'        => array(),
			'samp'       => array(),
			'var'        => array(),
			'bdi'        => array(),
			'bdo'        => array(),
			'ruby'       => array(),
			'rt'         => array(),
			'rp'         => array(),
			'wbr'        => array(),

			// --- Structure --------------------------------------------------
			'h1'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
			'p'          => array(),
			'div'        => array(),
			'span'       => array(),
			'section'    => array(),
			'article'    => array(),
			'aside'      => array(),
			'nav'        => array(),
			'header'     => array(),
			'footer'     => array(),
			'hr'         => array(),
			'br'         => array(),
			'a'          => array(
				'href'     => true,
				'target'   => true,
				'rel'      => true,
				'download' => true,
			),
			'details'    => array( 'open' => true ),
			'summary'    => array(),
		);

		foreach ( $additions as $tag => $attributes ) {
			$existing        = isset( $allowed[ $tag ] ) && is_array( $allowed[ $tag ] ) ? $allowed[ $tag ] : array();
			$allowed[ $tag ] = array_merge( $existing, $attributes, $global );
		}

		// Anything WordPress already allowed also gets the global attributes, so
		// an `id` or `lang` survives on a tag we did not list above.
		foreach ( $allowed as $tag => $attributes ) {
			if ( is_array( $attributes ) ) {
				$allowed[ $tag ] = array_merge( $attributes, $global );
			}
		}

		// Explicitly not allowed, so nobody has to wonder: script, style, iframe,
		// object, embed, link, meta, base, and every on* event attribute. They are
		// absent from the list, which is all it takes — but they are named here so
		// their absence reads as a decision rather than an oversight.
		//
		// <main> and <title> are in here for a different reason — not danger, but
		// duplication. See remove_page_furniture() above, which is what actually deals
		// with them; they are listed here as well so that a form of either tag the
		// regex there does not recognise still cannot reach the stored HTML.
		unset(
			$allowed['script'],
			$allowed['style'],
			$allowed['iframe'],
			$allowed['object'],
			$allowed['embed'],
			$allowed['link'],
			$allowed['meta'],
			$allowed['base'],
			$allowed['main'],
			$allowed['title'],
			$allowed['form']['action'],
			$allowed['form']['method']
		);

		/**
		 * Adjust what the converted HTML is allowed to contain.
		 *
		 * Filter with care. Removing something from this list will silently
		 * degrade the accessibility of every document converted afterwards.
		 *
		 * @param array $allowed Tag and attribute allowlist in wp_kses() format.
		 */
		return apply_filters( 'equalify_iris_allowed_html', $allowed );
	}

	/**
	 * Pull a table of contents out of the document's headings.
	 *
	 * Returns a flat list of headings with their level, text, and the id to link
	 * to. Any heading with no id is given one, so the returned HTML and the
	 * returned list always agree — building a table of contents whose links point
	 * at ids that do not exist is worse than having no table of contents.
	 *
	 * @return array{html: string, headings: array<array{level:int, text:string, id:string}>}
	 */
	public static function extract_headings( string $html ): array {
		$headings = array();
		$used_ids = array();

		$html = (string) preg_replace_callback(
			'#<(h[2-4])\b([^>]*)>(.*?)</\1>#is',
			static function ( array $match ) use ( &$headings, &$used_ids ) {
				$tag        = strtolower( $match[1] );
				$attributes = $match[2];
				$inner      = $match[3];
				$text       = trim( wp_strip_all_tags( $inner ) );

				if ( '' === $text ) {
					return $match[0];
				}

				// Reuse the id Iris already gave this heading when there is one,
				// so any in-document links it created keep working.
				if ( preg_match( '#\bid=(["\'])(.*?)\1#i', $attributes, $id_match ) ) {
					$id = $id_match[2];
				} else {
					$id = sanitize_title( $text );

					if ( '' === $id ) {
						$id = 'section';
					}

					// Two headings with the same words would otherwise get the
					// same id, and a duplicate id makes both links ambiguous.
					$base    = $id;
					$counter = 2;

					while ( in_array( $id, $used_ids, true ) ) {
						$id = $base . '-' . $counter;
						++$counter;
					}

					$attributes .= ' id="' . esc_attr( $id ) . '"';
				}

				$used_ids[] = $id;

				$headings[] = array(
					'level' => (int) substr( $tag, 1 ),
					'text'  => $text,
					'id'    => $id,
				);

				return '<' . $tag . $attributes . '>' . $inner . '</' . $tag . '>';
			},
			$html
		);

		return array(
			'html'     => $html,
			'headings' => $headings,
		);
	}
}
