<?php
/**
 * Import-members-from-CSV form partial for the admin members page.
 *
 * Reads $data['type_example'] (string): one of the club's type names, or ''
 * when it has only one type, so there's no type column.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

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
echo esc_html(
	'' !== $data['type_example']
		? __( 'Columns, in this order: name, email, grade, active, committee, type. A file with a header row can put its columns in any order.', 'photo-competition-manager' )
		: __( 'Columns, in this order: name, email, grade, active, committee. A file with a header row can put its columns in any order.', 'photo-competition-manager' )
);
echo '<br />';
echo esc_html__( 'Grade: one of the grades in Settings, by name or slug. New members need one. Leave it blank to keep an existing member\'s grade.', 'photo-competition-manager' );
echo '<br />';
echo esc_html__( 'Active and committee: 1 for yes, 0 for no. A blank cell means no, so a blank active cell makes the member inactive. A row that ends before these columns makes the member active and not on the committee.', 'photo-competition-manager' );
echo '<br />';
if ( '' !== $data['type_example'] ) {
	echo esc_html(
		sprintf(
			/* translators: %s: example of a competition type name */
			__( 'Type: one competition type name, such as %s. It replaces the member\'s type. Leave the cell blank to keep an existing member\'s type; a new member without one gets none, and no links for competitions open to all until you give them one.', 'photo-competition-manager' ),
			$data['type_example']
		)
	);
	echo '<br />';
}
echo '<a href="' . esc_url( $sample_url ) . '">' . esc_html__( 'Download sample CSV template', 'photo-competition-manager' ) . '</a>';
echo '</p>';

submit_button( __( 'Import Members', 'photo-competition-manager' ), 'secondary', 'submit', false );

echo '</form>';
echo '</div>';
