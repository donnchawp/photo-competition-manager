<?php
/**
 * How long logs are kept, for the admin logs page.
 *
 * Reads $data['months'] (int: months logs are kept, 0 to keep them forever)
 * and $data['settings_url'] (string: where the period is set).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<p class="description">';
if ( 0 === $data['months'] ) {
	echo esc_html__( 'Logs are kept forever.', 'photo-competition-manager' );
} else {
	echo esc_html(
		sprintf(
			/* translators: %d: number of months */
			_n( 'Log entries older than %d month are deleted once a day.', 'Log entries older than %d months are deleted once a day.', $data['months'], 'photo-competition-manager' ),
			$data['months']
		)
	);
}
echo ' <a href="' . esc_url( $data['settings_url'] ) . '">' . esc_html__( 'Change how long logs are kept', 'photo-competition-manager' ) . '</a>';
echo '</p>';
