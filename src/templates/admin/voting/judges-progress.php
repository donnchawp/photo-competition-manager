<?php
/**
 * Judges' progress partial for the admin voting controls page.
 *
 * Shows whether each judge has scored the open category, and never the
 * scores themselves.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type array<int, array{name: string, submitted: bool}> $judges Judges assigned to the competition.
 * }
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="postbox photo-comp-judges-progress">';
echo '<div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Judge progress', 'photo-competition-manager' ) . '</h2></div>';
echo '<div class="inside">';
echo '<table class="widefat striped"><tbody>';
foreach ( $data['judges'] as $judge ) {
	echo '<tr>';
	echo '<td>' . esc_html( $judge['name'] ) . '</td>';
	echo '<td>' . ( $judge['submitted'] ? esc_html__( 'Scored', 'photo-competition-manager' ) : esc_html__( 'Waiting', 'photo-competition-manager' ) ) . '</td>';
	echo '</tr>';
}
echo '</tbody></table>';
echo '</div>';
echo '</div>';
