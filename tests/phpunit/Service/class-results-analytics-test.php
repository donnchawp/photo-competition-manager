<?php
/**
 * Tests for Results_Analytics.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Results_Analytics;
use WP_UnitTestCase;

class Results_Analytics_Test extends WP_UnitTestCase {

	const NO_VOTES = array(
		'count'   => 0,
		'average' => 0.0,
		'median'  => 0.0,
		'min'     => 0.0,
		'max'     => 0.0,
		'std_dev' => 0.0,
	);

	const NINE_SEVEN_SIX_FIVE = array(
		'count'   => 4,
		'average' => 6.75,
		'median'  => 6.5,
		'min'     => 5.0,
		'max'     => 9.0,
		'std_dev' => 1.48,
	);

	/**
	 * @var Results_Analytics
	 */
	private $analytics;

	/**
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * @var Votes_Repository
	 */
	private $votes;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->competitions = new Competitions_Repository();
		$this->images       = new Images_Repository();
		$this->members      = new Members_Repository();
		$this->votes        = new Votes_Repository();
		$this->analytics    = new Results_Analytics( $this->competitions, $this->images, $this->members, $this->votes );
	}

	/**
	 * Vote rows with the given scores.
	 *
	 * @param array<int> $scores Scores.
	 * @return array<object>
	 */
	private function votes_scoring( array $scores ): array {
		return array_map(
			static function ( $score ) {
				return (object) array( 'score' => (string) $score );
			},
			$scores
		);
	}

	public function test_vote_statistics_of_no_votes_are_zero(): void {
		$this->assertSame( self::NO_VOTES, $this->analytics->get_vote_statistics( array() ) );
	}

	public function test_vote_statistics(): void {
		$this->assertSame( self::NINE_SEVEN_SIX_FIVE, $this->analytics->get_vote_statistics( $this->votes_scoring( array( 9, 7, 6, 5 ) ) ) );
	}

	public function test_vote_statistics_do_not_depend_on_vote_order(): void {
		$this->assertSame( self::NINE_SEVEN_SIX_FIVE, $this->analytics->get_vote_statistics( $this->votes_scoring( array( 6, 9, 5, 7 ) ) ) );
	}

	public function test_image_details_of_a_missing_image(): void {
		$this->assertSame(
			array(
				'image'      => null,
				'member'     => null,
				'votes'      => array(),
				'statistics' => self::NO_VOTES,
			),
			$this->analytics->get_image_details( 999999 )
		);
	}

	public function test_image_details(): void {
		$competition_id = (int) $this->competitions->create(
			array(
				'title'      => 'Spring Show',
				'slug'       => 'spring-show-' . wp_generate_password( 6, false ),
				'open_date'  => null,
				'close_date' => null,
				'settings'   => array(),
			)
		);
		$member_id      = (int) $this->members->create(
			array(
				'name'  => 'Entrant',
				'email' => 'entrant@example.com',
				'grade' => 'beginner',
			)
		);
		$image_id       = (int) $this->images->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'photo.jpg',
			)
		);
		foreach ( array( 9, 7, 6, 5 ) as $i => $score ) {
			$this->votes->create( $competition_id, 'colour', "Judge $i", $image_id, $score );
		}

		$details = $this->analytics->get_image_details( $image_id );

		$this->assertSame( $image_id, (int) $details['image']->id );
		$this->assertSame( $member_id, (int) $details['member']->id );
		$this->assertEquals( $this->votes->find_by_image( $image_id ), $details['votes'] );
		$this->assertSame( self::NINE_SEVEN_SIX_FIVE, $details['statistics'] );
	}
}
