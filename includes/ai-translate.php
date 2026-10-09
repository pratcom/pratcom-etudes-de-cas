<?php
/**
 * AI translation of a case study into another WPML language.
 *
 * Translated: title, text (block markup kept, section anchors unchanged),
 * excerpt, slug, Yoast fields, period, key results, testimonial and its
 * author. Copied: client, website, logo, featured image. Sectors and
 * services: the existing translation is used (renamed when it still has the
 * source name); a missing one is created in the target language and linked
 * in WPML. A new translation is saved as a draft; an existing one is
 * updated and keeps its status.
 */

namespace Pratcom\EtudesDeCas\AI;

defined( 'ABSPATH' ) || exit;

function translate_meta_schema(): array {
	$str  = [ 'type' => 'string' ];
	$list = [ 'type' => 'array', 'items' => $str ];
	return [
		'type'                 => 'object',
		'additionalProperties' => false,
		'properties'           => [
			'title'               => $str,
			'excerpt'             => $str,
			'slug'                => $str,
			'period'              => $str,
			'testimonial'         => $str,
			'testimonial_author'  => $str,
			'results'             => [
				'type'  => 'array',
				'items' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [ 'value' => $str, 'label' => $str ],
					'required'             => [ 'value', 'label' ],
				],
			],
			'sectors'             => $list,
			'services'            => $list,
			'seo_title'           => $str,
			'meta_description'    => $str,
			'focus_keyphrase'     => $str,
			'og_title'            => $str,
			'og_description'      => $str,
			'twitter_title'       => $str,
			'twitter_description' => $str,
		],
		// Every field required: strict schemas with many optional fields are
		// refused by the API. Empty source fields come back empty.
		'required'             => [ 'title', 'excerpt', 'slug', 'period', 'testimonial', 'testimonial_author', 'results', 'sectors', 'services', 'seo_title', 'meta_description', 'focus_keyphrase', 'og_title', 'og_description', 'twitter_title', 'twitter_description' ],
	];
}

/** Term names of a study for one taxonomy. */
function term_names( array $terms ): array {
	return array_map( static function ( $t ) {
		return html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
	}, $terms );
}

/**
 * Translation of a term in a language: the existing one, or a new term
 * created in that language and linked to the source in WPML.
 *
 * @return array { id, created, renamed }
 */
function translated_term( \WP_Term $term, string $name, string $source, string $target ): array {
	$taxonomy = $term->taxonomy;
	$existing = (int) apply_filters( 'wpml_object_id', (int) $term->term_id, $taxonomy, false, $target );
	if ( $existing && $existing !== (int) $term->term_id ) {
		return [ 'id' => $existing, 'created' => false, 'renamed' => rename_copied_term( $existing, $term, $name, $target ) ];
	}
	$parent = 0;
	if ( $term->parent ) {
		$parent_term = get_term( (int) $term->parent, $taxonomy );
		if ( $parent_term instanceof \WP_Term ) {
			$parent = (int) apply_filters( 'wpml_object_id', (int) $parent_term->term_id, $taxonomy, false, $target );
			$parent = $parent === (int) $parent_term->term_id ? 0 : $parent;
		}
	}
	$id = (int) with_language( $target, static function () use ( $term, $name, $taxonomy, $parent, $source, $target ) {
		$args   = $parent ? [ 'parent' => $parent ] : [];
		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			$result = wp_insert_term( $name, $taxonomy, array_merge( $args, [ 'slug' => sanitize_title( $name ) . '-' . $target ] ) );
		}
		if ( is_wp_error( $result ) ) {
			return (int) $result->get_error_data( 'term_exists' );
		}
		$trid = apply_filters( 'wpml_element_trid', null, (int) $term->term_taxonomy_id, 'tax_' . $taxonomy );
		if ( $trid ) {
			do_action( 'wpml_set_element_language_details', [
				'element_id'           => (int) $result['term_taxonomy_id'],
				'element_type'         => 'tax_' . $taxonomy,
				'trid'                 => $trid,
				'language_code'        => $target,
				'source_language_code' => $source,
			] );
		}
		return (int) $result['term_id'];
	} );
	return [ 'id' => $id, 'created' => $id > 0, 'renamed' => false ];
}

/**
 * A translated term that still carries the source name (WPML copies the
 * term when the translation is created) gets the translated name and slug.
 * A term someone already renamed is left alone.
 */
function rename_copied_term( int $id, \WP_Term $source_term, string $name, string $target ): bool {
	$taxonomy = $source_term->taxonomy;
	$copy     = get_term( $id, $taxonomy );
	if ( ! $copy instanceof \WP_Term ) {
		return false;
	}
	$plain = static function ( string $s ): string {
		return mb_strtolower( trim( html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ) ) );
	};
	$name = trim( $name );
	if ( '' === $name || $plain( $copy->name ) !== $plain( $source_term->name ) || $plain( $name ) === $plain( $copy->name ) ) {
		return false;
	}
	return (bool) with_language( $target, static function () use ( $id, $taxonomy, $name, $target ) {
		$slug = sanitize_title( $name );
		foreach ( [ $slug, $slug . '-' . $target ] as $try ) {
			if ( ! is_wp_error( wp_update_term( $id, $taxonomy, [ 'name' => $name, 'slug' => $try ] ) ) ) {
				return true;
			}
		}
		return ! is_wp_error( wp_update_term( $id, $taxonomy, [ 'name' => $name ] ) );
	} );
}

/** Tell WPML a translation is up to date (not "needs update"). */
function mark_synced( int $post_id ): void {
	global $wpdb;
	$table  = $wpdb->prefix . 'icl_translations';
	$status = $wpdb->prefix . 'icl_translation_status';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$translation_id = $wpdb->get_var( $wpdb->prepare( "SELECT translation_id FROM {$table} WHERE element_id = %d AND element_type = %s", $post_id, 'post_' . PEDC_POST_TYPE ) );
	if ( $translation_id && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $status ) ) ) === $status ) {
		$wpdb->update( $status, [ 'needs_update' => 0 ], [ 'translation_id' => (int) $translation_id ] );
	}
	// phpcs:enable
}

/**
 * Context shared by both parts of a translation.
 *
 * @return array|\WP_Error
 */
function translate_context( \WP_REST_Request $request ) {
	if ( ! wpml_active() ) {
		return new \WP_Error( 'pedc_ai_no_wpml', is_fr() ? 'WPML n\'est pas actif sur ce site.' : 'WPML is not active on this site.', [ 'status' => 400 ] );
	}
	$post = editable_study( $request->get_param( 'post_id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( original_of( $post->ID ) !== $post->ID ) {
		return new \WP_Error( 'pedc_ai_not_original', is_fr() ? 'Cette étude est une traduction : traduis à partir de l\'original.' : 'This case study is a translation: translate from the original.', [ 'status' => 400 ] );
	}
	$source = post_language( $post->ID );
	$target = sanitize_key( (string) $request->get_param( 'target' ) );
	if ( '' === $target || $target === $source || ! in_array( $target, wp_list_pluck( languages(), 'code' ), true ) ) {
		return new \WP_Error( 'pedc_ai_no_target', is_fr() ? 'Langue cible invalide.' : 'Invalid target language.', [ 'status' => 400 ] );
	}
	return [
		'post'     => $post,
		'source'   => $source,
		'target'   => $target,
		'name'     => language_name( $target ),
		'sheet'    => \Pratcom\EtudesDeCas\sheet( $post ),
		'existing' => (int) ( translations_of( $post->ID )[ $target ] ?? 0 ),
	];
}

/**
 * POST /translate: translate a study into one language.
 *
 * "part": "text" translates the content and creates or updates the
 * translation; "meta" then translates the title, excerpt, slug, project
 * sheet, sectors, services and Yoast fields of that translation. Two short
 * requests instead of one long one. Without "part", both run in a row.
 */
function handle_translate( \WP_REST_Request $request ) {
	long_request();
	$ctx = translate_context( $request );
	if ( is_wp_error( $ctx ) ) {
		return $ctx;
	}
	$part = sanitize_key( (string) $request->get_param( 'part' ) );
	$out  = [];
	if ( 'meta' !== $part ) {
		$text = translate_text_part( $ctx );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$out             = $text;
		$ctx['existing'] = (int) $text['translation']['id'];
	}
	if ( 'text' !== $part ) {
		if ( ! $ctx['existing'] ) {
			return new \WP_Error( 'pedc_ai_no_translation', is_fr() ? 'Traduis d\'abord le texte.' : 'Translate the text first.', [ 'status' => 400 ] );
		}
		$meta = translate_meta_part( $ctx );
		$out  = array_merge( $out, $meta );
	}
	return rest_ensure_response( $out );
}

/** Response for one translation. */
function translation_response( array $ctx, int $id, bool $updated ): array {
	$lang_name = $ctx['target'];
	foreach ( languages() as $l ) {
		if ( $l['code'] === $ctx['target'] ) {
			$lang_name = $l['name'];
		}
	}
	return [
		'post_id'     => $ctx['post']->ID,
		'lang'        => $ctx['target'],
		'lang_name'   => $lang_name,
		'translation' => [
			'id'     => $id,
			'title'  => get_the_title( $id ),
			'status' => get_post_status( $id ),
			'edit'   => get_edit_post_link( $id, 'raw' ),
			'view'   => get_permalink( $id ),
		],
		'updated'     => $updated,
	];
}

/**
 * Part 1: the block markup translated as is, saved in the translation
 * (created as a draft and linked in WPML when it does not exist yet).
 * Client, website, logo and featured image are copied.
 *
 * @return array|\WP_Error
 */
function translate_text_part( array $ctx ) {
	$post   = $ctx['post'];
	$source = $ctx['source'];
	$target = $ctx['target'];
	$sheet  = $ctx['sheet'];

	$text = ask(
		'translate',
		'You are a professional translator. Translate the content of this company case study into ' . $ctx['name'] . '. Keep every HTML tag, attribute and WordPress block comment (the <!-- wp:... --> and <!-- /wp:... --> markers, with their JSON) exactly unchanged, including "anchor" and id values such as section-1; translate only the human-readable text. Keep brand, product and company names as they are. Never use em-dashes. Output ONLY the translated content, with no preamble, no explanation and no code fences.',
		(string) $post->post_content,
		null,
		(int) apply_filters( 'pedc_ai_translate_max_tokens', 16000 )
	);
	if ( is_wp_error( $text ) ) {
		return $text;
	}
	$content = no_dashes( $text['text'] );
	if ( is_french( $target ) ) {
		$content = nbsp_markup( $content );
	}

	$existing = $ctx['existing'];
	$new_id   = with_language( $target, static function () use ( $existing, $content, $post, $source, $target ) {
		if ( $existing ) {
			return wp_update_post( wp_slash( [ 'ID' => $existing, 'post_content' => $content ] ), true );
		}
		// The title is translated by part 2; until then it keeps the original.
		$id = wp_insert_post( wp_slash( [
			'post_type'    => PEDC_POST_TYPE,
			'post_status'  => 'draft',
			'post_author'  => (int) $post->post_author,
			'post_title'   => $post->post_title,
			'post_content' => $content,
		] ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			return $id;
		}
		$type = 'post_' . PEDC_POST_TYPE;
		$trid = apply_filters( 'wpml_element_trid', null, $post->ID, $type );
		if ( empty( $trid ) ) {
			do_action( 'wpml_set_element_language_details', [ 'element_id' => $post->ID, 'element_type' => $type, 'trid' => null, 'language_code' => $source, 'source_language_code' => null ] );
			$trid = apply_filters( 'wpml_element_trid', null, $post->ID, $type );
		}
		do_action( 'wpml_set_element_language_details', [ 'element_id' => (int) $id, 'element_type' => $type, 'trid' => $trid, 'language_code' => $target, 'source_language_code' => $source ] );
		return (int) $id;
	} );
	if ( is_wp_error( $new_id ) || ! $new_id ) {
		return is_wp_error( $new_id ) ? $new_id : new \WP_Error( 'pedc_ai_save_failed', is_fr() ? 'La traduction n\'a pas pu être enregistrée.' : 'The translation could not be saved.', [ 'status' => 500 ] );
	}
	$new_id = (int) $new_id;

	foreach ( [ '_pedc_client' => 'client', '_pedc_client_url' => 'client_url', '_pedc_client_logo' => 'client_logo' ] as $key => $field ) {
		if ( ! empty( $sheet[ $field ] ) ) {
			update_post_meta( $new_id, $key, wp_slash( $sheet[ $field ] ) );
		}
	}
	$thumb = (int) get_post_thumbnail_id( $post->ID );
	if ( $thumb ) {
		set_post_thumbnail( $new_id, $thumb );
	}
	update_post_meta( $new_id, '_pedc_ai_translated', gmdate( 'Y-m-d H:i:s' ) );
	mark_synced( $new_id );

	$out          = translation_response( $ctx, $new_id, (bool) $existing );
	$out['model'] = $text['model'];
	return $out;
}

/**
 * Part 2: title, excerpt, slug, project sheet, sectors, services and Yoast
 * fields of an existing translation. A failure is reported with its reason
 * and leaves the translation as it is.
 */
function translate_meta_part( array $ctx ): array {
	$post   = $ctx['post'];
	$source = $ctx['source'];
	$target = $ctx['target'];
	$sheet  = $ctx['sheet'];
	$new_id = $ctx['existing'];

	// Sectors with their parents, parents first, so a translated child
	// sector keeps its place in the tree.
	$sectors = [];
	foreach ( $sheet['sectors'] as $term ) {
		foreach ( array_reverse( get_ancestors( $term->term_id, PEDC_TAX_SECTOR, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, PEDC_TAX_SECTOR );
			if ( $ancestor instanceof \WP_Term ) {
				$sectors[ $ancestor->term_id ] = $ancestor;
			}
		}
		$sectors[ $term->term_id ] = $term;
	}
	$sectors  = array_values( $sectors );
	$assigned = [ PEDC_TAX_SECTOR => wp_list_pluck( $sheet['sectors'], 'term_id' ), PEDC_TAX_SERVICE => wp_list_pluck( $sheet['services'], 'term_id' ) ];

	$fields = [
		'title'              => $post->post_title,
		'excerpt'            => (string) $post->post_excerpt,
		'slug'               => urldecode( (string) $post->post_name ),
		'period'             => $sheet['period'],
		'testimonial'        => $sheet['testimonial'],
		'testimonial_author' => $sheet['testimonial_author'],
		'results'            => $sheet['results'],
		'sectors'            => term_names( $sectors ),
		'services'           => term_names( $sheet['services'] ),
	];
	foreach ( yoast_keys() as $key => $field ) {
		$fields[ $field ] = (string) get_post_meta( $post->ID, $key, true );
	}
	$meta = ask(
		'translate',
		'Translate every field of this company case study into ' . $ctx['name'] . '. Keep brand, product, company and person names as they are. "slug" is a short, lowercase, hyphen-separated URL slug. "results" keeps the same rows in the same order; translate "value" only when it contains words (keep figures, signs and units). "testimonial_author" is a name and a job title: keep the name, translate the title. "sectors" and "services" are lists: same order, same count. A field that is empty stays an empty string (or an empty list). Never use em-dashes. Return strictly using the JSON schema.',
		"Fields JSON:\n" . wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		translate_meta_schema(),
		8000
	);
	if ( is_wp_error( $meta ) ) {
		return [ 'meta_failed' => true, 'meta_error' => $meta->get_error_message() ];
	}
	$m = $meta['data'];
	// Translated value of a field; an empty source field stays empty.
	$pick = static function ( string $key, string $fallback ) use ( $m, $target ): string {
		if ( '' === trim( $fallback ) ) {
			return '';
		}
		$v = isset( $m[ $key ] ) && is_string( $m[ $key ] ) && '' !== trim( $m[ $key ] ) ? $m[ $key ] : $fallback;
		return clean_text( $v, $target );
	};

	$postarr = [
		'ID'           => $new_id,
		'post_title'   => sanitize_text_field( $pick( 'title', $post->post_title ) ),
		'post_excerpt' => sanitize_textarea_field( $pick( 'excerpt', $fields['excerpt'] ) ),
	];
	$slug = isset( $m['slug'] ) ? sanitize_title( (string) $m['slug'] ) : '';
	if ( '' !== $slug ) {
		$postarr['post_name'] = $slug;
	}
	with_language( $target, static function () use ( $postarr ) {
		wp_update_post( wp_slash( $postarr ) );
	} );

	foreach ( [ '_pedc_period' => 'period', '_pedc_testimonial' => 'testimonial', '_pedc_testimonial_author' => 'testimonial_author' ] as $key => $field ) {
		if ( '' === $sheet[ $field ] ) {
			continue;
		}
		if ( 'period' === $field ) {
			// A date range keeps its dash (2024 – 2026).
			$value = isset( $m['period'] ) && is_string( $m['period'] ) && '' !== trim( $m['period'] ) ? $m['period'] : $sheet['period'];
			$value = sanitize_text_field( $value );
		} elseif ( 'testimonial' === $field ) {
			$value = sanitize_textarea_field( $pick( $field, $sheet[ $field ] ) );
		} else {
			$value = sanitize_text_field( $pick( $field, $sheet[ $field ] ) );
		}
		update_post_meta( $new_id, $key, wp_slash( $value ) );
	}
	if ( ! empty( $sheet['results'] ) ) {
		$given   = is_array( $m['results'] ?? null ) ? array_values( $m['results'] ) : [];
		$results = [];
		foreach ( $sheet['results'] as $i => $row ) {
			$results[] = [
				'value' => sanitize_text_field( clean_text( (string) ( $given[ $i ]['value'] ?? $row['value'] ), $target ) ),
				'label' => sanitize_text_field( clean_text( (string) ( $given[ $i ]['label'] ?? $row['label'] ), $target ) ),
			];
		}
		update_post_meta( $new_id, '_pedc_results', wp_slash( $results ) );
	}

	// Sectors and services.
	$created = [];
	$renamed = [];
	foreach ( [ 'sectors' => PEDC_TAX_SECTOR, 'services' => PEDC_TAX_SERVICE ] as $field => $taxonomy ) {
		$names = is_array( $m[ $field ] ?? null ) ? array_values( $m[ $field ] ) : [];
		$ids   = [];
		foreach ( ( 'sectors' === $field ? $sectors : $sheet['services'] ) as $i => $term ) {
			$label = isset( $names[ $i ] ) && '' !== trim( (string) $names[ $i ] ) ? sanitize_text_field( no_dashes( (string) $names[ $i ] ) ) : html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			$res   = translated_term( $term, $label, $source, $target );
			if ( $res['id'] ) {
				if ( in_array( (int) $term->term_id, array_map( 'intval', $assigned[ $taxonomy ] ), true ) ) {
					$ids[] = $res['id'];
				}
				if ( $res['created'] ) {
					$created[] = $label;
				} elseif ( $res['renamed'] ) {
					$renamed[] = $label;
				}
			}
		}
		wp_set_object_terms( $new_id, $ids, $taxonomy, false );
	}

	foreach ( yoast_keys() as $key => $field ) {
		if ( '' !== $fields[ $field ] ) {
			$value = 'focus_keyphrase' === $field ? no_dashes( (string) ( $m[ $field ] ?? $fields[ $field ] ) ) : $pick( $field, $fields[ $field ] );
			update_post_meta( $new_id, $key, wp_slash( sanitize_text_field( $value ) ) );
		}
	}
	mark_synced( $new_id );

	return [
		'translation'   => translation_response( $ctx, $new_id, true )['translation'],
		'created_terms' => $created,
		'renamed_terms' => $renamed,
		'meta_failed'   => false,
		'meta_model'    => $meta['model'],
	];
}
