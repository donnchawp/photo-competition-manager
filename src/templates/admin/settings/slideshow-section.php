<?php
/**
 * Slideshow section partial for the admin settings page.
 *
 * Reads $data['meter_types'] (array<string,string>, meter-type slug =>
 * label), $data['progress_meter_type'] (string), $data['preview_duration'],
 * $data['voting_duration'], and $data['critique_duration'] (all int).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Slideshow', 'photo-competition-manager' ) . '</h2>';

echo '<p>';
echo '<label>' . esc_html__( 'Progress Meter Style', 'photo-competition-manager' ) . '</label>';
echo '</p>';

echo '<div class="progress-meter-selector" style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;">';

foreach ( $data['meter_types'] as $meter_type_slug => $meter_type_label ) {
	$is_active = ( $meter_type_slug === $data['progress_meter_type'] ) ? ' active' : '';
	echo '<label class="progress-meter-card' . esc_attr( $is_active ) . '" style="cursor: pointer; border: 2px solid ' . ( $is_active ? '#0073aa' : '#ddd' ) . '; border-radius: 8px; padding: 12px; text-align: center; background: #1a1a1a; min-width: 140px; transition: border-color 0.2s;">';
	echo '<input type="radio" name="progress_meter_type" value="' . esc_attr( $meter_type_slug ) . '"' . checked( $data['progress_meter_type'], $meter_type_slug, false ) . ' style="display: none;" />';
	echo '<div class="meter-preview" data-meter-type="' . esc_attr( $meter_type_slug ) . '" style="height: 50px; position: relative; margin-bottom: 8px; overflow: hidden; border-radius: 4px;"></div>';
	echo '<span style="color: #666; font-size: 13px; font-weight: 600;">' . esc_html( $meter_type_label ) . '</span>';
	echo '</label>';
}

echo '</div>';
echo '<span class="description">' . esc_html__( 'Choose the progress indicator style shown during the slideshow.', 'photo-competition-manager' ) . '</span>';

echo '<table class="form-table" style="margin-top: 16px;"><tbody>';
?>
		<tr>
			<th scope="row">
				<label for="preview_duration"><?php esc_html_e( 'Preview Duration', 'photo-competition-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="preview_duration" name="preview_duration" value="<?php echo esc_attr( $data['preview_duration'] ); ?>" min="0" max="120" step="1" class="small-text" />
				<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="voting_duration"><?php esc_html_e( 'Voting Slideshow Duration', 'photo-competition-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="voting_duration" name="voting_duration" value="<?php echo esc_attr( $data['voting_duration'] ); ?>" min="0" max="120" step="1" class="small-text" />
				<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="critique_duration"><?php esc_html_e( 'Critique Duration', 'photo-competition-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="critique_duration" name="critique_duration" value="<?php echo esc_attr( $data['critique_duration'] ); ?>" min="0" max="120" step="1" class="small-text" />
				<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
			</td>
		</tr>
		<?php
		echo '</tbody></table>';
