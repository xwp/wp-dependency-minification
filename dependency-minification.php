<?php
/**
 * Plugin Name: Dependency Minification
 * Description: Concatenates and minifies scripts and stylesheets. Please install and activate <a href="http://scribu.net" target="_blank">scribu</a>'s <a href="http://wordpress.org/plugins/proper-network-activation/" target="_blank">Proper Network Activation</a> plugin <em>before</em> activating this plugin <em>network-wide</em>.
 * Version: 0.9.8
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

class Dependency_Minification {
	static $options = array();
	protected static $minified_count = 0;
	static $admin_page_hook;

	const DEFAULT_ENDPOINT   = '_minify';
	const CRON_MINIFY_ACTION = 'minify_dependencies';
	const CACHE_KEY_PREFIX   = 'depmin_cache_';
	const FILENAME_PATTERN   = '([^/]+?)\.([0-9a-f]+)(?:\.([0-9a-f]+))?\.(css|js)';
	const AJAX_ACTION        = 'dependency_minification';
	const ADMIN_PAGE_SLUG    = 'dependency-minification';
	const ADMIN_PARENT_PAGE  = 'tools.php';

	static $query_vars = array(
		'depmin_handles',
		'depmin_src_hash',
		'depmin_ver_hash',
		'depmin_file_ext',
	);

	static function setup() {
		self::$options = apply_filters( 'dependency_minification_options', array_merge(
			array(
				'endpoint'                            => self::DEFAULT_ENDPOINT,
				'default_exclude_remote_dependencies' => true,
				'cache_control_max_age_cache'         => 2629743, // 1 month in seconds
				'cache_control_max_age_error'         => 60 * 60, // 1 hour, to try minifying again
				'allow_not_modified_responses'        => true, // only needs to be true if not Akamaized and max-age is short
				'admin_page_capability'               => 'edit_theme_options',
				'show_error_messages'                 => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'disable_if_wp_debug'                 => true,
			),
			self::$options
		) );

		$is_frontend = ! (
			is_admin()
			||
			in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ) )
		);
		if ( $is_frontend ) {
			add_filter( 'print_scripts_array', array( __CLASS__, 'filter_print_scripts_array' ) );
			add_filter( 'print_styles_array', array( __CLASS__, 'filter_print_styles_array' ) );
		}
		add_action( 'init', array( __CLASS__, 'hook_rewrites' ) );
		add_action( self::CRON_MINIFY_ACTION, array( __CLASS__, 'minify' ), 10, 4 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'admin_ajax_handler' ) );
		add_filter( 'plugin_action_links', array( __CLASS__, 'admin_plugin_action_links' ), 10, 2 );
	}

	static function hook_rewrites() {
		add_filter( 'query_vars', array( __CLASS__, 'filter_query_vars' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'handle_request' ) );
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'add_rewrite_rule' ), 99999 );
	}

	static function get_rewrite_regex() {
		return sprintf( '^(?:.*/)?%s/%s', self::$options['endpoint'], self::FILENAME_PATTERN );
	}

	static function add_rewrite_rule( $rules ) {
		$regex    = self::get_rewrite_regex();
		$redirect = 'index.php?';
		for ( $i = 0; $i < count( self::$query_vars ); $i += 1 ) {
			$redirect .= sprintf( '%s=$matches[%d]&', self::$query_vars[$i], $i + 1 );
		}
		$new_rules[$regex] = $redirect;

		return array_merge( $new_rules, $rules );
	}

	static function remove_rewrite_rule() {
		remove_filter( 'rewrite_rules_array', array( __CLASS__, 'add_rewrite_rule' ), 99999 );
	}

	protected static $is_footer = array(
		'scripts' => false,
		'styles'  => false,
	);

	/**
	 * register_activation_hook
	 */
	static function activate() {
		self::setup();
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'add_rewrite_rule' ), 99999 );
		flush_rewrite_rules();
	}

	/**
	 * register_deactivation_hook
	 */
	static function deactivate() {
		self::remove_rewrite_rule();
		flush_rewrite_rules();
	}

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
		$handles = self::filter_print_dependency_array( $handles, 'styles' );
		return $handles;
	}

	/**
	 * @filter print_scripts_array
	 */
	static function filter_print_scripts_array( $handles ) {
		$handles = self::filter_print_dependency_array( $handles, 'scripts' );
		return $handles;
	}

	/**
	 * @action admin_enqueue_scripts
	 */
	static function admin_enqueue_scripts( $hook ) {
		if ( $hook !== self::$admin_page_hook ) {
			return;
		}
		$meta = get_plugin_data( __FILE__ );
		wp_enqueue_script( 'depmin-admin', plugin_dir_url( __FILE__ ) . 'admin.js', array( 'jquery' ), $meta['Version'] );
		wp_enqueue_style( 'depmin-admin', plugin_dir_url( __FILE__ ) . 'admin.css', array(), $meta['Version'] );
	}

	/**
	 * @action admin_menu
	 */
	static function admin_menu() {
		self::$admin_page_hook = add_submenu_page(
			self::ADMIN_PARENT_PAGE,
			__( 'Dependency Minification', 'dependency-minification' ),
			__( 'Dep. Minification', 'dependency-minification' ),
			self::$options['admin_page_capability'],
			self::ADMIN_PAGE_SLUG,
			array( __CLASS__, 'admin_page' )
		);
	}

	/**
	 * @action admin_notices
	 */
	static function admin_notices() {
		// Show a notice to notify user that pretty urls is disabled, hence the plugin won't work
		if ( empty( $GLOBALS['wp_rewrite']->permalink_structure ) ) {
			?>
			<div class="error">
				<p><?php
				echo sprintf(
					'<strong>%1$s</strong>: %2$s',
					__( 'Dependency Minification', 'dependency-minification' ),
					sprintf(
						__( 'Pretty permalinks are not enabled in your %1$s, which is required for this plugin to operate. Select something other than Default (e.g. ?p=123)', 'dependency-minification' ),
						sprintf(
							'<a href="%1$s">%2$s</a>',
							admin_url( 'options-permalink.php' ),
							__( 'Permalinks Settings', 'dependency-minification' )
						)
					)
				);
				?></p>
			</div>
			<?php
		}

		if ( get_current_screen()->id !== self::$admin_page_hook ) {
			return;
		}
		if ( empty( $_GET['updated-count'] ) ) {
			return;
		}
		if ( empty( $_GET['updated-action'] ) ) {
			return;
		}

		$updated_count = intval( $_REQUEST['updated-count'] );
		$updated_task = sanitize_title( $_REQUEST['updated-action'] );
		?>
		<div class="updated">
			<?php if ( 'expire' === $updated_task ) : ?>
				<p><?php
				echo esc_html( sprintf(
					_n( 'Expired %d minified dependency.', 'Expired %d minified dependencies.', $updated_count, 'dependency-minification' ),
					$updated_count
				));
				?></p>
			<?php elseif ( 'purge' === $updated_task ) : ?>
				<p><?php
				echo esc_html( sprintf(
					_n( 'Purged %d minified dependency.', 'Purged %d minified dependencies.', $updated_count, 'dependency-minification' ),
					$updated_count
				) );
				?></p>
			<?php else: ?>
				<p><?php esc_html_e( 'Updated.', 'dependency-minification' ) ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @action wp_ajax_dependency_minification
	 */
	static function admin_ajax_handler() {
		if ( ! current_user_can( self::$options['admin_page_capability'] ) ) {
			wp_die( __( 'You are not allowed to do that.', 'dependency-minification' ) );
		}
		if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], self::AJAX_ACTION ) ) {
			wp_die( __( 'Nonce check failed. Try reloading the previous page.', 'dependency-minification' ) );
		}
		$updated_count = 0;
		if ( ! empty( $_REQUEST['depmin_option_name'] ) && ! empty( $_REQUEST['depmin_task'] ) ) {
			foreach ( $_REQUEST['depmin_option_name'] as $option_name ) {
				if ( 'purge' === $_REQUEST['depmin_task'] ) {
					delete_option( $option_name );
				} else {
					$cached = get_option( $option_name );
					$cached['expires'] = time() - 1;
					update_option( $option_name, $cached );
				}
				$updated_count += 1;
			}
		}

		$redirect_url = add_query_arg( 'page', self::ADMIN_PAGE_SLUG, admin_url( self::ADMIN_PARENT_PAGE ) );
		$redirect_url = add_query_arg( 'updated-count', $updated_count, $redirect_url );
		$redirect_url = add_query_arg( 'updated-action', $_REQUEST['depmin_task'], $redirect_url );
		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * @filter plugin_action_links
	 */
	static function admin_plugin_action_links( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$admin_page_url  = admin_url( sprintf( '%s?page=%s', self::ADMIN_PARENT_PAGE, self::ADMIN_PAGE_SLUG ) );
			$admin_page_link = sprintf( '<a href="%s">%s</a>', esc_url( $admin_page_url ), esc_html__( 'Settings', 'dependency-minification' ) );
			array_push( $links, $admin_page_link );
		}
		return $links;
	}

	static function admin_page() {
		if ( ! current_user_can( self::$options['admin_page_capability'] ) ) {
			wp_die( __( 'You cannot access this page.', 'dependency-minification' ) );
		}
		$nonce = wp_create_nonce( self::AJAX_ACTION );
		?>
		<div class="wrap">
			<div class="icon32" id="icon-tools"><br></div>
			<h2><?php esc_html_e( 'Dependency Minification', 'dependency-minification' ) ?></h2>

			<?php  ?>
			<form action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ) ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ) ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ) ?>">

				<?php
				global $wpdb;
				$sql = $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", self::CACHE_KEY_PREFIX . '%' );
				$option_names = $wpdb->get_col( $sql );
				$minified_dependencies = array();
				foreach ( $option_names as $option_name ) {
					$minified_dependencies[$option_name] = get_option($option_name);
				}
				$minified_dependencies = array_filter( $minified_dependencies );

				$minify_crons = array();
				foreach ( _get_cron_array() as $timestamp => $cron ) {
					if ( isset( $cron[self::CRON_MINIFY_ACTION] ) ) {
						foreach ( $cron[self::CRON_MINIFY_ACTION] as $key => $min_cron ) {
							$cached = $min_cron['args'][0];
							$src_hash = self::hash_array( wp_list_pluck( $cached['deps'], 'src' ) );
							$cache_option_name = self::get_cache_option_name( $src_hash );
							if ( array_key_exists( $cache_option_name, $minified_dependencies ) ) {
								$minified_dependencies[$cache_option_name] = array_merge(
									$minified_dependencies[$cache_option_name],
									$cached
								);
							} else {
								$minified_dependencies[$cache_option_name] = $cached;
							}
						}
					}
				}
				?>

				<?php if ( self::$options['disable_if_wp_debug'] && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ): ?>
					<div class="error">
						<p><?php esc_html_e( 'Dependency Minification is disabled. Any minified dependencies below are cached. To minify new dependencies, disable WP_DEBUG or filter disable_if_wp_debug to be false.', 'dependency-minification' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( empty( $minified_dependencies ) ) : ?>
					<p>
						<em><?php esc_html_e( 'There are no minified dependencies yet. Try browsing the site.', 'dependency-minification' ); ?></em>
					</p>
				<?php else : ?>
					<div class="tablenav top">
						<div class="alignleft actions">
							<select name="depmin_task">
								<option value="-1" selected="selected"><?php esc_html_e( 'Bulk Actions' ) ?></option>
								<option value="expire"><?php esc_html_e( 'Expire', 'dependency-minification' ) ?></option>
								<option value="purge"><?php esc_html_e( 'Purge', 'dependency-minification' ) ?></option>
							</select>
							<input type="submit" name="" id="doaction" class="button action" value="<?php esc_attr_e( 'Apply' ) ?>">
						</div>
						<br class="clear">
					</div>

					<table class="wp-list-table widefat fixed" cellspacing="0">
						<?php foreach ( array( 'thead', 'tfoot' ) as $i => $tcontainer ) : ?>
							<<?php echo $tcontainer; // xss ok ?>>
								<tr>
									<th scope="col" class="manage-column column-cb check-column">
										<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All' ) ?></label><input id="cb-select-all-<?php echo esc_attr( $i + 1 ); ?>" type="checkbox">
									</th>
									<th scope="col" class="manage-column column-dependencies"><?php esc_html_e( 'Dependencies', 'dependency-minification' ) ?></th>
									<th scope="col" class="manage-column column-count"><?php esc_html_e( 'Count', 'dependency-minification' ) ?></th>
									<th scope="col" class="manage-column column-compression"><?php esc_html_e( 'Compression', 'dependency-minification' ) ?></th>
									<th scope="col" class="manage-column column-type"><?php esc_html_e( 'Type', 'dependency-minification' ) ?></th>
									<th scope="col" class="manage-column column-last-modified"><?php esc_html_e( 'Last Modified', 'dependency-minification' ) ?></th>
									<th scope="col" class="manage-column column-expires"><?php esc_html_e( 'Expires', 'dependency-minification' ) ?></th>
								</tr>
							</<?php echo $tcontainer;  // xss ok ?>>
						<?php endforeach; ?>

						<tbody id="the-list">
							<?php foreach ( $minified_dependencies as $option_name => $minified_dependency ) : ?>
								<?php
								extract( $minified_dependency ); // => $deps, $type, $pending, $scheduled, $error
								$handles = wp_list_pluck( $deps, 'handle' );
								$minified_src = self::get_dependency_minified_url( $deps, $type );
								$link_params = array(
									'_wpnonce' => $nonce,
									'action' => self::AJAX_ACTION,
									'depmin_option_name[]' => $option_name,
								);
								?>
								<tr id="<?php echo esc_attr($option_name) ?>" valign="top">
									<th scope="row" class="check-column">
										<label class="screen-reader-text" for="cb-select-<?php echo esc_attr($option_name) ?>"><?php esc_html_e( 'Select minified dependency', 'dependency-minification' ) ?></label>
										<input id="cb-select-<?php echo esc_attr($option_name) ?>" type="checkbox" name="depmin_option_name[]" value="<?php echo esc_attr($option_name) ?>" <?php disabled( $pending ) ?>>
									</th>
									<td class="column-dependencies">
										<strong>
											<?php for ( $i = 0; $i < count( $deps ); $i += 1 ) : ?>
												<a href="<?php echo esc_url( $deps[$i]['src'] ) ?>" target="_blank" title="<?php esc_attr_e( 'View unminified source (opens in new window)', 'dependency-minification' ) ?>"><?php echo esc_html( $deps[$i]['handle'] ) ?></a><?php if ( $i + 1 < count( $handles ) ) { esc_html_e( ', ' ); } ?>
											<?php endfor; ?>
										</strong>
										<?php if ( ! empty( $error ) ) : ?>
											<div class="error">
												<p>
													<?php echo esc_html( $error ) ?>
													<span class="trash">
														<?php
														$_link_params = $link_params;
														$_link_params['depmin_task'] = 'purge';
														?>
														<a class="submitdelete" title="<?php esc_attr_e( 'Delete the cached error to try again.', 'depmin' ) ?>" href="<?php echo esc_url( admin_url( 'admin-ajax.php' ) . '?' . http_build_query( $_link_params ) ) ?>">
															<?php esc_html_e( 'Try again', 'dependency-minification' ) ?>
														</a>
													</span>
												</p>
											</div>
										<?php endif; ?>
										<?php if ( ! $pending ) : ?>
											<div class="row-actions">
												<?php if ( empty( $error ) ) : ?>
													<span class="expire">
														<?php
														$_link_params = $link_params;
														$_link_params['depmin_task'] = 'expire';
														?>
														<a href="<?php echo esc_url( admin_url( 'admin-ajax.php' ) . '?' . http_build_query( $_link_params ) ) ?>" title="<?php esc_attr_e( 'Expire this item to gracefully regenerate', 'dependency-minification' ) ?>"><?php esc_html_e( 'Expire', 'dependency-minification' ) ?></a> |
													</span>
													<span class="trash">
														<?php
														$_link_params = $link_params;
														$_link_params['depmin_task'] = 'purge';
														?>
														<a class="submitdelete" title="<?php esc_attr_e( 'Purge item from cache (delete immediately; NOT recommended)', 'dependency-minification' ) ?>" href="<?php echo esc_url( admin_url( 'admin-ajax.php' ) . '?' . http_build_query( $_link_params ) ) ?>"><?php esc_html_e( 'Purge', 'dependency-minification' ) ?></a> |
													</span>
													<span class="view">
														<a href="<?php echo esc_url( $minified_src ) ?>" target="_blank" title="<?php esc_attr_e( 'View minified dependencies (opens in new window)', 'dependency-minification' ) ?>" rel="permalink"><?php esc_html_e( 'View minified', 'dependency-minification' ) ?></a>
													</span>
												<?php else: ?>
													<span class="trash">
														<?php
														$_link_params = $link_params;
														$_link_params['depmin_task'] = 'purge';
														?>
														<a class="submitdelete" title="<?php esc_attr_e( 'Delete the cached error to try again.', 'dependency-minification' ) ?>" href="<?php echo esc_url( admin_url( 'admin-ajax.php' ) . '?' . http_build_query( $_link_params ) ) ?>"><?php esc_html_e( 'Try again', 'dependency-minification' ) ?></a>
													</span>
												<?php endif; ?>
											</div>
										<?php endif; ?>
									</td>
									<td class="count column-count"><?php echo esc_html( count( $handles ) ) ?></td>
									<td class="count column-compression"><?php
										if ( empty( $unminified_size ) ) {
											esc_html_e( 'N/A', 'dependency-minification' );
										} else {
											$min = strlen( $contents );
											$max = $unminified_size;
											$percentage = round( 100 - ( $min / $max ) * 100 );
											printf( '<meter min=0 max=100 value="%d" title="%s">', $percentage, esc_attr( sprintf( __( '(%1$d / %2$d)', 'dependency-minification' ), $min, $max ) ) );
											print esc_html( sprintf( __( '%1$d%%', 'dependency-minification' ), $percentage ) );
											print '</meter>';
										}

									?></td>
									<td class="type column-type"><?php echo esc_html( $type ) ?></td>
									<?php
									$date_cols = array(
										'last-modified' => $last_modified,
										'expires' => $expires,
									);
									?>
									<?php foreach ( $date_cols as $col => $time ) : ?>
										<td class="<?php echo esc_attr( $col ) ?> column-<?php echo esc_attr( $col ) ?>">
											<?php if ( $pending ) : ?>
												<em><?php esc_html_e( '(Pending)', 'dependency-minification' ) ?></em>
											<?php else: ?>
												<time title="<?php echo esc_attr( gmdate( 'c', $time ) ) ?>" datetime="<?php echo esc_attr( gmdate( 'c', $time ) ) ?>">
													<?php if ( $time < time() ) : ?>
														<?php echo esc_html( sprintf( __( '%s ago' ), human_time_diff( $time ) ) ); ?>
													<?php else: ?>
														<?php echo esc_html( sprintf( __( '%s from now' ), human_time_diff( $time ) ) ); ?>
													<?php endif; ?>
												</time>
											<?php endif; ?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	static function hash_array( array $arr ) {
		return md5( serialize( $arr ) );
	}

	static function generate_etag( $src_hash, $ver_hash ) {
		return join( '.', array( $src_hash, $ver_hash ) );
	}

	static function get_cache_option_name( $src_hash ) {
		assert( is_string( $src_hash ) );
		$cache_option_name = self::CACHE_KEY_PREFIX . $src_hash;
		return $cache_option_name;
	}

	/**
	 * @param array $deps
	 * @param string $type (scripts or styles)
	 * @return string
	 */
	static function get_dependency_minified_url( array $deps, $type ) {
		$src_hash = self::hash_array( wp_list_pluck( $deps, 'src' ) );
		$ver_hash = self::hash_array( wp_list_pluck( $deps, 'ver' ) );
		$src = trailingslashit( get_option( 'home' ) . DIRECTORY_SEPARATOR . self::$options['endpoint'] );
		$src .= join( '.', array(
			join( ',', wp_list_pluck( $deps, 'handle' ) ),
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
		$disabled = self::$options['disable_if_wp_debug'] ? ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : false;
		$disabled = $disabled || ( defined( 'DEPENDENCY_MINIFICATION_DEFAULT_DISABLED' ) && DEPENDENCY_MINIFICATION_DEFAULT_DISABLED );
		$disabled = apply_filters( 'dependency_minification_disabled', $disabled, $handles, $type );
		$disabled = apply_filters( "dependency_minification_disabled_{$type}", $disabled, $handles );
		$disabled = $disabled || empty( $GLOBALS['wp_rewrite']->permalink_structure );
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
				if ( self::$options['show_error_messages'] ) {
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
		self::$is_footer[$type] = true; // for the next invocation

		return $filtered_handles;
	}

	/**
	 * @param string $src
	 * @return bool
	 */
	static function is_self_hosted_src( $src ) {
		$parsed_url = parse_url( $src );
		return (
			(
				empty( $parsed_url['host'] )
				&&
				substr( $parsed_url['path'], 0, 1) === '/'
			)
			||
			(
				! empty( $parsed_url['host'] )
				&&
				$parsed_url['host'] === parse_url( get_home_url(), PHP_URL_HOST )
			)
		);
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
			$is_local = self::is_self_hosted_src( $src );
			$is_excluded = !$is_local && self::$options['default_exclude_remote_dependencies'];
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
		$host_domain = parse_url( home_url(), PHP_URL_HOST );
		$ver_hash = self::hash_array( wp_list_pluck( $deps, 'ver' ) );
		$src_hash = self::hash_array( wp_list_pluck( $deps, 'src' ) );
		$cache_option_name = self::get_cache_option_name( $src_hash );

		try {
			$is_css = ( 'styles' === $type );
			if ( 'scripts' === $type ) {
				require_once( dirname(__FILE__) . '/minify/JS/JSMinPlus.php' );
			} elseif ( 'styles' === $type ) {
				require_once( dirname(__FILE__) . '/minify/CSS/UriRewriter.php' );
				require_once( dirname(__FILE__) . '/minify/CSS/Compressor.php' );
			}

			$unminified_size = 0;
			$srcs = wp_list_pluck( $deps, 'src' );

			// Get the contents of each script
			$contents_for_each_dep = array();
			foreach ( $srcs as $src ) {

				if ( ! preg_match( '|^(https?:)?//|', $src ) ) {
					$src = site_url( $src );
				}

				// First attempt to get the file from the filesystem
				$contents = false;
				$is_self_hosted = self::is_self_hosted_src( $src );
				if ( $is_self_hosted ) {
					$src_abspath = ltrim( parse_url( $src, PHP_URL_PATH ), '/' );
					$src_abspath = path_join( $_SERVER['DOCUMENT_ROOT'], $src_abspath );
					$contents = file_get_contents( $src_abspath );
				}

				// Dependency is not self-hosted or it the filesystem read failed, so do HTTP request
				if ( false === $contents ) {
					$r = wp_remote_get( $src );
					if ( is_wp_error($r) ) {
						throw new Exception("Failed to retrieve $src: " . $r->get_error_message());
					} elseif ( intval( wp_remote_retrieve_response_code( $r ) ) !== 200 ) {
						throw new Dependency_Minification_Exception( sprintf('Request for %s returned with HTTP %d %s', $src, wp_remote_retrieve_response_code( $r ), wp_remote_retrieve_response_message( $r )) );
					}
					$contents = wp_remote_retrieve_body( $r );
				}
				$unminified_size += strlen( $contents );

				// Remove the BOM
				$contents = preg_replace("/^\xEF\xBB\xBF/", '', $contents);

				// Rewrite relative paths in CSS
				$src_dir_path = dirname( parse_url( $src, PHP_URL_PATH ) );
				if ( 'styles' === $type && is_dir( ABSPATH . $src_dir_path ) ) {
					$contents = Minify_CSS_UriRewriter::rewrite( $contents, ABSPATH . $src_dir_path );
				}

				$contents_for_each_dep[$src] = $contents;
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
			if ( 'scripts' === $type ) {
				$minified_contents = join( "\n;;\n", $contents_for_each_dep );
				$minified_contents = JSMinPlus::minify($minified_contents);
				if ( false === $minified_contents ) {
					throw new Dependency_Minification_Exception( 'JavaScript parse error' );
				}
			} elseif ( 'styles' === $type ) {
				$minified_contents = join( "\n\n", $contents_for_each_dep );
				$minified_contents = Minify_CSS_Compressor::process($minified_contents);
			}

			$contents .= $minified_contents;
			$cached['unminified_size'] = $unminified_size;
			$max_age = apply_filters( 'dependency_minification_cache_control_max_age', (int) self::$options['cache_control_max_age_cache'], $srcs );
			$cached['contents'] = $contents;
			$cached['expires'] = time() + $max_age;
			$cached['error'] = null;
		}
		catch (Exception $e) {
			error_log( sprintf( '%s in %s: %s for srcs %s',
				get_class( $e ),
				__FUNCTION__,
				$e->getMessage(),
				join( ',', $srcs )
			) );
			$cached['error'] = $e->getMessage();
			$max_age = apply_filters( 'dependency_minification_cache_control_max_age_error', (int) self::$options['cache_control_max_age_error'], $srcs );
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
				$is_not_modified = self::$options['allow_not_modified_responses'] && (
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
			if ( $e instanceof Dependency_Minification_Exception || self::$options['show_error_messages'] ) {
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
