<?php

add_action( 'admin_menu', 'depmin_admin_menu' );
add_action( 'admin_notices', 'depmin_admin_notices' );
add_action( 'admin_enqueue_scripts', 'depmin_admin_enqueue_scripts' );
add_action( 'wp_ajax_' . Dependency_Minification::AJAX_OPTIONS_ACTION, 'depmin_admin_ajax_options_handler' );
add_action( 'wp_ajax_' . Dependency_Minification::AJAX_ACTION, 'depmin_admin_ajax_handler' );
add_filter( 'plugin_action_links', 'depmin_admin_plugin_action_links', 10, 2 );

/**
 * Register the admin page menu.
 *
 * @return void
 * @since X
 */
function depmin_admin_menu() {

        Dependency_Minification::$admin_page_hook = add_submenu_page(
                Dependency_Minification::ADMIN_PARENT_PAGE,
                __( 'Dependency Minification', 'depmin' ),
                __( 'Dep. Minification', 'depmin' ),
                depmin_get_option( 'admin_page_capability' ),
                Dependency_Minification::ADMIN_PAGE_SLUG,
                'depmin_display_admin_page'
        );

} // end depmin_admin_menu()

/**
 * Display the plugin admin page content.
 *
 * @return void
 * @since X
 */
function depmin_display_admin_page() {

    if ( ! current_user_can( depmin_get_option( 'admin_page_capability' ) ) ) {
            wp_die( __( 'You cannot access this page.', 'depmin' ) );
    }

    $nonce = wp_create_nonce( Dependency_Minification::AJAX_ACTION );
    ?>
    <div class="wrap">
            <div class="icon32" id="icon-tools"><br></div>
            <h2><?php esc_html_e( 'Dependency Minification', 'depmin' ) ?></h2>

            <h2 class="nav-tab-wrapper">
                    <a href="#tab-status" class="nav-tab nav-tab-active"><?php esc_html_e( 'Status', 'depmin' ) ?></a>
                    <a href="#tab-settings" class="nav-tab"><?php esc_html_e( 'Settings', 'depmin' ) ?></a>
            </h2>
            <div class="nav-tab-content" id="tab-content-status">

            <form action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ) ?>" method="post">
                    <input type="hidden" name="action" value="<?php echo esc_attr( Dependency_Minification::AJAX_ACTION ) ?>">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ) ?>">

                    <?php
                    global $wpdb;
                    $minified_dependencies = array();
                    $option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s", Dependency_Minification::CACHE_KEY_PREFIX . '%' ) );

                    if ( is_array( $option_names ) ) {

                        foreach ( $option_names as $option_name ) {
                                $minified_dependencies[$option_name] = get_option($option_name);
                        }

                    } // end if

                    $minified_dependencies = array_filter( $minified_dependencies );

                    foreach ( _get_cron_array() as $cron ) {
                            if ( isset( $cron[Dependency_Minification::CRON_MINIFY_ACTION] ) ) {
                                    foreach ( $cron[Dependency_Minification::CRON_MINIFY_ACTION] as $key => $min_cron ) {
                                            $cached = $min_cron['args'][0];
                                            $src_hash = Dependency_Minification::hash_array( wp_list_pluck( $cached['deps'], 'src' ) );
                                            $cache_option_name = Dependency_Minification::get_cache_option_name( $src_hash );
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

                    <?php if ( depmin_get_option( 'disable_if_wp_debug' ) && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ): ?>
                            <div class="error">
                                    <p><?php esc_html_e( 'Dependency Minification is disabled. Any minified dependencies below are cached. To minify new dependencies, disable WP_DEBUG or filter disable_if_wp_debug to be false.', 'depmin' ); ?></p>
                            </div>
                    <?php endif; ?>

                    <?php if ( empty( $minified_dependencies ) ) : ?>
                            <p>
                                    <em><?php esc_html_e( 'There are no minified dependencies yet. Try browsing the site.', 'depmin' ); ?></em>
                            </p>
                    <?php else : ?>
                            <div class="tablenav top">
                                    <div class="alignleft actions">
                                            <select name="depmin_task">
                                                    <option value="-1" selected="selected"><?php esc_html_e( 'Bulk Actions' ) ?></option>
                                                    <option value="expire"><?php esc_html_e( 'Expire', 'depmin' ) ?></option>
                                                    <option value="purge"><?php esc_html_e( 'Purge', 'depmin' ) ?></option>
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
                                                            <th scope="col" class="manage-column column-dependencies"><?php esc_html_e( 'Dependencies', 'depmin' ) ?></th>
                                                            <th scope="col" class="manage-column column-count"><?php esc_html_e( 'Count', 'depmin' ) ?></th>
                                                            <th scope="col" class="manage-column column-compression"><?php esc_html_e( 'Compression', 'depmin' ) ?></th>
                                                            <th scope="col" class="manage-column column-type"><?php esc_html_e( 'Type', 'depmin' ) ?></th>
                                                            <th scope="col" class="manage-column column-last-modified"><?php esc_html_e( 'Last Modified', 'depmin' ) ?></th>
                                                            <th scope="col" class="manage-column column-expires"><?php esc_html_e( 'Expires', 'depmin' ) ?></th>
                                                    </tr>
                                            </<?php echo $tcontainer;  // xss ok ?>>
                                    <?php endforeach; ?>

                                    <tbody id="the-list">
                                            <?php foreach ( $minified_dependencies as $option_name => $minified_dependency ) :

                                                    extract( $minified_dependency ); // => $deps, $type, $pending, $scheduled, $error
                                                    $handles = wp_list_pluck( $deps, 'handle' );
                                                    $minified_src = Dependency_Minification::get_dependency_minified_url( $deps, $type );
                                                    $link_params = array(
                                                            '_wpnonce' => $nonce,
                                                            'action' => Dependency_Minification::AJAX_ACTION,
                                                            'depmin_option_name[]' => $option_name,
                                                    );
                                                    ?>
                                                    <tr id="<?php echo esc_attr($option_name) ?>" valign="top">
                                                            <th scope="row" class="check-column">
                                                                    <label class="screen-reader-text" for="cb-select-<?php echo esc_attr($option_name) ?>"><?php esc_html_e( 'Select minified dependency', 'depmin' ) ?></label>
                                                                    <input id="cb-select-<?php echo esc_attr($option_name) ?>" type="checkbox" name="depmin_option_name[]" value="<?php echo esc_attr($option_name) ?>" <?php disabled( $pending ) ?>>
                                                            </th>
                                                            <td class="column-dependencies">
                                                                    <strong>
                                                                            <?php for ( $i = 0; $i < count( $deps ); $i += 1 ) : ?>
                                                                                    <a href="<?php echo esc_url( $deps[$i]['src'] ) ?>" target="_blank" title="<?php esc_attr_e( 'View unminified source (opens in new window)', 'depmin' ) ?>"><?php echo esc_html( $deps[$i]['handle'] ) ?></a><?php if ( $i + 1 < count( $handles ) ) { esc_html_e( ', ' ); } ?>
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
                                                                                                    <a class="submitdelete" title="<?php esc_attr_e( 'Delete the cached error to try again.', 'depmin' ) ?>" href="<?php echo esc_url( add_query_arg( $_link_params, admin_url( 'admin-ajax.php' ) ) ) ?>">
                                                                                                            <?php esc_html_e( 'Try again', 'depmin' ) ?>
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
                                                                                                    <a href="<?php echo esc_url( add_query_arg( $_link_params, admin_url( 'admin-ajax.php' ) ) ) ?>" title="<?php esc_attr_e( 'Expire this item to gracefully regenerate', 'depmin' ) ?>"><?php esc_html_e( 'Expire', 'depmin' ) ?></a> |
                                                                                            </span>
                                                                                            <span class="trash">
                                                                                                    <?php
                                                                                                    $_link_params = $link_params;
                                                                                                    $_link_params['depmin_task'] = 'purge';
                                                                                                    ?>
                                                                                                    <a class="submitdelete" title="<?php esc_attr_e( 'Purge item from cache (delete immediately; NOT recommended)', 'depmin' ) ?>" href="<?php echo esc_url( add_query_arg( $_link_params, admin_url( 'admin-ajax.php' ) ) ) ?>"><?php esc_html_e( 'Purge', 'depmin' ) ?></a> |
                                                                                            </span>
                                                                                            <span class="view">
                                                                                                    <a href="<?php echo esc_url( $minified_src ) ?>" target="_blank" title="<?php esc_attr_e( 'View minified dependencies (opens in new window)', 'depmin' ) ?>" rel="permalink"><?php esc_html_e( 'View minified', 'depmin' ) ?></a>
                                                                                            </span>
                                                                                    <?php else: ?>
                                                                                            <span class="trash">
                                                                                                    <?php
                                                                                                    $_link_params = $link_params;
                                                                                                    $_link_params['depmin_task'] = 'purge';
                                                                                                    ?>
                                                                                                    <a class="submitdelete" title="<?php esc_attr_e( 'Delete the cached error to try again.', 'depmin' ) ?>" href="<?php echo esc_url( add_query_arg( $_link_params, admin_url( 'admin-ajax.php' ) ) ) ?>"><?php esc_html_e( 'Try again', 'depmin' ) ?></a>
                                                                                            </span>
                                                                                    <?php endif; ?>
                                                                            </div>
                                                                    <?php endif; ?>
                                                            </td>
                                                            <td class="count column-count"><?php echo esc_html( count( $handles ) ) ?></td>
                                                            <td class="count column-compression"><?php
                                                                    if ( empty( $unminified_size ) ) {
                                                                            esc_html_e( 'N/A', 'depmin' );
                                                                    } else {
                                                                            $min = strlen( $contents );
                                                                            $max = $unminified_size;
                                                                            $percentage = round( 100 - ( $min / $max ) * 100 );
                                                                            printf( '<meter min=0 max=100 value="%d" title="%s">', $percentage, esc_attr( sprintf( __( '(%1$d / %2$d)', 'depmin' ), $min, $max ) ) );
                                                                            print esc_html( sprintf( __( '%1$d%%', 'depmin' ), $percentage ) );
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
                                                                                    <em><?php esc_html_e( '(Pending)', 'depmin' ) ?></em>
                                                                            <?php else: ?>
                                                                                    <time title="<?php echo esc_attr( gmdate( 'c', $time ) ) ?>" datetime="<?php echo esc_attr( gmdate( 'c', $time ) ) ?>">
                                                                                            <?php if ( $time < time() ) : ?>
                                                                                                    <?php printf( esc_html__( '%s ago' ), human_time_diff( $time ) ); ?>
                                                                                            <?php else: ?>
                                                                                                    <?php printf( esc_html__( '%s from now' ), human_time_diff( $time ) ); ?>
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
            $nonce = wp_create_nonce( Dependency_Minification::AJAX_OPTIONS_ACTION );
            $conditions = depmin_get_option( 'disabled_on_conditions' );
            ?>
            <div class="nav-tab-content" id="tab-content-settings">
                    <form action="<?php echo admin_url( 'admin-ajax.php' ) ?>" method="post" class="form-table options">
                            <input type="hidden" name="action" value="<?php echo esc_attr( Dependency_Minification::AJAX_OPTIONS_ACTION ) ?>">
                            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ) ?>">
                            <table class="widefat">
                                    <tbody>
                                            <tr>
                                                    <th><?php esc_html_e( 'Disable minification', 'depmin' ) ?>
                                                            <small><?php esc_html_e( 'Disable the plugin\'s functionality completely', 'depmin' ) ?></small></th>
                                                    <td>
                                                            <label for="options[disabled_on_conditions][all]">
                                                                    <input type="checkbox" name="options[disabled_on_conditions][all]" id="options[disabled_on_conditions][all]" <?php checked( $conditions['all'] ) ?> value="1">
                                                            </label>
                                                    </td>
                                            </tr>
                                            <tr>
                                                    <th><?php esc_html_e( 'Exclude remote dependencies', 'depmin' ) ?>
                                                            <small><?php esc_html_e( 'Makes the default is to exclude remote dependencies', 'depmin' ) ?></small></th>
                                                    <td>
                                                            <label for="options[default_exclude_remote_dependencies]">
                                                                    <input type="checkbox" name="options[default_exclude_remote_dependencies]" id="options[default_exclude_remote_dependencies]" <?php checked( depmin_get_option( 'default_exclude_remote_dependencies' ) ) ?> value="1">
                                                            </label>
                                                    </td>
                                            </tr>
                                            <tr>
                                                    <th><?php esc_html_e( 'Disable minification for:', 'depmin' ) ?>
                                                            <small><?php esc_html_e( 'Select conditions where minification should not happen', 'depmin' ) ?></small></th>
                                                    <td>
                                                            <label for="options[disabled_on_conditions][loggedin]">
                                                                    <input type="checkbox" name="options[disabled_on_conditions][loggedin]" id="options[disabled_on_conditions][loggedin]" <?php checked( $conditions['loggedin'] ) ?> value="1">
                                                                    <?php esc_html_e( 'Logged in Users', 'depmin' ) ?>
                                                            </label>
                                                            <label for="options[disabled_on_conditions][admin]">
                                                                    <input type="checkbox" name="options[disabled_on_conditions][admin]" id="options[disabled_on_conditions][admin]" <?php checked( $conditions['admin']) ?> value="1">
                                                                    <?php esc_html_e( 'Administrators', 'depmin' ) ?>
                                                            </label>
                                                            <label for="options[disabled_on_conditions][queryvar][enabled]">
                                                                    <input type="checkbox" name="options[disabled_on_conditions][queryvar][enabled]" id="options[disabled_on_conditions][queryvar][enabled]" <?php checked( $conditions['queryvar']['enabled'] ) ?> value="1">
                                                                    <?php esc_html_e( 'Query Variable', 'depmin' ) ?>
                                                                    <input type="text" name="options[disabled_on_conditions][queryvar][value]" id="options[disabled_on_conditions][queryvar][value]" value="<?php echo esc_attr( $conditions['queryvar']['value'] ) ?>">
                                                            </label>
                                                    </td>
                                            </tr>
                                            <tr>
                                                    <th><?php esc_html_e( 'Exclude resources', 'depmin' ) ?>
                                                            <small><?php esc_html_e( 'Add URLs of resources to exclude from minification, one resource per line. Note that you can just add a script name, or the last portion of the URL so it matches.', 'depmin' ) ?></small></th>
                                                    <td><textarea name="options[exclude_dependencies]" id="options[exclude_dependencies]" rows="10" class="widefat"><?php echo esc_html( join( "\n", (array) depmin_get_option( 'exclude_dependencies' ) ) ) ?></textarea></td>
                                            </tr>
                                    </tbody>
                                    <tfoot>
                                            <tr>
                                                    <td colspan="2">
                                                            <input type="submit" value="<?php esc_html_e( 'Submit', 'depmin' ) ?>" class="alignright button button-primary">
                                                    </td>
                                            </tr>
                                    </tfoot>
                            </table>
                    </form>
            </div>
    </div>
    <?php
}


/**
 *
 * @action admin_notices
 */
function depmin_admin_notices() {

        // Show a notice to notify user that pretty urls is disabled, hence the plugin won't work
        if ( empty( $GLOBALS['wp_rewrite']->permalink_structure ) ) { ?>
                <div class="error">
                        <p><?php
                        printf(
                                '<strong>%1$s</strong>: %2$s',
                                __( 'Dependency Minification', 'depmin' ),
                                sprintf(
                                        __( 'Pretty permalinks are not enabled in your %1$s, which is required for this plugin to operate. Select something other than Default (e.g. ?p=123)', 'depmin' ),
                                        sprintf(
                                                '<a href="%1$s">%2$s</a>',
                                                admin_url( 'options-permalink.php' ),
                                                __( 'Permalinks Settings', 'depmin' )
                                        )
                                )
                        );
                        ?></p>
                </div>
                <?php
        }

        if ( get_current_screen()->id !== Dependency_Minification::$admin_page_hook ) {
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
                                _n( 'Expired %d minified dependency.', 'Expired %d minified dependencies.', $updated_count, 'depmin' ),
                                $updated_count
                        ));
                        ?></p>
                <?php elseif ( 'purge' === $updated_task ) : ?>
                        <p><?php
                        echo esc_html( sprintf(
                                _n( 'Purged %d minified dependency.', 'Purged %d minified dependencies.', $updated_count, 'depmin' ),
                                $updated_count
                        ) );
                        ?></p>
                <?php else: ?>
                        <p><?php esc_html_e( 'Updated.', 'depmin' ) ?></p>
                <?php endif; ?>
        </div>
        <?php
}


/**
 * @action admin_enqueue_scripts
 */
function depmin_admin_enqueue_scripts( $hook ) {
        if ( $hook !== Dependency_Minification::$admin_page_hook ) {
                return;
        }

        wp_enqueue_script( 'depmin-admin', DEPMIN_URL . '/inc/admin/admin.js', array( 'jquery' ), Dependency_Minification::VERSION );
        wp_enqueue_style( 'depmin-admin', DEPMIN_URL . '/inc/admin/admin.css', array(), Dependency_Minification::VERSION );
}


/**
 * @action wp_ajax_dependency_minification
 */
function depmin_admin_ajax_handler() {
        if ( ! current_user_can( depmin_get_option( 'admin_page_capability' ) ) ) {
                wp_die( __( 'You are not allowed to do that.', 'depmin' ) );
        }
        if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], Dependency_Minification::AJAX_ACTION ) ) {
                wp_die( __( 'Nonce check failed. Try reloading the previous page.', 'depmin' ) );
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

        $redirect_url = add_query_arg( 'page', Dependency_Minification::ADMIN_PAGE_SLUG, admin_url( Dependency_Minification::ADMIN_PARENT_PAGE ) );
        $redirect_url = add_query_arg( 'updated-count', $updated_count, $redirect_url );
        $redirect_url = add_query_arg( 'updated-action', $_REQUEST['depmin_task'], $redirect_url );
        wp_redirect( $redirect_url );
        exit;
}

/**
 * @action wp_ajax_dependency_minification_options
 */
function depmin_admin_ajax_options_handler() {

    if ( ! current_user_can( depmin_get_option( 'admin_page_capability' ) ) ) {
            wp_die( __( 'You are not allowed to do that.', 'depmin' ) );
    }
    if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], Dependency_Minification::AJAX_OPTIONS_ACTION ) ) {
            wp_die( __( 'Nonce check failed. Try reloading the previous page.', 'depmin' ) );
    }

    if ( ! empty( $_REQUEST['options'] ) ) {
            $options = depmin_get_options();
            $options['exclude_dependencies']   = array_filter( preg_split( "#[\n\r]+#", $_REQUEST['options']['exclude_dependencies'] ) );
            $options['disabled_on_conditions'] = $_REQUEST['options']['disabled_on_conditions'];
            $options['default_exclude_remote_dependencies'] = isset( $_REQUEST['options']['default_exclude_remote_dependencies'] );
            depmin_update_options( $options) ;
    }

    $redirect_url = add_query_arg( 'page', Dependency_Minification::ADMIN_PAGE_SLUG, admin_url( Dependency_Minification::ADMIN_PARENT_PAGE ) );
    $redirect_url = add_query_arg( 'updated', 1, $redirect_url );
    $redirect_url.= '#tab-settings';
    wp_redirect( $redirect_url );

    die();

} // end depmin_admin_ajax_options_handler()

/**
 * @filter plugin_action_links
 */
function depmin_admin_plugin_action_links( $links, $file ) {
        if ( plugin_basename( __FILE__ ) === $file ) {
                $admin_page_url  = admin_url( sprintf( '%s?page=%s', Dependency_Minification::ADMIN_PARENT_PAGE, Dependency_Minification::ADMIN_PAGE_SLUG ) );
                $admin_page_link = sprintf( '<a href="%s">%s</a>', esc_url( $admin_page_url ), esc_html__( 'Settings', 'depmin' ) );
                array_push( $links, $admin_page_link );
        }
        return $links;
}