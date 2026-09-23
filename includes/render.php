<?php
/**
 * Front-end rendering shared by the templates, blocks and shortcodes.
 * Every function returns HTML; nothing is echoed here.
 */

namespace Pratcom\EtudesDeCas;

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', static function () {
	wp_register_style( 'pedc-front', PEDC_URL . 'assets/css/front.css', [], PEDC_VERSION );
	if ( is_case_study_view() ) {
		wp_enqueue_style( 'pedc-front' );
	}
} );

function is_case_study_view(): bool {
	return is_singular( PEDC_POST_TYPE ) || is_post_type_archive( PEDC_POST_TYPE ) || is_tax( [ PEDC_TAX_SECTOR, PEDC_TAX_SERVICE ] );
}

function enqueue_front(): void {
	if ( ! wp_style_is( 'pedc-front', 'registered' ) ) {
		wp_register_style( 'pedc-front', PEDC_URL . 'assets/css/front.css', [], PEDC_VERSION );
	}
	wp_enqueue_style( 'pedc-front' );
}

function term_links( array $terms, string $class = '' ): string {
	$links = [];
	foreach ( $terms as $t ) {
		$url     = get_term_link( $t );
		$links[] = is_wp_error( $url )
			? esc_html( $t->name )
			: sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $class ? ' class="' . esc_attr( $class ) . '"' : '', esc_html( $t->name ) );
	}
	return implode( ', ', $links );
}

/**
 * Project sheet.
 *
 * @param int|\WP_Post|null $post  Case study.
 * @param string[]          $parts Any of facts, results, testimonial.
 */
function render_sheet( $post = null, array $parts = [ 'facts', 'results', 'testimonial' ] ): string {
	$post = get_post( $post );
	if ( ! $post || PEDC_POST_TYPE !== $post->post_type ) {
		return '';
	}
	enqueue_front();
	$d   = sheet( $post );
	$out = '';

	if ( in_array( 'facts', $parts, true ) ) {
		$rows = [];
		if ( $d['client'] ) {
			$rows[ __( 'Client', 'pratcom-etudes-de-cas' ) ] = esc_html( $d['client'] );
		}
		if ( $d['sectors'] ) {
			$rows[ _n( 'Sector', 'Sectors', count( $d['sectors'] ), 'pratcom-etudes-de-cas' ) ] = term_links( $d['sectors'] );
		}
		if ( $d['services'] ) {
			$rows[ _n( 'Service', 'Services', count( $d['services'] ), 'pratcom-etudes-de-cas' ) ] = term_links( $d['services'] );
		}
		if ( $d['period'] ) {
			$rows[ __( 'Period', 'pratcom-etudes-de-cas' ) ] = esc_html( $d['period'] );
		}
		if ( $d['client_url'] ) {
			$host = wp_parse_url( $d['client_url'], PHP_URL_HOST );
			$rows[ __( 'Website', 'pratcom-etudes-de-cas' ) ] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $d['client_url'] ),
				esc_html( $host ? preg_replace( '/^www\./', '', $host ) : $d['client_url'] )
			);
		}
		if ( $rows || $d['client_logo'] ) {
			$out .= '<div class="pedc-sheet__facts">';
			if ( $d['client_logo'] ) {
				$out .= '<div class="pedc-sheet__logo">' . wp_get_attachment_image( $d['client_logo'], 'medium', false, [
					'alt'     => $d['client'] ? $d['client'] : '',
					'loading' => 'lazy',
				] ) . '</div>';
			}
			if ( $rows ) {
				$out .= '<dl>';
				foreach ( $rows as $label => $html ) {
					$out .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $html . '</dd></div>';
				}
				$out .= '</dl>';
			}
			$out .= '</div>';
		}
	}

	if ( in_array( 'results', $parts, true ) && $d['results'] ) {
		$out .= '<ul class="pedc-results" aria-label="' . esc_attr__( 'Key results', 'pratcom-etudes-de-cas' ) . '">';
		foreach ( $d['results'] as $r ) {
			$out .= '<li><strong class="pedc-results__value">' . esc_html( $r['value'] ) . '</strong><span class="pedc-results__label">' . esc_html( $r['label'] ) . '</span></li>';
		}
		$out .= '</ul>';
	}

	if ( in_array( 'testimonial', $parts, true ) && $d['testimonial'] ) {
		$out .= '<figure class="pedc-testimonial"><blockquote><p>' . nl2br( esc_html( $d['testimonial'] ) ) . '</p></blockquote>';
		if ( $d['testimonial_author'] ) {
			$out .= '<figcaption>' . esc_html( $d['testimonial_author'] ) . '</figcaption>';
		}
		$out .= '</figure>';
	}

	if ( '' === $out ) {
		return '';
	}
	return '<aside class="pedc-sheet" aria-label="' . esc_attr__( 'Project sheet', 'pratcom-etudes-de-cas' ) . '">' . $out . '</aside>';
}

/** One card (used by archives, the list block and related studies). */
function render_card( $post = null, string $heading = 'h3' ): string {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$heading = in_array( $heading, [ 'h2', 'h3', 'h4' ], true ) ? $heading : 'h3';
	$d       = sheet( $post );
	$link    = get_permalink( $post );
	$html    = '<article class="pedc-card">';

	if ( has_post_thumbnail( $post ) ) {
		$html .= '<div class="pedc-card__media">' . get_the_post_thumbnail( $post, 'medium_large', [ 'loading' => 'lazy', 'alt' => '' ] ) . '</div>';
	}
	$html .= '<div class="pedc-card__body">';
	if ( $d['sectors'] ) {
		$html .= '<p class="pedc-card__sector">' . esc_html( $d['sectors'][0]->name ) . '</p>';
	}
	$html .= sprintf(
		'<%1$s class="pedc-card__title"><a href="%2$s">%3$s</a></%1$s>',
		$heading,
		esc_url( $link ),
		esc_html( get_the_title( $post ) )
	);
	if ( $d['client'] ) {
		$html .= '<p class="pedc-card__client">' . esc_html( $d['client'] ) . '</p>';
	}
	$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 24 );
	if ( $excerpt ) {
		$html .= '<p class="pedc-card__excerpt">' . esc_html( $excerpt ) . '</p>';
	}
	if ( $d['results'] ) {
		$r     = $d['results'][0];
		$html .= '<p class="pedc-card__result"><strong>' . esc_html( $r['value'] ) . '</strong> ' . esc_html( $r['label'] ) . '</p>';
	}
	$html .= '</div></article>';

	return $html;
}

/**
 * Grid of case studies.
 *
 * @param array $args number, sector (slug list), service (slug list), columns, exclude, heading, orderby.
 */
function render_list( array $args = [] ): string {
	$a = wp_parse_args( $args, [
		'number'  => 3,
		'sector'  => '',
		'service' => '',
		'columns' => 3,
		'exclude' => [],
		'heading' => 'h3',
		'orderby' => 'date',
	] );

	$q_args = [
		'post_type'           => PEDC_POST_TYPE,
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, min( 24, (int) $a['number'] ) ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'post__not_in'        => array_map( 'intval', (array) $a['exclude'] ),
		'orderby'             => in_array( $a['orderby'], [ 'date', 'title', 'rand', 'menu_order' ], true ) ? $a['orderby'] : 'date',
		'order'               => 'title' === $a['orderby'] ? 'ASC' : 'DESC',
	];
	$tax = [];
	foreach ( [ 'sector' => PEDC_TAX_SECTOR, 'service' => PEDC_TAX_SERVICE ] as $key => $taxonomy ) {
		$slugs = array_filter( array_map( 'sanitize_title', is_array( $a[ $key ] ) ? $a[ $key ] : explode( ',', (string) $a[ $key ] ) ) );
		if ( $slugs ) {
			$tax[] = [ 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $slugs ];
		}
	}
	if ( $tax ) {
		$q_args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	$q = new \WP_Query( apply_filters( 'pedc_list_query_args', $q_args, $a ) );
	if ( ! $q->have_posts() ) {
		return '';
	}
	enqueue_front();

	$cols = max( 1, min( 4, (int) $a['columns'] ) );
	$html = '<div class="pedc-grid" style="--pedc-cols:' . $cols . '">';
	foreach ( $q->posts as $p ) {
		$html .= render_card( $p, $a['heading'] );
	}
	$html .= '</div>';
	wp_reset_postdata();

	return $html;
}

/** Up to 3 other case studies: same sector first, completed with the most recent. */
function render_related( $post = null ): string {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$want    = (int) apply_filters( 'pedc_related_count', 3 );
	$exclude = [ $post->ID ];
	$ids     = [];
	$sectors = wp_list_pluck( terms_of( $post->ID, PEDC_TAX_SECTOR ), 'term_id' );
	$base    = [
		'post_type'           => PEDC_POST_TYPE,
		'post_status'         => 'publish',
		'fields'              => 'ids',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	];
	if ( $sectors ) {
		$ids = get_posts( $base + [
			'posts_per_page' => $want,
			'post__not_in'   => $exclude,
			'tax_query'      => [ [ 'taxonomy' => PEDC_TAX_SECTOR, 'terms' => $sectors ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
	}
	if ( count( $ids ) < $want ) {
		$ids = array_merge( $ids, get_posts( $base + [
			'posts_per_page' => $want - count( $ids ),
			'post__not_in'   => array_merge( $exclude, $ids ),
		] ) );
	}
	if ( ! $ids ) {
		return '';
	}
	enqueue_front();
	$html = '';
	foreach ( $ids as $id ) {
		$html .= render_card( $id );
	}
	return '<section class="pedc-related"><h2 class="pedc-related__title">' . esc_html__( 'Other case studies', 'pratcom-etudes-de-cas' ) . '</h2><div class="pedc-grid" style="--pedc-cols:3">' . $html . '</div></section>';
}

/** Sector filter (All + each non-empty sector). */
function render_sector_filter(): string {
	$terms = get_terms( [ 'taxonomy' => PEDC_TAX_SECTOR, 'hide_empty' => true, 'parent' => 0 ] );
	if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
		return '';
	}
	enqueue_front();
	$current = is_tax( PEDC_TAX_SECTOR ) ? get_queried_object_id() : 0;
	$html    = '<nav class="pedc-filter" aria-label="' . esc_attr__( 'Filter by sector', 'pratcom-etudes-de-cas' ) . '"><ul>';
	$html   .= sprintf(
		'<li><a href="%s"%s>%s</a></li>',
		esc_url( get_post_type_archive_link( PEDC_POST_TYPE ) ),
		$current ? '' : ' aria-current="page"',
		esc_html__( 'All', 'pratcom-etudes-de-cas' )
	);
	foreach ( $terms as $t ) {
		$html .= sprintf(
			'<li><a href="%s"%s>%s</a></li>',
			esc_url( get_term_link( $t ) ),
			$current === $t->term_id ? ' aria-current="page"' : '',
			esc_html( $t->name )
		);
	}
	return $html . '</ul></nav>';
}

/* Shortcodes (classic editor, widgets, anywhere blocks are not available) */

add_shortcode( 'pratcom_fiche_projet', static function ( $atts ) {
	$a     = shortcode_atts( [ 'id' => 0, 'parts' => 'facts,results,testimonial' ], $atts, 'pratcom_fiche_projet' );
	$parts = array_map( 'trim', explode( ',', (string) $a['parts'] ) );
	return render_sheet( $a['id'] ? (int) $a['id'] : null, $parts );
} );

add_shortcode( 'pratcom_etudes_de_cas', static function ( $atts ) {
	$a = shortcode_atts( [
		'nombre'   => 3,
		'secteur'  => '',
		'service'  => '',
		'colonnes' => 3,
		'tri'      => 'date',
	], $atts, 'pratcom_etudes_de_cas' );
	return render_list( [
		'number'  => $a['nombre'],
		'sector'  => $a['secteur'],
		'service' => $a['service'],
		'columns' => $a['colonnes'],
		'orderby' => $a['tri'],
	] );
} );

/** Theme mode: optionally prepend the sheet to the content. */
add_filter( 'the_content', static function ( $content ) {
	if ( plugin_renders_single() || ! setting( 'auto_sheet' ) ) {
		return $content;
	}
	if ( ! is_singular( PEDC_POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( has_block( 'pratcom/fiche-projet' ) || has_shortcode( $content, 'pratcom_fiche_projet' ) ) {
		return $content;
	}
	return render_sheet( null, [ 'facts', 'results' ] ) . $content . render_sheet( null, [ 'testimonial' ] );
}, 8 );
