<?php
/**
 * @since 1.0
 */
class DepMin_Collation {

	/**
	 * @var int
	 * @since 1.0
	 */
	protected $minified_count = 0;

	/**
	 * @var array
	 * @since 1.0
	 */
	protected $is_footer = array(
		'scripts' => false,
		'styles'  => false,
	);

	/**
	 * @return void
	 * @since 1.0
	 */
	public function __construct() {

		$disabled = (
			( isset( Dependency_Minification::$options['disabled_on_conditions']['all'] ) && ! empty( Dependency_Minification::$options['disabled_on_conditions']['all'] ) )
			|| ( isset( Dependency_Minification::$options['disabled_on_conditions']['loggedin'] ) && ! empty( Dependency_Minification::$options['disabled_on_conditions']['loggedin'] ) && is_user_logged_in() )
			|| ( ! empty( Dependency_Minification::$options['disabled_on_conditions']['admin'] ) && is_user_logged_in() && current_user_can( 'manage_options' ) )
			|| ( ! empty( Dependency_Minification::$options['disabled_on_conditions']['queryvar']['enabled'] )
				&& ! empty( Dependency_Minification::$options['disabled_on_conditions']['queryvar']['enabled'] )
				&& ! empty( $_GET[ Dependency_Minification::$options['disabled_on_conditions']['queryvar']['value'] ] )
				)
			);

		if ( DepMin_is_frontend() && ! $disabled ) {

			add_filter( 'print_scripts_array', array( $this, 'filter_print_scripts_array' ) );
			add_filter( 'print_styles_array', array( $this, 'filter_print_styles_array' ) );
		}

	}

	/**
	 * @filter print_styles_array
	 * @return array
	 * @since 1.0
	 */
	public function filter_print_styles_array( $handles ) {
		$handles = $this->filter_print_dependency_array( $handles, 'styles' );
		return $handles;
	}

	/**
	 * @filter print_scripts_array
	 * @return array
	 * @since 1.0
	 */
	public function filter_print_scripts_array( $handles ) {
		$handles = $this->filter_print_dependency_array( $handles, 'scripts' );
		return $handles;
	}

	/**
	 * Separate external from internal (local) dependencies and then group the
	 * internal resources into maximal groups.
	 * @param array $handles
	 * @param string $type (scripts|styles)
	 * @return array
	 */
	public function filter_print_dependency_array( array $handles, $type ) {
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
		$disabled = Dependency_Minification::$options['disable_if_wp_debug'] ? ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : false;
		$disabled = $disabled || ( defined( 'DEPENDENCY_MINIFICATION_DEFAULT_DISABLED' ) && DEPENDENCY_MINIFICATION_DEFAULT_DISABLED );
		$disabled = apply_filters( 'dependency_minification_disabled', $disabled, $handles, $type );
		$disabled = apply_filters( "dependency_minification_disabled_{$type}", $disabled, $handles );
		$disabled = $disabled || empty( $GLOBALS['wp_rewrite']->permalink_structure );
		if ( $disabled ) {
			return $handles;
		}

		// @todo There should be a better way to determine which group we are in
		$current_group = (int) $this->is_footer[$type]; // false => 0, true => 1

		$handles_in_group = array();
		foreach ( $handles as $handle ) {
			$must_process_handle = (
				$wp_deps->groups[$handle] === $current_group
				||
				// Handle case where script is erroneously enqueued without in_footer=true (here's lookin at you, PollDaddy)
				(
					$wp_deps->groups[$handle] < $current_group
					&&
					! in_array( $handle, $wp_deps->done )
				)
			);

			if ( $must_process_handle ) {
				$handles_in_group[] = $handle;
			}
		}

		$filtered_handles = array();
		$groups = $this->group_dependencies_by_exclusion( $handles_in_group, $wp_deps );

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
			$vers = wp_list_pluck( $deps, 'ver' );

			$src_hash = DepMin_hash_array( $srcs );
			$ver_hash = DepMin_hash_array( $vers );

			$cached_ver_hash = null;
			$cached = DepMin_Cache::get( DepMin_Cache::get_key( $src_hash ) );

			if ( ! empty( $cached['deps'] ) ) {
				$cached_ver_hash = DepMin_hash_array( wp_list_pluck( $cached['deps'], 'ver' ) );
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
				if ( Dependency_Minification::$options['show_error_messages'] ) {
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
					compact( 'type', 'deps' )
				);

				wp_schedule_single_event( $scheduled, DepMin_Minify::CRON_ACTION, array( $args ) );
				// The bundle is not ready yet, so re-use the existing dependencies
				$filtered_handles = array_merge( $filtered_handles, $group['handles'] );
			} else {
				$this->minified_count += 1;
				$new_handle = sprintf( 'minified-%d', $this->minified_count );
				$filtered_handles[] = $new_handle;
				$src = DepMin_Minify::get_minified_dependency_url( $deps, $type );

				// Deps are registered without versions since the URL includes the version (ver_hash)
				if ( 'scripts' === $type ) {
					$in_footer = ! empty( $extra['group'] ); // @todo what if the group is not 0 or 1?
					wp_register_script( $new_handle, $src, array(), null, $in_footer );
				} elseif ( 'styles' === $type ) {
					wp_register_style( $new_handle, $src, array(), null, $extra['media'] );
				}
				$wp_deps->set_group( $new_handle, /*recursive*/false, $current_group );
				$new_dep = $wp_deps->registered[$new_handle];
				$new_extra = array(
					'data' => '',
				);
				foreach ( $group['handles'] as $handle ) {

					// Aggregate data from scripts (e.g. wp_localize_script)
					if ( ! empty( $wp_deps->registered[$handle]->extra ) ) {

						foreach ( array_keys( $wp_deps->registered[$handle]->extra ) as $extra_key ) {
							$data = $wp_deps->get_data( $handle, $extra_key );

							if ( 'data' === $extra_key ) {
								$new_extra['data'] .= "/* wp_localize_script($handle): */\n";
								$new_extra['data'] .= "$data\n\n";
							} else {
								if ( isset( $new_extra[$extra_key] ) ) {
									// The handles should have been grouped so that they have the same extras
									assert( $new_extra[$extra_key] === $data );
								}
								$new_extra[$extra_key] = $data;
							}
						}
					}

					// Mark the handles as done for the resources that have been minified
					$wp_deps->done[] = $handle;
				}

				// Add aggregated extra to new dependency
				foreach ( $new_extra as $key => $value ) {
					$new_dep->add_data( $key, $value );
				}
			}
		}

		// @todo Must be a better way to do this
		$this->is_footer[$type] = true; // for the next invocation

		return $filtered_handles;

	}

	/**
	 * @param array $handles
	 * @param WP_Dependencies $wp_deps
	 * @return array Two members, the 1st containing external handles and the 2nd containing internal handles
	 */
	public function group_dependencies_by_exclusion( $handles, WP_Dependencies $wp_deps ) {
		$groups = array();

		// First create groups based on whether they are excluded from minification
		$last_was_excluded = null;
		foreach ( $handles as $handle ) {
			$src = $wp_deps->registered[$handle]->src;
			$is_local = DepMin_is_self_hosted_src( $src );
			$is_excluded = ! $is_local && Dependency_Minification::$options['default_exclude_remote_dependencies'];
			$is_excluded = $is_excluded || $this->is_url_included( $src, Dependency_Minification::$options['exclude_dependencies'] );
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
				$handles_bundles = $this->group_handles_by_extra( $group['handles'], $wp_deps );
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
	 * @param array $handles
	 * @param WP_Dependencies $wp_deps
	 * @return array Associative array where the keys are the args and extras
	 */
	public function group_handles_by_extra( array $handles, WP_Dependencies $wp_deps ) {
		$bundles = array();
		foreach ( $handles as $handle ) {
			$dep = &$wp_deps->registered[$handle];
			$extra = (array) $dep->extra;
			if ( is_a( $wp_deps, 'WP_Styles' ) ) {
				$extra['media'] = is_string( $dep->args ) ? $dep->args : 'all';
			}
			unset($extra['suffix']);
			unset($extra['rtl']);
			unset($extra['data']);
			// Default scripts are not assigned 'group', so we use the original 'deps->args' value
			if ( is_a( $wp_deps, 'WP_Scripts' ) && empty( $extra['group'] ) && is_int( $dep->args ) ) {
				$extra['group'] = $dep->args;
			}
			ksort( $extra );
			$key = serialize( $extra );
			$bundles[$key][] = $handle;
		}
		return $bundles;
	}

	public function is_url_included( $needle, $haystack ) {
		foreach ( $haystack as $entry ) {
			if ( strpos( $needle, $entry ) !== false ) {
				return true;
			}
		}
		return false;
	}

}