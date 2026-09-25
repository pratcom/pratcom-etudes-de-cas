<?php
/**
 * AI assistant page for case studies: the list, the assistant (project
 * sheet, text, image, internal links, SEO, translation, publication), its
 * REST routes and its place in the menu.
 *
 * For the people who have access (owner + the "Access" box), the page
 * replaces "All case studies" and "Add new" in the Case studies menu (both
 * screens still work by their address, and the page links to them).
 * Everyone else keeps the usual WordPress screens. Without the WordPress 7
 * AI Client nothing here is loaded.
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
	$can    = __NAMESPACE__ . '\\has_access';
	$routes = [
		[ '/etudes', 'GET', 'handle_list' ],
		[ '/session', 'GET', 'handle_session' ],
		[ '/terms', 'GET', 'handle_terms' ],
		[ '/sheet', 'POST', 'handle_sheet' ],
		[ '/fields', 'POST', 'handle_fields' ],
		[ '/notes', 'POST', 'handle_notes' ],
		[ '/notes/apply', 'POST', 'handle_notes_apply' ],
		[ '/revise', 'POST', 'handle_revise' ],
		[ '/revise/apply', 'POST', 'handle_revise_apply' ],
		[ '/image/suggest', 'POST', 'handle_image_suggest' ],
		[ '/image/generate', 'POST', 'handle_image_generate' ],
		[ '/image/featured', 'POST', 'handle_image_featured' ],
		[ '/image/select', 'POST', 'handle_image_select' ],
		[ '/image/alt', 'POST', 'handle_image_alt' ],
		[ '/links/suggest', 'POST', 'handle_links_suggest' ],
		[ '/links/insert', 'POST', 'handle_links_insert' ],
		[ '/links/skip', 'POST', 'handle_links_skip' ],
		[ '/seo/generate', 'POST', 'handle_seo_generate' ],
		[ '/seo/save', 'POST', 'handle_seo_save' ],
		[ '/translate', 'POST', 'handle_translate' ],
		[ '/checks', 'GET', 'handle_checks' ],
		[ '/publish', 'POST', 'handle_publish' ],
	];
	foreach ( $routes as [ $route, $method, $callback ] ) {
		register_rest_route( REST_NS, $route, [ 'methods' => $method, 'permission_callback' => $can, 'callback' => __NAMESPACE__ . '\\' . $callback ] );
	}
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
	if ( ! available() || ! has_access() ) {
		return;
	}
	$fr   = is_fr();
	$hook = add_submenu_page(
		parent_slug(),
		$fr ? 'Assistant d\'études de cas' : 'Case study assistant',
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

/** "Open in the assistant" link in the WordPress list of case studies. */
add_filter( 'post_row_actions', static function ( $actions, $post ) {
	if ( PEDC_POST_TYPE !== $post->post_type || ! available() || ! has_access() || ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}
	$actions['pedc_ai'] = '<a href="' . esc_url( page_url( [ 'post' => $post->ID ] ) ) . '">' . esc_html( is_fr() ? 'Ouvrir dans l\'assistant' : 'Open in the assistant' ) . '</a>';
	return $actions;
}, 10, 2 );

function render_page(): void {
	if ( ! has_access() ) {
		wp_die( esc_html( is_fr() ? 'Tu n\'as pas accès à cette page.' : 'You do not have access to this page.' ) );
	}
	$fr = is_fr();
	echo '<div class="wrap pedc-ai-wrap">';
	echo '<h1 class="pedc-ai-title">' . esc_html( $fr ? 'Assistant d\'études de cas' : 'Case study assistant' ) . ' <span class="pedc-ai-version">v' . esc_html( PEDC_VERSION ) . '</span></h1>';
	echo '<div id="pedc-ai-app" class="pedc-ai-app"><p>' . esc_html( $fr ? 'Chargement…' : 'Loading…' ) . '</p></div>';
	echo '<p class="pedc-ai-wp-link"><a class="button" href="' . esc_url( admin_url( parent_slug() ) ) . '">' . esc_html( $fr ? 'Accéder via l\'interface WordPress' : 'Open in the WordPress interface' ) . ' &rarr;</a></p>';
	render_access_box();
	echo '</div>';
}

function image_styles(): array {
	$fr = is_fr();
	return [
		[ 'value' => 'realistic', 'label' => $fr ? 'Réaliste / photo' : 'Realistic / photo' ],
		[ 'value' => 'journalistic', 'label' => $fr ? 'Journalistique / éditorial' : 'Journalistic / editorial' ],
		[ 'value' => 'cinematic', 'label' => $fr ? 'Cinématographique' : 'Cinematic' ],
		[ 'value' => 'conceptual', 'label' => $fr ? 'Conceptuel / abstrait' : 'Conceptual / abstract' ],
		[ 'value' => 'illustration', 'label' => $fr ? 'Illustration' : 'Illustration' ],
	];
}

function enqueue(): void {
	wp_enqueue_media();
	wp_enqueue_style( 'wp-components' );
	wp_enqueue_style( 'pedc-ai', PEDC_URL . 'assets/css/ai.css', [ 'wp-components' ], PEDC_VERSION );
	wp_enqueue_script( 'pedc-ai', PEDC_URL . 'assets/js/ai.js', [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-url' ], PEDC_VERSION, true );
	$langs = languages();
	wp_localize_script( 'pedc-ai', 'pedcAI', [
		'ns'          => REST_NS,
		'postId'      => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'pageUrl'     => page_url(),
		'newUrl'      => admin_url( 'post-new.php?post_type=' . PEDC_POST_TYPE ),
		'wpml'        => wpml_active() && count( $langs ) > 1,
		'languages'   => $langs,
		'defaultLang' => default_language(),
		'yoast'       => yoast_active(),
		'imageReady'  => image_ready(),
		'canPublish'  => current_user_can( 'publish_posts' ),
		'canUpload'   => current_user_can( 'upload_files' ),
		'imageStyles' => image_styles(),
		'fr'          => is_fr(),
		'i18n'        => strings(),
	] );
}

function strings(): array {
	if ( is_fr() ) {
		return [
			'tab_sheet'           => 'Fiche projet',
			'tab_text'            => 'Texte',
			'tab_image'           => 'Image',
			'tab_links'           => 'Maillage',
			'tab_seo'             => 'SEO',
			'tab_translate'       => 'Traduction',
			'tab_publish'         => 'Publier',
			'allStudies'          => 'Toutes les études',
			'newStudy'            => 'Nouvelle étude',
			'search'              => 'Rechercher',
			'searchPh'            => 'Titre ou mot du texte',
			'count'               => 'étude(s)',
			'none'                => 'Aucune étude de cas pour l\'instant. Commence par « Nouvelle étude ».',
			'noResults'           => 'Aucune étude ne correspond.',
			'prev'                => 'Précédent',
			'next'                => 'Suivant',
			'page'                => 'Page',
			'edit'                => 'Modifier',
			'view'                => 'Voir',
			'open'                => 'Ouvrir',
			'back'                => 'Toutes les études',
			'modified'            => 'Modifiée',
			'original'            => 'Original',
			'status_draft'        => 'Brouillon',
			'status_pending'      => 'En attente',
			'status_future'       => 'Planifiée',
			'status_publish'      => 'Publiée',
			'status_private'      => 'Privée',
			'close'               => 'Fermer',
			'busy'                => 'Traitement en cours…',
			'failed'              => 'Une erreur est survenue. Réessaie.',
			'chars'               => 'caractères',
			'continue'            => 'Continuer',
			'skip'                => 'Passer cette étape',
			'saveContinue'        => 'Enregistrer et continuer',
			'apply'               => 'Appliquer',
			'cancel'              => 'Annuler',
			'model'               => 'Modèle utilisé :',
			'openEditor'          => 'Ouvrir dans l\'éditeur',
			'isTranslation'       => 'Cette étude est une traduction. Ouvre l\'original pour la modifier : la traduction se refait à partir de lui.',
			'openOriginal'        => 'Ouvrir l\'original',
			'sheetIntro'          => 'Une étude de cas raconte un vrai projet. Commence par la fiche : l\'étude est créée en brouillon avec ses 4 sections (Le contexte, Le défi, La solution, Les résultats). L\'IA n\'invente rien : elle range et corrige ce que tu lui donnes.',
			'language'            => 'Langue de l\'étude',
			'title'               => 'Titre',
			'titleHelp'           => 'Ex. : Comment Transport X a réduit ses bris sur route de 40 %',
			'noTitle'             => 'Donne un titre à l\'étude.',
			'client'              => 'Client',
			'clientUrl'           => 'Site web du client',
			'period'              => 'Période',
			'periodHelp'          => 'Ex. : 2024-2025, ou printemps 2025',
			'logo'                => 'Logo du client',
			'chooseLogo'          => 'Choisir le logo',
			'choose'              => 'Choisir',
			'replace'             => 'Remplacer',
			'remove'              => 'Retirer',
			'add'                 => 'Ajouter',
			'results'             => 'Résultats clés',
			'resultsHelp'         => 'Chiffre + ce qu\'il mesure (8 au plus). Seulement des résultats réels.',
			'resultValue'         => 'Chiffre',
			'resultLabel'         => 'Ce qu\'il mesure',
			'addResult'           => 'Ajouter un résultat',
			'testimonial'         => 'Témoignage',
			'testimonialHelp'     => 'Les mots du client, tels quels.',
			'testimonialAuthor'   => 'Auteur du témoignage (nom, poste)',
			'sectors'             => 'Secteurs',
			'noSectors'           => 'Aucun secteur dans cette langue. Ajoute-en un ci-dessous.',
			'newTerm'             => 'nouveau',
			'addSectorPh'         => 'Nouveau secteur',
			'services'            => 'Services',
			'servicesHelp'        => 'Choisis parmi les services existants ou tape un nouveau nom puis Entrée.',
			'createContinue'      => 'Créer l\'étude et continuer',
			'creating'            => 'Création de l\'étude.',
			'organizeNotes'       => 'Organiser mes notes',
			'correct'             => 'Corriger le texte',
			'notesIntro'          => 'Colle ton texte de base : notes du projet, courriel du client, compte rendu, points en vrac. Claude le range dans les sections et corrige la langue, sans rien ajouter. Une section sans information est marquée « À compléter ». Rien n\'est enregistré avant « Appliquer ».',
			'notesLabel'          => 'Notes',
			'notesPh'             => 'Ex. : Client : Transport X, 120 camions. Problème : crevaisons fréquentes, 3 remorquages par mois…',
			'notesShort'          => 'Colle d\'abord tes notes (quelques phrases au moins).',
			'organize'            => 'Organiser en sections',
			'organizing'          => 'Claude range tes notes dans les sections. Ça peut prendre une minute.',
			'notesReady'          => 'Texte organisé. Compare puis applique.',
			'notesReadyTodo'      => 'Texte organisé. Sections sans information (marquées « À compléter ») :',
			'notesApplied'        => 'Texte enregistré.',
			'sheetFilled'         => 'La fiche projet a aussi été complétée (résultats ou témoignage trouvés dans tes notes).',
			'replacesText'        => 'Le texte actuel de l\'étude sera remplacé. WordPress garde l\'ancienne version dans les révisions.',
			'emptySections'       => 'Rien dans tes notes pour :',
			'willFillResults'     => 'Trouvés dans tes notes : ils rempliront la fiche projet, qui n\'en a pas encore.',
			'keepsResults'        => 'Trouvés dans tes notes, mais la fiche projet a déjà ses résultats : elle ne change pas.',
			'willFillTestimonial' => 'Citation trouvée dans tes notes : elle remplira la fiche projet.',
			'keepsTestimonial'    => 'Citation trouvée dans tes notes, mais la fiche a déjà un témoignage : il ne change pas.',
			'correcting'          => 'Claude relit l\'étude. Ça peut prendre une à deux minutes.',
			'correctReady'        => 'Correction prête. Compare puis applique.',
			'applied'             => 'Correction appliquée.',
			'retranslating'       => 'Mise à jour de la traduction',
			'retranslated'        => 'Traduction mise à jour :',
			'publishedWarn'       => 'Cette étude est publiée : le changement sera en ligne dès que tu l\'appliques.',
			'words'               => 'Mots',
			'wordsLower'          => 'mots',
			'linksKept'           => 'Liens gardés',
			'mediaKept'           => 'Images, vidéos et tableaux gardés',
			'before'              => 'Avant',
			'after'               => 'Après',
			'history'             => 'WordPress garde l\'ancienne version dans les révisions de l\'étude.',
			'excerpt'             => 'Extrait',
			'excerptHelp'         => 'Affiché sur les cartes des études (120 à 350 caractères).',
			'preview'             => 'Aperçu',
			'sections'            => 'Sections',
			'noSections'          => 'Aucune section H2.',
			'toComplete'          => 'À compléter',
			'editorHint'          => 'Tu peux aussi écrire ou retoucher dans l\'éditeur WordPress : en revenant ici, l\'aperçu se met à jour tout seul.',
			'ownImage'            => 'Ta propre image',
			'ownImageHelp'        => 'Une photo du projet vaut souvent mieux qu\'une image générée : choisis-la dans la médiathèque ou téléverse-la.',
			'pickImage'           => 'Choisir ou téléverser une image',
			'pickTitle'           => 'Image à la une de l\'étude',
			'aiImage'             => 'Ou une image générée par IA',
			'imageStyle'          => 'Style d\'image',
			'matchColors'         => 'Couleurs de la marque en accents',
			'imagePrompt'         => 'Prompt de l\'image',
			'imagePromptHelp'     => 'Modifie-le ou ajoute des directives avant de générer.',
			'suggestPrompt'       => 'Proposer un prompt',
			'suggestingPrompt'    => 'Rédaction du prompt et du texte alternatif.',
			'generateImage'       => 'Générer l\'image',
			'generateAnother'     => 'Générer une autre',
			'generatingImage'     => 'Génération de l\'image.',
			'imageReadyMsg'       => 'Image générée. Garde-la ou génères-en une autre.',
			'noPrompt'            => 'Entre ou propose d\'abord un prompt.',
			'noImageProvider'     => 'Aucun fournisseur d\'images n\'est configuré (Réglages, Connecteurs). Choisis une image de la médiathèque.',
			'imagesSession'       => 'Images de cette session',
			'imageAlt'            => 'Texte alternatif',
			'imageAltHelp'        => 'Une phrase qui décrit l\'image, dans la langue de l\'étude.',
			'useImageContinue'    => 'Utiliser cette image et continuer',
			'keepCurrent'         => 'Garder l\'image actuelle',
			'currentFeatured'     => 'Image à la une actuelle',
			'imageShared'         => 'L\'image à la une est partagée avec les traductions (enregistrée en WebP).',
			'linksIntro'          => 'Claude cherche dans le texte des expressions à lier vers tes articles et tes autres études publiés, dans la même langue.',
			'suggestLinks'        => 'Suggérer des liens internes',
			'suggestingLinks'     => 'Analyse de tes articles et études publiés.',
			'linksFound'          => 'Coche les liens à insérer :',
			'noLinks'             => 'Aucun lien interne pertinent trouvé.',
			'linksInserted'       => 'lien(s) inséré(s).',
			'linksMissing'        => 'Introuvable dans le texte :',
			'linksDone'           => 'Maillage fait.',
			'insertContinue'      => 'Insérer et continuer',
			'kind_article'        => 'article',
			'kind_case_study'     => 'étude de cas',
			'generateSeo'         => 'Générer les champs SEO',
			'regenerateSeo'       => 'Régénérer les champs SEO',
			'generatingSeo'       => 'Rédaction des champs SEO.',
			'seoReady'            => 'Champs SEO rédigés. Relis-les puis enregistre.',
			'seoSaved'            => 'Champs Yoast et slug enregistrés.',
			'saveSeoContinue'     => 'Enregistrer dans Yoast et continuer',
			'noYoast'             => 'Yoast SEO n\'est pas actif : seul le slug sera enregistré.',
			'seo_seo_title'           => 'Titre SEO',
			'seo_meta_description'    => 'Méta description',
			'seo_focus_keyphrase'     => 'Requête cible',
			'seo_slug'                => 'Slug',
			'seo_og_title'            => 'Titre Facebook',
			'seo_og_description'      => 'Description Facebook',
			'seo_twitter_title'       => 'Titre X',
			'seo_twitter_description' => 'Description X',
			'translateIntro'      => 'Claude traduit le titre, le texte, l\'extrait, l\'adresse, les champs Yoast et la fiche projet. Le client, le site, le logo et l\'image à la une sont copiés. Les secteurs et services sans équivalent sont créés dans la langue et liés. Une nouvelle traduction est en brouillon; une traduction existante garde son statut.',
			'noWpml'              => 'WPML n\'est pas actif, ou une seule langue est active : rien à traduire.',
			'noTranslation'       => 'Pas encore traduite',
			'translateTo'         => 'Traduire',
			'retranslate'         => 'Retraduire',
			'retranslateConf'     => 'La traduction existante sera remplacée par une nouvelle. Continuer ?',
			'translateAll'        => 'Traduire dans toutes les langues',
			'translating'         => 'Traduction en cours, une à deux minutes par langue',
			'translated'          => 'traduction enregistrée.',
			'updatedTr'           => 'traduction mise à jour.',
			'createdTerms'        => 'Secteurs et services créés :',
			'metaFailed'          => 'Le titre et la fiche n\'ont pas pu être traduits : vérifie-les.',
			'checklist'           => 'Vérifications',
			'check_client'        => 'Client indiqué',
			'check_sectors'       => 'Au moins un secteur',
			'check_todo'          => 'Aucune section « À compléter »',
			'check_excerpt'       => 'Extrait (120 à 350 caractères)',
			'check_featured'      => 'Image à la une',
			'check_alt'           => 'Texte alternatif de l\'image',
			'check_results'       => 'Résultats clés dans la fiche',
			'check_anchors'       => 'Ancres des sections (complétées à la publication)',
			'check_seo'           => 'Titre SEO et méta description',
			'check_dashes'        => 'Aucun tiret cadratin',
			'check_blocks'        => 'Blocs natifs seulement',
			'errorsBlock'         => 'Il reste des points bloquants. Tu peux quand même publier, mais vérifie d\'abord.',
			'publishMode'         => 'Action',
			'modePublish'         => 'Publier maintenant',
			'modeFuture'          => 'Planifier',
			'modeDraft'           => 'Laisser en brouillon',
			'date'                => 'Date et heure',
			'includeTranslations' => 'Appliquer aussi aux traductions',
			'publishing'          => 'Mise à jour du statut.',
			'publishedDone'       => 'C\'est fait.',
		];
	}
	return [
		'tab_sheet'           => 'Project sheet',
		'tab_text'            => 'Text',
		'tab_image'           => 'Image',
		'tab_links'           => 'Linking',
		'tab_seo'             => 'SEO',
		'tab_translate'       => 'Translation',
		'tab_publish'         => 'Publish',
		'allStudies'          => 'All case studies',
		'newStudy'            => 'New case study',
		'search'              => 'Search',
		'searchPh'            => 'Title or word in the text',
		'count'               => 'case study(ies)',
		'none'                => 'No case study yet. Start with "New case study".',
		'noResults'           => 'No case study matches.',
		'prev'                => 'Previous',
		'next'                => 'Next',
		'page'                => 'Page',
		'edit'                => 'Edit',
		'view'                => 'View',
		'open'                => 'Open',
		'back'                => 'All case studies',
		'modified'            => 'Modified',
		'original'            => 'Original',
		'status_draft'        => 'Draft',
		'status_pending'      => 'Pending',
		'status_future'       => 'Scheduled',
		'status_publish'      => 'Published',
		'status_private'      => 'Private',
		'close'               => 'Close',
		'busy'                => 'Working…',
		'failed'              => 'Something went wrong. Please try again.',
		'chars'               => 'characters',
		'continue'            => 'Continue',
		'skip'                => 'Skip this step',
		'saveContinue'        => 'Save and continue',
		'apply'               => 'Apply',
		'cancel'              => 'Cancel',
		'model'               => 'Model used:',
		'openEditor'          => 'Open in the editor',
		'isTranslation'       => 'This case study is a translation. Open the original to edit it: the translation is redone from it.',
		'openOriginal'        => 'Open the original',
		'sheetIntro'          => 'A case study tells a real project. Start with the sheet: the study is created as a draft with its 4 sections (Context, Challenge, Solution, Results). The AI invents nothing: it sorts and corrects what you give it.',
		'language'            => 'Language of the case study',
		'title'               => 'Title',
		'titleHelp'           => 'E.g. How Transport X cut roadside breakdowns by 40%',
		'noTitle'             => 'Give the case study a title.',
		'client'              => 'Client',
		'clientUrl'           => 'Client website',
		'period'              => 'Period',
		'periodHelp'          => 'E.g. 2024-2025, or Spring 2025',
		'logo'                => 'Client logo',
		'chooseLogo'          => 'Choose the logo',
		'choose'              => 'Choose',
		'replace'             => 'Replace',
		'remove'              => 'Remove',
		'add'                 => 'Add',
		'results'             => 'Key results',
		'resultsHelp'         => 'Figure + what it measures (8 at most). Real results only.',
		'resultValue'         => 'Figure',
		'resultLabel'         => 'What it measures',
		'addResult'           => 'Add a result',
		'testimonial'         => 'Testimonial',
		'testimonialHelp'     => 'The client\'s own words.',
		'testimonialAuthor'   => 'Testimonial author (name, title)',
		'sectors'             => 'Sectors',
		'noSectors'           => 'No sector in this language. Add one below.',
		'newTerm'             => 'new',
		'addSectorPh'         => 'New sector',
		'services'            => 'Services',
		'servicesHelp'        => 'Pick existing services or type a new name then Enter.',
		'createContinue'      => 'Create the case study and continue',
		'creating'            => 'Creating the case study.',
		'organizeNotes'       => 'Organize my notes',
		'correct'             => 'Correct the text',
		'notesIntro'          => 'Paste your base text: project notes, client email, report, loose points. Claude sorts it into the sections and fixes the language, without adding anything. A section with no information is marked "To complete". Nothing is saved before "Apply".',
		'notesLabel'          => 'Notes',
		'notesPh'             => 'E.g. Client: Transport X, 120 trucks. Problem: frequent flats, 3 tows a month…',
		'notesShort'          => 'Paste your notes first (a few sentences at least).',
		'organize'            => 'Organize into sections',
		'organizing'          => 'Claude is sorting your notes into the sections. This can take a minute.',
		'notesReady'          => 'Text organized. Compare, then apply.',
		'notesReadyTodo'      => 'Text organized. Sections with no information (marked "To complete"):',
		'notesApplied'        => 'Text saved.',
		'sheetFilled'         => 'The project sheet was completed too (results or testimonial found in your notes).',
		'replacesText'        => 'The current text of the case study will be replaced. WordPress keeps the previous version in the revisions.',
		'emptySections'       => 'Nothing in your notes for:',
		'willFillResults'     => 'Found in your notes: they will fill the project sheet, which has none yet.',
		'keepsResults'        => 'Found in your notes, but the project sheet already has its results: it does not change.',
		'willFillTestimonial' => 'Quotation found in your notes: it will fill the project sheet.',
		'keepsTestimonial'    => 'Quotation found in your notes, but the sheet already has a testimonial: it does not change.',
		'correcting'          => 'Claude is proofreading the case study. This can take one to two minutes.',
		'correctReady'        => 'Correction ready. Compare, then apply.',
		'applied'             => 'Correction applied.',
		'retranslating'       => 'Updating the translation',
		'retranslated'        => 'Translation updated:',
		'publishedWarn'       => 'This case study is published: the change goes live as soon as you apply it.',
		'words'               => 'Words',
		'wordsLower'          => 'words',
		'linksKept'           => 'Links kept',
		'mediaKept'           => 'Images, videos and tables kept',
		'before'              => 'Before',
		'after'               => 'After',
		'history'             => 'WordPress keeps the previous version in the revisions.',
		'excerpt'             => 'Excerpt',
		'excerptHelp'         => 'Shown on the case study cards (120 to 350 characters).',
		'preview'             => 'Preview',
		'sections'            => 'Sections',
		'noSections'          => 'No H2 section.',
		'toComplete'          => 'To complete',
		'editorHint'          => 'You can also write or edit in the WordPress editor: when you come back here, the preview updates by itself.',
		'ownImage'            => 'Your own image',
		'ownImageHelp'        => 'A photo of the project is often better than a generated image: pick it in the media library or upload it.',
		'pickImage'           => 'Choose or upload an image',
		'pickTitle'           => 'Featured image of the case study',
		'aiImage'             => 'Or an AI generated image',
		'imageStyle'          => 'Image style',
		'matchColors'         => 'Brand colors as accents',
		'imagePrompt'         => 'Image prompt',
		'imagePromptHelp'     => 'Edit it or add directives before generating.',
		'suggestPrompt'       => 'Suggest a prompt',
		'suggestingPrompt'    => 'Writing the prompt and the alt text.',
		'generateImage'       => 'Generate the image',
		'generateAnother'     => 'Generate another',
		'generatingImage'     => 'Generating the image.',
		'imageReadyMsg'       => 'Image generated. Keep it or generate another one.',
		'noPrompt'            => 'Enter or suggest a prompt first.',
		'noImageProvider'     => 'No image provider is configured (Settings, Connectors). Pick an image from the media library.',
		'imagesSession'       => 'Images from this session',
		'imageAlt'            => 'Alt text',
		'imageAltHelp'        => 'One sentence describing the image, in the case study language.',
		'useImageContinue'    => 'Use this image and continue',
		'keepCurrent'         => 'Keep the current image',
		'currentFeatured'     => 'Current featured image',
		'imageShared'         => 'The featured image is shared with the translations (saved as WebP).',
		'linksIntro'          => 'Claude looks in the text for phrases to link to your published articles and other case studies, in the same language.',
		'suggestLinks'        => 'Suggest internal links',
		'suggestingLinks'     => 'Scanning your published articles and case studies.',
		'linksFound'          => 'Select the links to insert:',
		'noLinks'             => 'No relevant internal link found.',
		'linksInserted'       => 'link(s) inserted.',
		'linksMissing'        => 'Not found in the text:',
		'linksDone'           => 'Linking done.',
		'insertContinue'      => 'Insert and continue',
		'kind_article'        => 'article',
		'kind_case_study'     => 'case study',
		'generateSeo'         => 'Generate SEO fields',
		'regenerateSeo'       => 'Regenerate SEO fields',
		'generatingSeo'       => 'Writing the SEO fields.',
		'seoReady'            => 'SEO fields written. Review them, then save.',
		'seoSaved'            => 'Yoast fields and slug saved.',
		'saveSeoContinue'     => 'Save to Yoast and continue',
		'noYoast'             => 'Yoast SEO is not active: only the slug will be saved.',
		'seo_seo_title'           => 'SEO title',
		'seo_meta_description'    => 'Meta description',
		'seo_focus_keyphrase'     => 'Focus keyphrase',
		'seo_slug'                => 'Slug',
		'seo_og_title'            => 'Facebook title',
		'seo_og_description'      => 'Facebook description',
		'seo_twitter_title'       => 'X title',
		'seo_twitter_description' => 'X description',
		'translateIntro'      => 'Claude translates the title, text, excerpt, address, Yoast fields and project sheet. Client, website, logo and featured image are copied. Sectors and services with no equivalent are created in the language and linked. A new translation is a draft; an existing one keeps its status.',
		'noWpml'              => 'WPML is not active, or only one language is active: nothing to translate.',
		'noTranslation'       => 'Not translated yet',
		'translateTo'         => 'Translate',
		'retranslate'         => 'Translate again',
		'retranslateConf'     => 'The existing translation will be replaced. Continue?',
		'translateAll'        => 'Translate into every language',
		'translating'         => 'Translating, one to two minutes per language',
		'translated'          => 'translation saved.',
		'updatedTr'           => 'translation updated.',
		'createdTerms'        => 'Sectors and services created:',
		'metaFailed'          => 'The title and sheet could not be translated: check them.',
		'checklist'           => 'Checks',
		'check_client'        => 'Client given',
		'check_sectors'       => 'At least one sector',
		'check_todo'          => 'No section "To complete"',
		'check_excerpt'       => 'Excerpt (120 to 350 characters)',
		'check_featured'      => 'Featured image',
		'check_alt'           => 'Image alt text',
		'check_results'       => 'Key results in the sheet',
		'check_anchors'       => 'Section anchors (added on publish)',
		'check_seo'           => 'SEO title and meta description',
		'check_dashes'        => 'No em dash',
		'check_blocks'        => 'Native blocks only',
		'errorsBlock'         => 'Some blocking points remain. You can still publish, but check first.',
		'publishMode'         => 'Action',
		'modePublish'         => 'Publish now',
		'modeFuture'          => 'Schedule',
		'modeDraft'           => 'Keep as draft',
		'date'                => 'Date and time',
		'includeTranslations' => 'Apply to the translations too',
		'publishing'          => 'Updating the status.',
		'publishedDone'       => 'Done.',
	];
}
