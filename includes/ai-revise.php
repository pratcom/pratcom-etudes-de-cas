<?php
/**
 * AI correction of a case study.
 *
 * A light revision, not a rewrite: spelling, grammar, punctuation and
 * formatting of the text, the key results and the testimonial. The H2
 * sections keep their anchors (#section-1 ...). Links stay on their words;
 * images, videos, tables and other blocks are kept as-is, at their place.
 * Title, slug, client, website, logo and period are never touched. Nothing
 * is saved before "Apply"; WordPress keeps the old text in the revisions.
 */

namespace Pratcom\EtudesDeCas\AI;

defined( 'ABSPATH' ) || exit;

const META_PROPOSAL = '_pedc_ai_revision';

/** Reads a study into items for the model, and keeps what must not change. */
final class Reader {

	/** @var string[] Blocks kept as-is, by ref. */
	public $keeps = [];

	private const INLINE = [
		'a'      => [ 'href' => true, 'title' => true, 'target' => true, 'rel' => true ],
		'strong' => [],
		'b'      => [],
		'em'     => [],
		'i'      => [],
		'br'     => [],
	];

	public function inline( string $html ): string {
		$html = wp_kses( $html, self::INLINE );
		return trim( (string) preg_replace( '/\s+/u', ' ', $html ) );
	}

	private function keep( string $markup, string $what ): array {
		$this->keeps[] = trim( $markup );
		return [ 'type' => 'keep', 'ref' => count( $this->keeps ) - 1, 'what' => $what ];
	}

	private function inner_html( \DOMNode $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/** @return \DOMNode[] */
	private function dom_nodes( string $html ): array {
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><html><body><div id="pedc-root">' . $html . '</div></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $doc->getElementById( 'pedc-root' );
		$out  = [];
		if ( $root ) {
			foreach ( $root->childNodes as $child ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/** List items (inner HTML), nested items flattened. */
	private function list_items( string $html ): array {
		$items = [];
		foreach ( $this->dom_nodes( $html ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			foreach ( $node->getElementsByTagName( 'li' ) as $li ) {
				$clone = $li->cloneNode( true );
				foreach ( [ 'ul', 'ol' ] as $tag ) {
					$nested = $clone->getElementsByTagName( $tag );
					while ( $nested->length ) {
						$nested->item( 0 )->parentNode->removeChild( $nested->item( 0 ) );
					}
				}
				$text = $this->inline( $this->inner_html( $clone ) );
				if ( '' !== wp_strip_all_tags( $text ) ) {
					$items[] = $text;
				}
			}
		}
		return $items;
	}

	private function image_block( \DOMElement $img ): string {
		$attrs = [];
		$class = '';
		if ( preg_match( '/wp-image-(\d+)/', $img->getAttribute( 'class' ), $m ) ) {
			$attrs['id'] = (int) $m[1];
			$class       = ' class="wp-image-' . (int) $m[1] . '"';
		}
		return get_comment_delimited_block_content( 'core/image', $attrs, '<figure class="wp-block-image"><img src="' . esc_url( $img->getAttribute( 'src' ) ) . '" alt="' . esc_attr( $img->getAttribute( 'alt' ) ) . '"' . $class . '/></figure>' );
	}

	/** Items from classic (non-block) HTML. */
	private function items_from_classic( string $html ): array {
		$items = [];
		foreach ( $this->dom_nodes( wpautop( $html ) ) as $node ) {
			if ( $node instanceof \DOMText ) {
				$text = trim( $node->textContent );
				if ( '' !== $text ) {
					$items[] = [ 'type' => 'paragraph', 'text' => esc_html( $text ) ];
				}
				continue;
			}
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			$tag   = strtolower( $node->tagName );
			$outer = $node->ownerDocument->saveHTML( $node );
			if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
				$items[] = [ 'type' => 'heading', 'level' => max( 2, (int) $m[1] ), 'text' => trim( $node->textContent ), 'anchor' => (string) $node->getAttribute( 'id' ) ];
			} elseif ( 'ul' === $tag || 'ol' === $tag ) {
				$items[] = [ 'type' => 'list', 'items' => $this->list_items( $outer ) ];
			} elseif ( 'blockquote' === $tag ) {
				$items[] = [ 'type' => 'quote', 'text' => $this->inline( $this->inner_html( $node ) ) ];
			} elseif ( 'table' === $tag ) {
				$items[] = $this->keep( get_comment_delimited_block_content( 'core/table', [], '<figure class="wp-block-table">' . $outer . '</figure>' ), 'table' );
			} elseif ( 'p' === $tag ) {
				foreach ( iterator_to_array( $node->getElementsByTagName( 'img' ) ) as $img ) {
					$items[] = $this->keep( $this->image_block( $img ), 'image' );
					$img->parentNode->removeChild( $img );
				}
				$text = trim( $node->textContent );
				if ( preg_match( '/^\[[^\]]+\]$/', $text ) ) {
					$items[] = $this->keep( get_comment_delimited_block_content( 'core/shortcode', [], $text ), 'shortcode' );
				} elseif ( '' !== $text ) {
					$items[] = [ 'type' => 'paragraph', 'text' => $this->inline( $this->inner_html( $node ) ) ];
				}
			} elseif ( 'img' === $tag ) {
				$items[] = $this->keep( $this->image_block( $node ), 'image' );
			} else {
				$items[] = $this->keep( get_comment_delimited_block_content( 'core/html', [], $outer ), $tag );
			}
		}
		return $items;
	}

	/** Text items and kept blocks, in reading order. */
	public function read( string $content ): array {
		$this->keeps = [];
		$items       = [];
		foreach ( parse_blocks( $content ) as $block ) {
			$name = $block['blockName'];
			$html = (string) $block['innerHTML'];
			if ( null === $name || 'core/freeform' === $name ) {
				$classic = null === $name ? $html : render_block( $block );
				if ( '' !== trim( $classic ) ) {
					$items = array_merge( $items, $this->items_from_classic( $classic ) );
				}
				continue;
			}
			switch ( $name ) {
				case 'core/paragraph':
					$text = $this->inline( (string) preg_replace( '#^\s*<p[^>]*>|</p>\s*$#i', '', trim( $html ) ) );
					if ( '' !== wp_strip_all_tags( $text ) ) {
						$items[] = [ 'type' => 'paragraph', 'text' => $text ];
					}
					break;
				case 'core/heading':
					$items[] = [
						'type'   => 'heading',
						'level'  => max( 2, (int) ( $block['attrs']['level'] ?? 2 ) ),
						'text'   => trim( wp_strip_all_tags( $html ) ),
						'anchor' => (string) ( $block['attrs']['anchor'] ?? '' ),
					];
					break;
				case 'core/list':
					$list = $this->list_items( serialize_block( $block ) );
					if ( ! empty( $list ) ) {
						$items[] = [ 'type' => 'list', 'items' => $list ];
					}
					break;
				case 'core/quote':
				case 'core/pullquote':
					$cite = '';
					if ( preg_match( '#<cite[^>]*>(.*?)</cite>#is', serialize_block( $block ), $m ) ) {
						$cite = trim( wp_strip_all_tags( $m[1] ) );
					}
					$items[] = [
						'type' => 'quote',
						'text' => $this->inline( (string) preg_replace( '#<cite[^>]*>.*?</cite>#is', '', render_block( $block ) ) ),
						'cite' => $cite,
					];
					break;
				default:
					$items[] = $this->keep( serialize_block( $block ), str_replace( 'core/', '', (string) $name ) );
			}
		}
		return $items;
	}
}

/** JSON schema of the correction. */
function revise_schema(): array {
	return [
		'type'                 => 'object',
		'additionalProperties' => false,
		'properties'           => [
			'excerpt'     => [ 'type' => 'string' ],
			'testimonial' => [ 'type' => 'string' ],
			'results'     => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'value' => [ 'type' => 'string' ],
						'label' => [ 'type' => 'string' ],
					],
					'required'             => [ 'value', 'label' ],
				],
			],
			'blocks'      => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'type'   => [ 'type' => 'string', 'enum' => [ 'heading', 'paragraph', 'list', 'quote', 'keep' ] ],
						'level'  => [ 'type' => 'integer' ],
						'text'   => [ 'type' => 'string' ],
						'anchor' => [ 'type' => 'string' ],
						'items'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'cite'   => [ 'type' => 'string' ],
						'ref'    => [ 'type' => 'integer' ],
					],
					'required'             => [ 'type' ],
				],
			],
		],
		'required'             => [ 'excerpt', 'testimonial', 'results', 'blocks' ],
	];
}

function revise_system( string $lang ): string {
	return 'You are a meticulous copy editor for a company website. You receive an existing client case study (the story of a project) as JSON: its text blocks, its excerpt, its key results and the client testimonial. You return the same case study, corrected and formatted. Keep the language of the text (code: ' . $lang . ').'
		. ' Editing rules: this is a light revision, not a rewrite. Keep the author\'s wording, sentences, order and meaning. Fix spelling, grammar, punctuation and typography only. Never add facts, figures, examples, names, quotes or sources that are not in the text, and never remove information.'
		. ' Structure rules: a case study is organized in level 2 sections, usually Context, Challenge, Solution and Results. Keep every existing level 2 heading, in the same order, with its "anchor" unchanged; you may fix its spelling but not rename it into something else, and you never add or remove a level 2 heading, except when the text has none at all: then open the sections Context, Challenge, Solution and Results (in the language of the text) where the content clearly matches, with an empty "anchor". Level 3 headings are only used inside a section. Split paragraphs that are too long; turn obvious enumerations into a block of type "list".'
		. ' Callout rule: at most one block of type "quote", for one key sentence that already exists in the text; do not invent it.'
		. ' Inline HTML: text fields may contain <a>, <strong>, <em> and <br> tags. Keep every <a> tag exactly as given (same href and attributes) around the same words; keep bold and italic where they are.'
		. ' Blocks of type "keep" are images, videos, tables or other elements: return every one of them exactly once, with the same "ref", at the same place in the reading order.'
		. ' "results": return the same number of rows in the same order; copy each "value" exactly as given; only fix the spelling of each "label".'
		. ' "testimonial": the words of the client; fix typos only, never reword it. Return an empty string if it is empty.'
		. ' "excerpt": if the given excerpt is not empty, fix it lightly; if it is empty, write a summary of two sentences, between 200 and 300 characters, using only facts from the text.'
		. ' Never use em-dashes or any long dash character; use commas, parentheses, colons or separate sentences instead, and a regular hyphen only inside compound words. Return your answer strictly using the provided JSON schema.';
}

/**
 * Block markup from the corrected blocks.
 *
 * @return array { content, keeps_missing }
 */
function build_blocks( array $blocks, array $keeps, string $lang ): array {
	$out     = [];
	$seen    = [];
	$used    = [];
	$seen_h2 = false;
	$quote   = false;
	$reader  = new Reader();
	$clean   = static function ( string $text ) use ( $reader ): string {
		return $reader->inline( no_dashes( $text ) );
	};

	// Anchors the model gave back, so a new section never takes one of them.
	foreach ( $blocks as $b ) {
		if ( is_array( $b ) && 'heading' === ( $b['type'] ?? '' ) && ! empty( $b['anchor'] ) ) {
			$used[ sanitize_title( (string) $b['anchor'] ) ] = false;
		}
	}
	$taken = [];
	$next  = 1;

	foreach ( $blocks as $b ) {
		if ( ! is_array( $b ) || empty( $b['type'] ) ) {
			continue;
		}
		$type = (string) $b['type'];

		if ( 'keep' === $type ) {
			$ref = (int) ( $b['ref'] ?? -1 );
			if ( isset( $keeps[ $ref ] ) && ! isset( $seen[ $ref ] ) ) {
				$seen[ $ref ] = true;
				$out[]        = $keeps[ $ref ];
			}
			continue;
		}

		if ( 'heading' === $type ) {
			$text = rtrim( trim( wp_strip_all_tags( no_dashes( (string) ( $b['text'] ?? '' ) ) ) ), " \t." );
			if ( '' === $text ) {
				continue;
			}
			$level = max( 2, min( 4, (int) ( $b['level'] ?? 2 ) ) );
			if ( $level > 2 && ! $seen_h2 ) {
				$level = 2;
			}
			$text = esc_html( $text );
			if ( 2 === $level ) {
				$seen_h2 = true;
				$anchor  = sanitize_title( (string) ( $b['anchor'] ?? '' ) );
				if ( '' === $anchor || isset( $taken[ $anchor ] ) ) {
					do {
						$anchor = 'section-' . $next++;
					} while ( isset( $taken[ $anchor ] ) || isset( $used[ $anchor ] ) );
				}
				$taken[ $anchor ] = true;
				$out[]            = get_comment_delimited_block_content( 'core/heading', [ 'anchor' => $anchor ], '<h2 class="wp-block-heading" id="' . esc_attr( $anchor ) . '">' . $text . '</h2>' );
			} else {
				$out[] = get_comment_delimited_block_content( 'core/heading', [ 'level' => $level ], '<h' . $level . ' class="wp-block-heading">' . $text . '</h' . $level . '>' );
			}
			continue;
		}

		if ( 'list' === $type ) {
			$inner = [];
			foreach ( is_array( $b['items'] ?? null ) ? $b['items'] : [] as $item ) {
				$item = $clean( (string) $item );
				if ( '' !== wp_strip_all_tags( $item ) ) {
					$inner[] = get_comment_delimited_block_content( 'core/list-item', [], '<li>' . $item . '</li>' );
				}
			}
			if ( ! empty( $inner ) ) {
				$out[] = get_comment_delimited_block_content( 'core/list', [], '<ul class="wp-block-list">' . implode( "\n\n", $inner ) . '</ul>' );
			}
			continue;
		}

		$text = $clean( (string) ( $b['text'] ?? '' ) );
		if ( '' === wp_strip_all_tags( $text ) ) {
			continue;
		}
		if ( 'quote' === $type && ! $quote ) {
			$quote = true;
			$cite  = trim( wp_strip_all_tags( no_dashes( (string) ( $b['cite'] ?? '' ) ) ) );
			$html  = '<blockquote class="wp-block-quote">' . get_comment_delimited_block_content( 'core/paragraph', [], '<p>' . $text . '</p>' );
			if ( '' !== $cite ) {
				$html .= '<cite>' . esc_html( $cite ) . '</cite>';
			}
			$out[] = get_comment_delimited_block_content( 'core/quote', [], $html . '</blockquote>' );
			continue;
		}
		$out[] = get_comment_delimited_block_content( 'core/paragraph', [], '<p>' . $text . '</p>' );
	}

	// A kept element the model forgot is never lost: it goes at the end.
	$missing = 0;
	foreach ( $keeps as $ref => $markup ) {
		if ( ! isset( $seen[ $ref ] ) ) {
			$out[] = $markup;
			$missing++;
		}
	}
	$content = implode( "\n\n", $out );
	return [
		'content'       => is_french( $lang ) ? nbsp_markup( $content ) : $content,
		'keeps_missing' => $missing,
	];
}

/** Links of some HTML: [ href => anchor text ]. */
function links_of( string $html ): array {
	$links = [];
	if ( preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $row ) {
			$href = html_entity_decode( $row[1], ENT_QUOTES, 'UTF-8' );
			if ( ! isset( $links[ $href ] ) ) {
				$links[ $href ] = trim( wp_strip_all_tags( $row[2] ) );
			}
		}
	}
	return $links;
}

/** Put a lost link back on the first occurrence of its words (outside links, tags and block comments). */
function inject_link( string $content, string $href, string $anchor ): string {
	if ( '' === $anchor ) {
		return $content;
	}
	$parts = preg_split( '/(<!--.*?-->|<[^>]*>)/su', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) {
		return $content;
	}
	$in_link = false;
	foreach ( $parts as $i => $part ) {
		if ( '' === $part ) {
			continue;
		}
		if ( '<' === $part[0] ) {
			if ( preg_match( '#^<a[\s>]#i', $part ) ) {
				$in_link = true;
			} elseif ( preg_match( '#^</a\s*>#i', $part ) ) {
				$in_link = false;
			}
			continue;
		}
		if ( $in_link ) {
			continue;
		}
		$pos = mb_stripos( $part, $anchor );
		if ( false !== $pos ) {
			$found       = mb_substr( $part, $pos, mb_strlen( $anchor ) );
			$parts[ $i ] = mb_substr( $part, 0, $pos ) . '<a href="' . esc_url( $href ) . '">' . $found . '</a>' . mb_substr( $part, $pos + mb_strlen( $anchor ) );
			return implode( '', $parts );
		}
	}
	return $content;
}

function words( string $content ): int {
	$text = trim( wp_strip_all_tags( $content ) );
	return '' === $text ? 0 : count( (array) preg_split( '/\s+/u', $text ) );
}

/** POST /revise: build and store a correction proposal (nothing is changed). */
function handle_revise( \WP_REST_Request $request ) {
	long_request();
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$old    = (string) $post->post_content;
	$reader = new Reader();
	$items  = $reader->read( $old );
	$texts  = array_filter( $items, static function ( $i ) {
		return 'keep' !== $i['type'];
	} );
	if ( empty( $texts ) ) {
		return new \WP_Error( 'pedc_ai_empty', is_fr() ? 'Cette étude n\'a pas de texte à corriger.' : 'This case study has no text to correct.', [ 'status' => 400 ] );
	}

	$lang    = post_language( $post->ID );
	$sheet   = \Pratcom\EtudesDeCas\sheet( $post );
	$payload = [
		'excerpt'     => (string) $post->post_excerpt,
		'results'     => $sheet['results'],
		'testimonial' => $sheet['testimonial'],
		'blocks'      => array_values( $items ),
	];
	$user    = 'Case study title (do not change it, do not repeat it as a heading): ' . $post->post_title
		. "\n\nCase study JSON:\n" . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$answer  = ask( 'revise', revise_system( $lang ), $user, revise_schema() );
	if ( is_wp_error( $answer ) ) {
		return $answer;
	}
	$data = $answer['data'];
	if ( empty( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
		return new \WP_Error( 'pedc_ai_bad_response', is_fr() ? 'La réponse de l\'IA n\'a pas pu être lue. Réessaie.' : 'The AI response could not be read. Try again.' );
	}

	$built   = build_blocks( $data['blocks'], $reader->keeps, $lang );
	$content = $built['content'];

	$before_links = links_of( $old );
	foreach ( array_diff_key( $before_links, links_of( $content ) ) as $href => $anchor ) {
		$content = inject_link( $content, (string) $href, (string) $anchor );
	}
	$missing = array_diff_key( $before_links, links_of( $content ) );

	// Key results: same rows, values unchanged, labels corrected.
	$results = [];
	$given   = is_array( $data['results'] ?? null ) ? array_values( $data['results'] ) : [];
	foreach ( $sheet['results'] as $i => $row ) {
		$label     = isset( $given[ $i ]['label'] ) && '' !== trim( (string) $given[ $i ]['label'] ) ? (string) $given[ $i ]['label'] : $row['label'];
		$results[] = [ 'value' => $row['value'], 'label' => sanitize_text_field( clean_text( $label, $lang ) ) ];
	}
	$testimonial = '' === $sheet['testimonial'] ? '' : sanitize_textarea_field( clean_text( (string) ( $data['testimonial'] ?? '' ), $lang ) );
	if ( '' === $testimonial ) {
		$testimonial = $sheet['testimonial'];
	}
	$excerpt = sanitize_textarea_field( clean_text( (string) ( $data['excerpt'] ?? '' ), $lang ) );
	if ( '' === $excerpt ) {
		$excerpt = (string) $post->post_excerpt;
	}

	// update_post_meta() unslashes: slash first so the block JSON survives.
	update_post_meta( $post->ID, META_PROPOSAL, wp_slash( [
		'content'     => $content,
		'excerpt'     => $excerpt,
		'results'     => $results,
		'testimonial' => $testimonial,
		'modified'    => $post->post_modified_gmt,
		'model'       => $answer['model'],
	] ) );

	$h2 = preg_match_all( '#<h2[\s>]#i', $content );
	return rest_ensure_response( [
		'post_id'      => $post->ID,
		'title'        => $post->post_title,
		'status'       => $post->post_status,
		'lang'         => $lang,
		'before_html'  => wp_kses_post( do_blocks( $old ) ),
		'after_html'   => wp_kses_post( do_blocks( $content ) ),
		'excerpt'      => $excerpt,
		'old_excerpt'  => (string) $post->post_excerpt,
		'results'      => [ 'before' => $sheet['results'], 'after' => $results ],
		'testimonial'  => [ 'before' => $sheet['testimonial'], 'after' => $testimonial ],
		'model'        => $answer['model'],
		'stats'        => [
			'words_before'  => words( $old ),
			'words_after'   => words( $content ),
			'h2'            => (int) $h2,
			'links_total'   => count( $before_links ),
			'links_kept'    => count( $before_links ) - count( $missing ),
			'links_missing' => array_values( array_filter( array_values( $missing ) ) ),
			'keeps'         => count( $reader->keeps ),
			'keeps_moved'   => $built['keeps_missing'],
		],
	] );
}

/** POST /revise/apply: save the stored proposal. */
function handle_revise_apply( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$proposal = get_post_meta( $post->ID, META_PROPOSAL, true );
	if ( ! is_array( $proposal ) || empty( $proposal['content'] ) ) {
		return new \WP_Error( 'pedc_ai_no_revision', is_fr() ? 'Aucune correction à appliquer. Relance la correction.' : 'No correction to apply. Start the correction again.', [ 'status' => 400 ] );
	}
	if ( (string) $proposal['modified'] !== (string) $post->post_modified_gmt ) {
		return new \WP_Error( 'pedc_ai_changed', is_fr() ? 'L\'étude a été modifiée depuis la correction. Relance la correction.' : 'The case study changed since the correction was made. Start it again.', [ 'status' => 409 ] );
	}
	$lang    = post_language( $post->ID );
	$excerpt = $request->get_param( 'excerpt' );
	$excerpt = null !== $excerpt ? sanitize_textarea_field( clean_text( (string) $excerpt, $lang ) ) : (string) $proposal['excerpt'];

	// WordPress keeps the previous version in the revisions.
	$updated = wp_update_post( wp_slash( [
		'ID'           => $post->ID,
		'post_content' => (string) $proposal['content'],
		'post_excerpt' => $excerpt,
	] ), true );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	if ( ! empty( $proposal['results'] ) ) {
		update_post_meta( $post->ID, '_pedc_results', wp_slash( $proposal['results'] ) );
	}
	if ( '' !== (string) ( $proposal['testimonial'] ?? '' ) ) {
		update_post_meta( $post->ID, '_pedc_testimonial', wp_slash( (string) $proposal['testimonial'] ) );
	}
	delete_post_meta( $post->ID, META_PROPOSAL );
	update_post_meta( $post->ID, '_pedc_ai_revised', gmdate( 'Y-m-d H:i:s' ) );

	return rest_ensure_response( [
		'post_id'      => $post->ID,
		// Translations are redone from an original only.
		'translations' => original_of( $post->ID ) === $post->ID ? array_keys( translations_of( $post->ID ) ) : [],
	] );
}
