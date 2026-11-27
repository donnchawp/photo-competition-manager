<?php
/**
 * Warning about members' type tags, for the admin members page: those with no
 * type, or those tagged as both a club and a person.
 *
 * Reads $data['message'] (string) and $data['url'] (string) of the list of those members.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-warning"><p>';
echo esc_html( $data['message'] );
echo ' <a href="' . esc_url( $data['url'] ) . '">' . esc_html__( 'Show them', 'photo-competition-manager' ) . '</a>';
echo '</p></div>';
