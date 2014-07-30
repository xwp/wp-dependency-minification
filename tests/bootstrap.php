<?php
/**
 * Tests bootstrapper
 *
 * @author X-Team <x-team.com>
 * @author Jonathan Bardo <jonathan.bardo@x-team.com>
 */

// Use in code to trigger custom actions
define( 'WP_DEP_MIN_TESTS', true );

if ( ! $_tests_dir = getenv( 'WP_TESTS_DIR' ) ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Require WP test function file so we can access useful methods
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function() {
		// Manually load plugin
		require dirname( dirname( __FILE__ ) ) . '/dependency-minification.php';

		$GLOBALS['pagenow'] = '';

		// Call Activate plugin function
		Dependency_Minification::setup();
	}
);

// Removes all sql tables on shutdown
// Empty all tables so we don't deal with leftovers
// Do this action last
tests_add_filter(
	'shutdown',
	function() {
		drop_tables();
	},
	999999
);

require $_tests_dir . '/includes/bootstrap.php';
require dirname( __FILE__ ) . '/testcase.php';
