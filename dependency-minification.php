<?php
/**
 * Plugin Name: Dependency Minification
 * Description: Concatenates and minifies scripts and stylesheets. Please install and activate <a href="http://scribu.net" target="_blank">scribu</a>'s <a href="http://wordpress.org/plugins/proper-network-activation/" target="_blank">Proper Network Activation</a> plugin <em>before</em> activating this plugin <em>network-wide</em>.
 * Version: 1.0
 * Author: X-Team
 * Author URI: http://x-team.com/wordpress/
 * Text Domain: dependency-minification
 * License: GPLv2+
 * Domain Path: /languages
 */

/**
 * Copyright (c) 2013 X-Team (http://x-team.com/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Main Dependency Minification plugin class.
 *
 * @since 0.1
 */
class Dependency_Minification {

	/**
	 * @var float
	 * @since 1.0
	 */
	const VERSION = '1.0';

	/*** Properties ***********************************************************/

	/**
	 * @var DepMin_Admin
	 * @since 1.0
	 */
	public static $admin;

	/**
	 * @var DepMin_Options
	 * @since 1.0
	 */
	public static $options;

	/**
	 * @var DepMin_Handler
	 * @since 1.0
	 */
	public static $handler;

	/**
	 * @var DepMin_Collation
	 * @since 1.0
	 */
	public static $collation;

	/**
	 * @var array
	 * @since 1.0
	 */
	public static $query_vars = array(
		'depmin_handles',
		'depmin_src_hash',
		'depmin_ver_hash',
		'depmin_file_ext',
	);

	/*** Methods **************************************************************/

	/**
	 * @access private
	 * @return void
	 * @since 1.0
	 */
	private function load_includes() {

		// Load the helpers functions.
		require self::path( 'inc/helpers.php' );

	}

	/**
	 * @access private
	 * @return void
	 * @since 1.0
	 */
	private function setup_actions() {

		DepMin_Minify::hook_cron_action();
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'filter_query_vars' ) );

	}

	/**
	 * @access private
	 * @return void
	 * @since 1.0
	 */
	private function setup() {

		self::$options = new DepMin_Options();
		self::$handler = new DepMin_Handler();
		self::$collation = new DepMin_Collation();

		if ( is_admin() ) {

			self::$admin = new DepMin_Admin();

		}

	}

	/*** Static Methods *******************************************************/

	/**
	 * @filter query_vars
	 * @return array
	 * @since 1.0
	 */
	public static function filter_query_vars( $query_vars ) {
		return array_merge( $query_vars, self::$query_vars );
	}

	/**
	 * @return string
	 * @since 1.0
	 */
	public static function get_rewrite_regex() {

		if ( is_null( self::$options ) )
			self::$options = new DepMin_Options();

		return self::$options['endpoint'] . '/([^/]+?)\.([0-9a-f]+)(?:\.([0-9a-f]+))?\.(css|js)';
	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function add_rewrite_rules() {

		// Get the rewrite regex.
		$regex    = self::get_rewrite_regex();

		$redirect = 'index.php?';

		for ( $i = 0; $i < count( self::$query_vars ); $i += 1 ) {
			$redirect .= sprintf( '%s=$matches[%d]&', self::$query_vars[$i], $i + 1 );
		}

		add_rewrite_rule( $regex, $redirect, 'top' );
	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function remove_rewrite_rules() {
		global $wp_rewrite;
		$regex = self::get_rewrite_regex();
		unset( $wp_rewrite->extra_rules_top[ $regex ] );
	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function autoload( $class_name ) {

		switch( $class_name ) {

			case 'DepMin_Admin':
				require self::path( 'inc/admin.php'		);
				break;

			case 'DepMin_Options':
				require self::path( 'inc/options.php'	);
				break;

			case 'DepMin_SrcInfo':
				require self::path( 'inc/helpers.php'	);
				break;

			case 'DepMin_Handler':
				require self::path( 'inc/handler.php'	);
				break;

			case 'DepMin_Collation':
				require self::path( 'inc/collation.php'	);
				break;

			case 'DepMin_Minify':
			case 'DepMin_Minifier':
			case 'DepMin_Minifier_Default':
				require self::path( 'inc/minifier.php'	);
				break;

			case 'DepMin_Cache':
			case 'DepMin_Cache_Default':
			case 'DepMin_Cache_Interface':
				require self::path( 'inc/cache.php'		);
				break;

		}

	}

	/**
	 * @return string
	 * @since 1.0
	 */
	public static function url( $path = '' ) {
		return plugins_url( $path, __FILE__ );
	}

	/**
	 * @return string
	 * @since 1.0
	 */
	public static function path( $path = '' ) {

		$base = plugin_dir_path( __FILE__ );

		if ( ! empty( $path ) )
			$path = path_join( $base, $path );
		else
			$path = untrailingslashit( $base );

		return $path;
	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function activate() {
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function deactivate() {
		self::remove_rewrite_rule();
		flush_rewrite_rules();
	}

	/*** SingleTone ***********************************************************/

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function instance() {

		static $instance;

		if ( is_null( $instance ) ) {

			$instance = new Dependency_Minification();
			$instance->load_includes();
			$instance->setup_actions();
			$instance->setup();

		}

		return $instance;
	}

}

/**
 * @since 1.0
 */
class DepMin_Exception extends Exception {}

// Register the plugin activation and deactivation hooks.
register_deactivation_hook( __FILE__, array( 'Dependency_Minification', 'deactivate' ) );
register_activation_hook( __FILE__, array( 'Dependency_Minification', 'activate' ) );

// Hook the plugin early onto the 'plugins_loaded' action.
add_action( 'plugins_loaded', array( 'Dependency_Minification', 'instance' ), 100 );

// Register the plugin classes autoloader.
spl_autoload_register( array( 'Dependency_Minification', 'autoload' ) );