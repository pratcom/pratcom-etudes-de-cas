<?php
/**
 * AI tools (correction and translation): shared helpers.
 *
 * Everything goes through the WordPress 7 AI Client (wp_ai_client_prompt()),
 * which holds the provider keys (Settings > Connectors). The plugin stores no
 * key. Without the AI Client the tools are simply not loaded.
 */

namespace Pratcom\EtudesDeCas\AI;

defined( 'ABSPATH' ) || exit;

const REST_NS = 'pedc/v1';

/** Whether the AI tools can run on this site. */
function available(): bool {
	return function_exists( 'wp_ai_client_prompt' ) && (bool) apply_filters( 'pedc_ai_enabled', true );
}

/** French admin screen? */
function is_fr(): bool {
	return 0 === strpos( strtolower( (string) get_user_locale() ), 'fr' );
}

/** Text models, best first. No temperature is ever sent (Claude 5 refuses it). */
function models( string $task ): array {
	return (array) apply_filters( 'pedc_ai_model_preference', [ 'claude-opus-5-5', 'claude-sonnet-5', 'claude-sonnet-4-6' ], $task );
}

/** Let the next steps of a long request run. */
function long_request(): void {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

/**
 * Ask the model. With a schema, the answer is decoded JSON.
 *
 * @return array|\WP_Error { text|data, model }
 */
function ask( string $task, string $system, string $user, ?array $schema = null, int $max_tokens = 16000 ) {
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new \WP_Error( 'pedc_ai_no_client', is_fr() ? 'Le client IA de WordPress n\'est pas disponible sur ce site.' : 'The WordPress AI Client is not available on this site.' );
	}
	$timeout = (int) apply_filters( 'pedc_ai_request_timeout', 240 );
	$raise   = static function ( $http_args ) use ( $timeout ) {
		if ( is_array( $http_args ) && $timeout > (int) ( $http_args['timeout'] ?? 0 ) ) {
			$http_args['timeout'] = $timeout;
		}
		return $http_args;
	};
	add_filter( 'http_request_args', $raise, 9999 );
	try {
		$builder = wp_ai_client_prompt( $user )->using_system_instruction( $system );
		if ( null !== $schema ) {
			$builder = $builder->as_json_response( $schema );
		}
		$builder = $builder->using_max_tokens( $max_tokens );
		$list    = array_values( models( $task ) );
		if ( ! empty( $list ) ) {
			$builder = $builder->using_model_preference( ...$list );
		}
		$result = $builder->generate_text_result();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$text  = (string) $result->toText();
		$model = '';
		try {
			$model = (string) $result->getModelMetadata()->getId();
		} catch ( \Throwable $e ) {
			$model = '';
		}
	} catch ( \Throwable $e ) {
		return new \WP_Error( 'pedc_ai_exception', $e->getMessage() );
	} finally {
		remove_filter( 'http_request_args', $raise, 9999 );
	}

	$text = trim( $text );
	$text = (string) preg_replace( '/^```[a-zA-Z0-9]*\s*|\s*```$/', '', $text );
	if ( null === $schema ) {
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'pedc_ai_empty', is_fr() ? 'La réponse de l\'IA est vide. Réessaie.' : 'The AI response was empty. Try again.' );
		}
		return [ 'text' => $text, 'model' => $model ];
	}
	$data = json_decode( $text, true );
	if ( ! is_array( $data ) ) {
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		$data  = ( false !== $start && false !== $end ) ? json_decode( substr( $text, $start, $end - $start + 1 ), true ) : null;
	}
	if ( ! is_array( $data ) ) {
		return new \WP_Error( 'pedc_ai_bad_response', is_fr() ? 'La réponse de l\'IA n\'a pas pu être lue. Réessaie.' : 'The AI response could not be read. Try again.' );
	}
	return [ 'data' => $data, 'model' => $model ];
}

/* Text rules */

/** No long dash in plain text: commas instead, a hyphen inside words. */
function no_dashes( string $text ): string {
	$out = preg_replace( '/(?<=\w)[\x{2012}\x{2014}\x{2015}](?=\w)/u', '-', $text );
	$out = preg_replace( '/[ \t]*[\x{2012}\x{2014}\x{2015}][ \t]*/u', ', ', (string) $out );
	$out = preg_replace( '/ \x{2013} /u', ', ', (string) $out );
	$out = preg_replace( '/,\s*,/u', ', ', (string) $out );
	return is_string( $out ) ? $out : $text;
}

/**
 * French: a normal space before : ; ? ! or inside « » becomes a
 * non-breaking space, so the sign never starts a line on its own. A sign
 * written without a space is left as is.
 */
function nbsp_text( string $text ): string {
	if ( '' === $text ) {
		return $text;
	}
	$nb   = "\u{00A0}";
	$text = (string) preg_replace( '/[ \t]+([:;?!])/u', $nb . '$1', $text );
	$text = (string) preg_replace( '/«[ \t]+/u', '«' . $nb, $text );
	$text = (string) preg_replace( '/[ \t]+»/u', $nb . '»', $text );
	return (string) preg_replace( '/&nbsp;([:;?!»])/u', $nb . '$1', $text );
}

/** Same as nbsp_text() on markup: text only, never tags, block comments or code. */
function nbsp_markup( string $html ): string {
	$parts = preg_split( '/(<!--.*?-->|<[^>]*>)/su', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) {
		return $html;
	}
	$skip = 0;
	foreach ( $parts as $i => $part ) {
		if ( '' === $part ) {
			continue;
		}
		if ( '<' === $part[0] ) {
			if ( preg_match( '#^<(code|pre|script|style)\b#i', $part ) ) {
				++$skip;
			} elseif ( preg_match( '#^</(code|pre|script|style)\s*>#i', $part ) ) {
				$skip = max( 0, $skip - 1 );
			}
			continue;
		}
		if ( 0 === $skip ) {
			$parts[ $i ] = nbsp_text( $part );
		}
	}
	return implode( '', $parts );
}

/** Final text rules for a language: no long dash, French spacing. */
function clean_text( string $text, string $lang ): string {
	$text = no_dashes( $text );
	return is_french( $lang ) ? nbsp_text( $text ) : $text;
}

function is_french( string $lang ): bool {
	return 0 === strpos( strtolower( $lang ), 'fr' );
}

/* Languages (WPML) */

function wpml_active(): bool {
	return defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'icl_object_id' );
}

/** Active languages: [ { code, name } ]. */
function languages(): array {
	$out = [];
	if ( wpml_active() ) {
		$active = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		if ( is_array( $active ) ) {
			foreach ( $active as $code => $l ) {
				$out[] = [
					'code' => (string) $code,
					'name' => (string) ( $l['native_name'] ?? ( $l['translated_name'] ?? $code ) ),
				];
			}
		}
	}
	if ( empty( $out ) ) {
		$code  = substr( (string) get_locale(), 0, 2 );
		$out[] = [ 'code' => $code, 'name' => $code ];
	}
	return $out;
}

function default_language(): string {
	if ( wpml_active() ) {
		$lang = apply_filters( 'wpml_default_language', null );
		if ( is_string( $lang ) && '' !== $lang ) {
			return $lang;
		}
	}
	return substr( (string) get_locale(), 0, 2 );
}

function post_language( int $post_id ): string {
	if ( wpml_active() ) {
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
			return (string) $details['language_code'];
		}
	}
	return default_language();
}

/** Other-language versions of a study: [ code => post ID ]. */
function translations_of( int $post_id ): array {
	$out = [];
	if ( ! wpml_active() ) {
		return $out;
	}
	$type = 'post_' . PEDC_POST_TYPE;
	$trid = apply_filters( 'wpml_element_trid', null, $post_id, $type );
	if ( ! $trid ) {
		return $out;
	}
	$list = apply_filters( 'wpml_get_element_translations', [], $trid, $type );
	foreach ( is_array( $list ) ? $list : [] as $code => $tr ) {
		$id = isset( $tr->element_id ) ? (int) $tr->element_id : 0;
		if ( $id && $id !== $post_id && get_post( $id ) ) {
			$out[ (string) ( $tr->language_code ?? $code ) ] = $id;
		}
	}
	return $out;
}

/** Original of a study in WPML (itself when it is the original). */
function original_of( int $post_id ): int {
	if ( ! wpml_active() ) {
		return $post_id;
	}
	$type = 'post_' . PEDC_POST_TYPE;
	$trid = apply_filters( 'wpml_element_trid', null, $post_id, $type );
	if ( ! $trid ) {
		return $post_id;
	}
	$list = apply_filters( 'wpml_get_element_translations', [], $trid, $type );
	foreach ( is_array( $list ) ? $list : [] as $tr ) {
		if ( ! empty( $tr->original ) && ! empty( $tr->element_id ) ) {
			return (int) $tr->element_id;
		}
	}
	return $post_id;
}

/** Run a callback with WPML switched to a language. */
function with_language( string $lang, callable $callback ) {
	if ( ! wpml_active() || '' === $lang ) {
		return $callback();
	}
	$current = apply_filters( 'wpml_current_language', null );
	do_action( 'wpml_switch_language', $lang );
	try {
		return $callback();
	} finally {
		do_action( 'wpml_switch_language', $current );
	}
}

/** English name of a language, for the prompts. */
function language_name( string $lang ): string {
	$map  = [ 'fr' => 'French', 'en' => 'English', 'es' => 'Spanish', 'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch' ];
	$code = strtolower( substr( $lang, 0, 2 ) );
	return $map[ $code ] ?? $lang;
}

/** A case study the current user may edit. */
function editable_study( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post || PEDC_POST_TYPE !== $post->post_type ) {
		return new \WP_Error( 'pedc_ai_not_found', is_fr() ? 'Étude de cas introuvable.' : 'Case study not found.', [ 'status' => 404 ] );
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return new \WP_Error( 'pedc_ai_forbidden', is_fr() ? 'Tu n\'as pas le droit de modifier cette étude.' : 'You are not allowed to edit this case study.', [ 'status' => 403 ] );
	}
	return $post;
}

/** Yoast text fields handled by the tools. */
function yoast_keys(): array {
	return [
		'_yoast_wpseo_title'                 => 'seo_title',
		'_yoast_wpseo_metadesc'              => 'meta_description',
		'_yoast_wpseo_focuskw'               => 'focus_keyphrase',
		'_yoast_wpseo_opengraph-title'       => 'og_title',
		'_yoast_wpseo_opengraph-description' => 'og_description',
		'_yoast_wpseo_twitter-title'         => 'twitter_title',
		'_yoast_wpseo_twitter-description'   => 'twitter_description',
	];
}

require_once __DIR__ . '/ai-revise.php';
require_once __DIR__ . '/ai-translate.php';
require_once __DIR__ . '/ai-admin.php';
