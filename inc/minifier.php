<?php
/**
 * @since 1.0
 */
abstract class DepMin_Minifier {

	/**
	 * @var array
	 * @since 1.0
	 */
	protected $args = array();

	/**
	 * @var DepMin_SrcInfo[]
	 * @since 1.0
	 */
	protected $srcs = array();

	/*** Methods **************************************************************/

	/**
	 * @throw DepMin_Exception
	 * @return DepMin_Minifier
	 * @since 1.0
	 */
	public function set_args( array $args ) {

		$this->args = wp_parse_args( $args, array(
			// @TODO: Add more minification options.
			'type' => '', // 'styles' or 'scripts'
		) );

		return $this;

	}

	/**
	 * @param DepMin_SrcInfo[] $srcs
	 * @return DepMin_Minifier
	 * @since 1.0
	 */
	public function set_srcs( array $srcs ) {

		foreach( $srcs as $src ) {

			if ( ! is_a( $src, 'DepMin_SrcInfo' ) ) {
				$src = new DepMin_SrcInfo( $src );
			}

			$this->srcs[]= $src;

		}

		return $this;
	}

	/**
	 * @return mixed
	 * @since 1.0
	 */
	public function get_args( $key = '' ) {

		if ( ! empty( $key ) ) {

			if ( isset( $this->args[ $key ] ) ) {
				return $this->args[ $key ];
			}

			return false;

		}

		return $this->args;

	}

	/**
	 * @return DepMin_SrcInfo[]
	 * @since 1.0
	 */
	public function get_srcs() {
		return $this->srcs;
	}

	/**
	 * @throw DepMin_Exception
	 * @return array
	 * @since 1.0
	 */
	abstract function minify();

}

/**
 * @since 1.0
 */
class DepMin_Minifier_Default extends DepMin_Minifier {

	/**
	 * @return string
	 * @since 1.0
	 */
	private function get_unminified_contents() {

		$i = 0;
		$unminified = '';

		foreach( $this->get_srcs() as $source ) {

			$contents = $source->get_contents();

			if ( 'styles' === $this->get_args( 'type' ) ) {

				$dir_path = dirname( $source->get_path() );

				// Rewrite relative paths in CSS.
				if ( ! empty( $dir_path ) ) {
					require_once Dependency_Minification::path( 'minify/CSS/UriRewriter.php' );
					$contents = Minify_CSS_UriRewriter::rewrite( $contents, $dir_path );
				}

			}

			$unminified .= $contents;

			if ( $i < ( count( $this->get_srcs() ) - 1 ) ) {

				/*
				 * @note
				 * Semicolon needed in case a file lacks trailing semicolon
				 * like `x = {a:1}` and the next file is IIFE (function(){}),
				 * then it would get combined as x={a:1}(function(){}) and attempt
				 * to pass the anonymous function into a function {a:1} which
				 * is of course an object and not a function. Culprit here
				 * is the comment-reply.js in WordPress.
				 */
				switch( $this->get_args( 'type' ) ) {

					case 'scripts':
						$unminified .= "\n;;\n";
						break;

					case 'styles':
						$unminified .= "\n\n";
						break;

				}

			}

			$i++;

		}

		return $unminified;
	}

	/**
	 * @return array
	 * @since 1.0
	 */
	public function minify() {

		$minified = '';
		$unminified = $this->get_unminified_contents();

		switch( $this->get_args( 'type' ) ) {

			case 'styles':
				require_once Dependency_Minification::path( 'minify/CSS/Compressor.php' );
				$minified = Minify_CSS_Compressor::process( $unminified );
				break;

			case 'scripts':
				require_once Dependency_Minification::path( 'minify/JS/JSMin.php' );
				$minified = JSMin::minify( $unminified );
				break;

		}

		return array(
			'contents' => $minified,
			'minified_size' => strlen( $minified ),
			'unminified_size' => strlen( $unminified ),
		);

	}

}

/**
 * @since 1.0
 */
class DepMin_Minify {

	/**
	 * @var string
	 * @since 1.0
	 */
	const CRON_ACTION  = 'minify_dependencies';

	/**
	 * @return array|bool
	 * @since 1.0
	 */
	public static function minify( array $srcs, $type ) {

		$minifier = apply_filters( 'DepMin_minifier_class', 'DepMin_Minifier_Default' );

		if ( ! empty( $minifier ) && class_exists( $minifier ) ) {

			$minifier = new $minifier();
			$minifier->set_srcs( $srcs )
					 ->set_args( array(
						'type' => $type,
					 ) );

			return $minifier->minify();

		}

		return false;
	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function cron_action( $args = '' ) {

		$args = wp_parse_args( $args, array(
			'unminified_size' => false,
			'minified_size' => false,
			'last_modified' => false,
			'contents' => false,
			'expires' => false,
			'pending' => true,
			'deps' => array(),
			'etag' => false,
			'type' => '',
		) );

		$srcs = wp_list_pluck( $args['deps'], 'src' );
		$vers = wp_list_pluck( $args['deps'], 'ver' );

		$src_hash = DepMin_hash_array( $srcs );
		$ver_hash = DepMin_hash_array( $vers );

		try {

			foreach( $srcs as &$src ) {
				if ( ! preg_match( '|^(https?:)?//|', $src ) ) {
					$src = site_url($src);
				}
			}

			if ( ( $minified = DepMin_Minify::minify( $srcs, $args['type'] ) ) ) {

				$args['contents'] = "/*! This minified dependency bundle includes:\n";

				foreach ( $srcs as $key => $src ) {
					$args['contents'] .= sprintf( " * %02d. %s\n", $key + 1, $src );
				}

				$args['contents'] .= " */\n\n" . $minified['contents'];
				$args['unminified_size'] = $minified['unminified_size'];
				$args['minified_size'] = $minified['minified_size'];

			}

			$max_age = apply_filters( 'dependency_minification_cache_control_max_age',
							(int) Dependency_Minification::$options['cache_control_max_age_cache'],
							$srcs
						);

			$args['expires'] = time() + $max_age;
			$args['error'] = null;

		} catch ( Exception $e ) {

			error_log(
				sprintf(
					'%s in %s: %s for srcs %s',
					get_class( $e ),
					__FUNCTION__,
					$e->getMessage(),
					implode( ',', $srcs )
				)
			);

			$args['error'] = $e->getMessage();

			$max_age = apply_filters( 'dependency_minification_cache_control_max_age_error',
							(int) Dependency_Minification::$options['cache_control_max_age_error'],
							$srcs
						);

			$args['expires'] = time() + $max_age;

		}

		$args['etag'] = implode( '.', array( $src_hash, $ver_hash ) );
		$args['last_modified'] = time();
		$args['pending'] = false;

		DepMin_Cache::set( DepMin_Cache::get_key( $src_hash ), $args );

	}

	/**
	 * @param array $deps
	 * @param string $type (scripts or styles)
	 * @return string
	 * @since 1.0
	 */
	public static function get_minified_dependency_url( array $deps, $type ) {
		$srcs = wp_list_pluck( $deps, 'src' );
		$vars = wp_list_pluck( $deps, 'ver' );
		$handles = wp_list_pluck( $deps, 'handle' );

		$src = trailingslashit( home_url( Dependency_Minification::$options['endpoint'] ) );
		$src .= implode( '.', array(
					implode( ',', $handles ),
					DepMin_hash_array( $srcs ),
					DepMin_hash_array( $vars ),
					$type === 'scripts' ? 'js' : 'css',
			) );

		return $src;
	}

	/**
	 * @return array
	 * @since 1.0
	 */
	public static function get_pending_dependencies() {

		$list = array();
		foreach ( _get_cron_array() as $cron ) {
			if ( isset( $cron[ self::CRON_ACTION ] ) ) {
				foreach ( $cron[ self::CRON_ACTION ] as $event ) {
					$data = reset( $event['args'] );
					if ( ! empty( $data['pending'] ) ) {
						$list[] = $data;
					}
				}
			}
		}

		return $list;
	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public static function hook_cron_action() {
		add_action( self::CRON_ACTION, array( __CLASS__, 'cron_action' ) );
	}

}