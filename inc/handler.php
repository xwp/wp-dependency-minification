<?php
/**
 * @since 1.0
 */
class DepMin_Handler {

	/**
	 * @return void
	 * @since 1.0
	 */
	public function __construct() {
		add_action( 'pre_get_posts', array( $this, 'handle_request' ) );
	}

	/**
	 * Handle a request for the minified resource.
	 *
	 * @return void
	 * @since 1.0
	 */
	public function handle_request() {

		$ext = get_query_var( 'depmin_file_ext' );
		$src_hash = get_query_var( 'depmin_src_hash' );

		if ( empty( $src_hash ) || empty( $ext ) )
			return;

		try {
			ob_start();

			if ( 'js' === $ext ) {
				header( 'Content-Type: application/javascript; charset=utf-8' );
			} else {
				header( 'Content-Type: text/css; charset=utf-8' );
			}

			$cached = DepMin_Cache::get( DepMin_Cache::get_key( $src_hash ) );

			if ( empty( $cached ) ) {
				throw new DepMin_Exception( 'Unknown minified dependency bundle.', 404 );
			}
			if ( ! empty( $cached['error'] ) ) {
				throw new DepMin_Exception( $cached['error'], 500 );
			}

			// Send the response headers for caching
			header( 'Expires: ' . str_replace( '+0000', 'GMT', gmdate( 'r', $cached['expires'] ) ) );
			if ( ! empty( $cached['last_modified'] ) ) {
				header( 'Last-Modified: ' . str_replace( '+0000', 'GMT', gmdate( 'r', $cached['last_modified'] ) ) );
			}
			if ( ! empty( $cached['etag'] ) ) {
				header( 'ETag: ' . $cached['etag'] );
			}

			$is_not_modified = false;
			if ( time() < $cached['expires'] ) {
				$is_not_modified = Dependency_Minification::$options['allow_not_modified_responses'] && (
					(
						! empty( $_SERVER['HTTP_IF_NONE_MATCH'] )
						&&
						! empty( $cached['etag'] )
						&&
						trim( $_SERVER['HTTP_IF_NONE_MATCH'] ) === $cached['etag']
					)
					||
					(
						! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
						&&
						! empty( $cached['last_modified'] )
						&&
						strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) <= $cached['last_modified']
					)
				);
			}

			if ( $is_not_modified ) {
				status_header( 304 );
			} else {
				status_header( 200 );
				$out = $cached['contents'];

				global $compress_scripts, $compress_css;
				script_concat_settings();
				$compress = ( 'js' === $ext ? $compress_scripts : $compress_css );
				$force_gzip = ( $compress && defined( 'ENFORCE_GZIP' ) && ENFORCE_GZIP );

				// Copied from /wp-admin/load-scripts.php
				if ( $compress && ! ini_get( 'zlib.output_compression' ) && 'ob_gzhandler' != ini_get( 'output_handler' ) && isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) {
					header( 'Vary: Accept-Encoding' ); // Handle proxies
					if ( false !== stripos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'deflate' ) && function_exists( 'gzdeflate' ) && ! $force_gzip ) {
						header( 'Content-Encoding: deflate' );
						$out = gzdeflate( $out, 3 );
					} elseif ( false !== stripos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) && function_exists( 'gzencode' ) ) {
						header( 'Content-Encoding: gzip' );
						$out = gzencode( $out, 3 );
					}
				}
				print $out; // xss ok
			}
			ob_end_flush();
		}
		catch(Exception $e) {
			ob_end_clean();
			$status = null;
			$message = '';
			if ( $e instanceof DepMin_Exception || Dependency_Minification::$options['show_error_messages'] ) {
				$status = $e->getCode();
				$message = $e->getMessage();
			} else {
				error_log(
					sprintf(
						'%s: %s via URI %s',
						__METHOD__,
						$e->getMessage(),
						esc_url_raw( $_SERVER['REQUEST_URI'] )
						)
					);
				$message = 'Unexpected error occurred.';
			}
			if ( empty($status) ) {
				$status = 500;
			}
			status_header( $status );
			nocache_headers();
			header( 'Content-Type: text/plain' );
			print $message; // xss ok
		}
		exit;
	}

}