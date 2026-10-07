<?php
/**
 * Email service for sending notifications.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Support\Email_Configuration;
use WP_Error;

/**
 * Class Email_Service
 *
 * @package PhotoCompetitionManager\Service
 */
class Email_Service {

	/**
	 * Event logger.
	 *
	 * @var Event_Logger
	 */
	private $event_logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->event_logger = new Event_Logger();
	}

	/**
	 * Send a member one kind of email.
	 *
	 * The saved template is laid over the kind's default. A notification that
	 * is switched off is skipped. A requested email always sends.
	 *
	 * @since 0.4.0
	 *
	 * @param string                $kind        Kind key, from Email_Kinds.
	 * @param object                $member      Member row.
	 * @param object|null           $competition Competition row, if the email is about one.
	 * @param array<string, string> $tags        Values for the kind's own merge tags, all of them.
	 * @return string|WP_Error 'sent', 'skipped', or why it wasn't sent.
	 */
	public function send( string $kind, object $member, ?object $competition, array $tags ) {
		$definition = Email_Kinds::get( $kind );
		if ( ! $definition ) {
			return new WP_Error( 'unknown_email_kind', sprintf( 'Unknown email kind "%s"', $kind ) );
		}

		// An alias is another name for a tag, kept for saved templates. The caller doesn't pass it.
		$aliases = array();
		foreach ( $definition['tags'] as $tag => $spec ) {
			if ( ! empty( $spec['alias_of'] ) ) {
				$aliases[ $tag ] = $spec['alias_of'];
			}
		}

		$declared = array_keys( array_diff_key( $definition['tags'], $aliases ) );
		if ( array_diff( $declared, array_keys( $tags ) ) || array_diff( array_keys( $tags ), $declared ) ) {
			return new WP_Error(
				'wrong_email_tags',
				sprintf( 'The "%s" email takes the tags %s, but was given %s', $kind, implode( ' ', $declared ), implode( ' ', array_keys( $tags ) ) )
			);
		}

		foreach ( $aliases as $alias => $tag ) {
			$tags[ $alias ] = $tags[ $tag ];
		}

		if ( ! $this->is_template_enabled( $kind ) ) {
			return 'skipped';
		}

		$template = $this->get_kind_template( $kind, $definition );

		$member_name = '' !== (string) ( $member->name ?? '' ) ? (string) $member->name : (string) $member->email;
		$values      = array(
			'{member_name}'       => $member_name,
			'{competition_title}' => $competition ? (string) $competition->title : '',
			'{site_name}'         => get_bloginfo( 'name' ),
		) + $tags;
		$types       = array_map(
			function ( $tag ) {
				return $tag['type'];
			},
			Email_Kinds::shared_tags() + $definition['tags']
		);

		$escaped = array();
		$html    = array();
		foreach ( $values as $tag => $value ) {
			if ( 'html' === $types[ $tag ] ) {
				// After wpautop(), so it isn't reformatted. A tag on its own line
				// comes out of wpautop() as a paragraph, which it replaces.
				$html[ '<p>' . $tag . '</p>' ] = $value;
				$html[ $tag ]                  = $value;
				$values[ $tag ]                = '';
			} else {
				$escaped[ $tag ] = 'link' === $types[ $tag ] ? esc_url( $value ) : esc_html( $value );
			}
		}

		$subject = $this->replace_merge_tags( $template['subject'], $values );
		$body    = wpautop( $this->replace_merge_tags( wp_kses_post( $template['body'] ), $escaped ) );
		$message = $this->wrap_html_email( $this->replace_merge_tags( $body, $html ) );

		$sent = $this->send_mail( $member->email, $this->prefix_subject( $subject ), $message, array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( ! $sent ) {
			return new WP_Error( 'send_failed', 'wp_mail() failed' );
		}

		$this->event_logger->log_email_sent(
			$competition ? (int) $competition->id : null,
			$kind,
			$member_name,
			array( 'email' => $member->email )
		);

		return 'sent';
	}

	/**
	 * Whether a kind of email is sent.
	 *
	 * Only a notification can be switched off. A requested email is always on.
	 *
	 * @since 0.3.0
	 * @since 0.4.0 Uses the notification's default when nothing is saved, and
	 *              ignores a saved "off" for a requested email.
	 *
	 * @param string $template_key Kind key.
	 * @return bool
	 */
	public function is_template_enabled( string $template_key ): bool {
		$definition = Email_Kinds::get( $template_key );
		if ( ! $definition ) {
			return false;
		}

		if ( ! $definition['notification'] ) {
			return true;
		}

		$saved = get_option( 'photo_comp_email_templates', array() )[ $template_key ] ?? array();

		return (bool) ( $saved['enabled'] ?? $definition['on_by_default'] );
	}

	/**
	 * A kind's saved template laid over its default.
	 *
	 * @param string               $kind       Kind key.
	 * @param array<string, mixed> $definition The kind, from Email_Kinds.
	 * @return array{subject: string, body: string}
	 */
	private function get_kind_template( string $kind, array $definition ): array {
		$saved = get_option( 'photo_comp_email_templates', array() )[ $kind ] ?? array();

		return array(
			'subject' => ! empty( $saved['subject'] ) ? (string) $saved['subject'] : $definition['subject'],
			'body'    => ! empty( $saved['body'] ) ? (string) $saved['body'] : $definition['body'],
		);
	}

	/**
	 * Replace merge tags in a string.
	 *
	 * @param string               $content    Content with merge tags.
	 * @param array<string, mixed> $merge_data Merge tag data.
	 * @return string Content with merge tags replaced.
	 */
	private function replace_merge_tags( string $content, array $merge_data ): string {
		return str_replace( array_keys( $merge_data ), array_values( $merge_data ), $content );
	}

	/**
	 * Prefix email subject with site title.
	 *
	 * @param string $subject The email subject.
	 * @return string Subject prefixed with [Site Title].
	 */
	private function prefix_subject( string $subject ): string {
		$site_title = get_bloginfo( 'name' );
		return sprintf( '[%s] %s', $site_title, $subject );
	}

	/**
	 * Wrap content in HTML email template.
	 *
	 * @param string $content Email body content.
	 * @return string Wrapped HTML email.
	 */
	private function wrap_html_email( string $content ): string {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
		</head>
		<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
			<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
				<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered and escaped by the caller. ?>

				<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

				<p style="color: #999; font-size: 12px;">
					<?php
					printf(
						/* translators: %s: Site name */
						esc_html__( 'This email was sent by %s', 'photo-competition-manager' ),
						esc_html( get_bloginfo( 'name' ) )
					);
					?>
				</p>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Send an email with plugin context flag set.
	 *
	 * Wraps wp_mail to ensure Email_Configuration knows this is a plugin email.
	 *
	 * @param string       $to      Recipient email address.
	 * @param string       $subject Email subject.
	 * @param string       $message Email body.
	 * @param array|string $headers Optional headers.
	 * @return bool Whether the email was sent successfully.
	 */
	private function send_mail( string $to, string $subject, string $message, $headers = array() ): bool {
		Email_Configuration::begin_plugin_email();
		$result = wp_mail( $to, $subject, $message, $headers );
		Email_Configuration::end_plugin_email();
		return $result;
	}
}
