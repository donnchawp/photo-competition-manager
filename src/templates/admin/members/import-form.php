<?php
/**
 * Import-members-from-CSV form partial for the admin members page.
 *
 * Reads no $data keys.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

$sample_url = wp_nonce_url(
	add_query_arg(
		array(
			'page'   => 'photo-competition-manager-members',
			'action' => 'download_sample_csv',
		),
		admin_url( 'admin.php' )
	),
	'photo_competition_download_sample'
);

echo '<div style="margin-top: 30px;">';
echo '<h2>' . esc_html__( 'Import Members from CSV', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Upload a CSV file to import multiple members at once. Existing members (matched by email) will be updated.', 'photo-competition-manager' ) . '</p>';

echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 600px; padding: 16px; margin-top: 10px;">';
wp_nonce_field( 'photo_competition_import_members', 'photo_competition_import_nonce' );
echo '<input type="hidden" name="photo_competition_action" value="import_members_csv" />';
echo '<input type="hidden" name="page" value="photo-competition-manager-members" />';

echo '<p>';
echo '<label for="csv_file">' . esc_html__( 'CSV File', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="file" id="csv_file" name="csv_file" accept=".csv,.txt" required />';
echo '</p>';

echo '<p class="description">';
echo esc_html__( 'CSV format: name,email,grade,active (active: 1=active, 0=inactive)', 'photo-competition-manager' );
echo '<br />';
echo '<a href="' . esc_url( $sample_url ) . '">' . esc_html__( 'Download sample CSV template', 'photo-competition-manager' ) . '</a>';
echo '</p>';

submit_button( __( 'Import Members', 'photo-competition-manager' ), 'secondary', 'submit', false );

echo '</form>';
echo '</div>';
