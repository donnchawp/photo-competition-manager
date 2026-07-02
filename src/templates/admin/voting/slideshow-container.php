<?php
/**
 * Slideshow container partial for the admin voting controls page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;
?>
		<div id="photo-comp-slideshow-modal" class="slideshow-display" style="display: none;">
			<div class="slideshow-image-container">
				<img src="" alt="" class="slideshow-current-image" />
				<div class="slideshow-image-info">
					<span class="image-number"></span>
				</div>
			</div>
			<div class="slideshow-progress">
				<div class="progress-bar" style="width: 0%;"></div>
			</div>
			<button type="button" class="slideshow-exit" aria-label="<?php esc_attr_e( 'Exit slideshow', 'photo-competition-manager' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
			<div class="slideshow-controls-overlay">
				<button type="button" class="button button-large slideshow-pause">
		<?php esc_html_e( 'Pause', 'photo-competition-manager' ); ?>
				</button>
				<button type="button" class="button button-large slideshow-resume" style="display: none;">
		<?php esc_html_e( 'Resume', 'photo-competition-manager' ); ?>
				</button>
				<button type="button" class="button button-large button-primary slideshow-stop">
		<?php esc_html_e( 'Stop Slideshow', 'photo-competition-manager' ); ?>
				</button>
			</div>
		</div>
		