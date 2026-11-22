<?php
/**
 * The member page: every image a member has entered, reached by an emailed link.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\API\Member_Visibility_API;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Service\Competition_Archive;
use PhotoCompetitionManager\Service\Member_Entries;
use PhotoCompetitionManager\Service\Member_Page_Link;
use PhotoCompetitionManager\Service\Member_Visibility;

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
	 * Query arg the no-JavaScript save redirects with: 'saved' or 'failed'.
	 */
	const SAVED_ARG = 'choices';

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
		add_action( 'template_redirect', array( $this, 'handle_visibility' ) );
	}

	/**
	 * Save the choices posted by the form that works without JavaScript,
	 * before the page renders, then go back to the page so a refresh can't
	 * post them again. Only a POST changes a choice: mail scanners open links.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function handle_visibility(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- The member's key in the URL authenticates; there's no login session to forge.
		$post = get_post();
		if ( ! isset( $_POST['photo_comp_visibility'] ) || ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) || ! Competition_Archive::settings()['enabled'] ) {
			return;
		}

		$key    = isset( $_GET[ Member_Page_Link::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ Member_Page_Link::QUERY_ARG ] ) ) : '';
		$member = '' !== $key ? $this->link->member( $key ) : null;
		if ( ! $member ) {
			return; // The page says the link has expired.
		}

		$args = array(
			Member_Page_Link::QUERY_ARG => $key,
			self::SAVED_ARG             => $this->save_visibility( $member ) ? 'saved' : 'failed',
		);
		if ( isset( $_GET['sort'] ) ) {
			$args['sort'] = sanitize_key( wp_unslash( $_GET['sort'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		wp_safe_redirect( add_query_arg( $args, (string) get_permalink( $post ) ) );
		exit;
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
			$this->link->request( $email, $page_url );

			$message = '<p class="success">' . esc_html__( "If that address is on our list, we've sent you a link to your entries. It works for 7 days.", 'photo-competition-manager' ) . '</p>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key is its own authentication.
		$key    = isset( $_GET[ Member_Page_Link::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ Member_Page_Link::QUERY_ARG ] ) ) : '';
		$member = '' !== $key ? $this->link->member( $key ) : null;

		if ( '' !== $key && ! $member && '' === $message ) {
			$message = '<p class="notice">' . esc_html__( "This link has expired or isn't valid. Enter your email address to get a new one.", 'photo-competition-manager' ) . '</p>';
		}

		// Past competitions controls, only while the club shows them.
		$shown = null;
		if ( $member && Competition_Archive::settings()['enabled'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which message to show, after handle_visibility() saved.
			$saved = isset( $_GET[ self::SAVED_ARG ] ) && is_string( $_GET[ self::SAVED_ARG ] ) ? sanitize_key( $_GET[ self::SAVED_ARG ] ) : '';
			if ( 'saved' === $saved ) {
				$message = '<p class="success">' . esc_html__( 'Your choices are saved.', 'photo-competition-manager' ) . '</p>';
			} elseif ( 'failed' === $saved ) {
				$message = '<p class="error">' . esc_html__( "Some of your choices couldn't be saved. Check them below and try again.", 'photo-competition-manager' ) . '</p>';
			}
			$shown = (bool) (int) $member->images_public;
		}

		ob_start();
		echo '<div class="photo-comp-member-page">';
		echo wp_kses_post( $message );

		if ( $member ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sort order only.
			$sort = isset( $_GET['sort'] ) && Member_Entries::SORT_POSITION === $_GET['sort'] ? Member_Entries::SORT_POSITION : Member_Entries::SORT_DATE;
			$this->render_entries( ( new Member_Entries() )->for_member( $member, $sort ), $sort, add_query_arg( Member_Page_Link::QUERY_ARG, $key, $page_url ), $shown, $key );
		} else {
			$this->render_form();
		}

		echo '</div>';
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}

	/**
	 * Save the choices posted by the form that works without JavaScript.
	 *
	 * @param object $member Member, from their key.
	 * @return bool Whether every choice was saved.
	 */
	private function save_visibility( object $member ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The member's key in the URL authenticates; there's no login session to forge.
		$visibility = new Member_Visibility();
		$saved      = true;

		if ( isset( $_POST['visibility_all'] ) ) {
			$all   = sanitize_key( wp_unslash( $_POST['visibility_all'] ) );
			$saved = $visibility->set_all_shown( $member, 'show' === $all );
		}

		$entries = isset( $_POST['visibility'] ) && is_array( $_POST['visibility'] ) ? wp_unslash( $_POST['visibility'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is compared with 'show' below.
		foreach ( $entries as $entry_id => $choice ) {
			$saved = $visibility->set_entry_shown( $member, absint( $entry_id ), 'show' === $choice ) && $saved;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $saved;
	}

	/**
	 * A Show / Hide radio pair.
	 *
	 * @param string $name  Field name.
	 * @param bool   $shown Whether Show is chosen.
	 * @param string $label What the pair is for, for screen readers.
	 * @return void
	 */
	private function render_show_hide( string $name, bool $shown, string $label ): void {
		?>
		<fieldset class="photo-comp-visibility-choice">
			<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="show"<?php checked( $shown ); ?> /> <?php esc_html_e( 'Show', 'photo-competition-manager' ); ?></label>
			<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="hide"<?php checked( ! $shown ); ?> /> <?php esc_html_e( 'Hide', 'photo-competition-manager' ); ?></label>
			<span class="photo-comp-visibility-status" aria-live="polite"></span>
		</fieldset>
		<?php
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
	 * @param string             $base_url   This page with the member's key.
	 * @param bool|null          $shown      Whether the member shows their images in past competitions, or null while past competitions are off.
	 * @param string             $key        The member's key, for saving choices in the background.
	 * @return void
	 */
	private function render_entries( array $entries, string $sort, string $base_url, ?bool $shown, string $key ): void {
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
		<?php if ( null !== $shown ) : ?>
			<?php wp_enqueue_script( 'photo-comp-member-visibility', PHOTO_COMPETITION_MANAGER_URL . 'assets/js/member-visibility.js', array(), PHOTO_COMPETITION_MANAGER_VERSION, true ); ?>
			<form method="post" action="<?php echo esc_url( add_query_arg( 'sort', $sort, $base_url ) ); ?>" class="photo-comp-visibility-form" data-endpoint="<?php echo esc_url( Member_Visibility_API::url() ); ?>" data-key="<?php echo esc_attr( $key ); ?>" data-saved="<?php esc_attr_e( 'Saved', 'photo-competition-manager' ); ?>" data-failed="<?php esc_attr_e( 'Not saved yet. Press Save below.', 'photo-competition-manager' ); ?>" data-expired="<?php esc_attr_e( 'This link has expired. Reload the page to get a new one.', 'photo-competition-manager' ); ?>">
				<input type="hidden" name="photo_comp_visibility" value="1" />
				<div class="photo-comp-visibility-all">
					<p><strong><?php esc_html_e( 'Your images in past competitions', 'photo-competition-manager' ); ?></strong></p>
					<?php $this->render_show_hide( 'visibility_all', $shown, __( 'All your images in past competitions', 'photo-competition-manager' ) ); ?>
					<p class="photo-comp-visibility-off-notice"<?php echo $shown ? ' hidden' : ''; ?>><?php esc_html_e( 'None of your images are public while this is set to Hide. Your choices for single images below are kept for when you show them again.', 'photo-competition-manager' ); ?></p>
				</div>
		<?php endif; ?>
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
						<?php if ( null !== $shown && $entry->may_show ) : ?>
							<?php
							/* translators: 1: competition title, 2: category name */
							$this->render_show_hide( 'visibility[' . $entry->id . ']', ! $entry->archive_hidden, sprintf( __( 'This image in past competitions: %1$s, %2$s', 'photo-competition-manager' ), $entry->competition_title, $entry->category ) );
							?>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( null !== $shown ) : ?>
				<p class="photo-comp-visibility-save"><button type="submit" class="button"><?php esc_html_e( 'Save', 'photo-competition-manager' ); ?></button></p>
			</form>
		<?php endif; ?>
		<?php
	}
}
