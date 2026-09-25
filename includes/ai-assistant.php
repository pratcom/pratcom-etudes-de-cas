<?php
/**
 * AI assistant for case studies: project sheet, text (notes organized into
 * the sections, never invented), featured image (media library or AI),
 * internal links, Yoast SEO, checks and publication.
 *
 * A case study tells a real project: the AI never writes facts. It only
 * sorts and cleans what the author gives, and marks empty sections
 * "To complete".
 */

namespace Pratcom\EtudesDeCas\AI;

defined( 'ABSPATH' ) || exit;

const META_NOTES = '_pedc_ai_notes';
const META_LINKS = '_pedc_ai_links';
const META_IMAGE = '_pedc_ai_image';
const META_SEO   = '_pedc_ai_seo';

/* Sections */

/** Default section titles of a new case study, in its language. */
function section_titles( string $lang ): array {
	$titles = is_french( $lang )
		? [ 'Le contexte', 'Le défi', 'La solution', 'Les résultats' ]
		: [ 'Context', 'The challenge', 'The solution', 'The results' ];
	return (array) apply_filters( 'pedc_ai_section_titles', $titles, $lang );
}

/** Marker put in an empty section. */
function todo_label( string $lang ): string {
	return is_french( $lang ) ? 'À compléter' : 'To complete';
}

/** Starting content: one H2 per section (anchors section-1…), an empty paragraph each. */
function skeleton_markup( string $lang ): string {
	$out = [];
	foreach ( array_values( section_titles( $lang ) ) as $i => $title ) {
		$anchor = 'section-' . ( $i + 1 );
		$out[]  = get_comment_delimited_block_content( 'core/heading', [ 'anchor' => $anchor ], '<h2 class="wp-block-heading" id="' . esc_attr( $anchor ) . '">' . esc_html( $title ) . '</h2>' );
		$out[]  = get_comment_delimited_block_content( 'core/paragraph', [], '<p></p>' );
	}
	return implode( "\n\n", $out );
}

/** Plain text of some HTML or block markup (one line per block, no-break spaces as spaces). */
function html_to_text( string $html ): string {
	$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
	$html = (string) preg_replace( '#</(p|h[1-6]|li|blockquote|figcaption|td|th|cite)>#i', "$0\n", $html );
	$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES, 'UTF-8' );
	$text = str_replace( "\u{00A0}", ' ', $text );
	$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
	$text = (string) preg_replace( "/\n\s*\n+/u", "\n\n", $text );
	return trim( $text );
}

function count_words( string $text ): int {
	$text = trim( $text );
	return '' === $text ? 0 : count( (array) preg_split( '/\s+/u', $text ) );
}

function has_todo( string $text ): bool {
	foreach ( [ 'À compléter', 'To complete' ] as $label ) {
		if ( false !== mb_stripos( $text, $label ) ) {
			return true;
		}
	}
	return false;
}

function is_h2_block( array $block ): bool {
	return 'core/heading' === ( $block['blockName'] ?? '' ) && 2 === (int) ( $block['attrs']['level'] ?? 2 );
}

function heading_anchor( array $block ): string {
	if ( ! empty( $block['attrs']['anchor'] ) ) {
		return (string) $block['attrs']['anchor'];
	}
	return preg_match( '/\sid\s*=\s*["\']([^"\']+)["\']/i', (string) $block['innerHTML'], $m ) ? $m[1] : '';
}

/**
 * The H2 sections of a content, with what they hold.
 *
 * @return array { intro_words, sections: [ { text, anchor, words, todo } ] }
 */
function h2_sections( string $content ): array {
	$sections = [];
	$intro    = 0;
	foreach ( parse_blocks( $content ) as $block ) {
		if ( empty( $block['blockName'] ) && '' === trim( (string) $block['innerHTML'] ) ) {
			continue;
		}
		if ( is_h2_block( $block ) ) {
			$sections[] = [
				'text'   => trim( html_to_text( (string) $block['innerHTML'] ) ),
				'anchor' => heading_anchor( $block ),
				'words'  => 0,
				'todo'   => false,
			];
			continue;
		}
		$text  = html_to_text( serialize_block( $block ) );
		$words = count_words( $text );
		if ( empty( $sections ) ) {
			$intro += $words;
			continue;
		}
		$last = count( $sections ) - 1;
		if ( has_todo( $text ) ) {
			$sections[ $last ]['todo'] = true;
		} else {
			$sections[ $last ]['words'] += $words;
		}
	}
	return [ 'intro_words' => $intro, 'sections' => $sections ];
}

/** Give every H2 without anchor the next free section-N anchor. */
function ensure_anchors( string $content ): string {
	$blocks = parse_blocks( $content );
	$taken  = [];
	$miss   = false;
	foreach ( $blocks as $b ) {
		if ( is_h2_block( $b ) ) {
			$a = heading_anchor( $b );
			if ( '' === $a ) {
				$miss = true;
			} else {
				$taken[ $a ] = true;
			}
		}
	}
	if ( ! $miss ) {
		return $content;
	}
	$next = 1;
	foreach ( $blocks as &$b ) {
		if ( ! is_h2_block( $b ) || '' !== heading_anchor( $b ) ) {
			continue;
		}
		while ( isset( $taken[ 'section-' . $next ] ) ) {
			++$next;
		}
		$anchor               = 'section-' . $next;
		$taken[ $anchor ]     = true;
		$b['attrs']['anchor'] = $anchor;
		$b['innerHTML']       = (string) preg_replace( '/<h2\b([^>]*)>/i', '<h2$1 id="' . esc_attr( $anchor ) . '">', (string) $b['innerHTML'], 1 );
		$b['innerContent']    = [ $b['innerHTML'] ];
	}
	unset( $b );
	return serialize_blocks( $blocks );
}

/* Environment */

function yoast_active(): bool {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
}

function image_ready(): bool {
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return false;
	}
	try {
		return (bool) wp_ai_client_prompt( 'ping' )->is_supported_for_image_generation();
	} catch ( \Throwable $e ) {
		return false;
	}
}

function image_models(): array {
	return (array) apply_filters( 'pedc_ai_image_model_preference', [ 'gpt-image-2.5-sunburst', 'gpt-image-2.5-flare', 'gpt-image-2' ] );
}

/** Set the WPML language of a new original study. */
function set_study_language( int $post_id, string $lang ): void {
	if ( ! wpml_active() || '' === $lang ) {
		return;
	}
	do_action( 'wpml_set_element_language_details', [
		'element_id'           => $post_id,
		'element_type'         => 'post_' . PEDC_POST_TYPE,
		'trid'                 => apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . PEDC_POST_TYPE ) ?: null,
		'language_code'        => $lang,
		'source_language_code' => null,
	] );
}

/* Terms */

/** Sectors or services of a language: [ { id, name, parent } ]. */
function terms_for( string $taxonomy, string $lang ): array {
	global $wpdb;
	$out   = [];
	$table = translations_table();
	if ( '' !== $table ) {
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.name, tt.parent FROM {$wpdb->terms} AS t
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id
			LEFT JOIN {$table} AS i ON i.element_id = tt.term_taxonomy_id AND i.element_type = %s
			WHERE tt.taxonomy = %s AND ( i.language_code = %s OR i.language_code IS NULL )
			ORDER BY t.name ASC",
			'tax_' . $taxonomy,
			$taxonomy,
			$lang
		) );
		// phpcs:enable
		foreach ( (array) $rows as $r ) {
			$out[] = [ 'id' => (int) $r->term_id, 'name' => html_entity_decode( $r->name, ENT_QUOTES, 'UTF-8' ), 'parent' => (int) $r->parent ];
		}
		return $out;
	}
	$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ] );
	foreach ( is_array( $terms ) ? $terms : [] as $t ) {
		$out[] = [ 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), 'parent' => (int) $t->parent ];
	}
	return $out;
}

/** A term of a language by name: the existing one, or a new one in that language. */
function term_in_language( string $name, string $taxonomy, string $lang ): int {
	$name = trim( sanitize_text_field( no_dashes( $name ) ) );
	if ( '' === $name ) {
		return 0;
	}
	foreach ( terms_for( $taxonomy, $lang ) as $t ) {
		if ( 0 === strcasecmp( remove_accents( $t['name'] ), remove_accents( $name ) ) ) {
			return $t['id'];
		}
	}
	return (int) with_language( $lang, static function () use ( $name, $taxonomy, $lang ) {
		$result = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $result ) ) {
			$result = wp_insert_term( $name, $taxonomy, [ 'slug' => sanitize_title( $name ) . '-' . $lang ] );
		}
		if ( is_wp_error( $result ) ) {
			return 0;
		}
		if ( wpml_active() ) {
			do_action( 'wpml_set_element_language_details', [
				'element_id'           => (int) $result['term_taxonomy_id'],
				'element_type'         => 'tax_' . $taxonomy,
				'trid'                 => apply_filters( 'wpml_element_trid', null, (int) $result['term_taxonomy_id'], 'tax_' . $taxonomy ) ?: null,
				'language_code'        => $lang,
				'source_language_code' => null,
			] );
		}
		return (int) $result['term_id'];
	} );
}

/* Session */

function featured_of( int $post_id ): ?array {
	$thumb = (int) get_post_thumbnail_id( $post_id );
	if ( ! $thumb ) {
		return null;
	}
	$src = wp_get_attachment_image_src( $thumb, 'medium_large' );
	return [
		'id'  => $thumb,
		'url' => $src ? $src[0] : (string) wp_get_attachment_url( $thumb ),
		'alt' => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ),
	];
}

/** Everything the assistant shows about a study. */
function session_payload( int $post_id ): array {
	$post     = get_post( $post_id );
	$content  = (string) $post->post_content;
	$lang     = post_language( $post_id );
	$sheet    = \Pratcom\EtudesDeCas\sheet( $post );
	$sections = h2_sections( $content );
	$words    = $sections['intro_words'];
	$todo     = false;
	foreach ( $sections['sections'] as $s ) {
		$words += $s['words'];
		$todo   = $todo || $s['todo'] || 0 === $s['words'];
	}

	$logo_id  = (int) $sheet['client_logo'];
	$logo_src = $logo_id ? wp_get_attachment_image_src( $logo_id, 'thumbnail' ) : false;

	$seo = [ 'slug' => $post->post_name ? urldecode( $post->post_name ) : '' ];
	foreach ( yoast_keys() as $key => $field ) {
		$seo[ $field ] = (string) get_post_meta( $post_id, $key, true );
	}

	$translations = [];
	foreach ( translations_of( $post_id ) as $code => $tid ) {
		$tp = get_post( $tid );
		if ( ! $tp ) {
			continue;
		}
		$translations[] = [
			'lang'   => $code,
			'id'     => $tid,
			'title'  => $tp->post_title,
			'status' => $tp->post_status,
			'edit'   => get_edit_post_link( $tid, 'raw' ),
			'view'   => 'publish' === $tp->post_status ? get_permalink( $tid ) : get_preview_post_link( $tid ),
		];
	}

	$image    = get_post_meta( $post_id, META_IMAGE, true );
	$original = original_of( $post_id );
	$featured = featured_of( $post_id );

	return [
		'post_id'      => (int) $post_id,
		'title'        => $post->post_title,
		'excerpt'      => $post->post_excerpt,
		'status'       => $post->post_status,
		'date'         => $post->post_date,
		'modified'     => $post->post_modified_gmt,
		'lang'         => $lang,
		'original'     => $original === (int) $post_id ? 0 : $original,
		'edit_link'    => get_edit_post_link( $post_id, 'raw' ),
		'view_link'    => 'publish' === $post->post_status ? get_permalink( $post_id ) : get_preview_post_link( $post_id ),
		'content_html' => wp_kses_post( do_blocks( $content ) ),
		'sections'     => $sections['sections'],
		'words'        => $words,
		'sheet'        => [
			'client'             => $sheet['client'],
			'client_url'         => $sheet['client_url'],
			'client_logo'        => $logo_id,
			'client_logo_url'    => $logo_src ? $logo_src[0] : '',
			'period'             => $sheet['period'],
			'testimonial'        => $sheet['testimonial'],
			'testimonial_author' => $sheet['testimonial_author'],
			'results'            => $sheet['results'],
			'sectors'            => array_map( 'intval', wp_list_pluck( $sheet['sectors'], 'term_id' ) ),
			'services'           => term_names( $sheet['services'] ),
		],
		'featured'     => $featured,
		'image'        => is_array( $image ) ? $image : new \stdClass(),
		'seo'          => $seo,
		'links_done'   => (bool) get_post_meta( $post_id, META_LINKS, true ),
		'translations' => $translations,
		'steps'        => [
			'sheet'     => true,
			'text'      => $words >= 30 && ! $todo,
			'image'     => (bool) $featured,
			'links'     => (bool) get_post_meta( $post_id, META_LINKS, true ),
			'seo'       => '' !== $seo['seo_title'] || '' !== $seo['meta_description'] || (bool) get_post_meta( $post_id, META_SEO, true ),
			'translate' => ! empty( $translations ),
			'publish'   => in_array( $post->post_status, [ 'publish', 'future' ], true ),
		],
	];
}

function handle_session( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	return is_wp_error( $post ) ? $post : rest_ensure_response( session_payload( $post->ID ) );
}

function handle_terms( \WP_REST_Request $request ) {
	$lang = sanitize_key( (string) $request->get_param( 'lang' ) );
	$lang = '' !== $lang ? $lang : default_language();
	return rest_ensure_response( [
		'sectors'  => terms_for( PEDC_TAX_SECTOR, $lang ),
		'services' => terms_for( PEDC_TAX_SERVICE, $lang ),
	] );
}

/* 1. Project sheet */

/** POST /sheet: create the study (draft with the 4 sections) or save its sheet. */
function handle_sheet( \WP_REST_Request $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$title   = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );
	if ( '' === $title ) {
		return new \WP_Error( 'pedc_ai_no_title', is_fr() ? 'Donne un titre à l\'étude.' : 'Give the case study a title.', [ 'status' => 400 ] );
	}

	if ( $post_id ) {
		$post = editable_study( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$lang  = post_language( $post->ID );
		$title = clean_text( $title, $lang );
		if ( $title !== $post->post_title ) {
			$updated = wp_update_post( wp_slash( [ 'ID' => $post->ID, 'post_title' => $title ] ), true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
	} else {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'pedc_ai_forbidden', is_fr() ? 'Tu ne peux pas créer d\'étude.' : 'You cannot create case studies.', [ 'status' => 403 ] );
		}
		$lang  = sanitize_key( (string) $request->get_param( 'lang' ) );
		$codes = wp_list_pluck( languages(), 'code' );
		$lang  = in_array( $lang, $codes, true ) ? $lang : default_language();
		$title = clean_text( $title, $lang );
		$new   = with_language( $lang, static function () use ( $title, $lang ) {
			return wp_insert_post( wp_slash( [
				'post_type'    => PEDC_POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => skeleton_markup( $lang ),
				'post_author'  => get_current_user_id(),
			] ), true );
		} );
		if ( is_wp_error( $new ) || ! $new ) {
			return is_wp_error( $new ) ? $new : new \WP_Error( 'pedc_ai_save_failed', is_fr() ? 'L\'étude n\'a pas pu être créée.' : 'The case study could not be created.', [ 'status' => 500 ] );
		}
		set_study_language( (int) $new, $lang );
		$post = get_post( (int) $new );
	}

	$id = (int) $post->ID;
	update_post_meta( $id, '_pedc_client', wp_slash( sanitize_text_field( (string) $request->get_param( 'client' ) ) ) );
	update_post_meta( $id, '_pedc_client_url', wp_slash( esc_url_raw( (string) $request->get_param( 'client_url' ) ) ) );
	$logo = absint( $request->get_param( 'client_logo' ) );
	update_post_meta( $id, '_pedc_client_logo', $logo && wp_attachment_is_image( $logo ) ? $logo : 0 );
	update_post_meta( $id, '_pedc_period', wp_slash( sanitize_text_field( (string) $request->get_param( 'period' ) ) ) );
	update_post_meta( $id, '_pedc_testimonial', wp_slash( sanitize_textarea_field( clean_text( (string) $request->get_param( 'testimonial' ), $lang ) ) ) );
	update_post_meta( $id, '_pedc_testimonial_author', wp_slash( sanitize_text_field( clean_text( (string) $request->get_param( 'testimonial_author' ), $lang ) ) ) );
	$rows = [];
	foreach ( (array) $request->get_param( 'results' ) as $row ) {
		if ( is_array( $row ) ) {
			$rows[] = [ 'value' => (string) ( $row['value'] ?? '' ), 'label' => clean_text( (string) ( $row['label'] ?? '' ), $lang ) ];
		}
	}
	update_post_meta( $id, '_pedc_results', wp_slash( \Pratcom\EtudesDeCas\sanitize_results( $rows ) ) );

	// Sectors: chosen ones (existing in this language) plus new names.
	$known   = wp_list_pluck( terms_for( PEDC_TAX_SECTOR, $lang ), 'id' );
	$sectors = array_values( array_intersect( array_map( 'intval', (array) $request->get_param( 'sectors' ) ), array_map( 'intval', $known ) ) );
	foreach ( (array) $request->get_param( 'new_sectors' ) as $name ) {
		$tid = term_in_language( (string) $name, PEDC_TAX_SECTOR, $lang );
		if ( $tid ) {
			$sectors[] = $tid;
		}
	}
	wp_set_object_terms( $id, array_values( array_unique( $sectors ) ), PEDC_TAX_SECTOR, false );

	$services = [];
	foreach ( (array) $request->get_param( 'services' ) as $name ) {
		$tid = term_in_language( (string) $name, PEDC_TAX_SERVICE, $lang );
		if ( $tid ) {
			$services[] = $tid;
		}
	}
	wp_set_object_terms( $id, array_values( array_unique( $services ) ), PEDC_TAX_SERVICE, false );

	return rest_ensure_response( session_payload( $id ) );
}

/** POST /fields: title and excerpt. */
function handle_fields( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$lang    = post_language( $post->ID );
	$postarr = [ 'ID' => $post->ID ];
	if ( null !== $request->get_param( 'excerpt' ) ) {
		$postarr['post_excerpt'] = sanitize_textarea_field( clean_text( (string) $request->get_param( 'excerpt' ), $lang ) );
	}
	if ( null !== $request->get_param( 'title' ) && '' !== trim( (string) $request->get_param( 'title' ) ) ) {
		$postarr['post_title'] = sanitize_text_field( clean_text( (string) $request->get_param( 'title' ), $lang ) );
	}
	$updated = wp_update_post( wp_slash( $postarr ), true );
	return is_wp_error( $updated ) ? $updated : rest_ensure_response( session_payload( $post->ID ) );
}

/* 2. Text: notes organized into the sections */

function notes_schema(): array {
	$str = [ 'type' => 'string' ];
	return [
		'type'                 => 'object',
		'additionalProperties' => false,
		'properties'           => [
			'sections'           => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'index'  => [ 'type' => 'integer' ],
						'blocks' => [
							'type'  => 'array',
							'items' => [
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => [
									'type'  => [ 'type' => 'string', 'enum' => [ 'paragraph', 'list', 'heading', 'quote' ] ],
									'text'  => $str,
									'items' => [ 'type' => 'array', 'items' => $str ],
									'cite'  => $str,
								],
								'required'             => [ 'type' ],
							],
						],
					],
					'required'             => [ 'index', 'blocks' ],
				],
			],
			'excerpt'            => $str,
			'results'            => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [ 'value' => $str, 'label' => $str ],
					'required'             => [ 'value', 'label' ],
				],
			],
			'testimonial'        => $str,
			'testimonial_author' => $str,
		],
		'required'             => [ 'sections', 'excerpt', 'results', 'testimonial', 'testimonial_author' ],
	];
}

function notes_system( string $lang ): string {
	return 'You organize the notes of a REAL client project into a company case study, written in ' . language_name( $lang ) . '. You receive the numbered section titles of the case study and the author\'s notes.'
		. ' Place every piece of information from the notes in the section where it belongs; nothing from the notes may be dropped.'
		. ' Strict rule: a case study reports what really happened. Use only facts that are in the notes. Never add facts, figures, names, examples, quotes, results, benefits or claims, never guess, never generalize and never fill a gap. If the notes are in another language, translate them faithfully.'
		. ' Keep the author\'s wording as much as possible: you may fix spelling and grammar, turn fragments into full sentences, split long paragraphs and turn enumerations into a block of type "list", but do not embellish and do not add marketing language.'
		. ' When the notes contain nothing for a section, return that section with an empty "blocks" array; never write placeholder text.'
		. ' Block types: "paragraph" (text), "list" (items), "heading" only for a sub-part inside a section (it becomes a level 3 heading), "quote" at most once, for a sentence that already exists in the notes. Text may contain <strong> and <em>.'
		. ' "excerpt": two sentences, 200 to 300 characters, summarizing only facts from the notes; an empty string if the notes are too thin.'
		. ' "results": the measurable results stated in the notes, each with "value" (the figure exactly as written, such as "+45 %" or "3 weeks") and "label" (what it measures); an empty array if the notes state no figure.'
		. ' "testimonial": a client quotation only if the notes contain one word for word, otherwise an empty string; "testimonial_author": who said it, only if the notes say so.'
		. ' Never use em-dashes or any long dash character; use commas, parentheses, colons or separate sentences instead. Return your answer strictly using the provided JSON schema.';
}

/** POST /notes: organize the author's notes into the sections (proposal, nothing saved). */
function handle_notes( \WP_REST_Request $request ) {
	long_request();
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$notes = trim( sanitize_textarea_field( (string) $request->get_param( 'notes' ) ) );
	if ( mb_strlen( $notes ) < 40 ) {
		return new \WP_Error( 'pedc_ai_no_notes', is_fr() ? 'Colle d\'abord tes notes ou ton texte de base (quelques phrases au moins).' : 'Paste your notes or base text first (a few sentences at least).', [ 'status' => 400 ] );
	}
	$notes = mb_substr( $notes, 0, (int) apply_filters( 'pedc_ai_notes_limit', 30000 ) );
	$lang  = post_language( $post->ID );

	// Sections: the H2 of the study, or the 4 default ones.
	$sections = [];
	foreach ( h2_sections( (string) $post->post_content )['sections'] as $s ) {
		if ( '' !== $s['text'] ) {
			$sections[] = [ 'text' => $s['text'], 'anchor' => $s['anchor'] ];
		}
	}
	if ( empty( $sections ) ) {
		foreach ( array_values( section_titles( $lang ) ) as $i => $t ) {
			$sections[] = [ 'text' => $t, 'anchor' => 'section-' . ( $i + 1 ) ];
		}
	}
	$list = '';
	foreach ( $sections as $i => $s ) {
		$list .= ( $i + 1 ) . '. ' . $s['text'] . "\n";
	}
	$sheet = \Pratcom\EtudesDeCas\sheet( $post );
	$user  = 'Case study title: ' . $post->post_title
		. ( '' !== $sheet['client'] ? "\nClient: " . $sheet['client'] : '' )
		. "\n\nSection titles:\n" . $list
		. "\nAuthor's notes:\n" . $notes;

	$answer = ask( 'notes', notes_system( $lang ), $user, notes_schema(), 16000 );
	if ( is_wp_error( $answer ) ) {
		return $answer;
	}
	$data  = $answer['data'];
	$given = [];
	foreach ( is_array( $data['sections'] ?? null ) ? array_values( $data['sections'] ) : [] as $pos => $s ) {
		$idx = isset( $s['index'] ) ? (int) $s['index'] - 1 : $pos;
		if ( $idx >= 0 && $idx < count( $sections ) && is_array( $s['blocks'] ?? null ) ) {
			$given[ $idx ] = array_merge( $given[ $idx ] ?? [], $s['blocks'] );
		}
	}

	$todo   = todo_label( $lang );
	$blocks = [];
	$empty  = [];
	foreach ( $sections as $i => $s ) {
		$blocks[] = [ 'type' => 'heading', 'level' => 2, 'text' => $s['text'], 'anchor' => $s['anchor'] ];
		$inner    = [];
		foreach ( $given[ $i ] ?? [] as $b ) {
			if ( ! is_array( $b ) || empty( $b['type'] ) ) {
				continue;
			}
			if ( 'heading' === $b['type'] ) {
				$b['level'] = 3;
			}
			$inner[] = $b;
		}
		$filled = array_filter( $inner, static function ( $b ) {
			return '' !== trim( wp_strip_all_tags( (string) ( $b['text'] ?? '' ) . implode( ' ', array_map( 'strval', (array) ( $b['items'] ?? [] ) ) ) ) );
		} );
		if ( empty( $filled ) ) {
			$empty[] = $s['text'];
			$inner   = [ [ 'type' => 'paragraph', 'text' => '<strong>' . $todo . '</strong>' ] ];
		}
		$blocks = array_merge( $blocks, $inner );
	}
	$built   = build_blocks( $blocks, [], $lang );
	$content = $built['content'];

	$results = [];
	foreach ( is_array( $data['results'] ?? null ) ? $data['results'] : [] as $row ) {
		if ( is_array( $row ) ) {
			$results[] = [ 'value' => (string) ( $row['value'] ?? '' ), 'label' => clean_text( (string) ( $row['label'] ?? '' ), $lang ) ];
		}
	}
	$results     = \Pratcom\EtudesDeCas\sanitize_results( $results );
	$testimonial = sanitize_textarea_field( clean_text( (string) ( $data['testimonial'] ?? '' ), $lang ) );
	$author      = '' !== $testimonial ? sanitize_text_field( clean_text( (string) ( $data['testimonial_author'] ?? '' ), $lang ) ) : '';
	$excerpt     = sanitize_textarea_field( clean_text( (string) ( $data['excerpt'] ?? '' ), $lang ) );

	// The sheet is only filled where it is still empty.
	$fill_results     = empty( $sheet['results'] ) && ! empty( $results );
	$fill_testimonial = '' === $sheet['testimonial'] && '' !== $testimonial;

	update_post_meta( $post->ID, META_NOTES, wp_slash( [
		'content'            => $content,
		'excerpt'            => $excerpt,
		'results'            => $fill_results ? $results : [],
		'testimonial'        => $fill_testimonial ? $testimonial : '',
		'testimonial_author' => $fill_testimonial ? $author : '',
		'modified'           => $post->post_modified_gmt,
		'model'              => $answer['model'],
	] ) );

	return rest_ensure_response( [
		'post_id'            => $post->ID,
		'before_html'        => wp_kses_post( do_blocks( (string) $post->post_content ) ),
		'after_html'         => wp_kses_post( do_blocks( $content ) ),
		'replaces_text'      => count_words( html_to_text( (string) $post->post_content ) ) - count_words( implode( ' ', wp_list_pluck( $sections, 'text' ) ) ) > 0,
		'excerpt'            => '' !== $excerpt ? $excerpt : (string) $post->post_excerpt,
		'empty_sections'     => $empty,
		'results'            => $results,
		'fill_results'       => $fill_results,
		'testimonial'        => $testimonial,
		'testimonial_author' => $author,
		'fill_testimonial'   => $fill_testimonial,
		'model'              => $answer['model'],
	] );
}

/** POST /notes/apply: save the organized text. */
function handle_notes_apply( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$proposal = get_post_meta( $post->ID, META_NOTES, true );
	if ( ! is_array( $proposal ) || empty( $proposal['content'] ) ) {
		return new \WP_Error( 'pedc_ai_no_proposal', is_fr() ? 'Rien à appliquer. Relance « Organiser mes notes ».' : 'Nothing to apply. Run "Organize my notes" again.', [ 'status' => 400 ] );
	}
	if ( (string) $proposal['modified'] !== (string) $post->post_modified_gmt ) {
		return new \WP_Error( 'pedc_ai_changed', is_fr() ? 'L\'étude a été modifiée depuis. Relance « Organiser mes notes ».' : 'The case study changed since. Run "Organize my notes" again.', [ 'status' => 409 ] );
	}
	$lang    = post_language( $post->ID );
	$excerpt = $request->get_param( 'excerpt' );
	$excerpt = null !== $excerpt ? sanitize_textarea_field( clean_text( (string) $excerpt, $lang ) ) : (string) $proposal['excerpt'];
	$postarr = [ 'ID' => $post->ID, 'post_content' => (string) $proposal['content'] ];
	if ( '' !== $excerpt ) {
		$postarr['post_excerpt'] = $excerpt;
	}
	$updated = wp_update_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	$filled = [];
	if ( ! empty( $proposal['results'] ) ) {
		update_post_meta( $post->ID, '_pedc_results', wp_slash( $proposal['results'] ) );
		$filled[] = 'results';
	}
	if ( '' !== (string) ( $proposal['testimonial'] ?? '' ) ) {
		update_post_meta( $post->ID, '_pedc_testimonial', wp_slash( (string) $proposal['testimonial'] ) );
		if ( '' !== (string) ( $proposal['testimonial_author'] ?? '' ) ) {
			update_post_meta( $post->ID, '_pedc_testimonial_author', wp_slash( (string) $proposal['testimonial_author'] ) );
		}
		$filled[] = 'testimonial';
	}
	delete_post_meta( $post->ID, META_NOTES );

	$payload           = session_payload( $post->ID );
	$payload['filled'] = $filled;
	return rest_ensure_response( $payload );
}

/* 3. Image */

function image_style_descriptor( string $style ): string {
	$map = [
		'realistic'    => 'photorealistic, natural lighting',
		'journalistic' => 'editorial photojournalism, documentary',
		'cinematic'    => 'cinematic film still, dramatic lighting, shallow depth of field',
		'conceptual'   => 'conceptual, abstract, symbolic',
		'illustration' => 'modern flat vector illustration',
	];
	return $map[ $style ] ?? $map['realistic'];
}

/** Brand colors of the theme palette (max 4). */
function brand_colors(): array {
	$colors = [];
	if ( function_exists( 'wp_get_global_settings' ) ) {
		$palette = wp_get_global_settings( [ 'color', 'palette' ] );
		$entries = [];
		if ( is_array( $palette ) ) {
			if ( isset( $palette['theme'] ) || isset( $palette['custom'] ) || isset( $palette['default'] ) ) {
				foreach ( [ 'theme', 'custom', 'default' ] as $origin ) {
					if ( ! empty( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ) {
						$entries = array_merge( $entries, $palette[ $origin ] );
					}
				}
			} else {
				$entries = $palette;
			}
		}
		$preferred = [];
		$others    = [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['color'] ) ) {
				continue;
			}
			$slug = strtolower( (string) ( $entry['slug'] ?? '' ) );
			if ( preg_match( '/(primary|secondary|accent|brand)/', $slug ) ) {
				$preferred[] = (string) $entry['color'];
			} elseif ( ! preg_match( '/(base|background|foreground|contrast|white|black|neutral|gray|grey)/', $slug ) ) {
				$others[] = (string) $entry['color'];
			}
		}
		$colors = array_slice( array_values( array_unique( array_merge( $preferred, $others ) ) ), 0, 4 );
	}
	return (array) apply_filters( 'pedc_ai_brand_colors', $colors );
}

function clean_alt( string $alt ): string {
	$alt = trim( sanitize_text_field( no_dashes( $alt ) ), " \"'" );
	$alt = (string) preg_replace( '/^(image|photo|illustration|picture)\s+(de|d\'|du|des|of|showing)\s*/iu', '', $alt );
	return '' !== $alt ? mb_strtoupper( mb_substr( $alt, 0, 1 ) ) . mb_substr( $alt, 1 ) : '';
}

/** POST /image/suggest: prompt and alt text from the study. */
function handle_image_suggest( \WP_REST_Request $request ) {
	long_request();
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$lang   = post_language( $post->ID );
	$style  = sanitize_key( (string) $request->get_param( 'style' ) );
	$colors = (bool) $request->get_param( 'match_colors' );
	$sheet  = \Pratcom\EtudesDeCas\sheet( $post );
	$text   = mb_substr( html_to_text( (string) $post->post_content ), 0, 3000 );
	$hint   = '';
	if ( $colors && ! empty( brand_colors() ) ) {
		$hint = ' Use these brand colors only as subtle decorative accents such as color bands, geometric shapes, borders or graphic overlays, not as an overall tint, and keep the main subject natural. Brand colors: ' . implode( ', ', brand_colors() ) . '.';
	}
	$system = 'You write prompts for an AI image generator. Return "image_prompt": one or two vivid sentences in English describing a wide 16:9 featured image for a company case study page. Return "image_alt": one short descriptive sentence in ' . language_name( $lang ) . ' of what that image shows, for screen readers, without starting with words like "image of" or "photo of". Never use em-dashes. Answer strictly with the provided JSON schema.';
	$user   = 'Case study title: ' . $post->post_title
		. ( ! empty( $sheet['sectors'] ) ? "\nClient sector: " . implode( ', ', term_names( $sheet['sectors'] ) ) : '' )
		. ( ! empty( $sheet['services'] ) ? "\nServices delivered: " . implode( ', ', term_names( $sheet['services'] ) ) : '' )
		. "\n\nCase study text:\n" . $text
		. "\n\nThe image illustrates this project in a " . image_style_descriptor( $style ) . ' style.' . $hint
		. ' Do not include any text, words, logos or brand names in the image, and do not depict the client\'s real people or products.';
	$schema = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'properties'           => [ 'image_prompt' => [ 'type' => 'string' ], 'image_alt' => [ 'type' => 'string' ] ],
		'required'             => [ 'image_prompt', 'image_alt' ],
	];
	$answer = ask( 'image', $system, $user, $schema, 2000 );
	if ( is_wp_error( $answer ) ) {
		return $answer;
	}
	$prompt = trim( (string) ( $answer['data']['image_prompt'] ?? '' ), " \"'" );
	if ( '' === $prompt ) {
		$prompt = 'A wide 16:9 featured image illustrating a client project titled "' . $post->post_title . '", in a ' . image_style_descriptor( $style ) . ' style. No text in the image.' . $hint;
	}
	$out  = [
		'image_prompt' => $prompt,
		'image_alt'    => clean_text( clean_alt( (string) ( $answer['data']['image_alt'] ?? '' ) ), $lang ),
	];
	$prev = get_post_meta( $post->ID, META_IMAGE, true );
	update_post_meta( $post->ID, META_IMAGE, wp_slash( array_merge( is_array( $prev ) ? $prev : [], $out, [ 'style' => $style, 'match_colors' => $colors ] ) ) );
	return rest_ensure_response( $out );
}

/** Data URI of an AI Client file (inline data or URL). */
function file_to_data_uri( $file ): string {
	$uri = '';
	if ( is_object( $file ) && method_exists( $file, 'getDataUri' ) ) {
		try {
			$uri = (string) $file->getDataUri();
		} catch ( \Throwable $e ) {
			$uri = '';
		}
		if ( '' === $uri && method_exists( $file, 'getUrl' ) ) {
			$uri = remote_to_data_uri( (string) $file->getUrl() );
		}
	} elseif ( is_string( $file ) ) {
		$uri = 0 === strpos( $file, 'http' ) ? remote_to_data_uri( $file ) : $file;
	}
	return $uri;
}

function remote_to_data_uri( string $url ): string {
	if ( '' === $url ) {
		return '';
	}
	$response = wp_remote_get( $url, [ 'timeout' => 60 ] );
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	$body = wp_remote_retrieve_body( $response );
	$mime = (string) wp_remote_retrieve_header( $response, 'content-type' );
	return '' !== $body && 0 === strpos( $mime, 'image/' ) ? 'data:' . $mime . ';base64,' . base64_encode( $body ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/** POST /image/generate: one 16:9 image from the prompt. */
function handle_image_generate( \WP_REST_Request $request ) {
	long_request();
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$prompt = trim( sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ) );
	if ( '' === $prompt ) {
		return new \WP_Error( 'pedc_ai_no_prompt', is_fr() ? 'Entre ou propose d\'abord un prompt.' : 'Enter or suggest a prompt first.', [ 'status' => 400 ] );
	}
	$timeout = (int) apply_filters( 'pedc_ai_request_timeout', 240 );
	$raise   = static function ( $args ) use ( $timeout ) {
		if ( is_array( $args ) && $timeout > (int) ( $args['timeout'] ?? 0 ) ) {
			$args['timeout'] = $timeout;
		}
		return $args;
	};
	add_filter( 'http_request_args', $raise, 9999 );
	try {
		$builder = wp_ai_client_prompt( $prompt );
		$models  = array_values( image_models() );
		if ( ! empty( $models ) ) {
			$builder = $builder->using_model_preference( ...$models );
		}
		$enum = 'WordPress\\AiClient\\Files\\Enums\\MediaOrientationEnum';
		if ( class_exists( $enum ) ) {
			$builder = $builder->as_output_media_orientation( $enum::from( 'landscape' ) );
		}
		if ( ! $builder->is_supported_for_image_generation() ) {
			return new \WP_Error( 'pedc_ai_no_image_provider', is_fr() ? 'Aucun fournisseur d\'images n\'est configuré (Réglages, Connecteurs).' : 'No image provider is configured (Settings, Connectors).' );
		}
		$result = $builder->generate_image_result();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$model = '';
		try {
			$model = (string) $result->getModelMetadata()->getId();
		} catch ( \Throwable $e ) {
			$model = '';
		}
		$uri = file_to_data_uri( $result->toImageFile() );
	} catch ( \Throwable $e ) {
		return new \WP_Error( 'pedc_ai_image_exception', $e->getMessage() );
	} finally {
		remove_filter( 'http_request_args', $raise, 9999 );
	}
	if ( '' === $uri ) {
		return new \WP_Error( 'pedc_ai_no_image', is_fr() ? 'Le fournisseur n\'a renvoyé aucune image. Réessaie.' : 'The provider returned no image. Try again.' );
	}
	$prev = get_post_meta( $post->ID, META_IMAGE, true );
	update_post_meta( $post->ID, META_IMAGE, wp_slash( array_merge( is_array( $prev ) ? $prev : [], [ 'image_prompt' => $prompt, 'model' => $model ] ) ) );
	return rest_ensure_response( [ 'image' => $uri, 'model' => $model ] );
}

/** Image bytes re-encoded as WebP, or null when the server cannot. */
function to_webp( string $bytes ): ?string {
	if ( ! (bool) apply_filters( 'pedc_ai_force_webp', true ) || ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) {
		return null;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$tmp = wp_tempnam( 'pedc-image' );
	if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return null;
	}
	$out    = null;
	$editor = wp_get_image_editor( $tmp );
	if ( ! is_wp_error( $editor ) ) {
		$editor->set_quality( 82 );
		$saved = $editor->save( $tmp . '.webp', 'image/webp' );
		if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
			$out = (string) file_get_contents( $saved['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_delete_file( $saved['path'] );
		}
	}
	wp_delete_file( $tmp );
	return '' !== (string) $out ? $out : null;
}

/** Keep the Yoast social images on the featured image. */
function sync_social_image( int $post_id, int $thumb ): void {
	if ( ! yoast_active() || ! $thumb ) {
		return;
	}
	$url = wp_get_attachment_url( $thumb );
	if ( ! $url ) {
		return;
	}
	update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $url );
	update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', (string) $thumb );
	update_post_meta( $post_id, '_yoast_wpseo_twitter-image', $url );
	update_post_meta( $post_id, '_yoast_wpseo_twitter-image-id', (string) $thumb );
}

/** Featured image on the study and on its translations (same file, WPML shares it). */
function set_featured( \WP_Post $post, int $attachment ): void {
	set_post_thumbnail( $post->ID, $attachment );
	sync_social_image( $post->ID, $attachment );
	foreach ( translations_of( $post->ID ) as $tid ) {
		if ( current_user_can( 'edit_post', $tid ) ) {
			set_post_thumbnail( $tid, $attachment );
			sync_social_image( $tid, $attachment );
		}
	}
}

/** POST /image/featured: upload the chosen AI image (WebP) and set it as featured image. */
function handle_image_featured( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( ! current_user_can( 'upload_files' ) ) {
		return new \WP_Error( 'pedc_ai_no_upload', is_fr() ? 'Tu ne peux pas téléverser de fichiers.' : 'You are not allowed to upload files.', [ 'status' => 403 ] );
	}
	$uri = (string) $request->get_param( 'image' );
	if ( ! preg_match( '#^data:(image/(?:jpeg|jpg|png|webp|gif));base64,(.+)$#s', $uri, $m ) ) {
		return new \WP_Error( 'pedc_ai_bad_image', is_fr() ? 'Image invalide.' : 'Invalid image.', [ 'status' => 400 ] );
	}
	$bytes = base64_decode( $m[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	if ( false === $bytes || '' === $bytes ) {
		return new \WP_Error( 'pedc_ai_bad_image', is_fr() ? 'Image invalide.' : 'Invalid image.', [ 'status' => 400 ] );
	}
	$mime = 'image/jpg' === $m[1] ? 'image/jpeg' : $m[1];
	if ( 'image/webp' !== $mime ) {
		$webp = to_webp( $bytes );
		if ( null !== $webp ) {
			$bytes = $webp;
			$mime  = 'image/webp';
		}
	}
	$ext  = [ 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif' ][ $mime ] ?? 'png';
	$base = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
	$base = '' !== $base ? mb_substr( $base, 0, 60 ) : 'etude-de-cas-' . $post->ID;
	$file = $base . '-' . wp_generate_password( 4, false, false ) . '.' . $ext;

	$upload = wp_upload_bits( $file, null, $bytes );
	if ( ! empty( $upload['error'] ) ) {
		return new \WP_Error( 'pedc_ai_upload_failed', (string) $upload['error'], [ 'status' => 500 ] );
	}
	$lang = post_language( $post->ID );
	$alt  = sanitize_text_field( clean_text( (string) $request->get_param( 'alt' ), $lang ) );
	$type = wp_check_filetype( $upload['file'], null );
	$id   = wp_insert_attachment( wp_slash( [
		'post_mime_type' => $type['type'] ? $type['type'] : $mime,
		'post_title'     => '' !== $alt ? $alt : sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	] ), $upload['file'], $post->ID );
	if ( is_wp_error( $id ) || ! $id ) {
		return new \WP_Error( 'pedc_ai_attach_failed', is_fr() ? 'Le fichier n\'a pas pu être ajouté à la médiathèque.' : 'The file could not be added to the media library.', [ 'status' => 500 ] );
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	if ( '' !== $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
	}
	set_featured( $post, (int) $id );
	return rest_ensure_response( session_payload( $post->ID ) );
}

/** POST /image/select: an image of the media library becomes the featured image. */
function handle_image_select( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$id = absint( $request->get_param( 'attachment_id' ) );
	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		return new \WP_Error( 'pedc_ai_bad_image', is_fr() ? 'Choisis une image de la médiathèque.' : 'Choose an image from the media library.', [ 'status' => 400 ] );
	}
	$alt = $request->get_param( 'alt' );
	if ( null !== $alt && current_user_can( 'edit_post', $id ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( clean_text( (string) $alt, post_language( $post->ID ) ) ) ) );
	}
	set_featured( $post, $id );
	return rest_ensure_response( session_payload( $post->ID ) );
}

/** POST /image/alt: alt text of the current featured image. */
function handle_image_alt( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$thumb = (int) get_post_thumbnail_id( $post->ID );
	if ( ! $thumb ) {
		return new \WP_Error( 'pedc_ai_no_thumb', is_fr() ? 'Cette étude n\'a pas encore d\'image à la une.' : 'This case study has no featured image yet.', [ 'status' => 400 ] );
	}
	if ( ! current_user_can( 'edit_post', $thumb ) ) {
		return new \WP_Error( 'pedc_ai_forbidden', is_fr() ? 'Tu ne peux pas modifier cette image.' : 'You cannot edit this image.', [ 'status' => 403 ] );
	}
	update_post_meta( $thumb, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( clean_text( (string) $request->get_param( 'alt' ), post_language( $post->ID ) ) ) ) );
	return rest_ensure_response( session_payload( $post->ID ) );
}

/* 4. Internal links */

/** Published articles and other case studies of the same language. */
function link_candidates( \WP_Post $post, string $lang ): array {
	global $wpdb;
	$exclude = array_merge( [ (int) $post->ID ], array_values( translations_of( $post->ID ) ) );
	$types   = [ 'post', PEDC_POST_TYPE ];
	$limit   = (int) apply_filters( 'pedc_ai_link_candidates', 50 );
	$chars   = (int) apply_filters( 'pedc_ai_link_chars', 1500 );
	// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
	$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
	$not   = implode( ', ', array_map( 'intval', $exclude ) );
	$join  = '';
	$where = $wpdb->prepare( "p.post_status = 'publish' AND p.post_type IN ( {$in} ) AND p.ID NOT IN ( {$not} )", $types );
	$table = translations_table();
	if ( '' !== $table ) {
		$join   = " LEFT JOIN {$table} AS t ON t.element_id = p.ID AND t.element_type = CONCAT( 'post_', p.post_type )";
		$where .= $wpdb->prepare( ' AND ( t.language_code = %s OR t.language_code IS NULL )', $lang );
	}
	$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} AS p {$join} WHERE {$where} ORDER BY p.post_type = '" . esc_sql( PEDC_POST_TYPE ) . "' DESC, p.post_date DESC LIMIT " . $limit );
	// phpcs:enable
	$out = [];
	foreach ( $ids as $id ) {
		$p = get_post( (int) $id );
		if ( ! $p ) {
			continue;
		}
		$out[] = [
			'title'   => html_entity_decode( $p->post_title, ENT_QUOTES, 'UTF-8' ),
			'url'     => (string) get_permalink( $p ),
			'kind'    => PEDC_POST_TYPE === $p->post_type ? 'case study' : 'article',
			'content' => mb_substr( html_to_text( (string) $p->post_content ), 0, $chars ),
		];
	}
	return $out;
}

/** Text compare: lowercase, spaces normalized. */
function flat( string $text ): string {
	return mb_strtolower( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
}

/** POST /links/suggest */
function handle_links_suggest( \WP_REST_Request $request ) {
	long_request();
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$lang = post_language( $post->ID );
	$text = html_to_text( (string) $post->post_content );
	if ( count_words( $text ) < 30 ) {
		return new \WP_Error( 'pedc_ai_no_text', is_fr() ? 'Écris d\'abord le texte de l\'étude.' : 'Write the case study text first.', [ 'status' => 400 ] );
	}
	$candidates = link_candidates( $post, $lang );
	if ( empty( $candidates ) ) {
		return rest_ensure_response( [ 'links' => [] ] );
	}
	$list = '';
	foreach ( $candidates as $i => $c ) {
		$list .= sprintf( "\n[%d] (%s) %s\nURL: %s\n%s\n", $i + 1, $c['kind'], $c['title'], $c['url'], $c['content'] );
	}
	$max    = (int) apply_filters( 'pedc_ai_max_internal_links', 5 );
	$system = sprintf(
		'You are an SEO internal-linking assistant. You receive a company CASE STUDY and a numbered list of EXISTING published pages (articles and other case studies) with their URLs and content. Identify up to %d short phrases that appear VERBATIM in the case study body and would each be natural anchor text for an internal link to the single most relevant existing page. Rules: each anchor must be an exact substring of the case study body, never a section title; use only URLs from the provided list, never invent any; never link the same target twice; only propose genuinely relevant, helpful links; if nothing fits, return an empty list. Never use em-dashes. Answer strictly with the provided JSON schema.',
		$max
	);
	$schema = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'properties'           => [
			'links' => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [ 'anchor' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string' ], 'reason' => [ 'type' => 'string' ] ],
					'required'             => [ 'anchor', 'url' ],
				],
			],
		],
		'required'             => [ 'links' ],
	];
	$answer = ask( 'links', $system, "CASE STUDY TITLE: {$post->post_title}\n\nCASE STUDY BODY:\n{$text}\n\nEXISTING PAGES:{$list}", $schema, 4000 );
	if ( is_wp_error( $answer ) ) {
		return $answer;
	}
	$valid = [];
	foreach ( $candidates as $c ) {
		$valid[ $c['url'] ] = $c;
	}
	$body = flat( $text );
	$out  = [];
	$used = [];
	foreach ( is_array( $answer['data']['links'] ?? null ) ? $answer['data']['links'] : [] as $l ) {
		$anchor = trim( (string) ( $l['anchor'] ?? '' ) );
		$url    = esc_url_raw( (string) ( $l['url'] ?? '' ) );
		if ( '' === $anchor || ! isset( $valid[ $url ] ) || isset( $used[ $url ] ) || false === mb_strpos( $body, flat( $anchor ) ) ) {
			continue;
		}
		$used[ $url ] = true;
		$out[]        = [ 'anchor' => $anchor, 'url' => $url, 'title' => $valid[ $url ]['title'], 'kind' => $valid[ $url ]['kind'] ];
		if ( count( $out ) >= $max ) {
			break;
		}
	}
	return rest_ensure_response( [ 'links' => $out ] );
}

/**
 * Link the first occurrence of a phrase: outside links, headings, tags and
 * block comments; a space in the phrase also matches a no-break space.
 */
function link_phrase( string $content, string $anchor, string $url ): string {
	$words = preg_split( '/[\s\x{00A0}]+/u', trim( $anchor ) );
	if ( empty( $words ) || '' === $words[0] ) {
		return $content;
	}
	$pattern = '/' . implode( '(?:[\s\x{00A0}]|&nbsp;)+', array_map( static function ( $w ) {
		$q = preg_quote( htmlspecialchars( $w, ENT_NOQUOTES, 'UTF-8' ), '/' );
		return str_replace( [ "'", "\u{2019}" ], '(?:\'|&#0?39;|&#8217;|\x{2019})', $q );
	}, $words ) ) . '/iu';
	$parts   = preg_split( '/(<!--.*?-->|<[^>]*>)/su', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) {
		return $content;
	}
	$in_link    = false;
	$in_heading = false;
	foreach ( $parts as $i => $part ) {
		if ( '' === $part ) {
			continue;
		}
		if ( '<' === $part[0] ) {
			if ( preg_match( '#^<a[\s>]#i', $part ) ) {
				$in_link = true;
			} elseif ( preg_match( '#^</a\s*>#i', $part ) ) {
				$in_link = false;
			} elseif ( preg_match( '#^<h[1-6][\s>]#i', $part ) ) {
				$in_heading = true;
			} elseif ( preg_match( '#^</h[1-6]\s*>#i', $part ) ) {
				$in_heading = false;
			}
			continue;
		}
		if ( $in_link || $in_heading ) {
			continue;
		}
		if ( preg_match( $pattern, $part, $m, PREG_OFFSET_CAPTURE ) ) {
			$parts[ $i ] = substr( $part, 0, $m[0][1] ) . '<a href="' . esc_url( $url ) . '">' . $m[0][0] . '</a>' . substr( $part, $m[0][1] + strlen( $m[0][0] ) );
			return implode( '', $parts );
		}
	}
	return $content;
}

/** POST /links/insert */
function handle_links_insert( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$home    = wp_parse_url( home_url(), PHP_URL_HOST );
	$content = (string) $post->post_content;
	$added   = 0;
	$missing = [];
	foreach ( (array) $request->get_param( 'links' ) as $l ) {
		if ( ! is_array( $l ) || empty( $l['anchor'] ) || empty( $l['url'] ) ) {
			continue;
		}
		$url = esc_url_raw( (string) $l['url'] );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
			continue; // Internal links only.
		}
		$anchor = sanitize_text_field( (string) $l['anchor'] );
		$new    = link_phrase( $content, $anchor, $url );
		if ( $new === $content ) {
			$missing[] = $anchor;
		} else {
			$content = $new;
			++$added;
		}
	}
	if ( $added ) {
		$updated = wp_update_post( wp_slash( [ 'ID' => $post->ID, 'post_content' => $content ] ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}
	update_post_meta( $post->ID, META_LINKS, 1 );
	$payload            = session_payload( $post->ID );
	$payload['added']   = $added;
	$payload['missing'] = $missing;
	return rest_ensure_response( $payload );
}

/** POST /links/skip: the step is done without links. */
function handle_links_skip( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	update_post_meta( $post->ID, META_LINKS, 1 );
	return rest_ensure_response( session_payload( $post->ID ) );
}

/* 5. SEO */

function handle_seo_generate( \WP_REST_Request $request ) {
	long_request();
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$lang  = post_language( $post->ID );
	$sheet = \Pratcom\EtudesDeCas\sheet( $post );
	$text  = mb_substr( html_to_text( (string) $post->post_content ), 0, 4000 );
	$facts = '';
	if ( '' !== $sheet['client'] ) {
		$facts .= "\nClient: " . $sheet['client'];
	}
	if ( ! empty( $sheet['sectors'] ) ) {
		$facts .= "\nSector: " . implode( ', ', term_names( $sheet['sectors'] ) );
	}
	if ( ! empty( $sheet['services'] ) ) {
		$facts .= "\nServices: " . implode( ', ', term_names( $sheet['services'] ) );
	}
	foreach ( $sheet['results'] as $r ) {
		$facts .= "\nResult: " . $r['value'] . ' ' . $r['label'];
	}
	$system = 'You are an SEO expert. Produce optimized SEO metadata for a company case study page, written entirely in ' . language_name( $lang ) . '. Use only facts given; never invent figures. Keep the SEO title at or under 60 characters, the meta description at or under 155 characters, and social titles and descriptions concise. The focus keyphrase is 2 to 4 words. The slug is short, lowercase, hyphen-separated, no stop words. Never use em-dashes. Return your answer strictly using the provided JSON schema.';
	$str    = [ 'type' => 'string' ];
	$schema = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'properties'           => [
			'seo_title'           => $str,
			'meta_description'    => $str,
			'focus_keyphrase'     => $str,
			'slug'                => $str,
			'og_title'            => $str,
			'og_description'      => $str,
			'twitter_title'       => $str,
			'twitter_description' => $str,
		],
		'required'             => [ 'seo_title', 'meta_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description' ],
	];
	$answer = ask( 'seo', $system, "Case study title: {$post->post_title}{$facts}\n\nCase study text:\n{$text}", $schema, 3000 );
	if ( is_wp_error( $answer ) ) {
		return $answer;
	}
	$d = $answer['data'];
	if ( empty( $d['seo_title'] ) ) {
		return new \WP_Error( 'pedc_ai_bad_response', is_fr() ? 'La réponse de l\'IA n\'a pas pu être lue. Réessaie.' : 'The AI response could not be read. Try again.' );
	}
	$field = static function ( string $key, string $fallback = '' ) use ( $d, $lang ): string {
		$v = isset( $d[ $key ] ) && '' !== trim( (string) $d[ $key ] ) ? (string) $d[ $key ] : $fallback;
		return sanitize_text_field( clean_text( $v, $lang ) );
	};
	$title = $field( 'seo_title' );
	$desc  = $field( 'meta_description' );
	return rest_ensure_response( [
		'seo_title'           => $title,
		'meta_description'    => $desc,
		'focus_keyphrase'     => sanitize_text_field( no_dashes( (string) ( $d['focus_keyphrase'] ?? '' ) ) ),
		'slug'                => sanitize_title( (string) ( $d['slug'] ?? '' ) ),
		'og_title'            => $field( 'og_title', $title ),
		'og_description'      => $field( 'og_description', $desc ),
		'twitter_title'       => $field( 'twitter_title', $title ),
		'twitter_description' => $field( 'twitter_description', $desc ),
	] );
}

function handle_seo_save( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$lang   = post_language( $post->ID );
	$fields = (array) $request->get_param( 'fields' );
	if ( yoast_active() ) {
		foreach ( yoast_keys() as $key => $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$value = (string) $fields[ $field ];
				$value = 'focus_keyphrase' === $field ? no_dashes( $value ) : clean_text( $value, $lang );
				update_post_meta( $post->ID, $key, wp_slash( sanitize_text_field( $value ) ) );
			}
		}
		sync_social_image( $post->ID, (int) get_post_thumbnail_id( $post->ID ) );
	}
	if ( ! empty( $fields['slug'] ) ) {
		$updated = wp_update_post( wp_slash( [ 'ID' => $post->ID, 'post_name' => sanitize_title( (string) $fields['slug'] ) ] ), true );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}
	update_post_meta( $post->ID, META_SEO, 1 );
	return rest_ensure_response( session_payload( $post->ID ) );
}

/* 7. Checks and publication */

function count_long_dashes( string $text ): int {
	return (int) preg_match_all( '/[\x{2012}\x{2014}\x{2015}]/u', $text ) + (int) preg_match_all( '/\s\x{2013}\s/u', $text );
}

/** Checklist of one study. */
function checks_for( int $post_id ): array {
	$post     = get_post( $post_id );
	$content  = (string) $post->post_content;
	$sheet    = \Pratcom\EtudesDeCas\sheet( $post );
	$sections = h2_sections( $content );
	$items    = [];

	$items[] = [ 'key' => 'client', 'ok' => '' !== $sheet['client'], 'level' => 'warning', 'value' => $sheet['client'] ];
	$items[] = [ 'key' => 'sectors', 'ok' => ! empty( $sheet['sectors'] ), 'level' => 'warning', 'value' => count( $sheet['sectors'] ) ];

	$todo = [];
	foreach ( $sections['sections'] as $s ) {
		if ( $s['todo'] || 0 === $s['words'] ) {
			$todo[] = $s['text'];
		}
	}
	$items[] = [ 'key' => 'todo', 'ok' => empty( $todo ), 'level' => 'error', 'value' => implode( ', ', $todo ) ];

	$len     = mb_strlen( trim( (string) $post->post_excerpt ) );
	$items[] = [ 'key' => 'excerpt', 'ok' => $len >= 120 && $len <= 350, 'level' => 0 === $len ? 'error' : 'warning', 'value' => $len ];

	$thumb   = (int) get_post_thumbnail_id( $post_id );
	$items[] = [ 'key' => 'featured', 'ok' => (bool) $thumb, 'level' => 'error', 'value' => $thumb ];
	$alt     = $thumb ? (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) : '';
	$items[] = [ 'key' => 'alt', 'ok' => '' !== trim( $alt ), 'level' => 'warning', 'value' => $alt ];

	$items[] = [ 'key' => 'results', 'ok' => ! empty( $sheet['results'] ), 'level' => 'info', 'value' => count( $sheet['results'] ) ];

	$missing = 0;
	foreach ( $sections['sections'] as $s ) {
		if ( '' === $s['anchor'] ) {
			++$missing;
		}
	}
	$items[] = [ 'key' => 'anchors', 'ok' => 0 === $missing, 'level' => 'info', 'value' => $missing ];

	if ( yoast_active() ) {
		$ok      = '' !== (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ) && '' !== (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		$items[] = [ 'key' => 'seo', 'ok' => $ok, 'level' => 'warning', 'value' => $ok ];
	}

	$dashes  = count_long_dashes( $post->post_title . ' ' . $post->post_excerpt . ' ' . html_to_text( $content ) );
	$items[] = [ 'key' => 'dashes', 'ok' => 0 === $dashes, 'level' => 'warning', 'value' => $dashes ];

	$foreign = 0;
	foreach ( parse_blocks( $content ) as $b ) {
		if ( ( null === $b['blockName'] && '' !== trim( (string) $b['innerHTML'] ) ) || 'core/html' === $b['blockName'] ) {
			++$foreign;
		}
	}
	$items[] = [ 'key' => 'blocks', 'ok' => 0 === $foreign, 'level' => 'warning', 'value' => $foreign ];
	return $items;
}

function handle_checks( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$out = [ [ 'post_id' => $post->ID, 'lang' => post_language( $post->ID ), 'title' => $post->post_title, 'status' => $post->post_status, 'items' => checks_for( $post->ID ) ] ];
	foreach ( translations_of( $post->ID ) as $code => $tid ) {
		if ( current_user_can( 'edit_post', $tid ) ) {
			$tp    = get_post( $tid );
			$out[] = [ 'post_id' => $tid, 'lang' => $code, 'title' => $tp->post_title, 'status' => $tp->post_status, 'items' => checks_for( $tid ) ];
		}
	}
	return rest_ensure_response( [ 'posts' => $out ] );
}

/** POST /publish: publish, schedule or keep as draft (with the translations if asked). */
function handle_publish( \WP_REST_Request $request ) {
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$mode = sanitize_key( (string) $request->get_param( 'mode' ) );
	$mode = in_array( $mode, [ 'publish', 'future', 'draft' ], true ) ? $mode : 'draft';
	if ( 'draft' !== $mode && ! current_user_can( 'publish_posts' ) ) {
		return new \WP_Error( 'pedc_ai_forbidden', is_fr() ? 'Tu ne peux pas publier.' : 'You are not allowed to publish.', [ 'status' => 403 ] );
	}
	$date = '';
	if ( 'future' === $mode ) {
		$time = strtotime( str_replace( 'T', ' ', (string) $request->get_param( 'date' ) ) );
		if ( ! $time ) {
			return new \WP_Error( 'pedc_ai_bad_date', is_fr() ? 'Choisis une date de publication valide.' : 'Choose a valid publication date.', [ 'status' => 400 ] );
		}
		$date = gmdate( 'Y-m-d H:i:s', $time );
	}
	$ids = [ $post->ID ];
	if ( $request->get_param( 'include_translations' ) ) {
		foreach ( translations_of( $post->ID ) as $tid ) {
			if ( current_user_can( 'edit_post', $tid ) ) {
				$ids[] = $tid;
			}
		}
	}
	$done = [];
	foreach ( $ids as $id ) {
		$p       = get_post( $id );
		$postarr = [ 'ID' => $id, 'post_status' => $mode ];
		$fixed   = ensure_anchors( (string) $p->post_content );
		if ( $fixed !== (string) $p->post_content ) {
			$postarr['post_content'] = $fixed;
		}
		if ( 'future' === $mode ) {
			$postarr['post_date']     = $date;
			$postarr['post_date_gmt'] = get_gmt_from_date( $date );
			$postarr['edit_date']     = true;
		}
		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $id !== $post->ID && get_post_meta( $id, '_pedc_ai_translated', true ) ) {
			mark_synced( $id );
		}
		$np     = get_post( $id );
		$done[] = [
			'post_id' => $id,
			'lang'    => post_language( $id ),
			'status'  => $np->post_status,
			'date'    => $np->post_date,
			'view'    => 'publish' === $np->post_status ? get_permalink( $id ) : get_preview_post_link( $id ),
		];
	}
	$payload              = session_payload( $post->ID );
	$payload['published'] = $done;
	return rest_ensure_response( $payload );
}
