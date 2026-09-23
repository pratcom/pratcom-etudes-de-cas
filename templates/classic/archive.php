<?php
/**
 * Case studies list, sector and service archives (classic themes).
 * Override: copy to your theme as archive-etude_de_cas.php
 * or pratcom-etudes-de-cas/archive.php.
 */

defined( 'ABSPATH' ) || exit;

use function Pratcom\EtudesDeCas\render_card;
use function Pratcom\EtudesDeCas\render_sector_filter;

get_header();
?>
<main id="content" class="site-main pedc-page pedc-page--archive">
	<header class="pedc-archive__header">
		<h1 class="pedc-archive__title entry-title">
			<?php
			if ( is_tax() ) {
				single_term_title();
			} else {
				post_type_archive_title();
			}
			?>
		</h1>
		<?php
		$pedc_desc = is_tax() ? term_description() : '';
		if ( $pedc_desc ) {
			echo '<div class="pedc-archive__description">' . wp_kses_post( $pedc_desc ) . '</div>';
		}
		echo render_sector_filter(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="pedc-grid" style="--pedc-cols:3">
			<?php
			while ( have_posts() ) :
				the_post();
				echo render_card( get_post(), 'h2' ); // phpcs:ignore WordPress.Security.EscapeOutput
			endwhile;
			?>
		</div>
		<?php
		the_posts_pagination( [
			'class'     => 'pedc-pagination',
			'prev_text' => __( 'Previous', 'pratcom-etudes-de-cas' ),
			'next_text' => __( 'Next', 'pratcom-etudes-de-cas' ),
		] );
		?>
	<?php else : ?>
		<p class="pedc-archive__empty"><?php esc_html_e( 'No case studies found.', 'pratcom-etudes-de-cas' ); ?></p>
	<?php endif; ?>
</main>
<?php
get_footer();
