<?php
/**
 * Redirect interception exception for controller characterization tests.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

/**
 * Thrown by the redirect interceptor to capture the target URL before exit.
 */
class Redirect_Exception extends \Exception {}
