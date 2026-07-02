<?php
/**
 * Factory for admin controller dependencies.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Email_Results_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Results_Analytics;
use PhotoCompetitionManager\Service\Score_Calculator;

/**
 * Creates and provides dependencies for admin controllers.
 *
 * @since 0.1.0
 */
class Admin_Dependencies {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	public Competitions_Repository $competitions;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	public Members_Repository $members;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	public Images_Repository $images;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	public Votes_Repository $votes;

	/**
	 * Logs repository.
	 *
	 * @var Logs_Repository
	 */
	public Logs_Repository $logs;

	/**
	 * Results analytics service.
	 *
	 * @var Results_Analytics
	 */
	public Results_Analytics $analytics;

	/**
	 * Score calculator service.
	 *
	 * @var Score_Calculator
	 */
	public Score_Calculator $score_calculator;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	public Email_Service $email_service;

	/**
	 * Email job manager.
	 *
	 * @var Email_Results_Job_Manager
	 */
	public Email_Results_Job_Manager $email_job_manager;

	/**
	 * Constructor - initializes all dependencies.
	 */
	public function __construct() {
		// Repositories.
		$this->competitions = new Competitions_Repository();
		$this->members      = new Members_Repository();
		$this->images       = new Images_Repository();
		$this->votes        = new Votes_Repository();
		$this->logs         = new Logs_Repository();

		// Services.
		$this->analytics        = new Results_Analytics( $this->competitions, $this->images, $this->members, $this->votes );
		$this->score_calculator = new Score_Calculator( $this->images, $this->votes );
		$this->email_service    = new Email_Service();
		$this->email_job_manager = new Email_Results_Job_Manager(
			$this->competitions,
			$this->images,
			$this->members,
			$this->votes,
			$this->analytics,
			$this->score_calculator,
			$this->email_service
		);
	}
}
