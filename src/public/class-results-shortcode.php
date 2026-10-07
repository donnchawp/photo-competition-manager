<?php
/**
 * Handle results shortcode.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Service\Results_Ranking;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Render the public competition results shortcode output.
 *
 * @since 0.1.0
 */
class Results_Shortcode {

	use Results_Competition;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * Results ranking service.
	 *
	 * @var Results_Ranking
	 */
	private $ranking;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Images_Repository|null       $images_repo       Images repository.
	 * @param Votes_Repository|null        $votes_repo        Votes repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Entries|null                 $entries           Entries module.
	 */
	public function __construct(
		?Competitions_Repository $competitions_repo = null,
		?Images_Repository $images_repo = null,
		?Votes_Repository $votes_repo = null,
		?Members_Repository $members_repo = null,
		?Entries $entries = null
	) {
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->images_repo       = $images_repo ?? new Images_Repository();
		$members_repo            = $members_repo ?? new Members_Repository();
		$this->workflow          = new Competition_Workflow( $this->competitions_repo, $this->images_repo );
		$this->ranking           = new Results_Ranking( $this->images_repo, $votes_repo ?? new Votes_Repository(), $members_repo );
		$this->entries           = $entries ?? new Entries( $this->competitions_repo, $this->images_repo, $members_repo );
	}

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'competition_results', array( $this, 'render' ) );
	}

	/**
	 * Render results shortcode.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is a WP Super Cache constant.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

		$atts = shortcode_atts(
			array(
				'competition' => '',
				'hide_names'  => 'false',
			),
			$atts,
			'competition_results'
		);

		$share_hash  = $this->requested_share_hash();
		$competition = $this->resolve_competition( (string) $atts['competition'], $share_hash );

		if ( is_wp_error( $competition ) ) {
			return '<p class="error">' . esc_html( $competition->get_error_message() ) . '</p>';
		}

		$hide_names = filter_var( $atts['hide_names'], FILTER_VALIDATE_BOOLEAN );

		ob_start();
		$this->render_results( $competition, $hide_names, $share_hash );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Render results display.
	 *
	 * @param object $competition  Competition object.
	 * @param bool   $hide_names   Whether to hide member names.
	 * @param string $share_hash   Share hash from the request, or ''.
	 * @return void
	 */
	private function render_results( object $competition, bool $hide_names, string $share_hash ): void {
		$settings   = Competition_Settings::parse( $competition->settings );
		$grades     = Competition_Settings::club_grades();
		$categories = Competition_Settings::get_categories( $settings );

		if ( ! $this->results_viewable( $competition, $share_hash ) ) {
			echo '<div class="photo-comp-results">';
			echo '<h2>' . esc_html( $competition->title ) . ' - ' . esc_html__( 'Results', 'photo-competition-manager' ) . '</h2>';
			echo '<p class="notice">' . esc_html__( 'Results are not yet available. Please check back later.', 'photo-competition-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( empty( $this->images_repo->find_by_competition( (int) $competition->id ) ) ) {
			echo '<p class="notice">' . esc_html__( 'No images submitted for this competition yet.', 'photo-competition-manager' ) . '</p>';
			return;
		}

		// Rank each category within the club's grades. Ungraded entries are for admins to fix and aren't shown.
		$results_by_category = array();
		foreach ( $categories as $category_config ) {
			foreach ( $this->ranking->rank_category( (int) $competition->id, $category_config['slug'] ) as $group ) {
				if ( ! $group['ungraded'] ) {
					$results_by_category[ $category_config['slug'] ][ $group['slug'] ] = $group['entries'];
				}
			}
		}

		?>
		<div class="photo-comp-results">
			<h2><?php echo esc_html( $competition->title ); ?> - <?php esc_html_e( 'Results', 'photo-competition-manager' ); ?></h2>

			<?php foreach ( $categories as $category_config ) : ?>
				<?php
				$category_slug    = $category_config['slug'];
				$category_label   = $category_config['label'];
				$category_results = $results_by_category[ $category_slug ] ?? array();
				?>
				<?php if ( ! empty( $category_results ) ) : ?>
					<div class="results-category-section">
						<h3><?php echo esc_html( $category_label ); ?></h3>

						<?php foreach ( $grades as $grade_config ) : ?>
							<?php
							$grade_slug    = $grade_config['slug'];
							$grade_label   = $grade_config['label'];
							$grade_results = $category_results[ $grade_slug ] ?? array();
							?>
							<?php if ( ! empty( $grade_results ) ) : ?>
								<div class="results-grade-section">
									<h4><?php echo esc_html( $grade_label ); ?></h4>
									<div class="results-table">
										<table>
											<thead>
												<tr>
													<th><?php esc_html_e( 'Position', 'photo-competition-manager' ); ?></th>
													<th><?php esc_html_e( 'Image', 'photo-competition-manager' ); ?></th>
													<?php if ( ! $hide_names ) : ?>
														<th><?php esc_html_e( 'Member', 'photo-competition-manager' ); ?></th>
													<?php endif; ?>
													<th><?php esc_html_e( 'Score', 'photo-competition-manager' ); ?></th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $grade_results as $result ) : ?>
													<?php
													$image       = $result['image'];
													$member      = $result['member'];
													$total_score = $result['total_score'];
													$position    = $result['position'];
													// A recorded entry deleted since has no image.
													$image_urls = $image ? $this->entries->urls( $competition, $image ) : array(
														'full'  => '',
														'thumb' => '',
													);
													$thumb_url  = $image_urls['thumb'] ? $image_urls['thumb'] : $image_urls['full'];
													/* translators: %d: Anonymised image identifier. */
													$alt_text = $image ? sprintf( __( 'Image %d', 'photo-competition-manager' ), $image->random_number ) : '';
													?>
													<tr>
														<td class="position"><?php echo esc_html( $position ); ?></td>
														<td class="image-cell">
															<?php if ( $thumb_url ) : ?>
																<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $alt_text ); ?>" loading="lazy" class="result-thumbnail" />
															<?php else : ?>
																<div class="image-unavailable">
																	<?php esc_html_e( 'Image unavailable', 'photo-competition-manager' ); ?>
																</div>
															<?php endif; ?>
															<?php if ( $image ) : ?>
																<div class="image-number">#<?php echo esc_html( $image->random_number ); ?></div>
															<?php endif; ?>
														</td>
														<?php if ( ! $hide_names ) : ?>
															<td class="member-name" data-label="<?php esc_attr_e( 'Member', 'photo-competition-manager' ); ?>"><?php echo esc_html( $member ? $member->name : __( 'Former member', 'photo-competition-manager' ) ); ?></td>
														<?php endif; ?>
														<td class="score" data-label="<?php esc_attr_e( 'Score', 'photo-competition-manager' ); ?>"><?php echo esc_html( number_format( $total_score, 0 ) ); ?></td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php if ( empty( array_filter( $results_by_category ) ) ) : ?>
				<p class="notice"><?php esc_html_e( 'No results available yet.', 'photo-competition-manager' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
