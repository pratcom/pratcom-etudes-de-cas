<?php
/**
 * Templates.
 *
 * Classic themes: template_include, unless the theme ships its own
 *   single-etude_de_cas.php / archive-etude_de_cas.php / taxonomy-etude_*.php
 *   or pratcom-etudes-de-cas/{single,archive}.php.
 * Block themes: plugin-registered block templates (WP 6.7+). A theme file
 *   templates/single-etude_de_cas.html, or an edit in the Site Editor, wins.
 */

namespace Pratcom\EtudesDeCas;

defined( 'ABSPATH' ) || exit;

/* Classic themes */

add_filter( 'template_include', static function ( $template ) {
	if ( 'plugin' !== setting( 'templates' ) || wp_is_block_theme() ) {
		return $template;
	}

	if ( is_singular( PEDC_POST_TYPE ) ) {
		if ( ! plugin_renders_single() ) {
			return $template;
		}
		// A page template chosen on the post stays in charge.
		if ( get_page_template_slug() ) {
			return $template;
		}
		$override = locate_template( [ 'pratcom-etudes-de-cas/single.php', 'single-' . PEDC_POST_TYPE . '.php' ] );
		return $override ?: PEDC_DIR . 'templates/classic/single.php';
	}

	if ( is_post_type_archive( PEDC_POST_TYPE ) || is_tax( [ PEDC_TAX_SECTOR, PEDC_TAX_SERVICE ] ) ) {
		$candidates = [ 'pratcom-etudes-de-cas/archive.php', 'archive-' . PEDC_POST_TYPE . '.php' ];
		if ( is_tax() ) {
			$term = get_queried_object();
			array_unshift( $candidates, 'taxonomy-' . $term->taxonomy . '-' . $term->slug . '.php', 'taxonomy-' . $term->taxonomy . '.php' );
		}
		$override = locate_template( $candidates );
		return $override ?: PEDC_DIR . 'templates/classic/archive.php';
	}

	return $template;
}, 10 );

/* Block themes */

add_action( 'init', static function () {
	if ( 'plugin' !== setting( 'templates' ) || ! function_exists( 'register_block_template' ) ) {
		return;
	}

	// Single: only when the plugin renders it; otherwise the theme's single.html
	// (its table of contents, call to action, author) takes over.
	if ( plugin_renders_single() ) {
		register_block_template( 'pratcom-etudes-de-cas//single-' . PEDC_POST_TYPE, [
			'title'       => __( 'Single case study', 'pratcom-etudes-de-cas' ),
			'description' => __( 'Displays a single case study with its project sheet.', 'pratcom-etudes-de-cas' ),
			'content'     => block_template_single(),
			'post_types'  => [ PEDC_POST_TYPE ],
		] );
	}

	$archive = block_template_archive();
	register_block_template( 'pratcom-etudes-de-cas//archive-' . PEDC_POST_TYPE, [
		'title'       => __( 'Case studies archive', 'pratcom-etudes-de-cas' ),
		'description' => __( 'Displays the list of case studies.', 'pratcom-etudes-de-cas' ),
		'content'     => $archive,
	] );
	register_block_template( 'pratcom-etudes-de-cas//taxonomy-' . PEDC_TAX_SECTOR, [
		'title'       => __( 'Case studies by sector', 'pratcom-etudes-de-cas' ),
		'description' => __( 'Displays the case studies of a sector.', 'pratcom-etudes-de-cas' ),
		'content'     => $archive,
	] );
	register_block_template( 'pratcom-etudes-de-cas//taxonomy-' . PEDC_TAX_SERVICE, [
		'title'       => __( 'Case studies by service', 'pratcom-etudes-de-cas' ),
		'description' => __( 'Displays the case studies of a service.', 'pratcom-etudes-de-cas' ),
		'content'     => $archive,
	] );
}, 20 );

function block_template_single(): string {
	$tpl = <<<'HTML'
<!-- wp:template-part {"slug":"header","area":"header"} /-->

<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:post-terms {"term":"%1$s"} /-->

<!-- wp:post-title {"level":1} /-->

<!-- wp:post-excerpt /-->

<!-- wp:post-featured-image {"align":"wide"} /-->

<!-- wp:pratcom/fiche-projet {"showTestimonial":false,"align":"wide"} /-->

<!-- wp:post-content {"layout":{"type":"constrained"}} /-->

<!-- wp:pratcom/fiche-projet {"showFacts":false,"showResults":false} /-->

<!-- wp:pratcom/etudes-connexes {"align":"wide"} /-->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer"} /-->
HTML;
	return sprintf( $tpl, PEDC_TAX_SECTOR );
}

function block_template_archive(): string {
	$tpl = <<<'HTML'
<!-- wp:template-part {"slug":"header","area":"header"} /-->

<!-- wp:group {"tagName":"main","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--70)">
<!-- wp:query-title {"type":"archive","showPrefix":false,"align":"wide"} /-->

<!-- wp:term-description {"align":"wide"} /-->

<!-- wp:pratcom/etudes-filtre {"align":"wide"} /-->

<!-- wp:query {"queryId":1,"query":{"inherit":true},"align":"wide"} -->
<div class="wp-block-query alignwide">
<!-- wp:post-template {"layout":{"type":"grid","columnCount":3,"minimumColumnWidth":"16rem"}} -->
<!-- wp:pratcom/carte-etude /-->
<!-- /wp:post-template -->

<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->
<!-- wp:query-pagination-previous /-->

<!-- wp:query-pagination-numbers /-->

<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->

<!-- wp:query-no-results -->
<!-- wp:paragraph -->
<p>%1$s</p>
<!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer"} /-->
HTML;
	return sprintf( $tpl, esc_html__( 'No case studies found.', 'pratcom-etudes-de-cas' ) );
}

/** Card block used inside the Query Loop of the block archive template. */
add_action( 'init', static function () {
	register_block_type( 'pratcom/carte-etude', [
		'api_version'     => 3,
		'title'           => __( 'Case study card', 'pratcom-etudes-de-cas' ),
		'description'     => __( 'Card of the current case study (use inside a Query Loop).', 'pratcom-etudes-de-cas' ),
		'category'        => 'theme',
		'icon'            => 'id',
		'parent'          => [ 'core/post-template' ],
		'uses_context'    => [ 'postId', 'postType' ],
		'editor_script'   => 'pedc-blocks',
		'style'           => 'pedc-front',
		'supports'        => [ 'html' => false ],
		'render_callback' => static function ( $attrs, $content, $block ) {
			$id = $block->context['postId'] ?? get_the_ID();
			return $id ? render_card( $id, 'h2' ) : '';
		},
	] );
} );
