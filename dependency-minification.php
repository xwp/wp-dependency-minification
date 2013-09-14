<?php
/*
Plugin Name: Dependency Minification
Description: Concatenates and minifies scripts and stylesheets.
Version: 0.9.4
Author: X-Team
Author URI: http://x-team.com/
Text Domain: depmin
*/

class Dependency_Minification {

        /*** Constants ********************************************************/

        /**
         * The plugin version constant.
         *
         * @since X
         * @var float
         */
        const VERSION = '0.9.4';

	const CRON_MINIFY_ACTION = 'minify_dependencies';
	const CACHE_KEY_PREFIX   = 'depmin_cache_';
	const FILENAME_PATTERN   = '([^/]+?)\.([0-9a-f]+)(?:\.([0-9a-f]+))?\.(css|js)';
	const AJAX_ACTION        = 'dependency_minification';
	const AJAX_OPTIONS_ACTION = 'dependency_minification_options';
	const ADMIN_PAGE_SLUG    = 'dependency-minification';
	const ADMIN_PARENT_PAGE  = 'tools.php';

        // @deprecated...
	const DEFAULT_ENDPOINT    = '_minify';


        /*** Properties *******************************************************/

        /**
         * The plugin admin page hook_suffix.
         *
         * @var string
         * @access public
         */
	public static $admin_page_hook;

        /**
         * The plugin run-time options list.
         *
         * @var array
         * @access public
         */
	public static $options = array();

        /**
         * The plugin query vars keys.
         *
         * @var array
         * @access public
         */
	public static $query_vars = array(
                'depmin_handles',
                'depmin_src_hash',
                'depmin_ver_hash',
                'depmin_file_ext',
	);

	protected static $minified_count = 0;

	protected static $is_footer = array(
		'scripts' => false,
		'styles'  => false,
	);


        /*** Functions *******************************************************/

        /**
         * Define the plugin global constants.
         *
         * @return void
         * @since X
         */
        public static function define_constants() {

                // Define the plugin directory path constant.
                define( 'DEPMIN_DIR', plugin_dir_path( __FILE__ ) );

                // Define the plugin directory URL constant.
                define( 'DEPMIN_URL', plugin_dir_url( __FILE__ ) );

        } // end define_constants()

        /**
         * Load the plugin components files.
         *
         * @return void
         * @since X
         */
        public static function load_components() {

                require DEPMIN_DIR . '/inc/common/functions.php';

                if ( is_admin() )
                    require DEPMIN_DIR . '/inc/admin/admin.php';

        } // end load_functions()

        /**
         * Load the plugin files and setup the needed hooks.
         *
         * @return void
         * @since X
         */
	public static function setup() {

                // Load the plugin!
                self::define_constants();
                self::load_components();

		add_action( 'init', array( __CLASS__, 'hook_rewrites' ) );
		add_action( self::CRON_MINIFY_ACTION, array( __CLASS__, 'minify' ), 10, 4 );

		if ( depmin_is_frontend() ) {
			add_filter( 'print_scripts_array', array( __CLASS__, 'filter_print_scripts_array' ) );
			add_filter( 'print_styles_array', array( __CLASS__, 'filter_print_styles_array' ) );
		}

	}

	static function hook_rewrites() {
		add_filter( 'query_vars', array( __CLASS__, 'filter_query_vars' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'handle_request' ) );
		self::add_rewrite_rule();
	}

	static function add_rewrite_rule() {
		$regex = sprintf( '^%s/%s', depmin_get_option( 'endpoint' ), self::FILENAME_PATTERN );
		$redirect = 'index.php?';
		for ( $i = 0; $i < count( self::$query_vars ); $i += 1 ) {
			$redirect .= sprintf( '%s=$matches[%d]&', self::$query_vars[$i], $i + 1 );
		}
		add_rewrite_rule( $regex, $redirect, 'top' );
	}

	/**
	 * register_activation_hook
	 */
	static function activate() {
		self::setup();
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * register_deactivation_hook
	 */
	static function deactivate() {}

	/**
	 * @filter query_vars
	 */
	static function filter_query_vars( $query_vars ) {
		return array_merge( $query_vars, self::$query_vars );
	}

	/**
	 * @filter print_styles_array
	 */
	static function filter_print_styles_array( $handles ) {
		return self::filter_print_dependency_array( $handles, 'styles' );
	}

	/**
	 * @filter print_scripts_array
	 */
	static function filter_print_scripts_array( $handles ) {
		return self::filter_print_dependency_array( $handles, 'scripts' );
	}


	static function hash_array( array $arr ) {
		return md5( serialize( $arr ) );
	}

	static function generate_etag( $src_hash, $ver_hash ) {
		return implode( '.', array( $src_hash, $ver_hash ) );
	}

	static function get_cache_option_name( $src_hash ) {
		assert( is_string( $src_hash ) );
		return self::CACHE_KEY_PREFIX . $src_hash;
	}

	/**
	 * @param array $deps
	 * @param string $type (scripts or styles)
	 * @return string
	 */
	static function get_dependency_minified_url( array $deps, $type ) {
		$src_hash = self::hash_array( wp_list_pluck( $deps, 'src' ) );
		$ver_hash = self::hash_array( wp_list_pluck( $deps, 'ver' ) );
		$src = trailingslashit( home_url( depmin_get_option( 'endpoint' ) ) );
		$src .= implode( '.', array(
			implode( ',', wp_list_pluck( $deps, 'handle' ) ),
			$src_hash,
			$ver_hash,
			$type === 'scripts' ? 'js' : 'css',
		) );
		return $src;
	}

	/**
	 * Separate external from internal (local) dependencies and then group the
	 * internal resources into maximal groups.
	 * @param {array} $handles
	 * @param {string} $type (scripts|styles)
	 */
	static function filter_print_dependency_array( array $handles, $type ) {
		assert( in_array($type, array( 'scripts', 'styles' ) ) );
		assert( isset($GLOBALS["wp_{$type}"]) );
		$wp_deps = &$GLOBALS["wp_{$type}"];
		assert( is_a($wp_deps, 'WP_Dependencies') );

		/**
		 * Determine if minification is enabled for the provided $handles.
		 * Note that we cannot use the $concatenate_scripts global set by script_concat_settings
		 * because it is intended to only be used in the WP Admin
		 * Plugin is automatically disabled if pretty permalinks is not activated
		 */
                $disabled = depmin_is_disabled();
		$disabled = apply_filters( 'dependency_minification_disabled', $disabled, $handles, $type );
		$disabled = apply_filters( "dependency_minification_disabled_{$type}", $disabled, $handles );
		if ( $disabled ) {
			return $handles;
		}

		// @todo There should be a better way to determine which group we are in
		$current_group = (int) self::$is_footer[$type]; // false => 0, true => 1

		$handles_in_group = array();
		foreach ( $handles as $handle ) {
			$must_process_handle = (
				$wp_deps->groups[$handle] === $current_group
				||
				// Handle case where script is erroneously enqueued without in_footer=true (here's lookin at you, PollDaddy)
				(
					$wp_deps->groups[$handle] < $current_group
					&&
					!in_array($handle, $wp_deps->done)
				)
			);

			if ( $must_process_handle ) {
				$handles_in_group[] = $handle;
			}
		}

		$filtered_handles = array();
		$groups = self::group_dependencies_by_exclusion( $handles_in_group, $wp_deps );

		foreach ( $groups as $group ) {
			// $internal_groups as $extra => $handles_in_group
			if ( empty( $group['handles'] ) ) {
				continue;
			}

			if ( $group['excluded'] ) {
				$filtered_handles = array_merge( $filtered_handles, $group['handles'] );
				continue;
			}
			$extra = empty( $group['extra'] ) ? array() : $group['extra'];

			$deps = array();
			foreach ( $group['handles'] as $handle ) {
				$deps[] = array(
					'handle' => $handle,
					'src' => $wp_deps->registered[$handle]->src,
					'ver' => $wp_deps->registered[$handle]->ver,
				);
			}
			$srcs = wp_list_pluck( $deps, 'src' );
			$src_hash = self::hash_array( $srcs );
			$ver_hash = self::hash_array( wp_list_pluck( $deps, 'ver' ) );

			$cache_option_name = self::get_cache_option_name( $src_hash );
			$cached = get_option( $cache_option_name );
			$cached_ver_hash = null;
			if ( ! empty( $cached['deps'] ) ) {
				$cached_ver_hash = self::hash_array( wp_list_pluck( $cached['deps'], 'ver' ) );
			}

			$is_error = (
				! empty( $cached['error'] )
				&&
				$ver_hash === $cached_ver_hash
				&&
				time() < $cached['expires']
			);

			$is_stale = (
				empty( $cached )
				||
				time() > $cached['expires']
				||
				$ver_hash !== $cached_ver_hash
			);

			if ( $is_error ) {
				if ( depmin_get_option( 'show_error_messages' ) ) {
					print "\n<!--\nDEPENDENCY MINIFICATION\n";
					printf( "Error: %s\n", $cached['error'] );
					printf( "Minification will be attempted again in %s seconds,\n", $cached['expires'] - time() );
					printf( "or if the version in one of the problematic dependencies is modified:\n" );
					foreach ( $srcs as $i => $src ) {
						printf( " - %02d. %s\n", $i + 1, $src );
					}
					printf( "-->\n" );
				}
				$filtered_handles = array_merge( $filtered_handles, $group['handles'] );
			} elseif ( $is_stale ) {
				printf( "\n<!--\nDEPENDENCY MINIFICATION\n" );
				printf( "Scheduled immediate single event (via wp-cron) to minify the following:\n" );
				foreach ( $srcs as $i => $src ) {
					printf( " - %02d. %s\n", $i + 1, $src );
				}
				printf( "-->\n" );

				// @todo We could store the info in the option, and just pass the cache key to the cron; this would allow reliable passing of request_uri

				$scheduled = time();
				$args = array_merge(
					array(
						'expires' => false,
						'last_modified' => false,
						'etag' => false,
						'unminified_size' => false,
						'contents' => false,
						'pending' => true,
					),
					compact( 'type', 'deps', 'scheduled' )
				);

				wp_schedule_single_event( $scheduled, self::CRON_MINIFY_ACTION, array( $args ) );
				// The bundle is not ready yet, so re-use the existing dependencies
				$filtered_handles = array_merge( $filtered_handles, $group['handles'] );
			} else {
				self::$minified_count += 1;
				$new_handle = sprintf('minified-%d', self::$minified_count);
				$filtered_handles[] = $new_handle;
				$src = self::get_dependency_minified_url( $deps, $type );

				// Deps are registered without versions since the URL includes the version (ver_hash)
				if ( 'scripts' === $type ) {
					$in_footer = !empty( $extra['group'] ); // @todo what if the group is not 0 or 1?
					wp_register_script( $new_handle, $src, array(), null, $in_footer );
				} elseif ( 'styles' === $type ) {
					wp_register_style( $new_handle, $src, array(), null, $extra['media'] );
				}
				$wp_deps->set_group( $new_handle, /*recursive*/false, $current_group );

				foreach ( $group['handles'] as $handle ) {

					// Aggregate data from scripts (e.g. wp_localize_script)
					if ( !empty( $wp_deps->registered[$handle]->extra ) ) {
						$dep = $wp_deps->registered[$new_handle];
						$data = array();
						foreach ( $wp_deps->registered[$handle]->extra as $key => $value ) {
							$data[ $handle ] = $wp_deps->get_data( $handle, $key );
						}
						if ( 'data' === $key ) {
							$concatenated_data = '';
							foreach ( $data as $data_handle => $data_value ) {
								$concatenated_data .= "/* wp_localize_script($data_handle): */\n";
								$concatenated_data .= "$data_value\n\n";
							}
							$data = $concatenated_data;
						} else {
							for ( $i = 1; $i < count($data); $i += 1 ) {
								assert( $data[0] === $data[$i] );
							}
							$data = array_shift( $data );
						}
						$dep->add_data( $key, $data );
					}

					// Mark the handles as done for the resources that have been minified
					$wp_deps->done[] = $handle;
				}
			}
		}

		// @todo Must be a better way to do this
		self::$is_footer[$type] = true; // for the next invocation

		return $filtered_handles;
	}

	/**
	 * @return {array} Two members, the 1st containing external handles and the 2nd containing internal handles
	 */
	static function group_dependencies_by_exclusion( $handles, WP_Dependencies $wp_deps ) {
		$groups = array();

		// First create groups based on whether they are excluded from minification
		$last_was_excluded = null;
		foreach ( $handles as $handle ) {
			$src = $wp_deps->registered[$handle]->src;
			$is_local = depmin_is_self_hosted_src( $src );
			$is_excluded = !$is_local && depmin_get_option( 'default_exclude_remote_dependencies' );
			$is_excluded = $is_excluded || depmin_is_url_included( $src, self::$options['exclude_dependencies'] );
			$is_excluded = apply_filters( 'dependency_minification_excluded', $is_excluded, $handle, $src );

			if ( $last_was_excluded !== $is_excluded ) {
				$groups[] = array(
					'excluded' => $is_excluded,
					'handles' => array(),
				);
			}
			$groups[ count( $groups ) - 1 ]['handles'][] = $handle;
			$last_was_excluded = $is_excluded;
		}

		// Now divide up the groups to create bundles that share the same extras (e.g. stylesheet media or conditional)
		$bundled_groups = array();
		foreach ( $groups as $group ) {
			if ( $group['excluded'] ) {
				$bundled_groups[] = $group;
			} else {
				$handles_bundles = self::group_handles_by_extra( $group['handles'], $wp_deps );
				foreach ( $handles_bundles as $extra => $handles_bundle ) {
					$bundled_groups[] = array(
						'excluded' => false,
						'extra' => unserialize( $extra ),
						'handles' => $handles_bundle,
					);
				}
			}
		}

		return $bundled_groups;
	}

	/**
	 * @todo This is only applicable for styles, right? The media and conditional extras.
	 * @param {array} $handles
	 * @return {array} Associative array where the keys are the args and extras
	 */
	static function group_handles_by_extra( array $handles, WP_Dependencies $wp_deps ) {
		$bundles = array();
		foreach ( $handles as $handle ) {
			$dep = &$wp_deps->registered[$handle];
			$extra = (array) $dep->extra;
			if ( is_a($wp_deps, 'WP_Styles') ) {
				$extra['media'] = is_string($dep->args) ? $dep->args : 'all';
			}
			unset($extra['suffix']);
			unset($extra['rtl']);
			unset($extra['data']);
			// Default scripts are not assigned 'group', so we use the original 'deps->args' value
			if ( is_a( $wp_deps, 'WP_Scripts' ) && empty( $extra['group'] ) && is_int( $dep->args ) ) {
				$extra['group'] = $dep->args;
			}
			ksort($extra);
			$key = serialize($extra);
			$bundles[$key][] = $handle;
		}
		return $bundles;
	}

	/**
	 * @action minify_dependencies
	 *
	 */
	static function minify( $cached ) {
                extract( $cached );
                $ver_hash = self::hash_array( wp_list_pluck( $deps, 'ver' ) );
                $src_hash = self::hash_array( wp_list_pluck( $deps, 'src' ) );
                $cache_option_name = self::get_cache_option_name( $src_hash );

		try {
			$is_css = ( 'styles' === $type );
			if ( 'scripts' === $type ) {
				require DEPMIN_DIR . '/minify/JS/JSMin.php';
			} elseif ( 'styles' === $type ) {
				require DEPMIN_DIR . '/minify/CSS/UriRewriter.php';
				require DEPMIN_DIR . '/minify/CSS/Compressor.php';
			}

			$unminified_size = 0;
			$srcs = wp_list_pluck( $deps, 'src' );

			// Get the contents of each script
			$contents_for_each_dep = array();
			foreach ( $srcs as $src ) {

                                if ( ! preg_match( '|^(https?:)?//|', $src ) )
                                        $src = site_url( $src );

                                // Get the script absolute path.
                                $abspath = depmin_get_src_abspath( $src );

                                // Get the script file contents.
				$contents = depmin_get_src_contents( $src, $abspath );

				// Rewrite relative paths in CSS
                                if ( 'styles' === $type && ! empty( $abspath ) ) {
                                        $contents = Minify_CSS_UriRewriter::rewrite( $contents, dirname( $abspath ) );
                                }

				$contents_for_each_dep[$src] = $contents;
				$unminified_size += strlen( $contents );

			}

			$contents = '';

			// Print a manifest of the dependencies
			$contents .= sprintf("/*! This minified dependency bundle includes:\n");
			$i = 0;
			foreach ( $srcs as $src ) {
				$i += 1;
				$contents .= sprintf( " * %02d. %s\n", $i, $src );
			}
			$contents .= sprintf(" */\n\n");

			// Minify
			// Note: semicolon needed in case a file lacks trailing semicolon
			// like `x = {a:1}` and the next file is IIFE (function(){}),
			// then it would get combined as x={a:1}(function(){}) and attempt
			// to pass the anonymous function into a function {a:1} which
			// is of course an object and not a function. Culprit here
			// is the comment-reply.js in WordPress.
                        switch( $type ) {

                            case 'scripts':
				$minified_contents = implode( "\n;;\n", $contents_for_each_dep );
				$minified_contents = JSMin::minify( $minified_contents );

				if ( false === $minified_contents ) {
					throw new Dependency_Minification_Exception( 'JavaScript parse error' );
				}
                                break;

                            case 'styles':
				$minified_contents = implode( "\n\n", $contents_for_each_dep );
				$minified_contents = Minify_CSS_Compressor::process($minified_contents);
                                break;

                        }

			$contents .= $minified_contents;
			$cached['unminified_size'] = $unminified_size;
			$max_age = apply_filters( 'dependency_minification_cache_control_max_age', (int) depmin_get_option( 'cache_control_max_age_cache' ), $srcs );
			$cached['contents'] = $contents;
			$cached['expires'] = time() + $max_age;
			$cached['error'] = null;
		}
		catch (Exception $e) {
			error_log( sprintf( '%s in %s: %s for srcs %s',
				get_class( $e ),
				__FUNCTION__,
				$e->getMessage(),
				implode( ',', $srcs )
			) );
			$cached['error'] = $e->getMessage();
			$max_age = apply_filters( 'dependency_minification_cache_control_max_age_error', (int) depmin_get_option( 'cache_control_max_age_error' ), $srcs );
			$cached['expires'] = time() + $max_age;
		}
		$cached['etag'] = self::generate_etag( $src_hash, $ver_hash );
		$cached['last_modified'] = time();
		$cached['pending'] = false;

		if ( false === get_option( $cache_option_name ) ) {
			add_option( $cache_option_name, $cached, '', 'no' );
		} else {
			update_option( $cache_option_name, $cached );
		}
	}

	/**
	 * Handle a request for the minified resource
	 */
	static function handle_request() {
		$src_hash = get_query_var( 'depmin_src_hash' );
		$ext = get_query_var( 'depmin_file_ext' );
		if ( empty( $src_hash ) || empty( $ext ) ) {
			return;
		}

		try {
			ob_start();

			if ( 'js' === $ext ) {
				header( 'Content-Type: application/javascript; charset=utf-8' );
			} else {
				header( 'Content-Type: text/css; charset=utf-8' );
			}

			$cache_option_name = self::get_cache_option_name( $src_hash );
			$cached = get_option($cache_option_name);
			if ( empty( $cached ) ) {
				throw new Dependency_Minification_Exception( 'Unknown minified dependency bundle.', 404 );
			}
			if ( ! empty( $cached['error'] ) ) {
				throw new Dependency_Minification_Exception( $cached['error'], 500 );
			}

			// Send the response headers for caching
			header( 'Expires: ' . str_replace('+0000', 'GMT', gmdate('r', $cached['expires'])) );
			if ( ! empty( $cached['last_modified'] ) ) {
				header( 'Last-Modified: ' . str_replace('+0000', 'GMT', gmdate('r', $cached['last_modified'])) );
			}
			if ( ! empty( $cached['etag'] ) ) {
				header( 'ETag: ' . $cached['etag'] );
			}

			$is_not_modified = false;
			if ( time() < $cached['expires'] ) {
				$is_not_modified = depmin_get_option( 'allow_not_modified_responses' ) && (
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
				status_header(304);
			} else {
				status_header(200);
				$out = $cached['contents'];

				global $compress_scripts, $compress_css;
				script_concat_settings();
				$compress = ( 'js' === $ext ? $compress_scripts : $compress_css );
				$force_gzip = ( $compress && defined('ENFORCE_GZIP') && ENFORCE_GZIP );

				// Copied from /wp-admin/load-scripts.php
				if ( $compress && ! ini_get('zlib.output_compression') && 'ob_gzhandler' != ini_get('output_handler') && isset($_SERVER['HTTP_ACCEPT_ENCODING']) ) {
					header('Vary: Accept-Encoding'); // Handle proxies
					if ( false !== stripos($_SERVER['HTTP_ACCEPT_ENCODING'], 'deflate') && function_exists('gzdeflate') && ! $force_gzip ) {
						header('Content-Encoding: deflate');
						$out = gzdeflate( $out, 3 );
					} elseif ( false !== stripos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') && function_exists('gzencode') ) {
						header('Content-Encoding: gzip');
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
			if ( $e instanceof Dependency_Minification_Exception || depmin_get_option( 'show_error_messages' ) ) {
				$status = $e->getCode();
				$message = $e->getMessage();
			} else {
				error_log( sprintf('%s: %s via URI %s', __METHOD__, $e->getMessage(), esc_url_raw( $_SERVER['REQUEST_URI'] )) );
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
add_action( 'plugins_loaded', array( 'Dependency_Minification', 'setup' ), 100 );

register_activation_hook( __FILE__, array( 'Dependency_Minification', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Dependency_Minification', 'deactivate' ) );

class Dependency_Minification_Exception extends Exception {}
