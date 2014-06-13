<?php
/**
 * Tests Dependency_Minification
 *
 * @author X-Team
 * @author Jonathan Bardo <jonathan.bardo@x-team.com>
 */
class Test_Dep_Min_Admin extends WP_DepMinTestCase {

	/**
	 * Create a user for test
	 */
	public function setUp() {
		parent::setUp();

		//Add admin user to test caps
		// We need to change user to verify editing option as admin or editor
		$administrator_id = $this->factory->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'test_admin',
				'email'      => 'test@localhost.com',
			)
		);
		wp_set_current_user( $administrator_id );
	}

	/**
	 * This is an example test
	 */
	public function test_example() {
		// Put your test here
	}

}
