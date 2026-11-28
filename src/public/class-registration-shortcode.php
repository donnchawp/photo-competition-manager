<?php
/**
 * The registration page: people and clubs registering for competitions.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Service\Competition_Entry;
use PhotoCompetitionManager\Service\Competition_Labels;
use PhotoCompetitionManager\Service\Registration;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Support\Link_Request_Form;

use function PhotoCompetitionManager\Support\format_site_date;

/**
 * Shortcode [competition_registration]. Lists every competition open for
 * registration, each with its own form. The emailed link brings the person
 * back here to confirm, with a button: mail scanners open links, so only a
 * POST confirms. The rules are Registration's.
 *
 * @since 0.6.0
 */
class Registration_Shortcode {

	const SHORTCODE = 'competition_registration';

	/**
	 * The confirm button's name, and its nonce action.
	 */
	const CONFIRM = 'photo_comp_registration_confirm';

	/**
	 * Field a bot fills in and a person never sees.
	 */
	const HONEYPOT = 'photo_comp_website';

	/**
	 * Registration, made when the page is shown: the shortcode is set up on every request.
	 *
	 * @var Registration|null
	 */
	private $registration;

	/**
	 * The competition whose form gets what was posted back, after a refusal or a stale form.
	 *
	 * @var int
	 */
	private $refill_id = 0;

	/**
	 * The registration form's nonce and email field.
	 *
	 * @var Link_Request_Form
	 */
	private $form;

	/**
	 * Constructor.
	 *
	 * @param Registration|null $registration Registration.
	 */
	public function __construct( ?Registration $registration = null ) {
		$this->registration = $registration;
		$this->form         = new Link_Request_Form( 'photo_comp_register', 'photo_comp_register_nonce' );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The key is its own authentication.
		$key = isset( $_GET[ Registration::QUERY_ARG ] ) && is_string( $_GET[ Registration::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ Registration::QUERY_ARG ] ) ) : '';

		ob_start();
		echo '<div class="photo-comp-registration">';

		if ( '' === $key || ! $this->render_confirmation( $key ) ) {
			$this->render_form_page( $page_url );
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Show the outcome of a posted form, if any, and the competitions open for registration.
	 *
	 * @param string $page_url This page's URL, for the emailed link.
	 * @return void
	 */
	private function render_form_page( string $page_url ): void {
		$message = (string) $this->form->handle(
			function ( string $email ) use ( $page_url ): string {
				return $this->register_from_post( $email, $page_url );
			}
		);

		// A stale form is filled back in, as a refused one is.
		$this->refill_id = $this->refill_id ? $this->refill_id : absint( $this->form->refill( 'competition_id' ) );

		echo wp_kses_post( $message );
		$this->render_competitions();
	}

	/**
	 * Register from the posted form.
	 *
	 * @param string $email    Address typed in.
	 * @param string $page_url This page.
	 * @return string Message to show.
	 */
	private function register_from_post( string $email, string $page_url ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Link_Request_Form::handle() checked the nonce.
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$name           = isset( $_POST['registration_name'] ) && is_string( $_POST['registration_name'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_name'] ) ) : '';
		$grade          = isset( $_POST['registration_grade'] ) && is_string( $_POST['registration_grade'] ) ? sanitize_key( wp_unslash( $_POST['registration_grade'] ) ) : '';
		$honeypot       = isset( $_POST[ self::HONEYPOT ] ) && is_string( $_POST[ self::HONEYPOT ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::HONEYPOT ] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$sent = '<p class="success">' . esc_html__( "Thanks. Check your email: we've sent a link to confirm your registration. You aren't registered until you use it, and it works for 7 days.", 'photo-competition-manager' ) . '</p>';

		// A bot filled in the field people can't see: tell it what a person is told.
		if ( '' !== $honeypot ) {
			return $sent;
		}

		$result = $this->registration()->request( $competition_id, $name, $email, $grade, $page_url );
		if ( true === $result ) {
			return $sent;
		}

		$this->refill_id = $competition_id;

		return '<p class="error">' . esc_html( $result->get_error_message() ) . '</p>';
	}

	/**
	 * Show the confirm button for a link, or the outcome once it's pressed.
	 *
	 * @param string $key Key from the link.
	 * @return bool False when the link isn't valid, so the page lists the competitions instead.
	 */
	private function render_confirmation( string $key ): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- This is the nonce check.
		$pressed = isset( $_POST[ self::CONFIRM ] );
		$nonce   = isset( $_POST['photo_comp_registration_nonce'] ) && is_string( $_POST['photo_comp_registration_nonce'] ) ? sanitize_key( wp_unslash( $_POST['photo_comp_registration_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $pressed && false !== wp_verify_nonce( $nonce, self::CONFIRM ) ) {
			$outcome = $this->registration()->confirm( $key );
			$classes = array(
				'registered'      => 'success',
				'already_entrant' => 'notice',
			);
			echo '<p class="' . esc_attr( $classes[ $outcome['status'] ] ?? 'error' ) . '">' . esc_html( $outcome['message'] ) . '</p>';

			return 'invalid' !== $outcome['status'];
		}

		$registration = $this->registration()->pending( $key );
		if ( ! $registration ) {
			echo '<p class="notice">' . esc_html__( 'This link has expired or has already been used. Register again below to get a new one.', 'photo-competition-manager' ) . '</p>';
			return false;
		}

		if ( $pressed ) {
			echo '<p class="error">' . esc_html__( 'This form has expired. Please press Confirm again.', 'photo-competition-manager' ) . '</p>';
		}

		echo '<form method="post" class="photo-comp-registration-confirm">';
		wp_nonce_field( self::CONFIRM, 'photo_comp_registration_nonce' );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: Competition title, 2: Name typed into the registration form, 3: Email address */
				__( 'Confirm your registration for %1$s as %2$s (%3$s).', 'photo-competition-manager' ),
				( new Competition_Labels() )->title_for_everyone( $registration->competition ),
				$registration->name,
				$registration->email
			)
		) . '</p>';
		echo '<p><button type="submit" name="' . esc_attr( self::CONFIRM ) . '" value="1" class="button">' . esc_html__( 'Confirm', 'photo-competition-manager' ) . '</button></p>';
		echo '</form>';

		return true;
	}

	/**
	 * List the competitions open for registration, each with its form.
	 *
	 * @return void
	 */
	private function render_competitions(): void {
		$competitions = $this->registration()->open_competitions();

		if ( ! $competitions ) {
			echo '<p>' . esc_html__( 'No competitions are open for registration at the moment.', 'photo-competition-manager' ) . '</p>';
			return;
		}

		$labels = new Competition_Labels();

		foreach ( $competitions as $competition ) {
			$id        = (int) $competition->id;
			$interclub = Competition_Settings::is_interclub( $competition );
			$refill    = $id === $this->refill_id;
			$value     = function ( string $field ) use ( $refill ): string {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only fills the form back in; nothing is saved.
				return $refill && isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			};
			?>
			<section class="photo-comp-registration-competition">
				<h3><?php echo esc_html( $labels->title_for_everyone( $competition ) ); ?></h3>
				<?php if ( ! empty( $competition->open_date ) ) : ?>
					<p class="photo-comp-registration-dates">
						<?php
						/* translators: %s: Date uploads open */
						echo esc_html( sprintf( __( 'Opens %s', 'photo-competition-manager' ), format_site_date( $competition->open_date ) ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( Competition_Entry::has_fee( $competition ) ) : ?>
					<p class="photo-comp-registration-fee">
						<?php
						/* translators: %s: Entry fee, such as "€20" */
						echo esc_html( sprintf( __( 'Entry fee: %s', 'photo-competition-manager' ), $competition->entry_fee ) );
						?>
					</p>
				<?php endif; ?>
				<form method="post" class="photo-comp-registration-form">
					<?php $this->form->nonce_field(); ?>
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( (string) $id ); ?>" />
					<p>
						<label for="photo-comp-registration-name-<?php echo esc_attr( (string) $id ); ?>"><?php echo $interclub ? esc_html__( "Club's name", 'photo-competition-manager' ) : esc_html__( 'Your name', 'photo-competition-manager' ); ?></label>
						<input type="text" id="photo-comp-registration-name-<?php echo esc_attr( (string) $id ); ?>" name="registration_name" maxlength="191" value="<?php echo esc_attr( $value( 'registration_name' ) ); ?>" required />
					</p>
					<?php if ( $interclub ) : ?>
						<p class="photo-comp-registration-club-email"><?php esc_html_e( "Use an address only the club uses. An address you already use to enter competitions yourself can't also be your club's.", 'photo-competition-manager' ); ?></p>
					<?php endif; ?>
					<p>
						<label for="photo-comp-registration-email-<?php echo esc_attr( (string) $id ); ?>"><?php echo $interclub ? esc_html__( "Club's email address", 'photo-competition-manager' ) : esc_html__( 'Your email address', 'photo-competition-manager' ); ?></label>
						<input type="email" id="photo-comp-registration-email-<?php echo esc_attr( (string) $id ); ?>" name="member_email" value="<?php echo esc_attr( $value( 'member_email' ) ); ?>" required />
					</p>
					<p>
						<label for="photo-comp-registration-grade-<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Grade', 'photo-competition-manager' ); ?></label>
						<select id="photo-comp-registration-grade-<?php echo esc_attr( (string) $id ); ?>" name="registration_grade" required>
							<option value=""><?php esc_html_e( 'Choose a grade', 'photo-competition-manager' ); ?></option>
							<?php foreach ( $this->registration()->grade_choices( $competition ) as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $value( 'registration_grade' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="photo-comp-registration-website" aria-hidden="true" style="position: absolute; left: -9999px;">
						<label for="photo-comp-registration-website-<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Leave this empty', 'photo-competition-manager' ); ?></label>
						<input type="text" id="photo-comp-registration-website-<?php echo esc_attr( (string) $id ); ?>" name="<?php echo esc_attr( self::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" />
					</p>
					<p>
						<button type="submit" name="photo_comp_register" value="1" class="button"><?php esc_html_e( 'Register', 'photo-competition-manager' ); ?></button>
					</p>
				</form>
			</section>
			<?php
		}
	}

	/**
	 * Registration, made on first use.
	 *
	 * @return Registration
	 */
	private function registration(): Registration {
		if ( ! $this->registration ) {
			$this->registration = new Registration();
		}

		return $this->registration;
	}
}
