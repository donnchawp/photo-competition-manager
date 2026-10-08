<?php
/**
 * Log retention section partial for the admin settings page.
 *
 * Reads $data['months'] (int: months logs are kept, 0 to keep them forever).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

$photo_comp_keep_forever = 0 === $data['months'];
$photo_comp_months       = $photo_comp_keep_forever ? 12 : $data['months'];

echo '<h2 id="log-retention">' . esc_html__( 'Logs', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'The plugin logs emails sent, uploads, votes and other events. Many log entries name a member and hold their email address. Entries older than the period you choose are deleted once a day.', 'photo-competition-manager' ) . '</p>';

echo '<fieldset>';
echo '<legend class="screen-reader-text">' . esc_html__( 'How long logs are kept', 'photo-competition-manager' ) . '</legend>';
echo '<p>';
echo '<label><input type="radio" name="log_retention" value="forever"' . checked( $photo_comp_keep_forever, true, false ) . ' /> ';
echo esc_html__( 'Keep logs forever', 'photo-competition-manager' ) . '</label>';
echo '</p>';
echo '<p>';
echo '<label><input type="radio" name="log_retention" value="months"' . checked( $photo_comp_keep_forever, false, false ) . ' /> ';
// One sentence with the box in it, so translators can place the box and the plural follows the saved number.
$photo_comp_months_box = '<input type="number" id="log_retention_months" name="log_retention_months" value="' . esc_attr( (string) $photo_comp_months ) . '" min="1" step="1" class="small-text" aria-label="' . esc_attr__( 'Number of months', 'photo-competition-manager' ) . '" />';
/* translators: %s: box for the number of months */
$photo_comp_months_text = esc_html( _n( 'Delete log entries older than %s month', 'Delete log entries older than %s months', $photo_comp_months, 'photo-competition-manager' ) );
printf( $photo_comp_months_text, $photo_comp_months_box ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped text around an input built from escaped parts.
echo '</label>';
echo '</p>';
echo '</fieldset>';
