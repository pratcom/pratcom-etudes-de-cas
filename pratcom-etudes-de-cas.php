<?php
/**
 * Plugin Name:       Pratcom – Études de cas
 * Plugin URI:        https://pratcom.net/
 * Description:       Adds a "Case Studies" content type, separate from blog posts: sectors, services, project sheet (client, results, testimonial), templates for block and classic themes, WPML ready. With the WordPress 7 AI Client: a case study assistant (project sheet, notes organized into sections, image, internal links, Yoast SEO, WPML translation, publication).
 * Version:           1.3.0
 * Requires at least: 6.7
 * Requires PHP:      8.0
 * Author:            Pratcom Média
 * Author URI:        https://pratcom.net/
 * License:           GPL-2.0-or-later
 * Text Domain:       pratcom-etudes-de-cas
 * Domain Path:       /languages
 * Update URI:        https://github.com/pratcom/pratcom-etudes-de-cas
 */

defined( 'ABSPATH' ) || exit;

define( 'PEDC_VERSION', '1.3.0' );
define( 'PEDC_FILE', __FILE__ );
define( 'PEDC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PEDC_URL', plugin_dir_url( __FILE__ ) );

/** Post type and taxonomy keys (never change once content exists). */
define( 'PEDC_POST_TYPE', 'etude_de_cas' );
define( 'PEDC_TAX_SECTOR', 'etude_secteur' );
define( 'PEDC_TAX_SERVICE', 'etude_service' );

require_once PEDC_DIR . 'includes/settings.php';
require_once PEDC_DIR . 'includes/content-types.php';
require_once PEDC_DIR . 'includes/render.php';
require_once PEDC_DIR . 'includes/blocks.php';
require_once PEDC_DIR . 'includes/templates.php';
require_once PEDC_DIR . 'includes/schema.php';
require_once PEDC_DIR . 'includes/updater.php';
require_once PEDC_DIR . 'includes/ai.php';

if ( is_admin() ) {
	require_once PEDC_DIR . 'includes/admin.php';
}

add_action( 'init', static function () {
	load_plugin_textdomain( 'pratcom-etudes-de-cas', false, dirname( plugin_basename( PEDC_FILE ) ) . '/languages' );
}, 0 );

register_activation_hook( __FILE__, static function () {
	\Pratcom\EtudesDeCas\register_taxonomies();
	\Pratcom\EtudesDeCas\register_post_type();
	flush_rewrite_rules( false );
} );

register_deactivation_hook( __FILE__, static function () {
	unregister_post_type( PEDC_POST_TYPE );
	flush_rewrite_rules( false );
} );
