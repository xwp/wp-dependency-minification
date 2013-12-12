=== Dependency Minification ===
Contributors:      X-team, westonruter, fjarrett, kucrut, shadyvb, alex-ye, c3mdigital, lkraav
Tags:              performance, dependencies, minify, concatenate, compress, js, javascript, scripts, css, styles, stylesheets, gzip, yslow, pagespeed, caching
Tested up to:      3.8
Requires at least: 3.5
Stable tag:        trunk
License:           GPLv2 or later
License URI:       http://www.gnu.org/licenses/gpl-2.0.html

Automatically concatenates and minifies any scripts and stylesheets enqueued using the standard dependency system.

== Description ==

This plugin takes all scripts and stylesheets that have been added via `wp_enqueue_script` and `wp_enqueue_style`
and *automatically* concatenates and minifies them into logical groups. For example, scripts in the footer get grouped
together and styles with the same media (e.g. `print`) get minified together. Minification is done via WP-Cron in order
to prevent race conditions and to ensure that the minification process does not slow down page responses.

This is a reincarnation and rewrite of the [Optimize Scripts](http://wordpress.org/plugins/optimize-scripts/) plugin,
which this plugin now supersedes.

**Features:**

 * Minified sources are stored in the WP Options table so no special filesystem access is required.
 * Endpoint for minified requests is at `/_minified`, which can be configured.
 * Admin page for taking inventory of minified scripts and stylesheets, with methods for expiring or purging the cached data.
 * Dependencies which must not be minified may be excluded via the `dependency_minification_excluded` filter.
 * Dependencies hosted on other domains are by default excluded, but this behavior can be changed by filtering the `default_exclude_remote_dependencies` option via the `dependency_minification_options` filter, or on a case-by-case basis via the filter previously mentioned.
 * By default excludes external scripts from being concatenated and minified, but they can be opted in via the `dependency_minification_excluded` filter.
 * The length of time that a minified source is cached defaults to 1 month, but can be configured via the `cache_control_max_age_cache` option.
 * If a minified source is not available yet, the page source will note that the dependency minification process is pending.
 * Any errors that occur during minification will be shown on the frontend in comments if the `show_error_messages` option is enabled; such errors are enabled by default if `WP_DEBUG`.
 * If the minification process errors out, the original unminified sources are served and the error is cached for 1 hour (by default, configured via `cache_control_max_age_error`) to prevent back-to-back crons from continually attempting to minify in perpetuity.
 * Cached minified sources are served with `Last-Modified` and `ETag` responses headers and requests will honor `If-None-Match` and `If-Modified-Since` to return `304 Not Modified` responses (configurable via the `allow_not_modified_responses` option).
 * Data attached to scripts (e.g. via `wp_localize_script`) is also concatenated together and attached to the newly-minified script.
 * WP-Cron is utilized to initiate the minification process in order to prevent race conditions, and to ensure that page responses aren't slowed down.
 * Stale minified scripts and stylesheets remain until replaced by refreshed ones; this ensures that full-page caches which reference stale minified sources won't result in any 404s.
 * Can serve compressed responses with `gzip` or `deflate`.
 * Transforms relatives paths in stylesheets (e.g. background-images) to absolute ones, so that they don't 404.

**Development of this plugin is done [on GitHub](https://github.com/x-team/wp-dependency-minification). Pull requests welcome. Please see [issues](https://github.com/x-team/wp-dependency-minification/issues) reported there before going to the plugin forum.**

If you are using Nginx with the default Varying Vagrant Vagrants config, you'll want to remove `css|js` from this rule in `nginx-wp-common.conf` (or remove the rule altogether):

    # Handle all static assets by serving the file directly. Add directives 
    # to send expires headers and turn off 404 error logging.
    location ~* \.(js|css|png|jpg|jpeg|gif|ico)$ {
        expires 24h;
        log_not_found off;
    }


== Changelog ==

= 0.9.8 =
 * Fix rewrite rule broken by filtering home_url ([#49](https://github.com/x-team/wp-dependency-minification/pull/49)). Props [c3mdigital](http://profiles.wordpress.org/c3mdigital/).
 * Switch from JSMin to JSMinPlus due to repeated issues with JSMin causing execution timeouts.
 * Update plugin to indicate WordPress 3.8 compatibility.
 * Fix expire and purge links.

= 0.9.7 =
Improve how the plugin guesses the sources' absolute paths ([#34](https://github.com/x-team/wp-dependency-minification/pull/34)). Props [alex-ye](http://profiles.wordpress.org/alex-ye/).

= 0.9.6 =
Improve network activation and deactivation ([#37](https://github.com/x-team/wp-dependency-minification/pull/37)). Props [kucrut](http://profiles.wordpress.org/kucrut/).

= 0.9.5 =
Fix wp_localize_script data lost in minification ([#28](https://github.com/x-team/wp-dependency-minification/issues/28)). Props [lkraav](http://profiles.wordpress.org/lkraav/).

= 0.9.4 =
Issue warning if pretty permalinks are not enabled ([#16](https://github.com/x-team/wp-dependency-minification/issues/16)). Props [shadyvb](http://profiles.wordpress.org/shadyvb/).

= 0.9.3 =
Prevent default built-in scripts from breaking minification groups ([#9](https://github.com/x-team/wp-dependency-minification/issues/9)). Props [shadyvb](http://profiles.wordpress.org/shadyvb/).

= 0.9.2 =
Show alert if WP_DEBUG is disabling dependency minification ([#12](https://github.com/x-team/wp-dependency-minification/issues/12)). Props [c3mdigital](http://profiles.wordpress.org/c3mdigital/).

= 0.9.1 =
Add a settings link to the list of plugin action links ([#13](https://github.com/x-team/wp-dependency-minification/issues/13)). Props [fjarrett](http://profiles.wordpress.org/fjarrett/).

= 0.9 beta =
First Release
