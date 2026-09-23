<?php
/**
 * Settings: defaults, getter and the settings page (Case Studies > Settings).
 */

namespace Pratcom\EtudesDeCas;

defined( 'ABSPATH' ) || exit;

const OPTION = 'pedc_settings';

/**
 * Default settings. Slugs are stored untranslated; WPML translates them
 * through WPML > Settings > Post Types Translation (slug translation).
 */
function defaults(): array {
	return [
		'slug'           => 'etudes-de-cas',
		'sector_slug'    => 'secteur',
		'service_slug'   => 'service',
		'templates'      => 'plugin', // plugin | theme (all views)
		'single'         => 'auto',   // auto | plugin | theme: who renders a single case study
		'auto_sheet'     => 1,        // when the theme renders the case study: sheet above the content, testimonial below
		'related'        => 1,
		'schema'         => 1,
		'archive_count'  => 9,
	];
}

function settings(): array {
	static $cache = null;
	if ( null === $cache ) {
		$saved = get_option( OPTION, [] );
		$cache = array_merge( defaults(), is_array( $saved ) ? $saved : [] );
	}
	return $cache;
}

/**
 * Does the plugin render a single case study itself?
 * auto: block theme → the theme's single.html (its layout, table of contents,
 * call to action); classic theme → the plugin template.
 */
function plugin_renders_single(): bool {
	if ( 'plugin' !== setting( 'templates' ) ) {
		return false;
	}
	$mode = setting( 'single' );
	if ( 'auto' === $mode ) {
		return ! wp_is_block_theme();
	}
	return 'plugin' === $mode;
}

function setting( string $key ) {
	$s = settings();
	return $s[ $key ] ?? null;
}

function sanitize_settings( $input ): array {
	$d   = defaults();
	$in  = is_array( $input ) ? $input : [];
	$out = [];

	foreach ( [ 'slug', 'sector_slug', 'service_slug' ] as $k ) {
		$v         = sanitize_title( $in[ $k ] ?? '' );
		$out[ $k ] = '' !== $v ? $v : $d[ $k ];
	}
	$out['templates']     = in_array( $in['templates'] ?? '', [ 'plugin', 'theme' ], true ) ? $in['templates'] : $d['templates'];
	$out['single']        = in_array( $in['single'] ?? '', [ 'auto', 'plugin', 'theme' ], true ) ? $in['single'] : $d['single'];
	$out['auto_sheet']    = empty( $in['auto_sheet'] ) ? 0 : 1;
	$out['related']       = empty( $in['related'] ) ? 0 : 1;
	$out['schema']        = empty( $in['schema'] ) ? 0 : 1;
	$out['archive_count'] = max( 1, min( 48, (int) ( $in['archive_count'] ?? $d['archive_count'] ) ) );

	return $out;
}

// Rewrite rules must follow slug changes (flushed on the next request, once
// the post type is registered with the new slugs).
function flag_flush_if_slugs_changed( $old, $new ): void {
	$old = array_merge( defaults(), is_array( $old ) ? $old : [] );
	$new = array_merge( defaults(), is_array( $new ) ? $new : [] );
	foreach ( [ 'slug', 'sector_slug', 'service_slug' ] as $k ) {
		if ( $old[ $k ] !== $new[ $k ] ) {
			update_option( 'pedc_flush_rewrite', 1 );
			return;
		}
	}
}
add_action( 'update_option_' . OPTION, __NAMESPACE__ . '\\flag_flush_if_slugs_changed', 10, 2 );
add_action( 'add_option_' . OPTION, static function ( $option, $value ) {
	flag_flush_if_slugs_changed( [], $value );
}, 10, 2 );

add_action( 'init', static function () {
	if ( get_option( 'pedc_flush_rewrite' ) ) {
		delete_option( 'pedc_flush_rewrite' );
		flush_rewrite_rules( false );
	}
}, 99 );

add_action( 'admin_init', static function () {
	register_setting( 'pedc_settings_group', OPTION, [
		'type'              => 'array',
		'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
		'default'           => defaults(),
	] );
} );

add_action( 'admin_menu', static function () {
	add_submenu_page(
		'edit.php?post_type=' . PEDC_POST_TYPE,
		__( 'Case study settings', 'pratcom-etudes-de-cas' ),
		__( 'Settings', 'pratcom-etudes-de-cas' ),
		'manage_options',
		'pedc-settings',
		__NAMESPACE__ . '\\render_settings_page'
	);
} );

function render_settings_page(): void {
	$s    = settings();
	$name = OPTION;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Case study settings', 'pratcom-etudes-de-cas' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'pedc_settings_group' ); ?>
			<h2><?php esc_html_e( 'Addresses (URLs)', 'pratcom-etudes-de-cas' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="pedc-slug"><?php esc_html_e( 'Case studies slug', 'pratcom-etudes-de-cas' ); ?></label></th>
					<td>
						<code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="pedc-slug" name="<?php echo esc_attr( $name ); ?>[slug]" value="<?php echo esc_attr( $s['slug'] ); ?>" class="regular-text code">
						<p class="description"><?php esc_html_e( 'Used for the list page and each case study. With WPML, translate it in WPML > Settings > Post Types Translation (e.g. case-studies).', 'pratcom-etudes-de-cas' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="pedc-sector-slug"><?php esc_html_e( 'Sector slug', 'pratcom-etudes-de-cas' ); ?></label></th>
					<td><code>/<?php echo esc_html( $s['slug'] ); ?>/</code><input id="pedc-sector-slug" name="<?php echo esc_attr( $name ); ?>[sector_slug]" value="<?php echo esc_attr( $s['sector_slug'] ); ?>" class="regular-text code"><code>/transport/</code></td>
				</tr>
				<tr>
					<th scope="row"><label for="pedc-service-slug"><?php esc_html_e( 'Service slug', 'pratcom-etudes-de-cas' ); ?></label></th>
					<td><code>/<?php echo esc_html( $s['slug'] ); ?>/</code><input id="pedc-service-slug" name="<?php echo esc_attr( $name ); ?>[service_slug]" value="<?php echo esc_attr( $s['service_slug'] ); ?>" class="regular-text code"><code>/seo/</code></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Display', 'pratcom-etudes-de-cas' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Templates', 'pratcom-etudes-de-cas' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[templates]" value="plugin" <?php checked( $s['templates'], 'plugin' ); ?>> <?php esc_html_e( 'Use the plugin templates (the theme can still override them)', 'pratcom-etudes-de-cas' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[templates]" value="theme" <?php checked( $s['templates'], 'theme' ); ?>> <?php esc_html_e( 'Let the theme handle the display', 'pratcom-etudes-de-cas' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'A theme overrides a plugin template with single-etude_de_cas.php / archive-etude_de_cas.php (classic) or templates/single-etude_de_cas.html (block theme).', 'pratcom-etudes-de-cas' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Single case study', 'pratcom-etudes-de-cas' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[single]" value="auto" <?php checked( $s['single'], 'auto' ); ?>> <?php esc_html_e( 'Automatic: the theme\'s single post template on a block theme, the plugin template on a classic theme', 'pratcom-etudes-de-cas' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[single]" value="theme" <?php checked( $s['single'], 'theme' ); ?>> <?php esc_html_e( 'Always the theme\'s single post template', 'pratcom-etudes-de-cas' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[single]" value="plugin" <?php checked( $s['single'], 'plugin' ); ?>> <?php esc_html_e( 'Always the plugin template', 'pratcom-etudes-de-cas' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'With the theme template, a case study looks like a blog post (table of contents, call to action, author). List, sector and service pages keep the plugin templates.', 'pratcom-etudes-de-cas' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Project sheet', 'pratcom-etudes-de-cas' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_sheet]" value="1" <?php checked( $s['auto_sheet'], 1 ); ?>> <?php esc_html_e( 'When the theme displays the case study, insert the project sheet above the content and the testimonial below', 'pratcom-etudes-de-cas' ); ?></label>
					<p class="description"><?php
						/* translators: %s: shortcode */
						printf( esc_html__( 'Otherwise, place the "Project sheet" block or the %s shortcode where you want it.', 'pratcom-etudes-de-cas' ), '<code>[pratcom_fiche_projet]</code>' );
					?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Related case studies', 'pratcom-etudes-de-cas' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[related]" value="1" <?php checked( $s['related'], 1 ); ?>> <?php esc_html_e( 'Show 3 other case studies at the bottom, same sector first (plugin templates)', 'pratcom-etudes-de-cas' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="pedc-archive-count"><?php esc_html_e( 'Case studies per page', 'pratcom-etudes-de-cas' ); ?></label></th>
					<td><input type="number" min="1" max="48" id="pedc-archive-count" name="<?php echo esc_attr( $name ); ?>[archive_count]" value="<?php echo esc_attr( (string) $s['archive_count'] ); ?>" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Structured data', 'pratcom-etudes-de-cas' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[schema]" value="1" <?php checked( $s['schema'], 1 ); ?>> <?php esc_html_e( 'Add the client and sector to the Article schema (enriches Yoast / Rank Math, or outputs its own JSON-LD if neither is active)', 'pratcom-etudes-de-cas' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
