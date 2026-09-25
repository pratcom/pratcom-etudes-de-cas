<?php
/**
 * AI tools: the "Case studies" page (list, correction, translation), its
 * REST routes and its place in the menu.
 *
 * The page replaces "All case studies" and "Add new" in the Case studies
 * menu (both screens still work by their address, and the page links to
 * them). Every user who can edit posts sees it. Without the WordPress 7 AI
 * Client nothing here is loaded and the menu stays as usual.
 */

namespace Pratcom\EtudesDeCas\AI;

defined( 'ABSPATH' ) || exit;

const PAGE_SLUG = 'pedc-etudes';
const PER_PAGE  = 20;

function parent_slug(): string {
	return 'edit.php?post_type=' . PEDC_POST_TYPE;
}

function page_url( array $args = [] ): string {
	return add_query_arg( array_merge( [ 'post_type' => PEDC_POST_TYPE, 'page' => PAGE_SLUG ], $args ), admin_url( 'edit.php' ) );
}

/* REST */

add_action( 'rest_api_init', static function () {
	if ( ! available() ) {
		return;
	}
	$can = static function () {
		return current_user_can( 'edit_posts' );
	};
	register_rest_route( REST_NS, '/etudes', [ 'methods' => 'GET', 'permission_callback' => $can, 'callback' => __NAMESPACE__ . '\\handle_list' ] );
	register_rest_route( REST_NS, '/revise', [ 'methods' => 'POST', 'permission_callback' => $can, 'callback' => __NAMESPACE__ . '\\handle_revise' ] );
	register_rest_route( REST_NS, '/revise/apply', [ 'methods' => 'POST', 'permission_callback' => $can, 'callback' => __NAMESPACE__ . '\\handle_revise_apply' ] );
	register_rest_route( REST_NS, '/translate', [ 'methods' => 'POST', 'permission_callback' => $can, 'callback' => __NAMESPACE__ . '\\handle_translate' ] );
} );

/** WPML translations table, when it exists. */
function translations_table(): string {
	global $wpdb;
	if ( ! wpml_active() ) {
		return '';
	}
	$table = $wpdb->prefix . 'icl_translations';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return $found === $table ? $table : '';
}

/** One study for the list. */
function list_item( \WP_Post $p ): array {
	$translations = [];
	foreach ( translations_of( $p->ID ) as $code => $id ) {
		$translations[] = [ 'lang' => $code, 'id' => $id, 'status' => get_post_status( $id ), 'edit' => get_edit_post_link( $id, 'raw' ) ];
	}
	$thumb    = get_the_post_thumbnail_url( $p->ID, 'thumbnail' );
	$original = original_of( $p->ID );
	return [
		'id'           => (int) $p->ID,
		'title'        => '' !== $p->post_title ? $p->post_title : ( is_fr() ? '(sans titre)' : '(no title)' ),
		'status'       => $p->post_status,
		'modified'     => $p->post_modified,
		'client'       => (string) get_post_meta( $p->ID, '_pedc_client', true ),
		'thumb'        => $thumb ? $thumb : '',
		'lang'         => post_language( $p->ID ),
		'translations' => $translations,
		'original'     => $original === (int) $p->ID ? 0 : $original,
		'edit'         => get_edit_post_link( $p->ID, 'raw' ),
		'view'         => get_permalink( $p->ID ),
	];
}

/**
 * GET /etudes: original studies (all languages), newest change first.
 *
 * Plain SQL on purpose: WPML language filters do not follow a language
 * switch inside a REST request.
 */
function handle_list( \WP_REST_Request $request ) {
	global $wpdb;
	$one = (int) $request->get_param( 'id' );
	if ( $one ) {
		$post = editable_study( $one );
		return is_wp_error( $post ) ? $post : rest_ensure_response( [ 'items' => [ list_item( $post ) ], 'page' => 1, 'pages' => 1, 'total' => 1 ] );
	}
	$page   = max( 1, (int) $request->get_param( 'page' ) );
	$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

	$statuses = [ 'draft', 'pending', 'future', 'publish', 'private' ];
	$in       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	$join     = '';
	// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
	$where = $wpdb->prepare( "p.post_type = %s AND p.post_status IN ( {$in} )", array_merge( [ PEDC_POST_TYPE ], $statuses ) );
	$table = translations_table();
	if ( '' !== $table ) {
		$join  .= $wpdb->prepare( " LEFT JOIN {$table} AS t ON t.element_id = p.ID AND t.element_type = %s", 'post_' . PEDC_POST_TYPE );
		$where .= " AND ( t.source_language_code IS NULL OR t.source_language_code = '' )";
	}
	if ( '' !== $search ) {
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= $wpdb->prepare( ' AND ( p.post_title LIKE %s OR p.post_excerpt LIKE %s OR p.post_content LIKE %s )', $like, $like, $like );
	}
	$from  = "FROM {$wpdb->posts} AS p {$join} WHERE {$where}";
	$total = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT p.ID ) {$from}" );
	$ids   = $wpdb->get_col( "SELECT DISTINCT p.ID, p.post_modified {$from} ORDER BY p.post_modified DESC, p.ID DESC LIMIT " . (int) PER_PAGE . ' OFFSET ' . (int) ( ( $page - 1 ) * PER_PAGE ) );
	// phpcs:enable

	$items = [];
	foreach ( $ids as $id ) {
		$p = get_post( (int) $id );
		if ( $p && current_user_can( 'edit_post', $p->ID ) ) {
			$items[] = list_item( $p );
		}
	}
	return rest_ensure_response( [
		'items' => $items,
		'page'  => $page,
		'pages' => (int) ceil( $total / PER_PAGE ),
		'total' => $total,
	] );
}

/* Menu and page */

add_action( 'admin_menu', static function () {
	if ( ! available() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$fr   = is_fr();
	$hook = add_submenu_page(
		parent_slug(),
		$fr ? 'Études de cas' : 'Case studies',
		$fr ? 'Toutes les études' : 'All case studies',
		'edit_posts',
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page',
		0
	);
	if ( $hook && (bool) apply_filters( 'pedc_ai_hide_core_menus', true ) ) {
		remove_submenu_page( parent_slug(), parent_slug() );
		remove_submenu_page( parent_slug(), 'post-new.php?post_type=' . PEDC_POST_TYPE );
	}
	add_action( 'admin_enqueue_scripts', static function ( $current ) use ( $hook ) {
		if ( $current === $hook ) {
			enqueue();
		}
	} );
}, 20 );

/**
 * With "All case studies" hidden, WordPress links the menu to
 * admin.php?page=...: send it to edit.php?post_type=...&page=... so the
 * menu stays open and highlighted.
 */
add_action( 'admin_init', static function () {
	global $pagenow;
	if ( 'admin.php' !== $pagenow || PAGE_SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$args = array_map( 'sanitize_text_field', wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	unset( $args['page'] );
	wp_safe_redirect( page_url( $args ) );
	exit;
} );

/** "Correct / translate" link in the WordPress list of case studies. */
add_filter( 'post_row_actions', static function ( $actions, $post ) {
	if ( PEDC_POST_TYPE !== $post->post_type || ! available() || ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}
	$actions['pedc_ai'] = '<a href="' . esc_url( page_url( [ 'post' => $post->ID ] ) ) . '">' . esc_html( is_fr() ? 'Corriger / traduire' : 'Correct / translate' ) . '</a>';
	return $actions;
}, 10, 2 );

function render_page(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html( is_fr() ? 'Tu n\'as pas accès à cette page.' : 'You do not have access to this page.' ) );
	}
	$fr = is_fr();
	echo '<div class="wrap pedc-ai-wrap">';
	echo '<h1 class="wp-heading-inline">' . esc_html( $fr ? 'Études de cas' : 'Case studies' ) . '</h1> ';
	echo '<a class="page-title-action" href="' . esc_url( admin_url( 'post-new.php?post_type=' . PEDC_POST_TYPE ) ) . '">' . esc_html( $fr ? 'Nouvelle étude' : 'New case study' ) . '</a>';
	echo '<hr class="wp-header-end">';
	echo '<div id="pedc-ai-app"><p>' . esc_html( $fr ? 'Chargement…' : 'Loading…' ) . '</p></div>';
	echo '<p class="pedc-ai-wp-link"><a class="button" href="' . esc_url( admin_url( parent_slug() ) ) . '">' . esc_html( $fr ? 'Accéder via l\'interface WordPress' : 'Open in the WordPress interface' ) . ' &rarr;</a></p>';
	echo '</div>';
}

function enqueue(): void {
	wp_enqueue_style( 'wp-components' );
	wp_enqueue_style( 'pedc-ai', PEDC_URL . 'assets/css/ai.css', [ 'wp-components' ], PEDC_VERSION );
	wp_enqueue_script( 'pedc-ai', PEDC_URL . 'assets/js/ai.js', [ 'wp-element', 'wp-components', 'wp-api-fetch' ], PEDC_VERSION, true );
	$langs = languages();
	wp_localize_script( 'pedc-ai', 'pedcAI', [
		'ns'        => REST_NS,
		'postId'    => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'pageUrl'   => page_url(),
		'wpml'      => wpml_active() && count( $langs ) > 1,
		'languages' => $langs,
		'fr'        => is_fr(),
		'i18n'      => strings(),
	] );
}

function strings(): array {
	if ( is_fr() ) {
		return [
			'search'          => 'Rechercher',
			'searchPh'        => 'Titre ou mot du texte',
			'count'           => 'étude(s)',
			'none'            => 'Aucune étude de cas pour l\'instant. Commence par « Nouvelle étude ».',
			'noResults'       => 'Aucune étude ne correspond.',
			'prev'            => 'Précédent',
			'next'            => 'Suivant',
			'page'            => 'Page',
			'edit'            => 'Modifier',
			'view'            => 'Voir',
			'correct'         => 'Corriger',
			'translate'       => 'Traduire',
			'back'            => '← Toutes les études',
			'modified'        => 'Modifiée',
			'status_draft'    => 'Brouillon',
			'status_pending'  => 'En attente',
			'status_future'   => 'Planifiée',
			'status_publish'  => 'Publiée',
			'status_private'  => 'Privée',
			'correctTitle'    => 'Correction',
			'correctIntro'    => 'Claude corrige les fautes et la mise en forme du texte, des résultats clés et du témoignage, sans réécrire. Les sections (Le contexte, Le défi, La solution, Les résultats) et leurs ancres restent; liens, images et tableaux sont gardés à leur place. Le titre, l\'adresse, le client, le site, le logo et la période ne changent pas. Rien n\'est enregistré avant « Appliquer ».',
			'published'       => 'Cette étude est publiée : la correction sera en ligne dès que tu l\'appliques.',
			'correctStart'    => 'Corriger l\'étude',
			'correcting'      => 'Claude relit l\'étude. Ça peut prendre une à deux minutes.',
			'ready'           => 'Correction prête. Compare, ajuste l\'extrait puis applique.',
			'apply'           => 'Appliquer la correction',
			'again'           => 'Relancer la correction',
			'history'         => 'WordPress garde l\'ancienne version dans les révisions de l\'étude.',
			'applied'         => 'Correction appliquée.',
			'retranslating'   => 'Mise à jour de la traduction',
			'retranslated'    => 'Traduction mise à jour :',
			'before'          => 'Avant',
			'after'           => 'Après',
			'text'            => 'Texte',
			'excerpt'         => 'Extrait',
			'excerptHelp'     => 'Affiché sur les cartes des études.',
			'results'         => 'Résultats clés',
			'testimonial'     => 'Témoignage',
			'sections'        => 'Sections H2',
			'words'           => 'Mots',
			'linksKept'       => 'Liens gardés',
			'toCheck'         => 'à vérifier :',
			'mediaKept'       => 'Images, vidéos et tableaux gardés',
			'mediaMoved'      => 'replacé(s) à la fin, à vérifier',
			'model'           => 'Modèle utilisé :',
			'translateTitle'  => 'Traduction',
			'translateIntro'  => 'Claude traduit le titre, le texte, l\'extrait, l\'adresse, les champs Yoast et la fiche projet (période, résultats, témoignage). Le client, le site, le logo et l\'image à la une sont copiés. Les secteurs et services sans équivalent sont créés dans la langue et liés dans WPML. Une nouvelle traduction est enregistrée en brouillon; une traduction existante est mise à jour et garde son statut.',
			'noWpml'          => 'WPML n\'est pas actif, ou une seule langue est active : il n\'y a rien à traduire.',
			'noTranslation'   => 'Pas encore traduite',
			'translateTo'     => 'Traduire',
			'retranslate'     => 'Retraduire',
			'retranslateConf' => 'La traduction existante sera remplacée par une nouvelle. Continuer ?',
			'translateAll'    => 'Traduire dans toutes les langues',
			'translating'     => 'Traduction en cours. Ça peut prendre une à deux minutes par langue.',
			'translated'      => 'Traduction enregistrée.',
			'updatedTr'       => 'Traduction mise à jour.',
			'createdTerms'    => 'Secteurs et services créés et liés :',
			'metaFailed'      => 'Le titre et la fiche n\'ont pas pu être traduits : vérifie-les dans l\'éditeur.',
			'failed'          => 'Une erreur est survenue. Réessaie.',
			'busy'            => 'Traitement en cours…',
			'original'        => 'Original',
			'isTranslation'   => 'Cette étude est une traduction. Pour la mettre à jour, retraduis-la à partir de l\'original.',
			'openOriginal'    => 'Ouvrir l\'original',
		];
	}
	return [
		'search'          => 'Search',
		'searchPh'        => 'Title or word in the text',
		'count'           => 'case study(ies)',
		'none'            => 'No case study yet. Start with "New case study".',
		'noResults'       => 'No case study matches.',
		'prev'            => 'Previous',
		'next'            => 'Next',
		'page'            => 'Page',
		'edit'            => 'Edit',
		'view'            => 'View',
		'correct'         => 'Correct',
		'translate'       => 'Translate',
		'back'            => '← All case studies',
		'modified'        => 'Modified',
		'status_draft'    => 'Draft',
		'status_pending'  => 'Pending',
		'status_future'   => 'Scheduled',
		'status_publish'  => 'Published',
		'status_private'  => 'Private',
		'correctTitle'    => 'Correction',
		'correctIntro'    => 'Claude fixes typos and formatting in the text, the key results and the testimonial, without rewriting. The sections (Context, Challenge, Solution, Results) and their anchors stay; links, images and tables are kept in place. The title, address, client, website, logo and period do not change. Nothing is saved before "Apply".',
		'published'       => 'This case study is published: the correction goes live as soon as you apply it.',
		'correctStart'    => 'Correct the case study',
		'correcting'      => 'Claude is proofreading the case study. This can take one to two minutes.',
		'ready'           => 'Correction ready. Compare, adjust the excerpt, then apply.',
		'apply'           => 'Apply the correction',
		'again'           => 'Run the correction again',
		'history'         => 'WordPress keeps the previous version in the revisions.',
		'applied'         => 'Correction applied.',
		'retranslating'   => 'Updating the translation',
		'retranslated'    => 'Translation updated:',
		'before'          => 'Before',
		'after'           => 'After',
		'text'            => 'Text',
		'excerpt'         => 'Excerpt',
		'excerptHelp'     => 'Shown on the case study cards.',
		'results'         => 'Key results',
		'testimonial'     => 'Testimonial',
		'sections'        => 'H2 sections',
		'words'           => 'Words',
		'linksKept'       => 'Links kept',
		'toCheck'         => 'to check:',
		'mediaKept'       => 'Images, videos and tables kept',
		'mediaMoved'      => 'moved to the end, to check',
		'model'           => 'Model used:',
		'translateTitle'  => 'Translation',
		'translateIntro'  => 'Claude translates the title, text, excerpt, address, Yoast fields and project sheet (period, results, testimonial). Client, website, logo and featured image are copied. Sectors and services with no equivalent are created in the language and linked in WPML. A new translation is saved as a draft; an existing one is updated and keeps its status.',
		'noWpml'          => 'WPML is not active, or only one language is active: nothing to translate.',
		'noTranslation'   => 'Not translated yet',
		'translateTo'     => 'Translate',
		'retranslate'     => 'Translate again',
		'retranslateConf' => 'The existing translation will be replaced. Continue?',
		'translateAll'    => 'Translate into every language',
		'translating'     => 'Translating. This can take one to two minutes per language.',
		'translated'      => 'Translation saved.',
		'updatedTr'       => 'Translation updated.',
		'createdTerms'    => 'Sectors and services created and linked:',
		'metaFailed'      => 'The title and sheet could not be translated: check them in the editor.',
		'failed'          => 'Something went wrong. Please try again.',
		'busy'            => 'Working…',
		'original'        => 'Original',
		'isTranslation'   => 'This case study is a translation. To update it, translate the original again.',
		'openOriginal'    => 'Open the original',
	];
}
