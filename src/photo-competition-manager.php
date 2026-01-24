<?php
/**
 * Plugin Name: Photo Competition Manager
 * Description: Manage photography competitions, submissions, and voting.
 * Version: 0.2.0
 * Author: Donncha O Caoimh
 * License: GPL2
 * Text Domain: photo-competition-manager
 *
 * @package PhotoCompetitionManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin directory path.
if ( ! defined( 'PHOTO_COMPETITION_MANAGER_PLUGIN_DIR' ) ) {
	define( 'PHOTO_COMPETITION_MANAGER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

require_once __DIR__ . '/includes/bootstrap.php';

register_activation_hook(
	__FILE__,
	array( \PhotoCompetitionManager\Install\Activator::class, 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( \PhotoCompetitionManager\Install\Deactivator::class, 'deactivate' )
);
