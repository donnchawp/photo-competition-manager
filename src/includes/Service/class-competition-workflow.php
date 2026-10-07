<?php
/**
 * Competition workflow: a competition's phase and each category's voting stage.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;

use function PhotoCompetitionManager\Support\utc_time;

/**
 * Owns where a competition is in its life and how far each category has got
 * on competition night. It's the only reader and writer of the competition's
 * workflow column.
 *
 * @since 0.4.0
 */
class Competition_Workflow {

	const PHASE_SCHEDULED         = 'scheduled';
	const PHASE_ACCEPTING_UPLOADS = 'accepting_uploads';
	const PHASE_UPLOADS_CLOSED    = 'uploads_closed';
	const PHASE_RESULTS_PUBLISHED = 'results_published';
	const PHASE_CLOSED            = 'closed';
	const PHASE_ARCHIVED          = 'archived';

	const STAGE_NOT_STARTED     = 'not_started';
	const STAGE_PREVIEWED       = 'previewed';
	const STAGE_VOTING          = 'voting';
	const STAGE_SLIDESHOW_SHOWN = 'slideshow_shown';
	const STAGE_CRITIQUE        = 'critique';
	const STAGE_DONE            = 'done';

	/**
	 * Every stage, in the order a category goes through them.
	 */
	const STAGES = array(
		self::STAGE_NOT_STARTED,
		self::STAGE_PREVIEWED,
		self::STAGE_VOTING,
		self::STAGE_SLIDESHOW_SHOWN,
		self::STAGE_CRITIQUE,
		self::STAGE_DONE,
	);

	/**
	 * Stages that accept votes.
	 */
	const VOTING_STAGES = array( self::STAGE_VOTING, self::STAGE_SLIDESHOW_SHOWN );

	/**
	 * The stage each slideshow stage moves on to.
	 */
	const ADVANCES = array(
		self::STAGE_NOT_STARTED => self::STAGE_PREVIEWED,
		self::STAGE_VOTING      => self::STAGE_SLIDESHOW_SHOWN,
		self::STAGE_CRITIQUE    => self::STAGE_DONE,
	);

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Voting token repository.
	 *
	 * @var Voting_Token_Repository
	 */
	private $voting_tokens;

	/**
	 * Results ranking, which records results when they're published. Made
	 * when first needed, as it uses this workflow too.
	 *
	 * @var Results_Ranking|null
	 */
	private $ranking;

	/**
	 * Category slugs of competitions with categories of their own, keyed by
	 * their settings JSON. Nearly every question needs them, and parsing
	 * settings isn't cheap.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $category_slugs = array();

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions  Competitions repository.
	 * @param Images_Repository|null       $images        Images repository.
	 * @param Votes_Repository|null        $votes         Votes repository.
	 * @param Voting_Token_Repository|null $voting_tokens Voting token repository.
	 * @param Results_Ranking|null         $ranking       Results ranking.
	 */
	public function __construct(
		?Competitions_Repository $competitions = null,
		?Images_Repository $images = null,
		?Votes_Repository $votes = null,
		?Voting_Token_Repository $voting_tokens = null,
		?Results_Ranking $ranking = null
	) {
		$this->competitions  = $competitions ?? new Competitions_Repository();
		$this->images        = $images ?? new Images_Repository();
		$this->votes         = $votes ?? new Votes_Repository();
		$this->voting_tokens = $voting_tokens ?? new Voting_Token_Repository();
		$this->ranking       = $ranking;
	}

	/**
	 * The competition's phase.
	 *
	 * @param object $competition Competition row.
	 * @return string One of the PHASE_* constants.
	 */
	public function phase( object $competition ): string {
		if ( ! empty( $competition->deleted_at ) ) {
			return self::PHASE_ARCHIVED;
		}

		if ( ! $this->is_open( $competition ) ) {
			return ! empty( $competition->open_date ) && $competition->open_date > utc_time() ? self::PHASE_SCHEDULED : self::PHASE_CLOSED;
		}

		$state = $this->state( $competition );

		if ( $state['results_published'] ) {
			return self::PHASE_RESULTS_PUBLISHED;
		}

		return $state['uploads_closed'] ? self::PHASE_UPLOADS_CLOSED : self::PHASE_ACCEPTING_UPLOADS;
	}

	/**
	 * Whether the competition has closed: its phase is Closed or Archived.
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	public function has_closed( object $competition ): bool {
		return in_array( $this->phase( $competition ), array( self::PHASE_CLOSED, self::PHASE_ARCHIVED ), true );
	}

	/**
	 * Whether the competition is accepting uploads.
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	public function is_accepting_uploads( object $competition ): bool {
		return self::PHASE_ACCEPTING_UPLOADS === $this->phase( $competition );
	}

	/**
	 * A category's voting stage.
	 *
	 * @param object $competition   Competition row.
	 * @param string $category_slug Category slug.
	 * @return string One of the STAGE_* constants.
	 */
	public function stage( object $competition, string $category_slug ): string {
		return $this->state( $competition )['stages'][ $category_slug ] ?? self::STAGE_NOT_STARTED;
	}

	/**
	 * Whether a category's voting has started: its stage is past Previewed.
	 *
	 * @param object $competition   Competition row.
	 * @param string $category_slug Category slug.
	 * @return bool
	 */
	public function has_voting_started( object $competition, string $category_slug ): bool {
		return ! in_array( $this->stage( $competition, $category_slug ), array( self::STAGE_NOT_STARTED, self::STAGE_PREVIEWED ), true );
	}

	/**
	 * Whether a category is accepting votes: its stage takes votes, and the
	 * competition is inside its dates.
	 *
	 * @param object $competition   Competition row.
	 * @param string $category_slug Category slug.
	 * @return bool
	 */
	public function is_accepting_votes( object $competition, string $category_slug ): bool {
		return $this->is_open( $competition )
			&& in_array( $this->stage( $competition, $category_slug ), self::VOTING_STAGES, true );
	}

	/**
	 * Whether the competition is inside its dates and not archived:
	 * open_date <= now < close_date, with a missing date unbounded on that
	 * side. Competitions_Repository::find_current_active() selects with the
	 * same rule.
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	public function is_open( object $competition ): bool {
		$now = utc_time();

		return empty( $competition->deleted_at )
			&& ( empty( $competition->open_date ) || $competition->open_date <= $now )
			&& ( empty( $competition->close_date ) || $competition->close_date > $now );
	}

	/**
	 * Whether uploads have been closed, whatever the dates say.
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	public function uploads_closed( object $competition ): bool {
		return $this->state( $competition )['uploads_closed'];
	}

	/**
	 * Close uploads.
	 *
	 * @param int $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function close_uploads( int $competition_id ) {
		return $this->set_flag( $competition_id, 'uploads_closed', true, fn() => true );
	}

	/**
	 * Whether uploads may reopen.
	 *
	 * Not while results are published, since uploads stay shut then. Not
	 * while a category is accepting votes, or once any votes are cast: a
	 * new image would leave the ballots already cast incomplete.
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error 'results_published', 'voting_open' or 'votes_exist'.
	 */
	public function can_reopen_uploads( object $competition ) {
		if ( $this->results_published( $competition ) ) {
			return new WP_Error( 'results_published', __( 'Hide results before reopening uploads.', 'photo-competition-manager' ) );
		}

		if ( ! empty( $this->categories_accepting_votes( $competition ) ) ) {
			return new WP_Error( 'voting_open', __( 'Close voting before reopening uploads.', 'photo-competition-manager' ) );
		}

		if ( $this->votes->has_votes( (int) $competition->id ) ) {
			return new WP_Error( 'votes_exist', __( 'Votes have been cast, so uploads can\'t reopen. Reset the competition\'s votes first.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Reopen uploads.
	 *
	 * @param int $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function reopen_uploads( int $competition_id ) {
		return $this->set_flag( $competition_id, 'uploads_closed', false, array( $this, 'can_reopen_uploads' ) );
	}

	/**
	 * Whether results are published.
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	public function results_published( object $competition ): bool {
		return $this->state( $competition )['results_published'];
	}

	/**
	 * Whether results may be published: uploads are closed and no category
	 * is accepting votes.
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error 'uploads_open' or 'voting_open'.
	 */
	public function can_publish_results( object $competition ) {
		if ( ! $this->uploads_closed( $competition ) ) {
			return new WP_Error( 'uploads_open', __( 'Close uploads before publishing results.', 'photo-competition-manager' ) );
		}

		if ( ! empty( $this->categories_accepting_votes( $competition ) ) ) {
			return new WP_Error( 'voting_open', __( 'Close voting before publishing results.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Publish the results, recording them first.
	 *
	 * Publishing again replaces the record, as the votes can change while
	 * results are hidden. Once the competition has closed, an existing
	 * record stays as it is (see Results_Ranking::record()).
	 *
	 * @param int $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function publish_results( int $competition_id ) {
		$competition = $this->load( $competition_id );

		if ( is_wp_error( $competition ) ) {
			return $competition;
		}

		$allowed = $this->can_publish_results( $competition );

		if ( true !== $allowed ) {
			return $allowed;
		}

		$recorded = $this->ranking()->record( $competition );

		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}

		return $this->set_flag( $competition_id, 'results_published', true, array( $this, 'can_publish_results' ) );
	}

	/**
	 * Whether results may be hidden: not once the competition has closed,
	 * since publishing again then can't replace the record.
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error 'competition_closed'.
	 */
	public function can_unpublish_results( object $competition ) {
		if ( $this->has_closed( $competition ) ) {
			return new WP_Error( 'competition_closed', __( 'Results can\'t be hidden after the competition has closed. To correct them, move the close date into the future first.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Unpublish the results. Their record stays.
	 *
	 * @param int $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function unpublish_results( int $competition_id ) {
		return $this->set_flag( $competition_id, 'results_published', false, array( $this, 'can_unpublish_results' ) );
	}

	/**
	 * Whether a category may move on to the next stage after a slideshow.
	 *
	 * Only the slideshow moves are allowed: not started to previewed, voting
	 * to slideshow shown, and critique to done. Opening and closing voting
	 * have their own transitions.
	 *
	 * @param object $competition   Competition row.
	 * @param string $category_slug Category slug.
	 * @param string $to            The stage to move to.
	 * @return true|WP_Error 'unknown_category' or 'wrong_stage'.
	 */
	public function can_advance( object $competition, string $category_slug, string $to ) {
		return $this->require_stage( $competition, $category_slug, array_keys( self::ADVANCES, $to, true ) );
	}

	/**
	 * Move a category on to the next stage after a slideshow.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category_slug  Category slug.
	 * @param string $to             The stage to move to.
	 * @return true|WP_Error
	 */
	public function advance( int $competition_id, string $category_slug, string $to ) {
		return $this->transition(
			$competition_id,
			fn( $competition ) => $this->can_advance( $competition, $category_slug, $to ),
			fn( array $state ) => $this->with_stage( $state, $category_slug, $to )
		);
	}

	/**
	 * Whether the voting workflow can start at all: uploads are closed and
	 * results are hidden.
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error 'uploads_open' or 'results_published'.
	 */
	public function can_start_voting( object $competition ) {
		$state = $this->state( $competition );

		if ( ! $state['uploads_closed'] ) {
			return new WP_Error( 'uploads_open', __( 'Close uploads before starting the voting workflow.', 'photo-competition-manager' ) );
		}

		if ( $state['results_published'] ) {
			return new WP_Error( 'results_published', __( 'Hide results before starting the voting workflow.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Whether voting may open on a category.
	 *
	 * @param object $competition   Competition row.
	 * @param string $category_slug Category slug.
	 * @return true|WP_Error
	 */
	public function can_open_voting( object $competition, string $category_slug ) {
		if ( ! $this->is_open( $competition ) ) {
			return new WP_Error( 'competition_closed', __( 'This competition is closed, so voting can\'t open.', 'photo-competition-manager' ) );
		}

		$ready = $this->can_start_voting( $competition );

		if ( true !== $ready ) {
			return $ready;
		}

		$at_stage = $this->require_stage( $competition, $category_slug, array( self::STAGE_PREVIEWED ) );

		if ( true !== $at_stage ) {
			return $at_stage;
		}

		if ( ! $this->images->has_images( (int) $competition->id, $category_slug ) ) {
			return new WP_Error( 'no_images', __( 'That category has no images to vote on.', 'photo-competition-manager' ) );
		}

		if ( null !== $this->category_accepting_votes() ) {
			return new WP_Error( 'another_category_voting', __( 'Close voting in the other category first.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Open voting on a category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category_slug  Category slug.
	 * @return true|WP_Error
	 */
	public function open_voting( int $competition_id, string $category_slug ) {
		return $this->transition(
			$competition_id,
			fn( $competition ) => $this->can_open_voting( $competition, $category_slug ),
			fn( array $state ) => $this->with_stage( $state, $category_slug, self::STAGE_VOTING )
		);
	}

	/**
	 * Whether voting may close on a category.
	 *
	 * Allowed after the close date too, so an admin can finish a
	 * competition off.
	 *
	 * @param object $competition   Competition row.
	 * @param string $category_slug Category slug.
	 * @return true|WP_Error 'unknown_category' or 'wrong_stage'.
	 */
	public function can_close_voting( object $competition, string $category_slug ) {
		return $this->require_stage( $competition, $category_slug, self::VOTING_STAGES );
	}

	/**
	 * Close voting on a category, moving it on to critique.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category_slug  Category slug.
	 * @return true|WP_Error
	 */
	public function close_voting( int $competition_id, string $category_slug ) {
		return $this->transition(
			$competition_id,
			fn( $competition ) => $this->can_close_voting( $competition, $category_slug ),
			fn( array $state ) => $this->with_stage( $state, $category_slug, self::STAGE_CRITIQUE )
		);
	}

	/**
	 * Reset a category to not started, optionally clearing its votes and
	 * voting tokens. Reset is the escape hatch, so it's always allowed.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category_slug  Category slug.
	 * @param bool   $clear_votes    Whether to delete the category's votes and voting tokens.
	 * @return true|WP_Error
	 */
	public function reset_category( int $competition_id, string $category_slug, bool $clear_votes ) {
		$result = $this->transition(
			$competition_id,
			fn( $competition ) => $this->check_category( $competition, $category_slug ),
			function ( array $state ) use ( $category_slug ) {
				unset( $state['stages'][ $category_slug ] );
				return $state;
			}
		);

		if ( true === $result && $clear_votes ) {
			$this->votes->delete_by_competition_and_category( $competition_id, $category_slug );
			$this->voting_tokens->delete_by_competition_and_category( $competition_id, $category_slug );
		}

		return $result;
	}

	/**
	 * Reset every category to not started, and delete all the competition's
	 * votes and voting tokens. Uploads and results stay as they are.
	 *
	 * @param int $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function reset_competition( int $competition_id ) {
		$result = $this->transition(
			$competition_id,
			fn() => true,
			function ( array $state ) {
				$state['stages'] = array();
				return $state;
			}
		);

		if ( true === $result ) {
			$this->votes->delete_by_competition( $competition_id );
			$this->voting_tokens->delete_by_competition( $competition_id );
		}

		return $result;
	}

	/**
	 * Whether the competition may be closed now: only while it's open, so a
	 * replayed link can't move an existing close date.
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error 'competition_already_closed'.
	 */
	public function can_close_competition( object $competition ) {
		if ( ! $this->is_open( $competition ) ) {
			return new WP_Error( 'competition_already_closed', __( 'Competition is already closed.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Close the competition now, closing voting on any category accepting votes.
	 *
	 * @param int $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function close_competition( int $competition_id ) {
		$closed = $this->transition(
			$competition_id,
			array( $this, 'can_close_competition' ),
			function ( array $state, object $competition ) {
				foreach ( $this->categories_accepting_votes( $competition ) as $category_slug ) {
					$state = $this->with_stage( $state, $category_slug, self::STAGE_CRITIQUE );
				}
				return $state;
			}
		);

		if ( true !== $closed ) {
			return $closed;
		}

		// Use the current time, not a date-only "today": that is stored as
		// midnight UTC, which is still in the future just after midnight on
		// sites ahead of UTC.
		return $this->competitions->update( $competition_id, array( 'close_date' => utc_time() ) );
	}

	/**
	 * The one category in the club accepting votes, if any.
	 *
	 * Only one category can accept votes at a time, across all competitions.
	 * Competitions saved before only one could be open may still overlap, so
	 * every open competition is checked.
	 *
	 * @return array{competition: object, category: string}|null
	 */
	public function category_accepting_votes(): ?array {
		foreach ( $this->competitions->all_open() as $competition ) {
			$categories = $this->categories_accepting_votes( $competition );

			if ( ! empty( $categories ) ) {
				return array(
					'competition' => $competition,
					'category'    => $categories[0],
				);
			}
		}

		return null;
	}

	/**
	 * Slugs of the competition's categories accepting votes.
	 *
	 * @param object $competition Competition row.
	 * @return array<int, string>
	 */
	public function categories_accepting_votes( object $competition ): array {
		if ( ! $this->is_open( $competition ) ) {
			return array();
		}

		$voting = array_filter(
			$this->state( $competition )['stages'],
			fn( $stage ) => in_array( $stage, self::VOTING_STAGES, true )
		);

		return array_map( 'strval', array_keys( $voting ) );
	}

	/**
	 * Find the competition the results pages show by default.
	 *
	 * That's the latest competition, by open date, with results published,
	 * so last month's results stay up until the next ones are published.
	 * With none published, it's the current competition, then the one with
	 * the latest open date, so the page can still name a competition.
	 * Archived competitions are ignored.
	 *
	 * @return object|null
	 */
	public function find_for_results() {
		$competitions = $this->competitions->all_by_opening();
		$open         = null;

		foreach ( $competitions as $competition ) {
			if ( $this->results_published( $competition ) ) {
				return $competition;
			}

			if ( null === $open && $this->is_open( $competition ) ) {
				$open = $competition;
			}
		}

		return $open ?? ( $competitions[0] ?? null );
	}

	/**
	 * Check a category belongs to the competition and is at one of the given stages.
	 *
	 * @param object             $competition   Competition row.
	 * @param string             $category_slug Category slug.
	 * @param array<int, string> $stages        Stages the category may be at.
	 * @return true|WP_Error 'unknown_category' or 'wrong_stage'.
	 */
	private function require_stage( object $competition, string $category_slug, array $stages ) {
		$known = $this->check_category( $competition, $category_slug );

		if ( true !== $known ) {
			return $known;
		}

		if ( ! in_array( $this->stage( $competition, $category_slug ), $stages, true ) ) {
			return new WP_Error( 'wrong_stage', __( 'That category has moved on since this page loaded. Reload Voting Controls.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Check a category belongs to the competition.
	 *
	 * @param object $competition   Competition row.
	 * @param string $category_slug Category slug.
	 * @return true|WP_Error 'unknown_category'.
	 */
	private function check_category( object $competition, string $category_slug ) {
		if ( in_array( $category_slug, $this->category_slugs( $competition ), true ) ) {
			return true;
		}

		return new WP_Error( 'unknown_category', __( 'This competition doesn\'t have that category. Reload Voting Controls.', 'photo-competition-manager' ) );
	}

	/**
	 * Slugs of the competition's categories.
	 *
	 * @param object $competition Competition row.
	 * @return array<int, string>
	 */
	private function category_slugs( object $competition ): array {
		$json = (string) ( $competition->settings ?? '' );

		if ( isset( $this->category_slugs[ $json ] ) ) {
			return $this->category_slugs[ $json ];
		}

		$settings = Competition_Settings::parse( $json );
		$slugs    = wp_list_pluck( Competition_Settings::get_categories( $settings ), 'slug' );

		// A competition without categories uses the club's, which can change.
		if ( ! empty( $settings['categories'] ) ) {
			$this->category_slugs[ $json ] = $slugs;
		}

		return $slugs;
	}

	/**
	 * The results ranking, made when first needed.
	 *
	 * @return Results_Ranking
	 */
	private function ranking(): Results_Ranking {
		if ( null === $this->ranking ) {
			$this->ranking = new Results_Ranking( $this->images, $this->votes, new Members_Repository(), $this->competitions, $this );
		}

		return $this->ranking;
	}

	/**
	 * Set one of the competition-wide flags.
	 *
	 * @param int      $competition_id Competition ID.
	 * @param string   $flag           'uploads_closed' or 'results_published'.
	 * @param bool     $value          New value.
	 * @param callable $check          Given the competition, returns true or a WP_Error.
	 * @return true|WP_Error
	 */
	private function set_flag( int $competition_id, string $flag, bool $value, callable $check ) {
		return $this->transition(
			$competition_id,
			$check,
			function ( array $state ) use ( $flag, $value ) {
				$state[ $flag ] = $value;
				return $state;
			}
		);
	}

	/**
	 * Set a category's stage in the state.
	 *
	 * @param array<string, mixed> $state         Workflow state.
	 * @param string               $category_slug Category slug.
	 * @param string               $stage         Stage.
	 * @return array<string, mixed>
	 */
	private function with_stage( array $state, string $category_slug, string $stage ): array {
		$state['stages'][ $category_slug ] = $stage;

		return $state;
	}

	/**
	 * Load a competition fresh, check a transition is allowed, then save the new state.
	 *
	 * @param int      $competition_id Competition ID.
	 * @param callable $check          Given the competition, returns true or a WP_Error.
	 * @param callable $apply          Given the state and the competition, returns the new state.
	 * @return true|WP_Error
	 */
	private function transition( int $competition_id, callable $check, callable $apply ) {
		$competition = $this->load( $competition_id );

		if ( is_wp_error( $competition ) ) {
			return $competition;
		}

		$allowed = $check( $competition );

		if ( true !== $allowed ) {
			return $allowed;
		}

		return $this->competitions->save_workflow( $competition_id, $apply( $this->state( $competition ), $competition ) );
	}

	/**
	 * Load a competition fresh, archived or not, before changing it.
	 *
	 * @param int $competition_id Competition ID.
	 * @return object|WP_Error
	 */
	private function load( int $competition_id ) {
		$competition = $this->competitions->find( $competition_id, true );

		if ( ! $competition ) {
			return new WP_Error( 'competition_not_found', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		return $competition;
	}

	/**
	 * Read the stored workflow, filling in a new competition's empty state.
	 *
	 * @param object $competition Competition row.
	 * @return array{uploads_closed: bool, results_published: bool, stages: array<string, string>}
	 */
	private function state( object $competition ): array {
		$stored = json_decode( (string) ( $competition->workflow ?? '' ), true );
		$stored = is_array( $stored ) ? $stored : array();

		$stages = is_array( $stored['stages'] ?? null ) ? $stored['stages'] : array();

		// A category the competition no longer has keeps no stage, and an
		// unknown stage counts as not started.
		$stages = array_intersect_key( $stages, array_flip( $this->category_slugs( $competition ) ) );
		$stages = array_filter( $stages, fn( $stage ) => in_array( $stage, self::STAGES, true ) );

		return array(
			'uploads_closed'    => ! empty( $stored['uploads_closed'] ),
			'results_published' => ! empty( $stored['results_published'] ),
			'stages'            => $stages,
		);
	}
}
