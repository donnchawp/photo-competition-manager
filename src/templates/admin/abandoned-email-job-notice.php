<?php
/**
 * Notice for an email job that stopped partway, usually because its page was closed.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type string $message      What stopped and how far it got.
 *     @type string $carry_on_url Page that carries on sending the job.
 *     @type string $discard_url  Nonced URL that discards the job.
 * }
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-warning"><p>';
echo esc_html( $data['message'] ) . ' ';
echo '<a href="' . esc_url( $data['carry_on_url'] ) . '">' . esc_html__( 'Carry on', 'photo-competition-manager' ) . '</a>';
echo ' | ';
echo '<a href="' . esc_url( $data['discard_url'] ) . '">' . esc_html__( 'Discard', 'photo-competition-manager' ) . '</a>';
echo '</p></div>';
