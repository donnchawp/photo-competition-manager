<?php
/**
 * The member page: every image a member has entered, reached by an emailed link.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Service\Member_Entries;
use PhotoCompetitionManager\Service\Member_Page_Link;

use function PhotoCompetitionManager\Support\format_site_date;

/**
 * Shortcode [competition_my_entries]. Without a link it asks for an email
 * address and sends a link; with a valid link it lists the member's entries.
 *
 * @since 0.5.0
 */
class Member_Page_Shortcode {

	const SHORTCODE = 'competition_my_entries';

	/**
	 * Member page links.
	 *
	 * @var Member_Page_Link
	 */
	private $link;

	/**
	 * Constructor.
	 *
	 * @param Member_Page_Link|null $link Member page links.
	 */
	public function __construct( ?Member_Page_Link $link = null ) {
		$this->link = $link ? $link : new Member_Page_Link();
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, string>|string $atts Shortcode attributes (unused).
	 * @return string
	 */
	public function render( $atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Shortcode signature requires $atts.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is a WP Super Cache constant.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

		$page_url = (string) get_permalink();
		$message  = '';

		if ( isset( $_POST['photo_comp_member_link'] ) && check_admin_referer( 'photo_comp_member_link', 'photo_comp_member_link_nonce' ) ) {
			$email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
			$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$this->link->request( $email, $ip, $page_url );

			$message = '<p class="success">' . esc_html__( "If that address is on our list, we've sent you a link to your entries. It works for 7 days.", 'photo-competition-manager' ) . '</p>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key is its own authentication.
		$key    = isset( $_GET[ Member_Page_Link::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ Member_Page_Link::QUERY_ARG ] ) ) : '';
		$member = '' !== $key ? $this->link->member( $key ) : null;

		if ( '' !== $key && ! $member && '' === $message ) {
			$message = '<p class="notice">' . esc_html__( "This link has expired or isn't valid. Enter your email address to get a new one.", 'photo-competition-manager' ) . '</p>';
		}

		ob_start();
		echo '<div class="photo-comp-member-page">';
		echo wp_kses_post( $message );

		if ( $member ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sort order only.
			$sort = isset( $_GET['sort'] ) && Member_Entries::SORT_POSITION === $_GET['sort'] ? Member_Entries::SORT_POSITION : Member_Entries::SORT_DATE;
			$this->render_entries( ( new Member_Entries() )->for_member( $member, $sort ), $sort, add_query_arg( Member_Page_Link::QUERY_ARG, $key, $page_url ) );
		} else {
			$this->render_form();
		}

		echo '</div>';
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}

	/**
	 * The form asking for an email address.
	 *
	 * @return void
	 */
	private function render_form(): void {
		?>
		<form method="post" class="photo-comp-member-link-form">
			<?php wp_nonce_field( 'photo_comp_member_link', 'photo_comp_member_link_nonce' ); ?>
			<p><?php esc_html_e( "Enter the email address you're registered with and we'll send you a link to every image you've entered.", 'photo-competition-manager' ); ?></p>
			<p>
				<label for="photo-comp-member-email"><?php esc_html_e( 'Your email address', 'photo-competition-manager' ); ?></label>
				<input type="email" id="photo-comp-member-email" name="member_email" required />
			</p>
			<p>
				<button type="submit" name="photo_comp_member_link" value="1" class="button"><?php esc_html_e( 'Send me a link', 'photo-competition-manager' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * The member's entries.
	 *
	 * @param array<int, object> $entries  Entries, from Member_Entries.
	 * @param string             $sort     Current sort.
	 * @param string             $base_url This page with the member's key.
	 * @return void
	 */
	private function render_entries( array $entries, string $sort, string $base_url ): void {
		$sorts = array(
			Member_Entries::SORT_DATE     => __( 'Newest first', 'photo-competition-manager' ),
			Member_Entries::SORT_POSITION => __( 'Best position first', 'photo-competition-manager' ),
		);
		?>
		<h2><?php esc_html_e( 'Your entries', 'photo-competition-manager' ); ?></h2>
		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( "You haven't entered any images yet.", 'photo-competition-manager' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<p class="photo-comp-member-sort">
			<?php esc_html_e( 'Sort:', 'photo-competition-manager' ); ?>
			<?php foreach ( $sorts as $value => $label ) : ?>
				<?php if ( $value === $sort ) : ?>
					<strong><?php echo esc_html( $label ); ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'sort', $value, $base_url ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</p>
		<ul class="photo-comp-member-entries">
			<?php foreach ( $entries as $entry ) : ?>
				<li class="photo-comp-member-entry">
					<?php if ( '' !== $entry->thumb ) : ?>
						<a href="<?php echo esc_url( $entry->full ); ?>" target="_blank" rel="noopener noreferrer">
							<img src="<?php echo esc_url( $entry->thumb ); ?>" alt="<?php echo esc_attr( $entry->competition_title . ' — ' . $entry->category ); ?>" loading="lazy" />
						</a>
					<?php else : ?>
						<div class="photo-comp-member-no-image"><?php esc_html_e( 'Image no longer available', 'photo-competition-manager' ); ?></div>
					<?php endif; ?>
					<div class="photo-comp-member-entry-details">
						<strong><?php echo esc_html( $entry->competition_title ); ?></strong>
						<span class="photo-comp-member-date"><?php echo esc_html( format_site_date( $entry->competition_date ) ); ?></span>
						<span><?php echo esc_html( $entry->category ); ?></span>
						<?php if ( '' !== $entry->photographer ) : ?>
							<span><?php echo esc_html( $entry->photographer ); ?></span>
						<?php endif; ?>
						<?php if ( 'entered' !== $entry->status ) : ?>
							<span class="photo-comp-member-status"><?php echo esc_html( Images_Repository::entry_status_label( $entry->status ) ); ?></span>
						<?php endif; ?>
						<?php if ( null !== $entry->position ) : ?>
							<span class="photo-comp-member-result">
								<?php
								/* translators: 1: position, 2: grade name, 3: total score */
								echo esc_html( sprintf( __( 'Position %1$d in %2$s, total score %3$d', 'photo-competition-manager' ), $entry->position, $entry->grade, $entry->score ) );
								?>
							</span>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}
}
