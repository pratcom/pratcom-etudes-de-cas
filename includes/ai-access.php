<?php
/**
 * Who may use the AI assistant: the owner, plus the emails the owner adds
 * in the "Access" box (visible to the owner only).
 *
 * People without access keep the usual WordPress screens for case studies.
 */

namespace Pratcom\EtudesDeCas\AI;

defined( 'ABSPATH' ) || exit;

const OWNERS              = [ 'mpratte@pratcom.net' ];
const EXTRA_EMAILS_OPTION = 'pedc_ai_extra_emails';

function current_email(): string {
	$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
	return ( $user && ! empty( $user->user_email ) ) ? strtolower( (string) $user->user_email ) : '';
}

function is_owner(): bool {
	$email = current_email();
	return '' !== $email && in_array( $email, OWNERS, true );
}

/** Emails added by the owner. */
function extra_emails(): array {
	$list = get_option( EXTRA_EMAILS_OPTION, [] );
	$list = is_array( $list ) ? $list : [];
	return array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $list ) ), 'is_email' ) ) );
}

/** Whether the current user may use the assistant. */
function has_access(): bool {
	$email = current_email();
	if ( '' === $email || ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	/**
	 * Emails allowed to use the assistant (lowercase).
	 *
	 * @param string[] $emails Owner and added emails.
	 */
	$allowed = (array) apply_filters( 'pedc_ai_allowed_emails', array_merge( OWNERS, extra_emails() ) );
	return in_array( $email, array_map( 'strtolower', array_map( 'strval', $allowed ) ), true );
}

/** Add or remove an email (owner only). */
add_action( 'admin_post_pedc_ai_access', static function () {
	if ( ! is_owner() ) {
		wp_die( esc_html( is_fr() ? 'Réservé au propriétaire du plugin.' : 'Reserved to the plugin owner.' ), 403 );
	}
	check_admin_referer( 'pedc_ai_access' );

	$list   = extra_emails();
	$notice = '';
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
	if ( isset( $_POST['pedc_remove'] ) ) {
		$remove = strtolower( sanitize_email( wp_unslash( $_POST['pedc_remove'] ) ) );
		$list   = array_values( array_diff( $list, [ $remove ] ) );
		$notice = 'removed';
	} else {
		$email = strtolower( sanitize_email( wp_unslash( $_POST['pedc_email'] ?? '' ) ) );
		if ( ! is_email( $email ) ) {
			$notice = 'invalid';
		} elseif ( in_array( $email, OWNERS, true ) || in_array( $email, $list, true ) ) {
			$notice = 'exists';
		} else {
			$list[] = $email;
			$notice = 'added';
		}
	}
	// phpcs:enable
	update_option( EXTRA_EMAILS_OPTION, $list, false );

	wp_safe_redirect( add_query_arg( 'pedc_access', $notice, page_url() ) . '#pedc-access' );
	exit;
} );

/** State of an email on this site. */
function access_state( string $email, bool $fr ): string {
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return $fr ? 'aucun compte WordPress avec cette adresse sur ce site' : 'no WordPress account with this address on this site';
	}
	if ( ! user_can( $user, 'edit_posts' ) ) {
		return $fr ? 'compte trouvé, mais son rôle ne permet pas de modifier des études' : 'account found, but its role cannot edit case studies';
	}
	return $fr ? 'actif' : 'active';
}

/** The "Access" box, owner only. */
function render_access_box(): void {
	if ( ! is_owner() ) {
		return;
	}
	$fr   = is_fr();
	$list = extra_emails();
	$done = isset( $_GET['pedc_access'] ) ? sanitize_key( wp_unslash( $_GET['pedc_access'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$msgs = [
		'added'   => $fr ? 'Accès ajouté.' : 'Access added.',
		'removed' => $fr ? 'Accès retiré.' : 'Access removed.',
		'invalid' => $fr ? 'Adresse courriel invalide.' : 'Invalid email address.',
		'exists'  => $fr ? 'Cette adresse a déjà accès.' : 'This address already has access.',
	];

	echo '<details id="pedc-access" class="pedc-ai-box"' . ( '' !== $done ? ' open' : '' ) . '><summary>' . esc_html( $fr ? 'Accès à l\'assistant' : 'Assistant access' ) . '</summary>';
	if ( isset( $msgs[ $done ] ) ) {
		echo '<p><strong>' . esc_html( $msgs[ $done ] ) . '</strong></p>';
	}
	echo '<p>' . esc_html( $fr ? 'Visible par toi seulement. Les personnes ajoutées ici utilisent l\'assistant, mais ne voient pas cette boîte. Il leur faut un compte WordPress avec cette adresse et un rôle qui modifie des études (Auteur, Éditeur ou Administrateur). Les autres gardent les écrans WordPress habituels.' : 'Visible to you only. People added here use the assistant but do not see this box. They need a WordPress account with this address and a role that can edit case studies (Author, Editor or Administrator). Everyone else keeps the usual WordPress screens.' ) . '</p>';

	echo '<ul class="pedc-ai-access-list">';
	foreach ( OWNERS as $owner ) {
		echo '<li><strong>' . esc_html( $owner ) . '</strong> <span class="description">(' . esc_html( $fr ? 'propriétaire' : 'owner' ) . ')</span></li>';
	}
	foreach ( $list as $email ) {
		// One small form per address, so Enter in the add field never removes anyone.
		echo '<li><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pedc_ai_access' );
		echo '<input type="hidden" name="action" value="pedc_ai_access" />';
		echo esc_html( $email ) . ' <span class="description">(' . esc_html( access_state( $email, $fr ) ) . ')</span> ';
		echo '<button type="submit" name="pedc_remove" value="' . esc_attr( $email ) . '" class="button button-small">' . esc_html( $fr ? 'Retirer' : 'Remove' ) . '</button></form></li>';
	}
	echo '</ul>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'pedc_ai_access' );
	echo '<input type="hidden" name="action" value="pedc_ai_access" />';
	echo '<p><label for="pedc-access-email">' . esc_html( $fr ? 'Ajouter une adresse courriel' : 'Add an email address' ) . '</label><br />';
	echo '<input type="email" id="pedc-access-email" name="pedc_email" class="regular-text" placeholder="nom@exemple.com" required /> ';
	echo '<button type="submit" class="button button-primary">' . esc_html( $fr ? 'Ajouter' : 'Add' ) . '</button></p>';
	echo '</form>';
	echo '</details>';
}
