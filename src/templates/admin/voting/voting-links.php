<?php
/**
 * Voting links partial for the admin voting controls page: email every
 * voter their link for the category taking votes, once, or send one member
 * a new link when theirs didn't arrive.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type string                                $category_label Label of the category taking votes.
 *     @type string                                $email_all_url  Nonced URL that emails every voter, or '' once sent.
 *     @type string                                $sent_at        When the links were sent, formatted, or ''.
 *     @type array<int, array{id: int, name: string}> $members     Members who can be sent a link now.
 *     @type array<string, string|int>             $form_args      Hidden fields for the one-member form.
 *     @type string                                $nonce_action   Nonce action for the one-member form.
 * }
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

echo '<div class="postbox photo-comp-voting-links">';
echo '<div class="inside">';
echo '<h2>' . esc_html__( 'Voting links', 'photo-competition-manager' ) . '</h2>';

if ( '' !== $data['email_all_url'] ) {
	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: category label */
			__( 'Email every member who may vote their own link for %s. You can do this once per category: sending again would stop the links of members who are part-way through voting.', 'photo-competition-manager' ),
			$data['category_label']
		)
	) . '</p>';
	echo '<p><a class="button button-primary" href="' . esc_url( $data['email_all_url'] ) . '">' . esc_html__( 'Email voting links', 'photo-competition-manager' ) . '</a></p>';
} else {
	echo '<p>' . esc_html(
		sprintf(
			/* translators: %s: time the links were sent */
			__( 'Links sent at %s.', 'photo-competition-manager' ),
			$data['sent_at']
		)
	) . '</p>';
}

if ( ! empty( $data['members'] ) ) {
	echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
	foreach ( $data['form_args'] as $name => $value ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
	}
	wp_nonce_field( $data['nonce_action'], '_wpnonce', false );
	echo '<p><label for="photo-comp-voting-link-member">' . esc_html__( 'Send a new link to one member whose link didn\'t arrive', 'photo-competition-manager' ) . '</label><br />';
	echo '<select id="photo-comp-voting-link-member" name="member">';
	foreach ( $data['members'] as $member ) {
		echo '<option value="' . esc_attr( (string) $member['id'] ) . '">' . esc_html( $member['name'] ) . '</option>';
	}
	echo '</select> ';
	echo '<button type="submit" class="button">' . esc_html__( 'Send link', 'photo-competition-manager' ) . '</button></p>';
	echo '</form>';
}

echo '</div>';
echo '</div>';
