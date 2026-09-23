<?php
/**
 * Single case study (classic themes).
 * Override: copy to your theme as single-etude_de_cas.php
 * or pratcom-etudes-de-cas/single.php.
 */

defined( 'ABSPATH' ) || exit;

use function Pratcom\EtudesDeCas\render_sheet;
use function Pratcom\EtudesDeCas\render_related;
use function Pratcom\EtudesDeCas\setting;
use function Pratcom\EtudesDeCas\terms_of;
use function Pratcom\EtudesDeCas\term_links;

get_header();
?>
<main id="content" class="site-main pedc-page pedc-page--single">
	<?php
	while ( have_posts() ) :
		the_post();
		$pedc_sectors = terms_of( get_the_ID(), PEDC_TAX_SECTOR );
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'pedc-single' ); ?>>
			<header class="pedc-single__header">
				<?php if ( $pedc_sectors ) : ?>
					<p class="pedc-single__sector"><?php echo term_links( $pedc_sectors ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in term_links(). ?></p>
				<?php endif; ?>
				<?php the_title( '<h1 class="pedc-single__title entry-title">', '</h1>' ); ?>
				<?php if ( has_excerpt() ) : ?>
					<p class="pedc-single__lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
				<?php endif; ?>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="pedc-single__media"><?php the_post_thumbnail( 'large', [ 'loading' => 'eager', 'fetchpriority' => 'high' ] ); ?></figure>
			<?php endif; ?>

			<?php echo render_sheet( null, [ 'facts', 'results' ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<div class="pedc-single__content entry-content">
				<?php the_content(); ?>
			</div>

			<?php echo render_sheet( null, [ 'testimonial' ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<?php
			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
		</article>
		<?php
		if ( setting( 'related' ) ) {
			echo render_related(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	endwhile;
	?>
</main>
<?php
get_footer();
