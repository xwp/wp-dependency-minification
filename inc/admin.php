<?php
/**
 * @since 1.0
 */
class DepMin_Admin {

	/*** Constants ************************************************************/

	/**
	 * @var string
	 * @since 1.0
	 */
	const PARENT_PAGE = 'tools.php';

	/**
	 * @var string
	 * @since 1.0
	 */
	const PAGE_SLUG = 'dependency-minification';

	/**
	 * @var string
	 * @since 1.0
	 */
	const AJAX_ACTION = 'dependency_minification';

	/**
	 * @var string
	 * @since 1.0
	 */
	const AJAX_OPTIONS_ACTION = 'dependency_minification_options';


	/*** Properties ***********************************************************/

	/**
	 * @since 1.0
	 * @var string
	 */
	protected $page_hook;


	/*** Methods **************************************************************/

	/**
	 *
	 * @since 1.0
	 */
	function __construct() {

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 );

		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_handler' ) );
		add_action( 'wp_ajax_' . self::AJAX_OPTIONS_ACTION, array( $this, 'ajax_options_handler' ) );

	}

	/**
	 * @action admin_menu
	 * @return void
	 * @since 1.0
	 */
	public function add_menu() {

		$this->page_hook = add_submenu_page(
			self::PARENT_PAGE,
			__( 'Dependency Minification', 'dependency-minification' ),
			__( 'Dep. Minification', 'dependency-minification' ),
			Dependency_Minification::$options['admin_page_capability'],
			self::PAGE_SLUG,
			array( $this, 'page_content' )
		);

	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public function page_content() { ?>

		<div class="wrap">

			<?php screen_icon( 'tools' ) ?>

			<h2><?php esc_html_e( 'Dependency Minification', 'dependency-minification' ) ?></h2>

			<h2 class="nav-tab-wrapper">
				<a href="#tab-status" class="nav-tab nav-tab-active"><?php esc_html_e( 'Status', 'dependency-minification' ) ?></a>
				<a href="#tab-settings" class="nav-tab"><?php esc_html_e( 'Settings', 'dependency-minification' ) ?></a>
			</h2>

			<?php $this->page_tab_content_status() ?>

			<?php $this->page_tab_content_settings() ?>

		</div>

		<?php

	}

	/**
	 * @return void
	 * @since 1.0
	 */
	public function page_tab_content_status() {

		$nonce = wp_create_nonce( self::AJAX_ACTION ) ?>

		<div class="nav-tab-content" id="tab-content-status">

			<form action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ) ?>" method="post">

				<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_ACTION ) ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ) ?>">

				<?php

					$dependencies = array();

					foreach( DepMin_Cache::get_all() as $dependency ) {

						// Remove the not-needed values and save the world!
						$dependency = array_diff_key( $dependency, array( 'contents' => false ) );

						$key = DepMin_hash_array( wp_list_pluck( $dependency['deps'], 'src' ) );
						$dependencies[ $key ] = $dependency;

					}

					foreach ( DepMin_Minify::get_pending_dependencies() as $dependency ) {

						$key = DepMin_hash_array( wp_list_pluck( $dependency['deps'], 'src' ) );

						if ( isset( $dependencies[ $key ] ) ) {

							$dependencies[ $key ] = array_merge(
									$dependencies[ $key ],
									$dependency
								);

						} else {

							$dependencies[ $key ] = $dependency;

						}

					}

				?>

				<?php if ( Dependency_Minification::$options['disable_if_wp_debug'] && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ): ?>
					<div class="error">
						<p><?php esc_html_e( 'Dependency Minification is disabled. Any minified dependencies below are cached. To minify new dependencies, disable WP_DEBUG or filter disable_if_wp_debug to be false.', 'dependency-minification' ) ?></p>
					</div>
				<?php endif; ?>

				<?php if ( empty( $dependencies ) ) : ?>
					<p>
						<em><?php esc_html_e( 'There are no minified dependencies yet. Try browsing the site.', 'dependency-minification' ) ?></em>
					</p>
				<?php else : ?>
					<div class="tablenav top">
						<div class="alignleft actions">
							<select name="depmin_task">
								<option value="-1" selected="selected"><?php esc_html_e( 'Bulk Actions' ) ?></option>
								<option value="expire"><?php esc_html_e( 'Expire', 'dependency-minification' ) ?></option>
								<option value="purge"><?php esc_html_e( 'Purge', 'dependency-minification' ) ?></option>
							</select>
							<input type="submit" id="doaction" class="button action" value="<?php esc_attr_e( 'Apply' ) ?>">
						</div>
						<br class="clear">
					</div>

					<table class="wp-list-table widefat fixed" cellspacing="0">
						<?php foreach ( array( 'thead', 'tfoot' ) as $i => $tcontainer ) : ?>
							<<?php echo $tcontainer; // xss ok ?>>
								<tr>
									<th scope="col" class="manage-column column-cb check-column">
										<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All' ) ?></label><input id="cb-select-all-<?php echo esc_attr( $i + 1 ) ?>" type="checkbox">
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
							<?php foreach ( $dependencies as $key => $dependency ) :

									extract( $dependency, EXTR_OVERWRITE ); // => $deps, $type, $pending, $error

									$handles = wp_list_pluck( $dependency['deps'], 'handle' );
									$minified_src = DepMin_Minify::get_minified_dependency_url( $dependency['deps'], $dependency['type'] );

									$link_params = array(
										'_wpnonce' => $nonce,
										'action' => self::AJAX_ACTION,
										'depmin_dependencies[]' => $key,
									);
									?>

									<tr id="<?php echo esc_attr( $key ) ?>" valign="top">

										<th scope="row" class="check-column">
											<label class="screen-reader-text" for="cb-select-<?php echo esc_attr( $key ) ?>"><?php esc_html_e( 'Select minified dependency', 'dependency-minification' ) ?></label>
											<input type="checkbox" id="cb-select-<?php echo esc_attr( $key ) ?>" name="depmin_dependencies[]" value="<?php echo esc_attr( $key ) ?>" <?php disabled( $pending ) ?>>
										</th>

										<td class="column-dependencies">
											<strong>
												<?php for ( $i = 0; $i < count( $deps ); $i += 1 ) : ?>
													<a href="<?php echo esc_url( $deps[ $i ]['src'] ) ?>" target="_blank" title="<?php esc_attr_e( 'View unminified source (opens in new window)', 'dependency-minification' ) ?>"><?php echo esc_html( $deps[$i]['handle'] ) ?></a>
													<?php if ( $i + 1 < count( $handles ) ) { esc_html_e( ', ' ); } ?>
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
															<a class="submitdelete" title="<?php esc_attr_e( 'Delete the cached error to try again.', 'dependency-minification' ) ?>" href="<?php echo esc_url( add_query_arg( $_link_params, admin_url( 'admin-ajax.php' ) ) ) ?>">
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
															<a href="<?php echo esc_url( add_query_arg( $_link_params, admin_url( 'admin-ajax.php' ) ) ) ?>" title="<?php esc_attr_e( 'Expire this item to gracefully regenerate', 'dependency-minification' ) ?>"><?php esc_html_e( 'Expire', 'dependency-minification' ) ?></a> |
														</span>
														<span class="trash">
															<?php
															$_link_params = $link_params;
															$_link_params['depmin_task'] = 'purge';
															?>
															<a class="submitdelete" title="<?php esc_attr_e( 'Purge item from cache (delete immediately; NOT recommended)', 'dependency-minification' ) ?>" href="<?php echo esc_url( add_query_arg( $_link_params, admin_url( 'admin-ajax.php' ) ) ) ?>"><?php esc_html_e( 'Purge', 'dependency-minification' ) ?></a> |
														</span>
														<span class="view">
															<a href="<?php echo esc_url( $minified_src ) ?>" target="_blank" title="<?php esc_attr_e( 'View minified dependencies (opens in new window)', 'dependency-minification' ) ?>" rel="permalink"><?php esc_html_e( 'View minified', 'dependency-minification' ) ?></a>
														</span>
													<?php else : ?>
														<span class="trash">
															<?php
															$_link_params = $link_params;
															$_link_params['depmin_task'] = 'purge';
															?>
															<a class="submitdelete" title="<?php esc_attr_e( 'Delete the cached error to try again.', 'dependency-minification' ) ?>" href="<?php echo esc_url( add_query_arg( $_link_params, admin_url( 'admin-ajax.php' ) ) ) ?>"><?php esc_html_e( 'Try again', 'dependency-minification' ) ?></a>
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
												$min = $minified_size;
												$max = $unminified_size;
												$percentage = round( 100 - ( $min / $max ) * 100 );
												printf( '<meter min=0 max=100 value="%d" title="%s">', $percentage, esc_attr( sprintf( __( '(%1$d / %2$d)', 'dependency-minification' ), $min, $max ) ) );
												print esc_html( sprintf( __( '%1$d%%', 'dependency-minification' ), $percentage ) );
												print '</meter>';
											}

										?></td>
										<td class="type column-type"><?php echo esc_html( $type ) ?></td>

										<?php foreach ( array( 'last-modified' => $last_modified, 'expires' => $expires ) as $col => $time ) : ?>

											<td class="<?php echo $col; ?> column-<?php echo $col; ?>">

												<?php if ( $pending ) : ?>
													<em><?php esc_html_e( '(Pending)', 'dependency-minification' ) ?></em>
												<?php else : ?>
													<time title="<?php echo esc_attr( gmdate( 'c', $time ) ) ?>" datetime="<?php echo esc_attr( gmdate( 'c', $time ) ) ?>">
														<?php

															if ( $time < time() ) {
																echo esc_html( sprintf( __( '%s ago' ), human_time_diff( $time ) ) );

															} else  {
																echo esc_html( sprintf( __( '%s from now' ), human_time_diff( $time ) ) );
															}

														?>
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

	/**
	 * @since 1.0
	 */
	public function page_tab_content_settings() {

		$nonce = wp_create_nonce( self::AJAX_OPTIONS_ACTION ) ?>

		<div class="nav-tab-content" id="tab-content-settings">

			<form action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ) ?>" method="post" class="form-table options">

				<input type="hidden" name="action" value="<?php echo esc_attr( self::AJAX_OPTIONS_ACTION ) ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ) ?>">

				<table class="widefat">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Disable minification', 'dependency-minification' ) ?>
								<small><?php esc_html_e( 'Disable the plugin\'s functionality completely', 'dependency-minification' ) ?></small></th>
							<td>
								<label for="options[disabled_on_conditions][all]">
									<input type="checkbox" name="options[disabled_on_conditions][all]" id="options[disabled_on_conditions][all]" <?php checked( Dependency_Minification::$options['disabled_on_conditions']['all'] ) ?> value="1">
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Exclude remote dependencies', 'dependency-minification' ) ?>
								<small><?php esc_html_e( 'Makes the default is to exclude remote dependencies', 'dependency-minification' ) ?></small></th>
							<td>
								<label for="options[default_exclude_remote_dependencies]">
									<input type="checkbox" name="options[default_exclude_remote_dependencies]" id="options[default_exclude_remote_dependencies]" <?php checked( Dependency_Minification::$options['default_exclude_remote_dependencies'] ) ?> value="1">
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Disable minification for:', 'dependency-minification' ) ?>
								<small><?php esc_html_e( 'Select conditions where minification should not happen', 'dependency-minification' ) ?></small></th>
							<td>
								<label for="options[disabled_on_conditions][loggedin]">
									<input type="checkbox" name="options[disabled_on_conditions][loggedin]" id="options[disabled_on_conditions][loggedin]" <?php checked( Dependency_Minification::$options['disabled_on_conditions']['loggedin'] ) ?> value="1">
									<?php esc_html_e( 'Logged in Users', 'dependency-minification' ) ?>
								</label>
								<label for="options[disabled_on_conditions][admin]">
									<input type="checkbox" name="options[disabled_on_conditions][admin]" id="options[disabled_on_conditions][admin]" <?php checked( Dependency_Minification::$options['disabled_on_conditions']['admin'] ) ?> value="1">
									<?php esc_html_e( 'Administrators', 'dependency-minification' ) ?>
								</label>
								<label for="options[disabled_on_conditions][queryvar][enabled]">
									<input type="checkbox" name="options[disabled_on_conditions][queryvar][enabled]" id="options[disabled_on_conditions][queryvar][enabled]" <?php checked( Dependency_Minification::$options['disabled_on_conditions']['queryvar']['enabled'] ) ?> value="1">
									<?php esc_html_e( 'Query Variable', 'dependency-minification' ) ?>
									<input type="text" name="options[disabled_on_conditions][queryvar][value]" id="options[disabled_on_conditions][queryvar][value]" value="<?php echo esc_html( Dependency_Minification::$options['disabled_on_conditions']['queryvar']['value'] ) ?>">
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Exclude resources', 'dependency-minification' ) ?>
								<small><?php esc_html_e( 'Add URLs of resources to exclude from minification, one resource per line. Note that you can just add a script name, or the last portion of the URL so it matches.', 'dependency-minification' ) ?></small></th>
							<td><textarea name="options[exclude_dependencies]" id="options[exclude_dependencies]" rows="10" class="widefat"><?php echo esc_html( implode( "\n", Dependency_Minification::$options['exclude_dependencies'] ) ) ?></textarea></td>
						</tr>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="2">
								<input type="submit" value="<?php esc_html_e( 'Submit', 'dependency-minification' ) ?>" class="alignright button button-primary">
							</td>
						</tr>
					</tfoot>
				</table>

			</form>

		</div>
		<?php
	}

	/**
	 * @action wp_ajax_dependency_minification
	 * @since 1.0
	 */
	public function ajax_handler() {

		if ( ! current_user_can( Dependency_Minification::$options['admin_page_capability'] ) ) {
			wp_die( __( 'You are not allowed to do that.', 'dependency-minification' ) );
		}

		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], self::AJAX_ACTION ) ) {
			wp_die( __( 'Nonce check failed. Try reloading the previous page.', 'dependency-minification' ) );
		}

		if ( isset( $_REQUEST['depmin_dependencies'], $_REQUEST['depmin_task'] ) ) {

			$updated_count = 0;

			foreach ( (array) $_REQUEST['depmin_dependencies'] as $key ) {

				$key = sanitize_key( $key );

				if ( empty( $key ) ) {
					continue;
				}

				$key = DepMin_Cache::get_key( $key );

				switch( $_REQUEST['depmin_task'] ) {

					case 'purge':
						DepMin_Cache::delete( $key );
						break;

					case 'expire':

						if ( ( $cached = DepMin_Cache::get( $key ) ) ) {

							$cached['expires'] = time() - 1;
							DepMin_Cache::replace( $key, $cached );

						}

						break;

				}

				$updated_count += 1;

			}

			$redirect_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( self::PARENT_PAGE ) );
			$redirect_url = add_query_arg( 'updated-count', $updated_count, $redirect_url );
			$redirect_url = add_query_arg( 'updated-action', $_REQUEST['depmin_task'], $redirect_url );
			wp_redirect( $redirect_url );
			exit;

		}

	}

	/**
	 * @action wp_ajax_dependency_minification_options
	 * @since 1.0
	 */
	public function ajax_options_handler() {
		if ( ! current_user_can( Dependency_Minification::$options['admin_page_capability'] ) ) {
			wp_die( __( 'You are not allowed to do that.', 'dependency-minification' ) );
		}
		if ( ! isset( $_POST['_wpnonce']) || ! wp_verify_nonce( $_POST['_wpnonce'], self::AJAX_OPTIONS_ACTION ) ) {
			wp_die( __( 'Nonce check failed. Try reloading the previous page.', 'dependency-minification' ) );
		}

		if ( ! empty( $_POST['options'] ) ) {

			$options = (array) Dependency_Minification::$options->getArrayCopy();

			$options['exclude_dependencies']   = array_filter( preg_split( "#[\n\r]+#", esc_attr( $_POST['options']['exclude_dependencies'] ) ) );
			$options['disabled_on_conditions'] = ( isset( $_POST['options']['disabled_on_conditions'] ) )
												? $_POST['options']['disabled_on_conditions']
												: array();
			$options['default_exclude_remote_dependencies'] = isset( $_POST['options']['default_exclude_remote_dependencies'] );

			Dependency_Minification::$options->exchangeArray( $options );

		}

		$redirect_url  = add_query_arg( 'page', self::PAGE_SLUG, admin_url( self::PARENT_PAGE ) );
		$redirect_url  = add_query_arg( 'updated', 1, $redirect_url );
		$redirect_url .= '#tab-settings';
		wp_redirect( $redirect_url );

		die();
	}

	/**
	 * @action admin_enqueue_scripts
	 * @since 1.0
	 */
	public function enqueue_scripts( $hook ) {

		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( 'depmin-admin', Dependency_Minification::url( 'admin.css' ), array(), Dependency_Minification::VERSION );
		wp_enqueue_script( 'depmin-admin', Dependency_Minification::url( 'admin.js' ), array( 'jquery' ), Dependency_Minification::VERSION );

	}

	/**
	 * @action admin_notices
	 */
	public function admin_notices() {

		// Show a notice to notify user that pretty urls is disabled, hence the plugin won't work
		if ( empty( $GLOBALS['wp_rewrite']->permalink_structure ) ) { ?>

			<div class="error">
				<p><?php
						printf(
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
						); // xss ok
				?></p>
			</div>

			<?php
		}

		if ( get_current_screen()->id !== $this->page_hook ) {
			return;
		}

		if ( empty( $_GET['updated-action'] ) ) {
			return;
		}

		if ( empty( $_GET['updated-count'] ) ) {
			return;
		}

		$updated_count = intval( $_GET['updated-count'] );
		$updated_task = filter_input( INPUT_GET, 'updated-action' );
		?>
		<div class="updated">
			<?php if ( 'expire' === $updated_task ) : ?>
				<p><?php
				echo esc_html(
					sprintf(
					_n( 'Expired %d minified dependency.', 'Expired %d minified dependencies.', $updated_count, 'dependency-minification' ),
					$updated_count
					)
				);
				?></p>
			<?php elseif ( 'purge' === $updated_task ) : ?>
				<p><?php
				echo esc_html(
					sprintf(
					_n( 'Purged %d minified dependency.', 'Purged %d minified dependencies.', $updated_count, 'dependency-minification' ),
					$updated_count
					)
				);
				?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Updated.', 'dependency-minification' ) ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @filter plugin_action_links
	 * @since 1.0
	 */
	public function plugin_action_links( $links, $file ) {

		if ( dirname( $file ) === basename( Dependency_Minification::path() ) ) {

			$admin_page_url  = admin_url( sprintf( '%s?page=%s', DepMin_Admin::PARENT_PAGE, DepMin_Admin::PAGE_SLUG ) );
			$admin_page_link = sprintf( '<a href="%s">%s</a>', esc_url( $admin_page_url ), esc_html__( 'Settings', 'dependency-minification' ) );
			array_push( $links, $admin_page_link );

		}

		return $links;
	}

}