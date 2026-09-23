<?php
/**
 * Post type, taxonomies and project sheet meta.
 */

namespace Pratcom\EtudesDeCas;

defined( 'ABSPATH' ) || exit;

/**
 * Meta keys of the project sheet. Underscore-prefixed: hidden from the
 * generic "Custom fields" box, exposed in REST under `meta`.
 */
function meta_fields(): array {
	return [
		'_pedc_client'             => [ 'type' => 'string', 'sanitize' => 'sanitize_text_field' ],
		'_pedc_client_url'         => [ 'type' => 'string', 'sanitize' => 'esc_url_raw' ],
		'_pedc_client_logo'        => [ 'type' => 'integer', 'sanitize' => 'absint' ],
		'_pedc_period'             => [ 'type' => 'string', 'sanitize' => 'sanitize_text_field' ],
		'_pedc_testimonial'        => [ 'type' => 'string', 'sanitize' => 'sanitize_textarea_field' ],
		'_pedc_testimonial_author' => [ 'type' => 'string', 'sanitize' => 'sanitize_text_field' ],
		'_pedc_results'            => [ 'type' => 'array', 'sanitize' => __NAMESPACE__ . '\\sanitize_results' ],
	];
}

/** Results: list of { value: "+212 %", label: "organic traffic" }, max 8. */
function sanitize_results( $rows ): array {
	$out = [];
	if ( ! is_array( $rows ) ) {
		return $out;
	}
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$value = sanitize_text_field( $row['value'] ?? '' );
		$label = sanitize_text_field( $row['label'] ?? '' );
		if ( '' === $value && '' === $label ) {
			continue;
		}
		$out[] = [ 'value' => $value, 'label' => $label ];
		if ( count( $out ) >= 8 ) {
			break;
		}
	}
	return $out;
}

function register_taxonomies(): void {
	$base = setting( 'slug' );

	register_taxonomy( PEDC_TAX_SECTOR, [ PEDC_POST_TYPE ], [
		'labels'            => [
			'name'              => _x( 'Sectors', 'taxonomy general name', 'pratcom-etudes-de-cas' ),
			'singular_name'     => _x( 'Sector', 'taxonomy singular name', 'pratcom-etudes-de-cas' ),
			'menu_name'         => __( 'Sectors', 'pratcom-etudes-de-cas' ),
			'all_items'         => __( 'All sectors', 'pratcom-etudes-de-cas' ),
			'edit_item'         => __( 'Edit sector', 'pratcom-etudes-de-cas' ),
			'view_item'         => __( 'View sector', 'pratcom-etudes-de-cas' ),
			'update_item'       => __( 'Update sector', 'pratcom-etudes-de-cas' ),
			'add_new_item'      => __( 'Add new sector', 'pratcom-etudes-de-cas' ),
			'new_item_name'     => __( 'New sector name', 'pratcom-etudes-de-cas' ),
			'parent_item'       => __( 'Parent sector', 'pratcom-etudes-de-cas' ),
			'parent_item_colon' => __( 'Parent sector:', 'pratcom-etudes-de-cas' ),
			'search_items'      => __( 'Search sectors', 'pratcom-etudes-de-cas' ),
			'not_found'         => __( 'No sectors found.', 'pratcom-etudes-de-cas' ),
			'back_to_items'     => __( '&larr; Go to sectors', 'pratcom-etudes-de-cas' ),
		],
		'hierarchical'      => true,
		'public'            => true,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'rest_base'         => 'etude-secteurs',
		'rewrite'           => [ 'slug' => $base . '/' . setting( 'sector_slug' ), 'with_front' => false, 'hierarchical' => true ],
	] );

	register_taxonomy( PEDC_TAX_SERVICE, [ PEDC_POST_TYPE ], [
		'labels'                => [
			'name'                       => _x( 'Services', 'taxonomy general name', 'pratcom-etudes-de-cas' ),
			'singular_name'              => _x( 'Service', 'taxonomy singular name', 'pratcom-etudes-de-cas' ),
			'menu_name'                  => __( 'Services', 'pratcom-etudes-de-cas' ),
			'all_items'                  => __( 'All services', 'pratcom-etudes-de-cas' ),
			'edit_item'                  => __( 'Edit service', 'pratcom-etudes-de-cas' ),
			'view_item'                  => __( 'View service', 'pratcom-etudes-de-cas' ),
			'update_item'                => __( 'Update service', 'pratcom-etudes-de-cas' ),
			'add_new_item'               => __( 'Add new service', 'pratcom-etudes-de-cas' ),
			'new_item_name'              => __( 'New service name', 'pratcom-etudes-de-cas' ),
			'search_items'               => __( 'Search services', 'pratcom-etudes-de-cas' ),
			'popular_items'              => __( 'Popular services', 'pratcom-etudes-de-cas' ),
			'separate_items_with_commas' => __( 'Separate services with commas', 'pratcom-etudes-de-cas' ),
			'add_or_remove_items'        => __( 'Add or remove services', 'pratcom-etudes-de-cas' ),
			'choose_from_most_used'      => __( 'Choose from the most used services', 'pratcom-etudes-de-cas' ),
			'not_found'                  => __( 'No services found.', 'pratcom-etudes-de-cas' ),
			'back_to_items'              => __( '&larr; Go to services', 'pratcom-etudes-de-cas' ),
		],
		'hierarchical'          => false,
		'public'                => true,
		'show_admin_column'     => true,
		'show_in_rest'          => true,
		'rest_base'             => 'etude-services',
		'rewrite'               => [ 'slug' => $base . '/' . setting( 'service_slug' ), 'with_front' => false ],
	] );
}

function register_post_type(): void {
	\register_post_type( PEDC_POST_TYPE, [
		'labels'              => [
			'name'                     => _x( 'Case studies', 'post type general name', 'pratcom-etudes-de-cas' ),
			'singular_name'            => _x( 'Case study', 'post type singular name', 'pratcom-etudes-de-cas' ),
			'menu_name'                => __( 'Case studies', 'pratcom-etudes-de-cas' ),
			'name_admin_bar'           => __( 'Case study', 'pratcom-etudes-de-cas' ),
			'add_new'                  => __( 'Add new', 'pratcom-etudes-de-cas' ),
			'add_new_item'             => __( 'Add new case study', 'pratcom-etudes-de-cas' ),
			'new_item'                 => __( 'New case study', 'pratcom-etudes-de-cas' ),
			'edit_item'                => __( 'Edit case study', 'pratcom-etudes-de-cas' ),
			'view_item'                => __( 'View case study', 'pratcom-etudes-de-cas' ),
			'view_items'               => __( 'View case studies', 'pratcom-etudes-de-cas' ),
			'all_items'                => __( 'All case studies', 'pratcom-etudes-de-cas' ),
			'search_items'             => __( 'Search case studies', 'pratcom-etudes-de-cas' ),
			'not_found'                => __( 'No case studies found.', 'pratcom-etudes-de-cas' ),
			'not_found_in_trash'       => __( 'No case studies found in Trash.', 'pratcom-etudes-de-cas' ),
			'archives'                 => __( 'Case studies', 'pratcom-etudes-de-cas' ),
			'attributes'               => __( 'Case study attributes', 'pratcom-etudes-de-cas' ),
			'insert_into_item'         => __( 'Insert into case study', 'pratcom-etudes-de-cas' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this case study', 'pratcom-etudes-de-cas' ),
			'featured_image'           => __( 'Featured image', 'pratcom-etudes-de-cas' ),
			'filter_items_list'        => __( 'Filter case studies list', 'pratcom-etudes-de-cas' ),
			'items_list_navigation'    => __( 'Case studies list navigation', 'pratcom-etudes-de-cas' ),
			'items_list'               => __( 'Case studies list', 'pratcom-etudes-de-cas' ),
			'item_published'           => __( 'Case study published.', 'pratcom-etudes-de-cas' ),
			'item_published_privately' => __( 'Case study published privately.', 'pratcom-etudes-de-cas' ),
			'item_reverted_to_draft'   => __( 'Case study reverted to draft.', 'pratcom-etudes-de-cas' ),
			'item_scheduled'           => __( 'Case study scheduled.', 'pratcom-etudes-de-cas' ),
			'item_updated'             => __( 'Case study updated.', 'pratcom-etudes-de-cas' ),
			'item_link'                => __( 'Case study link', 'pratcom-etudes-de-cas' ),
		],
		'description'         => __( 'Client case studies, kept separate from blog posts.', 'pratcom-etudes-de-cas' ),
		'public'              => true,
		'show_in_rest'        => true,
		'rest_base'           => 'etudes-de-cas',
		'menu_position'       => 6, // Right under "Posts".
		'menu_icon'           => 'dashicons-portfolio',
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'hierarchical'        => false,
		'has_archive'         => setting( 'slug' ),
		'rewrite'             => [ 'slug' => setting( 'slug' ), 'with_front' => false, 'feeds' => true, 'pages' => true ],
		'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'comments', 'revisions', 'custom-fields', 'trackbacks' ],
		'taxonomies'          => [ PEDC_TAX_SECTOR, PEDC_TAX_SERVICE ],
		'template'            => content_skeleton(),
	] );

	foreach ( meta_fields() as $key => $def ) {
		$args = [
			'type'              => $def['type'],
			'single'            => true,
			'sanitize_callback' => $def['sanitize'],
			'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', $post_id );
			},
			'show_in_rest'      => true,
		];
		if ( 'array' === $def['type'] ) {
			$args['default']      = [];
			$args['show_in_rest'] = [
				'schema' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'value' => [ 'type' => 'string' ],
							'label' => [ 'type' => 'string' ],
						],
					],
				],
			];
		}
		register_post_meta( PEDC_POST_TYPE, $key, $args );
	}
}

// Taxonomies first: their rewrite rules (etudes-de-cas/secteur/…) must come
// before the post type ones (etudes-de-cas/…), which would otherwise catch them.
add_action( 'init', __NAMESPACE__ . '\\register_taxonomies', 5 );
add_action( 'init', __NAMESPACE__ . '\\register_post_type', 6 );

/** Archive page size (main query only). */
add_action( 'pre_get_posts', static function ( \WP_Query $q ) {
	if ( is_admin() || ! $q->is_main_query() ) {
		return;
	}
	if ( $q->is_post_type_archive( PEDC_POST_TYPE ) || $q->is_tax( [ PEDC_TAX_SECTOR, PEDC_TAX_SERVICE ] ) ) {
		$q->set( 'posts_per_page', (int) setting( 'archive_count' ) );
	}
} );

/** Project sheet data for a case study, normalized. */
function sheet( $post = null ): array {
	$post = get_post( $post );
	if ( ! $post ) {
		return [];
	}
	$id = $post->ID;
	return [
		'client'             => (string) get_post_meta( $id, '_pedc_client', true ),
		'client_url'         => (string) get_post_meta( $id, '_pedc_client_url', true ),
		'client_logo'        => (int) get_post_meta( $id, '_pedc_client_logo', true ),
		'period'             => (string) get_post_meta( $id, '_pedc_period', true ),
		'testimonial'        => (string) get_post_meta( $id, '_pedc_testimonial', true ),
		'testimonial_author' => (string) get_post_meta( $id, '_pedc_testimonial_author', true ),
		'results'            => sanitize_results( get_post_meta( $id, '_pedc_results', true ) ),
		'sectors'            => terms_of( $id, PEDC_TAX_SECTOR ),
		'services'           => terms_of( $id, PEDC_TAX_SERVICE ),
	];
}

/** @return \WP_Term[] */
function terms_of( int $post_id, string $taxonomy ): array {
	$terms = get_the_terms( $post_id, $taxonomy );
	return is_array( $terms ) ? $terms : [];
}

/**
 * Starting content of a new case study: native blocks only, one H2 per section.
 * Anchors are language-neutral (#section-1…) so WPML copies them unchanged and
 * links stay valid in every language. Not locked: sections can be added,
 * removed or renamed; H3 go inside a section.
 */
function content_skeleton(): array {
	$sections = [
		[ __( 'Context', 'pratcom-etudes-de-cas' ), __( 'Who is the client, what do they do, and what was their situation before the project?', 'pratcom-etudes-de-cas' ), '' ],
		[ __( 'The challenge', 'pratcom-etudes-de-cas' ), __( 'What problem did they need to solve, and why was it urgent?', 'pratcom-etudes-de-cas' ), '' ],
		[ __( 'The solution', 'pratcom-etudes-de-cas' ), __( 'What was put in place, and how?', 'pratcom-etudes-de-cas' ), __( 'Main step or component of the solution', 'pratcom-etudes-de-cas' ) ],
		[ __( 'The results', 'pratcom-etudes-de-cas' ), __( 'What changed for the client? Give measurable results.', 'pratcom-etudes-de-cas' ), __( 'Measurable result (figure + what it means)', 'pratcom-etudes-de-cas' ) ],
	];
	$blocks = [];
	foreach ( $sections as $i => [ $title, $paragraph, $list_item ] ) {
		$blocks[] = [ 'core/heading', [ 'level' => 2, 'anchor' => 'section-' . ( $i + 1 ), 'content' => $title ] ];
		$blocks[] = [ 'core/paragraph', [ 'placeholder' => $paragraph ] ];
		if ( '' !== $list_item ) {
			$blocks[] = [ 'core/list', [], [ [ 'core/list-item', [ 'placeholder' => $list_item ] ] ] ];
		}
	}
	return apply_filters( 'pedc_content_skeleton', $blocks );
}

/**
 * The starting paragraphs and list items carry a "placeholder" attribute (the
 * grey hint in the editor). Strip it on save so the stored content is clean
 * native blocks, identical to what a writer would type by hand.
 */
add_filter( 'wp_insert_post_data', static function ( $data ) {
	// $data is slashed: look for the bare word, the quotes are escaped.
	if ( PEDC_POST_TYPE !== ( $data['post_type'] ?? '' ) || false === strpos( $data['post_content'], 'placeholder' ) ) {
		return $data;
	}
	$blocks = parse_blocks( wp_unslash( $data['post_content'] ) );
	$strip  = static function ( array $blocks ) use ( &$strip ): array {
		foreach ( $blocks as &$block ) {
			if ( in_array( $block['blockName'], [ 'core/paragraph', 'core/list-item', 'core/heading' ], true ) ) {
				unset( $block['attrs']['placeholder'] );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $strip( $block['innerBlocks'] );
			}
		}
		return $blocks;
	};
	$data['post_content'] = wp_slash( serialize_blocks( $strip( $blocks ) ) );
	return $data;
}, 10 );
