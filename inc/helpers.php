<?php
/**
 * @since 1.0
 */
class DepMin_SrcInfo {

	/**
	 * @var string
	 * @since 1.0
	 */
	protected $url;

	/**
	 * @var string
	 * @since 1.0
	 */
	protected $path;

	/*** Mehods ***************************************************************/

	/**
	 * @throw DepMin_Exception
	 * @return void
	 * @since 1.0
	 */
	public function __construct( $url, $path = '' ) {

		if ( ! empty( $url ) ) {
			$this->set_url( $url );
		}

		if ( ! empty( $path ) ) {
			$this->set_path( $path );
		}

		if ( empty( $url ) && empty( $path ) ) {
			throw new DepMin_Exception( 'Specific the file path or URL' );
		}

	}

	/**
	 * @throw DepMin_Exception
	 * @return string|bool
	 * @since 1.0
	 */
	public function is_self_hosted() {

		$url = $this->get_url();

		if ( ! empty( $url ) ) {

			$parsed_url = parse_url( $url );

			if ( empty( $parsed_url['host'] ) && substr( $parsed_url['path'], 0, 1 ) === '/' ) {
				return true;
			}

			if ( ! empty( $parsed_url['host'] ) && $parsed_url['host'] === parse_url( get_site_url(), PHP_URL_HOST ) ) {
				return true;
			}

		}

		return false;
	}

	/**
	 * @throw DepMin_Exception
	 * @return string|bool
	 * @since 1.0
	 */
	public function get_contents() {

		$contents = false;
		$url = $this->get_url();
		$path = $this->get_path();

		if ( ! empty( $path ) ) {
			$contents = file_get_contents( $path );
		}

		if ( false === $contents && ! empty( $url ) ) {

			$response = wp_remote_get( $url );

			if ( is_wp_error( $response ) ) {

				throw new DepMin_Exception(
						sprintf( 'Failed to retrieve {%s}: %s',
							$url,
							$response->get_error_message()
						)
					);

			} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {

				throw new DepMin_Exception(
						sprintf( 'Request for %s returned with HTTP %d %s',
							$url,
							wp_remote_retrieve_response_code( $response ),
							wp_remote_retrieve_response_message( $response )
						)
					);

			}

			$contents = wp_remote_retrieve_body( $response );

		}

		// Remove the BOM
		if ( ! empty( $contents ) ) {
			$contents = preg_replace( "/^\xEF\xBB\xBF/", '', $contents );
		}

		return $contents;
	}

	/**
	 * @throw DepMin_Exception
	 * @return void
	 * @since 1.0
	 */
	protected function set_path( $path ) {

		if ( ! file_exists( $path ) ) {
			throw new DepMin_Exception( 'Invalid file path' );
		}

		$this->path = $path;

	}

	/**
	 * @throw DepMin_Exception
	 * @return void
	 * @since 1.0
	 */
	protected function set_url( $url ) {

		if ( ! DepMin_is_vaild_url( $url ) ) {
			throw new DepMin_Exception( 'Invalid file URL' );
		}

		$this->url = $url;

	}

	/**
	 * @return string
	 * @since 1.0
	 */
	public function get_path() {

		if ( empty( $this->path ) && $this->is_self_hosted() ) {

			$this->path = ltrim( parse_url( $this->url, PHP_URL_PATH ), '/' );
			$this->path = path_join( $_SERVER['DOCUMENT_ROOT'], $this->path );

		}

		return $this->path;
	}

	/**
	 * @return string
	 * @since 1.0
	 */
	public function get_url() {
		return $this->url;
	}

}

/**
 * URL Validation.
 *
 * @param string URL to be validated
 * @return bool Validation result
 */
function DepMin_is_vaild_url( $url ) {
	return (bool) filter_var( utf8_uri_encode( $url ), FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED + FILTER_FLAG_HOST_REQUIRED );
}

/**
 * @var bool
 * @since 1.0
 */
function DepMin_is_frontend() {

	return ! (
			is_admin()
			||
			in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ) )
		);

}

/**
 * @var string
 * @since 1.0
 */
function DepMin_hash_array( array $r ) {
	return md5( serialize( $r ) );
}

/**
 * @var bool
 * @since 1.0
 */
function DepMin_is_self_hosted_src( $src ) {

	$parsed_url = parse_url( $src );
	return (
		(
			empty( $parsed_url['host'] )
			&&
			substr( $parsed_url['path'], 0, 1 ) === '/'
		)
		||
		(
			! empty( $parsed_url['host'] )
			&&
			$parsed_url['host'] === parse_url( get_home_url(), PHP_URL_HOST )
		)
	);

}