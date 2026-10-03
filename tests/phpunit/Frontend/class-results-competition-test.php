<?php
/**
 * Tests for the Results_Competition trait shared by the results and top 3
 * shortcodes.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Results_Competition;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use WP_UnitTestCase;

/**
 * How a results page picks its competition and whether it may show results.
 */
class Results_Competition_Test extends WP_UnitTestCase {

	/**
	 * Share hash of the hidden competition.
	 */
	const HASH = '0123456789abcdef0123456789abcdef';

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Competitions created so far, so each gets its own month.
	 *
	 * @var int
	 */
	private $created = 0;

	/**
	 * Object using the trait, with its methods made public.
	 *
	 * @var object
	 */
	private $page;

	/**
	 * Set up the repository and the trait user.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->competitions = new Competitions_Repository();
		$this->page         = new class( $this->competitions ) {
			use Results_Competition {
				resolve_competition as public;
				results_viewable as public;
			}

			/**
			 * Competitions repository, as the shortcodes hold it.
			 *
			 * @var Competitions_Repository
			 */
			private $competitions_repo;

			/**
			 * Constructor.
			 *
			 * @param Competitions_Repository $competitions_repo Competitions repository.
			 */
			public function __construct( Competitions_Repository $competitions_repo ) {
				$this->competitions_repo = $competitions_repo;
			}
		};
	}

	/**
	 * Create a past competition, in its own month so none overlap.
	 *
	 * @param string $slug    Slug.
	 * @param bool   $visible Whether its results are visible.
	 * @param string $hash    Share hash, or '' for none.
	 * @return object Competition.
	 */
	private function create_competition( string $slug, bool $visible, string $hash = '' ): object {
		$id = (int) $this->competitions->create(
			array(
				'title'      => ucfirst( $slug ),
				'slug'       => $slug,
				'open_date'  => sprintf( '2020-%02d-01 00:00:00', ++$this->created ),
				'close_date' => sprintf( '2020-%02d-05 00:00:00', $this->created ),
				'settings'   => array( 'results' => array( 'results_visible' => $visible ) ),
			)
		);

		if ( '' !== $hash ) {
			$this->competitions->update_share_hash( $id, $hash );
		}

		return $this->competitions->find( $id );
	}

	public function test_slug_alone_resolves_competition_with_hidden_results_hidden(): void {
		$hidden = $this->create_competition( 'hidden', false, self::HASH );

		$competition = $this->page->resolve_competition( 'hidden', '' );

		$this->assertSame( (int) $hidden->id, (int) $competition->id );
		$this->assertFalse( $this->page->results_viewable( $competition, '' ) );
	}

	public function test_slug_with_matching_hash_makes_hidden_results_viewable(): void {
		$this->create_competition( 'hidden', false, self::HASH );

		$competition = $this->page->resolve_competition( 'hidden', self::HASH );

		$this->assertTrue( $this->page->results_viewable( $competition, self::HASH ) );
	}

	public function test_slug_with_wrong_hash_keeps_hidden_results_hidden(): void {
		$this->create_competition( 'hidden', false, self::HASH );

		$competition = $this->page->resolve_competition( 'hidden', 'ffffffffffffffffffffffffffffffff' );

		$this->assertFalse( $this->page->results_viewable( $competition, 'ffffffffffffffffffffffffffffffff' ) );
	}

	public function test_hash_alone_resolves_its_competition_and_makes_results_viewable(): void {
		$this->create_competition( 'shown', true );
		$hidden = $this->create_competition( 'hidden', false, self::HASH );

		$competition = $this->page->resolve_competition( '', self::HASH );

		$this->assertSame( (int) $hidden->id, (int) $competition->id );
		$this->assertTrue( $this->page->results_viewable( $competition, self::HASH ) );
	}

	public function test_unknown_hash_falls_back_to_find_for_results_with_results_hidden(): void {
		$hidden = $this->create_competition( 'hidden', false, self::HASH );

		$competition = $this->page->resolve_competition( '', 'ffffffffffffffffffffffffffffffff' );

		$this->assertSame( (int) $hidden->id, (int) $this->competitions->find_for_results()->id );
		$this->assertSame( (int) $hidden->id, (int) $competition->id );
		$this->assertFalse( $this->page->results_viewable( $competition, 'ffffffffffffffffffffffffffffffff' ) );
	}

	public function test_unknown_slug_is_not_found(): void {
		$this->create_competition( 'shown', true );

		$result = $this->page->resolve_competition( 'missing', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'Competition not found.', $result->get_error_message() );
	}

	public function test_no_competitions_gives_none_found(): void {
		$result = $this->page->resolve_competition( '', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'No competitions found.', $result->get_error_message() );
	}

	public function test_visible_results_are_viewable_without_a_hash(): void {
		$shown = $this->create_competition( 'shown', true );

		$this->assertTrue( $this->page->results_viewable( $shown, '' ) );
	}

	public function test_upper_cased_hash_does_not_make_hidden_results_viewable(): void {
		$hidden = $this->create_competition( 'hidden', false, self::HASH );

		$this->assertFalse( $this->page->results_viewable( $hidden, strtoupper( self::HASH ) ) );
	}

	public function test_hash_of_archived_competition_falls_back_with_results_hidden(): void {
		$archived = $this->create_competition( 'archived', false, self::HASH );
		$this->competitions->archive( (int) $archived->id );
		$other = $this->create_competition( 'other', false );

		$competition = $this->page->resolve_competition( '', self::HASH );

		$this->assertSame( (int) $other->id, (int) $competition->id );
		$this->assertFalse( $this->page->results_viewable( $competition, self::HASH ) );
	}

	public function test_zero_slug_falls_back_like_no_slug(): void {
		$shown = $this->create_competition( 'shown', true );

		$competition = $this->page->resolve_competition( '0', '' );

		$this->assertSame( (int) $shown->id, (int) $competition->id );
	}
}
