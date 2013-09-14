<?php

/*** Options API **************************************************************/

/**
 * Get all the options list.
 *
 * @return array
 * @since X
 */
function depmin_get_options() {

	// A shortcut for the class name :)
	$depmin = 'Dependency_Minification';

	if ( empty( $depmin::$options ) ) {

		$depmin::$options = apply_filters( 'depmin_options_defaults', array(
			'endpoint'                            => '_minify',
			'default_exclude_remote_dependencies' => true,
			'cache_control_max_age_cache'         => 2629743, // 1 month in seconds
			'cache_control_max_age_error'         => 60 * 60, // 1 hour, to try minifying again
			'allow_not_modified_responses'        => true, // only needs to be true if not Akamaized and max-age is short
			'admin_page_capability'               => 'edit_theme_options',
			'show_error_messages'                 => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'disable_if_wp_debug'                 => true,
			'exclude_dependencies'                => '',
			'disabled_on_conditions'              => array(),
		) );

		$depmin::$options = array_merge( $depmin::$options, get_option( 'depmin_options', array() ) );

	} // end if

	return apply_filters( 'dependency_minification_options', (array) $depmin::$options );

} // end depmin_get_options()

/**
 * Set all the plugin options.
 *
 * @return bool
 * @since X
 */
function depmin_update_options( $options ) {

	if ( ! update_option( 'depmin_options', $options ) )
	    return false;

	Dependency_Minification::$options = $options;

	return true;

} // end depmin_update_options()

/**
 * Delete all the plugin options.
 *
 * @return bool
 * @since X
 */
function depmin_delete_options() {

	if ( ! delete_option( 'depmin_options' ) )
	    return false;

	Dependency_Minification::$options = array();

	return true;

} // end depmin_delete_options()

/**
 * Get an option value.
 *
 * @return mixed
 * @since X
 */
function depmin_get_option( $option ) {

	$options = depmin_get_options();

	if ( ! isset( $options[ $option ] ) )
	    return;

	return $options[ $option ];

} // end depmin_get_option()


/*** Helpers ******************************************************************/

/**
 * Check if the current page isn't in the back-end or login/register page.
 *
 * @return bool
 * @since X
 */
function depmin_is_frontend() {

	global $pagenow;

	$is_frontend = ! is_admin();

	if ( $is_frontend )
	    $is_frontend = ! in_array( $pagenow, array( 'wp-login.php', 'wp-register.php' ) );

	return apply_filters( 'depmin_is_frontend', $is_frontend );

} // end depmin_is_frontend()

/**
 * Check if the plugin is disabled depending on the user options or the current context.
 *
 * @return bool
 * @since X
 */
function depmin_is_disabled() {

	static $disabled = null;

	if ( is_null( $disabled ) ) {

		/*** Disable on ugly permalinks *******************************/

		$disabled = empty( $GLOBALS['wp_rewrite']->permalink_structure );


		/*** Disable flag *********************************************/

		if ( ! $disabled && defined( 'DEPENDENCY_MINIFICATION_DEFAULT_DISABLED' ) )
		    $disabled = (bool) DEPENDENCY_MINIFICATION_DEFAULT_DISABLED;


		/*** Disable on debug *****************************************/

		if ( ! $disabled && depmin_get_option( 'disable_if_wp_debug' ) )
			$disabled = ( defined( 'WP_DEBUG' ) && WP_DEBUG );


		/*** Disable on conditions ************************************/

		if ( ! $disabled ) {

			foreach( (array) depmin_get_option( 'disabled_on_conditions' ) as $key => $value ) {

				switch( $key ) {

					case 'all':
						$disabled = ! empty( $value );
						break;

					case 'admin':
					case 'loggedin':

						$disabled = is_user_logged_in();

						if ( 'admin' === $key && current_user_can( 'manage_options' ) )
							$disabled = true;

						break;

					case 'queryvar':

						if ( isset( $value['enabled'] ) && $value['enabled'] )
							$disabled = ( ! empty( $value['key'] ) && isset( $_GET[ $value['key'] ] ) );

						break;

				} // end switch

				if ( $disabled )
					break;

			} // end foreach

		} // end if

	} // end if

	return apply_filters( 'depmin_is_disabled', $disabled );

} // end depmin_is_disabled()

/**
 * Try to convert the script URL to absolute path.
 *
 * @param string $src The script URL.
 * @return string|bool
 * @since X
 */
function depmin_get_src_abspath( $src ) {

	$path = false;

	if ( ! empty( $src ) && depmin_is_self_hosted_src( $src ) ) {

		// Parse the URL and return the possible path.
		$path = ltrim( parse_url( $src, PHP_URL_PATH ), '/' );
		$path = path_join( $_SERVER['DOCUMENT_ROOT'], $path );

		if ( ! file_exists( $path ) )
			$path = false;

	} // end if

	return apply_filters( 'depmin_get_src_abspath', $path, $src );

} // end depmin_get_src_abspath()

/**
 * Check if the given script URL is self-hosted.
 *
 * @param string $src The script URL.
 * @return bool
 * @since X
 */
function depmin_is_self_hosted_src( $src ) {

	$self_hosted = false;
	$parsed_url = parse_url( $src );

	if ( is_array( $parsed_url ) ) {

		if ( empty( $parsed_url['host'] ) ) {

			if ( substr( $parsed_url['path'], 0, 1) === '/' )
				$self_hosted = true;

		} else {

			if ( $parsed_url['host'] === parse_url( get_home_url(), PHP_URL_HOST ) )
				$self_hosted = true;

		} // end if

	} // end if

	return apply_filters( 'depmin_is_self_hosted_src', $self_hosted, $src );

} // end depmin_is_self_hosted_src()

/**
 * Get a script file contents.
 *
 * @param string $src The script URL.
 * @param string $abspath
 * @return string|bool
 * @since X
 */
function depmin_get_src_contents( $src, $abspath = '' ) {

	$contents = false;

	if ( empty( $abspath ) )
		$abspath = depmin_get_src_abspath( $src );

	// First attempt to get the file from the filesystem
	if ( ! empty( $abspath ) )
		$contents = file_get_contents( $abspath, false );

	// Dependency is not self-hosted or it the filesystem read failed, so do HTTP request
	if ( false === $contents ) {

		$r = wp_remote_get( $src );

		if ( is_wp_error( $r ) ) {
			throw new Exception( "Failed to retrieve {$src} : " . $r->get_error_message());

		} elseif ( intval( wp_remote_retrieve_response_code( $r ) ) !== 200 ) {
			throw new Dependency_Minification_Exception( sprintf( 'Request for %s returned with HTTP %d %s', $src, wp_remote_retrieve_response_code( $r ), wp_remote_retrieve_response_message( $r ) ) );
		}

		$contents = wp_remote_retrieve_body( $r );

	}

	// Remove the BOM.
	$contents = preg_replace( "/^\xEF\xBB\xBF/", '', $contents );

	return $contents;

} // end depmin_get_src_contents()

/**
 * A helper function to check if a given URL is included in the list.
 *
 * @return bool
 * @since X
 */
function depmin_is_url_included( $needle, $haystack ) {

	foreach ( (array) $haystack as $entry ) {

		if ( ! empty( $entry ) && strpos( $needle, $entry ) !== false ) {
			return true;
		}

	}

	return false;

} // end depmin_is_url_included()