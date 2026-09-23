<?php
/**
 * Admin: "Project sheet" meta box (works in the block editor and the classic
 * editor), list columns.
 */

namespace Pratcom\EtudesDeCas;

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes_' . PEDC_POST_TYPE, static function () {
	add_meta_box(
		'pedc-sheet',
		__( 'Project sheet', 'pratcom-etudes-de-cas' ),
		__NAMESPACE__ . '\\render_meta_box',
		PEDC_POST_TYPE,
		'normal',
		'high',
		// Block editor: the fields live in the "Project sheet" sidebar panel instead.
		[ '__back_compat_meta_box' => true ]
	);
} );

/** Block editor: "Project sheet" panel in the document sidebar. */
add_action( 'enqueue_block_editor_assets', static function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || PEDC_POST_TYPE !== $screen->post_type ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script(
		'pedc-editor-sheet',
		PEDC_URL . 'assets/js/editor-sheet.js',
		[ 'wp-plugins', 'wp-editor', 'wp-data', 'wp-core-data', 'wp-components', 'wp-element', 'wp-i18n', 'wp-block-editor' ],
		PEDC_VERSION,
		true
	);
	wp_set_script_translations( 'pedc-editor-sheet', 'pratcom-etudes-de-cas', PEDC_DIR . 'languages' );
	wp_add_inline_style( 'wp-edit-post', '.pedc-sheet-panel .pedc-panel__label{margin:20px 0 8px;font-size:11px;font-weight:500;text-transform:uppercase}.pedc-sheet-panel .pedc-panel__help{margin:-4px 0 10px;color:#757575;font-size:12px}.pedc-sheet-panel .pedc-panel__row{display:grid;grid-template-columns:5.5em 1fr auto;gap:6px;align-items:end;margin-bottom:8px}' );
} );

add_action( 'admin_enqueue_scripts', static function ( $hook ) {
	$screen = get_current_screen();
	if ( ! $screen || PEDC_POST_TYPE !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'pedc-admin', PEDC_URL . 'assets/css/admin.css', [], PEDC_VERSION );
	wp_enqueue_script( 'pedc-admin', PEDC_URL . 'assets/js/admin.js', [ 'jquery' ], PEDC_VERSION, true );
	wp_localize_script( 'pedc-admin', 'pedcAdmin', [
		'chooseLogo' => __( 'Choose the client logo', 'pratcom-etudes-de-cas' ),
		'useLogo'    => __( 'Use this logo', 'pratcom-etudes-de-cas' ),
	] );
} );

function render_meta_box( \WP_Post $post ): void {
	$d = sheet( $post );
	wp_nonce_field( 'pedc_save_sheet', 'pedc_sheet_nonce' );
	$results = $d['results'] ?: [ [ 'value' => '', 'label' => '' ] ];
	$logo    = $d['client_logo'] ? wp_get_attachment_image( $d['client_logo'], 'thumbnail' ) : '';
	?>
	<div class="pedc-box">
		<div class="pedc-box__grid">
			<p>
				<label for="pedc-client"><strong><?php esc_html_e( 'Client', 'pratcom-etudes-de-cas' ); ?></strong></label>
				<input type="text" id="pedc-client" name="pedc[client]" value="<?php echo esc_attr( $d['client'] ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Diesel Spec Inc.', 'pratcom-etudes-de-cas' ); ?>">
			</p>
			<p>
				<label for="pedc-client-url"><strong><?php esc_html_e( 'Client website', 'pratcom-etudes-de-cas' ); ?></strong></label>
				<input type="url" id="pedc-client-url" name="pedc[client_url]" value="<?php echo esc_attr( $d['client_url'] ); ?>" class="widefat" placeholder="https://">
			</p>
			<p>
				<label for="pedc-period"><strong><?php esc_html_e( 'Period', 'pratcom-etudes-de-cas' ); ?></strong></label>
				<input type="text" id="pedc-period" name="pedc[period]" value="<?php echo esc_attr( $d['period'] ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. 2024 – 2026', 'pratcom-etudes-de-cas' ); ?>">
			</p>
			<div class="pedc-logo">
				<strong><?php esc_html_e( 'Client logo', 'pratcom-etudes-de-cas' ); ?></strong>
				<input type="hidden" name="pedc[client_logo]" value="<?php echo esc_attr( (string) $d['client_logo'] ); ?>" class="pedc-logo__id">
				<div class="pedc-logo__preview"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<button type="button" class="button pedc-logo__pick"><?php esc_html_e( 'Choose…', 'pratcom-etudes-de-cas' ); ?></button>
				<button type="button" class="button-link pedc-logo__remove"<?php echo $d['client_logo'] ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'pratcom-etudes-de-cas' ); ?></button>
			</div>
		</div>

		<p class="description"><?php esc_html_e( 'Sectors and services are set in the sidebar (like post categories and tags).', 'pratcom-etudes-de-cas' ); ?></p>

		<h4><?php esc_html_e( 'Key results', 'pratcom-etudes-de-cas' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Up to 8. The first one is shown on the cards. E.g. "+212 %" / "organic traffic in 12 months".', 'pratcom-etudes-de-cas' ); ?></p>
		<table class="pedc-results-table widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Figure', 'pratcom-etudes-de-cas' ); ?></th>
				<th><?php esc_html_e( 'Description', 'pratcom-etudes-de-cas' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'pratcom-etudes-de-cas' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $results as $i => $r ) : ?>
				<tr>
					<td><input type="text" name="pedc[results][<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr( $r['value'] ); ?>" class="widefat" placeholder="+212 %"></td>
					<td><input type="text" name="pedc[results][<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $r['label'] ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'organic traffic in 12 months', 'pratcom-etudes-de-cas' ); ?>"></td>
					<td class="pedc-results-table__actions"><button type="button" class="button-link pedc-row-remove" aria-label="<?php esc_attr_e( 'Remove this result', 'pratcom-etudes-de-cas' ); ?>">&times;</button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button pedc-row-add"><?php esc_html_e( 'Add a result', 'pratcom-etudes-de-cas' ); ?></button></p>

		<h4><?php esc_html_e( 'Client testimonial', 'pratcom-etudes-de-cas' ); ?></h4>
		<p>
			<label for="pedc-testimonial" class="screen-reader-text"><?php esc_html_e( 'Testimonial', 'pratcom-etudes-de-cas' ); ?></label>
			<textarea id="pedc-testimonial" name="pedc[testimonial]" rows="3" class="widefat"><?php echo esc_textarea( $d['testimonial'] ); ?></textarea>
		</p>
		<p>
			<label for="pedc-testimonial-author"><strong><?php esc_html_e( 'Author (name, title)', 'pratcom-etudes-de-cas' ); ?></strong></label>
			<input type="text" id="pedc-testimonial-author" name="pedc[testimonial_author]" value="<?php echo esc_attr( $d['testimonial_author'] ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Ian, General Manager', 'pratcom-etudes-de-cas' ); ?>">
		</p>
	</div>
	<?php
}

add_action( 'save_post_' . PEDC_POST_TYPE, static function ( $post_id ) {
	if ( ! isset( $_POST['pedc_sheet_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pedc_sheet_nonce'] ) ), 'pedc_save_sheet' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$in = isset( $_POST['pedc'] ) && is_array( $_POST['pedc'] ) ? wp_unslash( $_POST['pedc'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.

	$map = [
		'client'             => '_pedc_client',
		'client_url'         => '_pedc_client_url',
		'client_logo'        => '_pedc_client_logo',
		'period'             => '_pedc_period',
		'testimonial'        => '_pedc_testimonial',
		'testimonial_author' => '_pedc_testimonial_author',
		'results'            => '_pedc_results',
	];
	$fields = meta_fields();
	foreach ( $map as $field => $key ) {
		$value = call_user_func( $fields[ $key ]['sanitize'], $in[ $field ] ?? ( 'results' === $field ? [] : '' ) );
		if ( '' === $value || [] === $value || 0 === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
} );

/* List table */

add_filter( 'manage_' . PEDC_POST_TYPE . '_posts_columns', static function ( $cols ) {
	$new = [];
	foreach ( $cols as $k => $v ) {
		if ( 'title' === $k ) {
			$new['pedc_thumb'] = '<span class="screen-reader-text">' . esc_html__( 'Image', 'pratcom-etudes-de-cas' ) . '</span>';
		}
		$new[ $k ] = $v;
		if ( 'title' === $k ) {
			$new['pedc_client'] = esc_html__( 'Client', 'pratcom-etudes-de-cas' );
		}
	}
	return $new;
} );

add_action( 'manage_' . PEDC_POST_TYPE . '_posts_custom_column', static function ( $col, $post_id ) {
	if ( 'pedc_client' === $col ) {
		echo esc_html( (string) get_post_meta( $post_id, '_pedc_client', true ) );
	} elseif ( 'pedc_thumb' === $col && has_post_thumbnail( $post_id ) ) {
		echo get_the_post_thumbnail( $post_id, [ 48, 48 ], [ 'style' => 'width:48px;height:48px;object-fit:cover;border-radius:4px' ] );
	}
}, 10, 2 );

add_action( 'admin_head-edit.php', static function () {
	if ( PEDC_POST_TYPE === ( get_current_screen()->post_type ?? '' ) ) {
		echo '<style>.column-pedc_thumb{width:56px}</style>';
	}
} );

/** "Settings" link on the Plugins screen. */
add_filter( 'plugin_action_links_' . plugin_basename( PEDC_FILE ), static function ( $links ) {
	array_unshift( $links, sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'edit.php?post_type=' . PEDC_POST_TYPE . '&page=pedc-settings' ) ),
		esc_html__( 'Settings', 'pratcom-etudes-de-cas' )
	) );
	return $links;
} );

/** Case studies count in the "At a Glance" dashboard widget. */
add_filter( 'dashboard_glance_items', static function ( $items ) {
	$count = wp_count_posts( PEDC_POST_TYPE );
	if ( $count && $count->publish ) {
		$text    = sprintf(
			/* translators: %s: number of case studies */
			_n( '%s case study', '%s case studies', $count->publish, 'pratcom-etudes-de-cas' ),
			number_format_i18n( $count->publish )
		);
		$items[] = sprintf( '<a class="pedc-count" href="%s">%s</a>', esc_url( admin_url( 'edit.php?post_type=' . PEDC_POST_TYPE ) ), esc_html( $text ) );
	}
	return $items;
} );
