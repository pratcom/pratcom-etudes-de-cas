<?php
/**
 * Updates from the public GitHub repository pratcom/pratcom-etudes-de-cas.
 *
 * WordPress reads the "Version:" header of the plugin file on the main branch.
 * When it is higher than the installed version, the usual "update available"
 * notice appears (Plugins screen, Dashboard > Updates, auto-updates), and the
 * package is the zip of the main branch. No token, no release to publish:
 * bumping the version on main is the release.
 */

namespace Pratcom\EtudesDeCas;

defined( 'ABSPATH' ) || exit;

const UPDATE_REPO      = 'pratcom/pratcom-etudes-de-cas';
const UPDATE_BRANCH    = 'main';
const UPDATE_TRANSIENT = 'pedc_update_info';

function update_raw_url( string $file ): string {
	return 'https://raw.githubusercontent.com/' . UPDATE_REPO . '/' . UPDATE_BRANCH . '/' . $file;
}

/**
 * Remote plugin data (version, requirements, changelog), cached 6 hours.
 * A failed request is cached 30 minutes so a GitHub outage never slows the admin.
 */
function remote_info( bool $force = false ): ?array {
	if ( ! $force ) {
		$cached = get_site_transient( UPDATE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
	}

	$info = [];
	$res  = wp_remote_get( update_raw_url( 'pratcom-etudes-de-cas.php' ), [ 'timeout' => 8 ] );
	if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
		$body   = wp_remote_retrieve_body( $res );
		$fields = [
			'version'      => 'Version',
			'requires'     => 'Requires at least',
			'requires_php' => 'Requires PHP',
		];
		foreach ( $fields as $key => $header ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $body, $m ) ) {
				$info[ $key ] = trim( $m[1] );
			}
		}
		$readme = wp_remote_get( update_raw_url( 'readme.txt' ), [ 'timeout' => 8 ] );
		if ( ! is_wp_error( $readme ) && 200 === wp_remote_retrieve_response_code( $readme ) ) {
			$text = wp_remote_retrieve_body( $readme );
			if ( preg_match( '/^Tested up to:(.*)$/mi', $text, $m ) ) {
				$info['tested'] = trim( $m[1] );
			}
			if ( preg_match( '/== Changelog ==(.*)$/s', $text, $m ) ) {
				$info['changelog'] = trim( $m[1] );
			}
		}
	}

	if ( empty( $info['version'] ) || ! preg_match( '/^\d+(\.\d+)*$/', $info['version'] ) ) {
		set_site_transient( UPDATE_TRANSIENT, [ 'version' => '' ], 30 * MINUTE_IN_SECONDS );
		return null;
	}
	set_site_transient( UPDATE_TRANSIENT, $info, 6 * HOUR_IN_SECONDS );
	return $info;
}

function update_package_url(): string {
	return 'https://github.com/' . UPDATE_REPO . '/archive/refs/heads/' . UPDATE_BRANCH . '.zip';
}

/** Tell WordPress about the update (or explicitly that there is none). */
add_filter( 'pre_set_site_transient_update_plugins', static function ( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$info = remote_info();
	if ( ! $info ) {
		return $transient;
	}
	$basename = plugin_basename( PEDC_FILE );
	$item     = (object) [
		'id'           => 'github.com/' . UPDATE_REPO,
		'slug'         => 'pratcom-etudes-de-cas',
		'plugin'       => $basename,
		'new_version'  => $info['version'],
		'url'          => 'https://github.com/' . UPDATE_REPO,
		'package'      => update_package_url(),
		'requires'     => $info['requires'] ?? '',
		'requires_php' => $info['requires_php'] ?? '',
		'tested'       => $info['tested'] ?? '',
		'icons'        => [],
		'banners'      => [],
	];
	if ( version_compare( $info['version'], PEDC_VERSION, '>' ) ) {
		$transient->response[ $basename ] = $item;
		unset( $transient->no_update[ $basename ] );
	} else {
		$item->package                     = '';
		$transient->no_update[ $basename ] = $item; // Enables the auto-update toggle.
		unset( $transient->response[ $basename ] );
	}
	return $transient;
} );

/** "View details" popup. */
add_filter( 'plugins_api', static function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || 'pratcom-etudes-de-cas' !== ( $args->slug ?? '' ) ) {
		return $result;
	}
	$info = remote_info();
	if ( ! $info ) {
		return $result;
	}
	$changelog = esc_html( $info['changelog'] ?? '' );
	$changelog = preg_replace( '/^= (.+) =$/m', '<h4>$1</h4>', $changelog );
	$changelog = preg_replace( '/^\* (.+)$/m', '<li>$1</li>', $changelog );
	return (object) [
		'name'          => 'Pratcom – Études de cas',
		'slug'          => 'pratcom-etudes-de-cas',
		'version'       => $info['version'],
		'author'        => '<a href="https://pratcom.net/">Pratcom Média</a>',
		'homepage'      => 'https://github.com/' . UPDATE_REPO,
		'requires'      => $info['requires'] ?? '',
		'requires_php'  => $info['requires_php'] ?? '',
		'tested'        => $info['tested'] ?? '',
		'download_link' => update_package_url(),
		'sections'      => [
			'description' => esc_html__( 'Client case studies, kept separate from blog posts.', 'pratcom-etudes-de-cas' ),
			'changelog'   => nl2br( $changelog ),
		],
	];
}, 10, 3 );

/**
 * The GitHub zip unpacks to "pratcom-etudes-de-cas-main/": rename it to the
 * installed folder so WordPress replaces the plugin instead of adding a copy.
 */
add_filter( 'upgrader_source_selection', static function ( $source, $remote_source, $upgrader, $hook_extra = [] ) {
	global $wp_filesystem;
	if ( ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( PEDC_FILE ) || ! $wp_filesystem ) {
		return $source;
	}
	$wanted = trailingslashit( $remote_source ) . dirname( plugin_basename( PEDC_FILE ) ) . '/';
	if ( trailingslashit( $source ) === $wanted ) {
		return $source;
	}
	if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
		return new \WP_Error( 'pedc_rename_failed', __( 'Could not rename the downloaded folder.', 'pratcom-etudes-de-cas' ) );
	}
	return $wanted;
}, 10, 4 );

/** Fresh check after an update, and when an admin clicks "Check again". */
add_action( 'upgrader_process_complete', static function () {
	delete_site_transient( UPDATE_TRANSIENT );
} );
add_action( 'load-update-core.php', static function () {
	if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		delete_site_transient( UPDATE_TRANSIENT );
	}
} );

/** "Check for updates" link on the Plugins screen. */
add_filter( 'plugin_row_meta', static function ( $links, $file ) {
	if ( plugin_basename( PEDC_FILE ) === $file && current_user_can( 'update_plugins' ) ) {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'plugins.php?pedc_check_update=1' ), 'pedc_check_update' ) ),
			esc_html__( 'Check for updates', 'pratcom-etudes-de-cas' )
		);
	}
	return $links;
}, 10, 2 );

add_action( 'load-plugins.php', static function () {
	if ( empty( $_GET['pedc_check_update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	check_admin_referer( 'pedc_check_update' );
	delete_site_transient( UPDATE_TRANSIENT );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
} );
