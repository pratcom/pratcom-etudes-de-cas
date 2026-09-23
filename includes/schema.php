<?php
/**
 * Structured data: the case study is an Article about the client.
 * - Yoast SEO: enrich its Article piece (and turn it on for this post type).
 * - Rank Math: enrich its Article entity.
 * - Neither: output a standalone Article JSON-LD.
 */

namespace Pratcom\EtudesDeCas;

defined( 'ABSPATH' ) || exit;

function schema_extra( int $post_id ): array {
	$d     = sheet( $post_id );
	$extra = [];
	if ( $d['client'] ) {
		$org = [ '@type' => 'Organization', 'name' => $d['client'] ];
		if ( $d['client_url'] ) {
			$org['url'] = $d['client_url'];
		}
		if ( $d['client_logo'] ) {
			$logo = wp_get_attachment_image_url( $d['client_logo'], 'medium' );
			if ( $logo ) {
				$org['logo'] = $logo;
			}
		}
		$extra['about'] = $org;
	}
	if ( $d['sectors'] ) {
		$extra['articleSection'] = wp_list_pluck( $d['sectors'], 'name' );
	}
	if ( $d['services'] ) {
		$extra['keywords'] = wp_list_pluck( $d['services'], 'name' );
	}
	return apply_filters( 'pedc_schema_extra', $extra, $post_id );
}

/* Yoast SEO */
add_filter( 'wpseo_schema_article_type', static function ( $type, $indexable = null ) {
	if ( ! setting( 'schema' ) || ! $indexable || PEDC_POST_TYPE !== ( $indexable->object_sub_type ?? '' ) ) {
		return $type;
	}
	return ( 'None' === $type || '' === $type || null === $type ) ? 'Article' : $type;
}, 10, 2 );

add_filter( 'wpseo_schema_article', static function ( $data ) {
	if ( ! setting( 'schema' ) || ! is_singular( PEDC_POST_TYPE ) ) {
		return $data;
	}
	$extra = schema_extra( get_queried_object_id() );
	if ( isset( $extra['keywords'], $data['keywords'] ) && is_array( $data['keywords'] ) ) {
		$extra['keywords'] = array_values( array_unique( array_merge( $data['keywords'], $extra['keywords'] ) ) );
	}
	return array_merge( $data, $extra );
} );

/* Rank Math */
add_filter( 'rank_math/snippet/rich_snippet_article_entity', static function ( $entity ) {
	if ( ! setting( 'schema' ) || ! is_singular( PEDC_POST_TYPE ) ) {
		return $entity;
	}
	return array_merge( $entity, schema_extra( get_queried_object_id() ) );
} );

/* Standalone */
add_action( 'wp_head', static function () {
	if ( ! setting( 'schema' ) || ! is_singular( PEDC_POST_TYPE ) ) {
		return;
	}
	if ( defined( 'WPSEO_VERSION' ) || class_exists( '\\RankMath' ) ) {
		return;
	}
	$post = get_queried_object();
	$node = [
		'@context'         => 'https://schema.org',
		'@type'            => 'Article',
		'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
		'url'              => get_permalink( $post ),
		'mainEntityOfPage' => get_permalink( $post ),
		'datePublished'    => get_post_time( 'c', true, $post ),
		'dateModified'     => get_post_modified_time( 'c', true, $post ),
		'inLanguage'       => get_bloginfo( 'language' ),
		'publisher'        => [ '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ],
	];
	$author = get_the_author_meta( 'display_name', $post->post_author );
	if ( $author ) {
		$node['author'] = [ '@type' => 'Person', 'name' => $author ];
	}
	if ( has_excerpt( $post ) ) {
		$node['description'] = wp_strip_all_tags( get_the_excerpt( $post ) );
	}
	$img = get_the_post_thumbnail_url( $post, 'large' );
	if ( $img ) {
		$node['image'] = $img;
	}
	$node = array_merge( $node, schema_extra( $post->ID ) );
	echo '<script type="application/ld+json">' . wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}, 20 );
