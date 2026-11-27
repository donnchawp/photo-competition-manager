<?php
/**
 * Email configuration section partial for the admin settings page.
 *
 * Reads $data['from_name'] (string), $data['from_email'] (string) and
 * $data['organiser_email'] (string).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Email Configuration', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Configure the sender name and email address for all competition emails. If left blank, WordPress defaults will be used.', 'photo-competition-manager' ) . '</p>';

echo '<p>';
echo '<label for="email_from_name">' . esc_html__( 'From Name', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="email_from_name" name="email_from_name" value="' . esc_attr( $data['from_name'] ) . '" class="regular-text" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '" />';
echo '<br /><span class="description">' . esc_html__( 'The name that appears as the sender in competition emails (e.g., "Photo Club Competitions").', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="email_from_email">' . esc_html__( 'From Email Address', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="email" id="email_from_email" name="email_from_email" value="' . esc_attr( $data['from_email'] ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '" />';
echo '<br /><span class="description">' . esc_html__( 'The email address that appears as the sender (e.g., "competitions@yourclub.org"). Leave blank to use WordPress default.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="email_organiser_email">' . esc_html__( 'Organiser Email Address', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="email" id="email_organiser_email" name="email_organiser_email" value="' . esc_attr( $data['organiser_email'] ) . '" class="regular-text" />';
echo '<br /><span class="description">' . esc_html__( 'Where emails for the competition organiser go, such as a new registration. Leave blank to use the From Email Address.', 'photo-competition-manager' ) . '</span>';
echo '</p>';
