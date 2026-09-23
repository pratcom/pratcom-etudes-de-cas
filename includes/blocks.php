<?php
/**
 * Dynamic blocks: "Project sheet" and "Case studies" (grid).
 * The editor script is plain JS (no build step).
 */

namespace Pratcom\EtudesDeCas;

defined( 'ABSPATH' ) || exit;

add_action( 'init', static function () {
	wp_register_style( 'pedc-front', PEDC_URL . 'assets/css/front.css', [], PEDC_VERSION );

	wp_register_script(
		'pedc-blocks',
		PEDC_URL . 'assets/js/blocks.js',
		[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n', 'wp-data' ],
		PEDC_VERSION,
		true
	);
	wp_set_script_translations( 'pedc-blocks', 'pratcom-etudes-de-cas', PEDC_DIR . 'languages' );

	register_block_type( 'pratcom/fiche-projet', [
		'api_version'     => 3,
		'title'           => __( 'Project sheet', 'pratcom-etudes-de-cas' ),
		'description'     => __( 'Client, sector, services, key results and testimonial of the case study.', 'pratcom-etudes-de-cas' ),
		'category'        => 'theme',
		'icon'            => 'portfolio',
		'keywords'        => [ 'case study', 'étude de cas', 'client', 'fiche' ],
		'uses_context'    => [ 'postId', 'postType' ],
		'editor_script'   => 'pedc-blocks',
		'style'           => 'pedc-front',
		'supports'        => [ 'html' => false, 'align' => [ 'wide', 'full' ] ],
		'attributes'      => [
			'showFacts'       => [ 'type' => 'boolean', 'default' => true ],
			'showResults'     => [ 'type' => 'boolean', 'default' => true ],
			'showTestimonial' => [ 'type' => 'boolean', 'default' => true ],
		],
		'render_callback' => static function ( $attrs, $content, $block ) {
			$post_id = $block->context['postId'] ?? get_the_ID();
			$parts   = [];
			if ( $attrs['showFacts'] ?? true ) {
				$parts[] = 'facts';
			}
			if ( $attrs['showResults'] ?? true ) {
				$parts[] = 'results';
			}
			if ( $attrs['showTestimonial'] ?? true ) {
				$parts[] = 'testimonial';
			}
			$html = render_sheet( $post_id, $parts );
			return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
		},
	] );

	register_block_type( 'pratcom/etudes-de-cas', [
		'api_version'     => 3,
		'title'           => __( 'Case studies', 'pratcom-etudes-de-cas' ),
		'description'     => __( 'Grid of the latest case studies, optionally filtered by sector or service.', 'pratcom-etudes-de-cas' ),
		'category'        => 'widgets',
		'icon'            => 'portfolio',
		'keywords'        => [ 'case studies', 'études de cas', 'réalisations', 'portfolio' ],
		'editor_script'   => 'pedc-blocks',
		'style'           => 'pedc-front',
		'supports'        => [ 'html' => false, 'align' => [ 'wide', 'full' ] ],
		'attributes'      => [
			'number'  => [ 'type' => 'number', 'default' => 3 ],
			'columns' => [ 'type' => 'number', 'default' => 3 ],
			'sector'  => [ 'type' => 'string', 'default' => '' ],
			'service' => [ 'type' => 'string', 'default' => '' ],
			'orderby' => [ 'type' => 'string', 'default' => 'date' ],
		],
		'render_callback' => static function ( $attrs ) {
			$html = render_list( [
				'number'  => $attrs['number'] ?? 3,
				'columns' => $attrs['columns'] ?? 3,
				'sector'  => $attrs['sector'] ?? '',
				'service' => $attrs['service'] ?? '',
				'orderby' => $attrs['orderby'] ?? 'date',
			] );
			return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
		},
	] );

	// Plugin-internal blocks used by the block-theme templates.
	register_block_type( 'pratcom/etudes-filtre', [
		'api_version'     => 3,
		'title'           => __( 'Case studies: sector filter', 'pratcom-etudes-de-cas' ),
		'category'        => 'theme',
		'icon'            => 'filter',
		'editor_script'   => 'pedc-blocks',
		'style'           => 'pedc-front',
		'supports'        => [ 'html' => false, 'align' => [ 'wide', 'full' ] ],
		'render_callback' => static function () {
			$html = render_sector_filter();
			return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
		},
	] );

	register_block_type( 'pratcom/etudes-connexes', [
		'api_version'     => 3,
		'title'           => __( 'Related case studies', 'pratcom-etudes-de-cas' ),
		'category'        => 'theme',
		'icon'            => 'portfolio',
		'uses_context'    => [ 'postId' ],
		'editor_script'   => 'pedc-blocks',
		'style'           => 'pedc-front',
		'supports'        => [ 'html' => false, 'align' => [ 'wide', 'full' ] ],
		'render_callback' => static function ( $attrs, $content, $block ) {
			if ( ! setting( 'related' ) ) {
				return '';
			}
			$html = render_related( $block->context['postId'] ?? get_the_ID() );
			return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
		},
	] );
} );

/** Localized sector/service lists for the block controls. */
add_action( 'enqueue_block_editor_assets', static function () {
	$pick = static function ( string $tax ): array {
		$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
		$out   = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[] = [ 'value' => $t->slug, 'label' => $t->name ];
			}
		}
		return $out;
	};
	wp_add_inline_script( 'pedc-blocks', 'window.pedcBlocks = ' . wp_json_encode( [
		'sectors'  => $pick( PEDC_TAX_SECTOR ),
		'services' => $pick( PEDC_TAX_SERVICE ),
	] ) . ';', 'before' );
} );
