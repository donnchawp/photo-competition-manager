<?php
/**
 * Plugin Name: Club Competitions
 * Description: Manage photography club competitions, submissions, and voting.
 * Version: 0.1.0
 * Author: Donncha O Caoimh
 * License: GPL2
 *
 * @package ClubCompetitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

register_activation_hook(
	__FILE__,
	array( \ClubCompetitions\Install\Activator::class, 'activate' )
);
