<?php
/**
 * @since 1.0
 */
class DepMin_Options extends ArrayObject {

	/**
	 * @return void
	 * @since 1.0
	 */
	public function __construct() {

		$options = wp_parse_args( $this->get_options(), array(
				'endpoint'                            => '_minify',
				'default_exclude_remote_dependencies' => true,
				'cache_control_max_age_cache'         => 2629743, // 1 month in seconds
				'cache_control_max_age_error'         => 60 * 60, // 1 hour, to try minifying again
				'allow_not_modified_responses'        => true, // only needs to be true if not Akamaized and max-age is short
				'admin_page_capability'               => 'edit_theme_options',
				'show_error_messages'                 => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'disable_if_wp_debug'                 => true,
				'exclude_dependencies'                => array(),
				'disabled_on_conditions'              => array(
					'all' => false,
					'loggedin' => false,
					'admin' => false,
					'queryvar' => false,
				),
		) );

		$options = apply_filters( 'dependency_minification_options', $options );

		parent::__construct( $options );

	}

	/**
	 * @access protected
	 * @return array
	 * @since 1.0
	 */
	protected function get_options() {
		return get_option( 'dependency_minification_options', array() );
	}

	/**
	 * @access protected
	 * @return bool
	 * @since 1.0
	 */
	protected function set_options( array $options ) {
		return update_option( 'dependency_minification_options', $options );
	}

	/*** ArrayObject Methods **************************************************/

	public function exchangeArray( array $input ) {
		if ( $this->set_options( $input ) ) {
			return parent::exchangeArray( $input );
		}
	}

	public function offsetSet( $index, $newval ) {
		$options = $this->get_options();
		$options[ $index ] = $newval;

		if ( $this->set_options($options) ) {
			parent::offsetSet( $index, $newval );
		}
	}

	public function offsetUnset( $index ) {
		$options = $this->get_options();
		unset( $options[ $index ] );

		if ( $this->set_options( $options ) ) {
			parent::offsetUnset( $index );
		}
	}

}
