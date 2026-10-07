<?php
/**
 * The kinds of email the plugin sends.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Class Email_Kinds
 *
 * The one definition of each kind of email: its label, whether it is a
 * notification or a requested email, its default subject and body, and the
 * merge tags it takes. A kind's key is its only name, used as the template
 * key, email job type and log event type.
 *
 * @since 0.4.0
 *
 * @package PhotoCompetitionManager\Service
 */
class Email_Kinds {

	/**
	 * Every kind of email, keyed by kind.
	 *
	 * Each kind has a label and description for the Email Templates screen,
	 * whether it is a notification (which an admin can switch off) and, if so,
	 * whether it is on by default, its default subject and body, and its own
	 * merge tags. A tag's type is text, link or html. A kind sent to members in
	 * bulk by an email job has the job's wording and the admin page that sends it.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array(
			'upload_reminder'      => array(
				'label'        => __( 'Upload link', 'photo-competition-manager' ),
				'description'  => __( 'Sent to members with a link to upload their images.', 'photo-competition-manager' ),
				'notification' => false,
				'subject'      => __( 'Upload your images for {competition_title}', 'photo-competition-manager' ),
				'body'         => self::body(
					__( 'Hi {member_name},', 'photo-competition-manager' ),
					__( 'Here is your link to upload images for {competition_title}.', 'photo-competition-manager' ),
					self::button( '{upload_link}', __( 'Upload images', 'photo-competition-manager' ) ),
					__( 'This link stays active for 14 days, so you can come back and carry on uploading.', 'photo-competition-manager' ),
					__( 'Once voting opens, you can vote at {voting_page}', 'photo-competition-manager' ),
					__( "If you didn't ask for this email, your club's competitions officer may have sent it. You can ignore it if you don't want to enter this competition.", 'photo-competition-manager' ),
					__( 'If you have any questions, please contact your club competitions officer.', 'photo-competition-manager' )
				),
				'job'          => array(
					'page'    => 'photo-competition-manager',
					'sending' => __( 'Sending upload link emails...', 'photo-competition-manager' ),
					'sent'    => __( 'Upload link emails sent.', 'photo-competition-manager' ),
					/* translators: 1: Competition title, 2: Members emailed so far, 3: Members in the job */
					'stopped' => __( 'Sending upload link emails for %1$s stopped at %2$d of %3$d.', 'photo-competition-manager' ),
				),
				'tags'         => array(
					'{upload_link}' => array(
						'type'        => 'link',
						'description' => __( "The member's own upload link.", 'photo-competition-manager' ),
					),
					'{voting_page}' => array(
						'type'        => 'link',
						'description' => __( 'The voting page.', 'photo-competition-manager' ),
					),
				),
			),
			'voting_opened'        => array(
				'label'         => __( 'Voting opened', 'photo-competition-manager' ),
				'description'   => __( 'Sent to every member when voting opens in a category.', 'photo-competition-manager' ),
				'notification'  => true,
				'on_by_default' => false,
				'subject'       => __( 'Voting is now open for {competition_title}', 'photo-competition-manager' ),
				'body'          => self::body(
					__( 'Hi {member_name},', 'photo-competition-manager' ),
					__( 'Voting is now open for {competition_title}. Visit the voting page to see the entries and cast your votes.', 'photo-competition-manager' ),
					self::button( '{voting_page}', __( 'Go to the voting page', 'photo-competition-manager' ) ),
					__( 'Voting closes on {close_date}.', 'photo-competition-manager' )
				),
				'job'           => array(
					'page'    => 'photo-competition-manager-voting',
					'sending' => __( 'Sending voting opened emails...', 'photo-competition-manager' ),
					'sent'    => __( 'Voting opened emails sent.', 'photo-competition-manager' ),
					/* translators: 1: Competition title, 2: Members emailed so far, 3: Members in the job */
					'stopped' => __( 'Sending voting opened emails for %1$s stopped at %2$d of %3$d.', 'photo-competition-manager' ),
				),
				'tags'          => array(
					'{voting_page}' => array(
						'type'        => 'link',
						'description' => __( 'The voting page.', 'photo-competition-manager' ),
					),
					'{close_date}'  => array(
						'type'        => 'text',
						'description' => __( "The competition's close date.", 'photo-competition-manager' ),
					),
				),
			),
			'voting_link'          => array(
				'label'        => __( 'Voting link', 'photo-competition-manager' ),
				'description'  => __( 'Sent to a member who asks for a voting link on the voting page.', 'photo-competition-manager' ),
				'notification' => false,
				'subject'      => __( 'Vote in {competition_title}', 'photo-competition-manager' ),
				'body'         => self::body(
					__( 'Hi {member_name},', 'photo-competition-manager' ),
					__( 'You asked to vote in {competition_title}. Click the button below to open the voting form.', 'photo-competition-manager' ),
					self::button( '{voting_link}', __( 'Vote now', 'photo-competition-manager' ) ),
					__( 'This link expires in 1 hour.', 'photo-competition-manager' ),
					__( "If you didn't ask for this link, you can ignore this email.", 'photo-competition-manager' )
				),
				'tags'         => array(
					'{voting_link}' => array(
						'type'        => 'link',
						'description' => __( "The member's own voting link.", 'photo-competition-manager' ),
					),
					'{close_date}'  => array(
						'type'        => 'text',
						'description' => __( "The competition's close date.", 'photo-competition-manager' ),
					),
				),
			),
			'results_published'    => array(
				'label'        => __( 'Results published', 'photo-competition-manager' ),
				'description'  => __( 'Sent to members with a private link to the results.', 'photo-competition-manager' ),
				'notification' => false,
				'subject'      => __( 'Results for {competition_title}', 'photo-competition-manager' ),
				'body'         => self::body(
					__( 'Hi {member_name},', 'photo-competition-manager' ),
					__( 'The results for {competition_title} are now available.', 'photo-competition-manager' ),
					self::button( '{results_page}', __( 'View the results', 'photo-competition-manager' ) ),
					__( "This is a private link. Please don't share it publicly.", 'photo-competition-manager' ),
					__( 'Thank you to everyone who took part.', 'photo-competition-manager' )
				),
				'job'          => array(
					'page'    => 'photo-competition-manager-results',
					'sending' => __( 'Sending results link emails...', 'photo-competition-manager' ),
					'sent'    => __( 'Results link emails sent.', 'photo-competition-manager' ),
					/* translators: 1: Competition title, 2: Members emailed so far, 3: Members in the job */
					'stopped' => __( 'Sending results link emails for %1$s stopped at %2$d of %3$d.', 'photo-competition-manager' ),
				),
				'tags'         => array(
					'{results_page}'       => array(
						'type'        => 'link',
						'description' => __( 'The private link to the results.', 'photo-competition-manager' ),
					),
					'{results_share_link}' => array(
						'type'        => 'link',
						'description' => __( 'The same as {results_page}.', 'photo-competition-manager' ),
						'alias_of'    => '{results_page}',
					),
				),
			),
			'results_detailed'     => array(
				'label'        => __( 'Detailed results', 'photo-competition-manager' ),
				'description'  => __( 'Sent to each member who entered, with the ranks, scores and votes for their images.', 'photo-competition-manager' ),
				'notification' => false,
				'subject'      => __( 'Results for {competition_title}', 'photo-competition-manager' ),
				'body'         => self::body(
					__( 'Hi {member_name},', 'photo-competition-manager' ),
					__( 'The results for {competition_title} are now available. Here are your results:', 'photo-competition-manager' )
				) . "\n\n{results_table}\n\n" . self::body(
					__( 'Thank you for taking part in this competition!', 'photo-competition-manager' )
				),
				'job'          => array(
					'page'    => 'photo-competition-manager-results',
					'sending' => __( 'Sending results emails...', 'photo-competition-manager' ),
					'sent'    => __( 'Email results sent successfully!', 'photo-competition-manager' ),
					/* translators: 1: Competition title, 2: Members emailed so far, 3: Members in the job */
					'stopped' => __( 'Sending results emails for %1$s stopped at %2$d of %3$d.', 'photo-competition-manager' ),
				),
				'tags'         => array(
					'{results_table}' => array(
						'type'        => 'html',
						'description' => __( "The ranks, scores and votes for the member's images.", 'photo-competition-manager' ),
					),
				),
			),
			'submission_confirmed' => array(
				'label'         => __( 'Submission confirmed', 'photo-competition-manager' ),
				'description'   => __( 'Sent to a member each time they upload an image.', 'photo-competition-manager' ),
				'notification'  => true,
				'on_by_default' => false,
				'subject'       => __( 'Image uploaded for {competition_title}', 'photo-competition-manager' ),
				'body'          => self::body(
					__( 'Hi {member_name},', 'photo-competition-manager' ),
					__( 'Your image has been uploaded to {competition_title} in the {category_name} category.', 'photo-competition-manager' ),
					__( 'You are entering in the {member_grade} grade.', 'photo-competition-manager' ),
					__( 'You have uploaded {current_count} of {quota} images for this category.', 'photo-competition-manager' ),
					__( 'Thank you for your entry!', 'photo-competition-manager' )
				),
				'tags'          => array(
					'{member_grade}'  => array(
						'type'        => 'text',
						'description' => __( "The member's grade.", 'photo-competition-manager' ),
					),
					'{category_name}' => array(
						'type'        => 'text',
						'description' => __( 'The category the image was uploaded to.', 'photo-competition-manager' ),
					),
					'{current_count}' => array(
						'type'        => 'text',
						'description' => __( 'How many images the member has uploaded to the category.', 'photo-competition-manager' ),
					),
					'{quota}'         => array(
						'type'        => 'text',
						'description' => __( 'How many images the category takes from each member.', 'photo-competition-manager' ),
					),
				),
			),
		);
	}

	/**
	 * One kind of email.
	 *
	 * @since 0.4.0
	 *
	 * @param string $kind Kind key.
	 * @return array<string, mixed>|null The kind, or null if there is no such kind.
	 */
	public static function get( string $kind ): ?array {
		return self::all()[ $kind ] ?? null;
	}

	/**
	 * The merge tags every kind takes, filled in by Email_Service::send().
	 *
	 * @since 0.4.0
	 *
	 * @return array<string, array{type: string, description: string}>
	 */
	public static function shared_tags(): array {
		return array(
			'{member_name}'       => array(
				'type'        => 'text',
				'description' => __( "The member's name.", 'photo-competition-manager' ),
			),
			'{competition_title}' => array(
				'type'        => 'text',
				'description' => __( "The competition's title.", 'photo-competition-manager' ),
			),
			'{site_name}'         => array(
				'type'        => 'text',
				'description' => __( "This site's name.", 'photo-competition-manager' ),
			),
		);
	}

	/**
	 * A default body from its paragraphs.
	 *
	 * @param string ...$paragraphs Paragraph text or markup.
	 * @return string
	 */
	private static function body( string ...$paragraphs ): string {
		return implode(
			"\n\n",
			array_map(
				function ( $paragraph ) {
					return '<p>' . $paragraph . '</p>';
				},
				$paragraphs
			)
		);
	}

	/**
	 * A link button.
	 *
	 * @param string $href  Link, usually a link tag.
	 * @param string $label Button text.
	 * @return string
	 */
	private static function button( string $href, string $label ): string {
		return '<a href="' . $href . '" style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;">' . esc_html( $label ) . '</a>';
	}
}
