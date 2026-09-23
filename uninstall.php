<?php
/**
 * Uninstall: removes the plugin settings only. Case studies, sectors and
 * services stay in the database (deleting content is never automatic).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pedc_settings' );
delete_option( 'pedc_flush_rewrite' );
