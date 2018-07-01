<?php
/*
 * Plugin Name: Zysys Hosting Optimizations
 * Plugin URI: https://codex.zysys.org/bin/view.cgi/Main/WordpressPlugin:ZysysHostingOptimizations
 * Description: This plugin allows for all the default Zysys Hosting Optimizations to be installed at once and continually configured.
 * Version: {VERSION}
 * Author: Z. Bornheimer (Zysys)
 * Author URI: http://zysys.org
 * License: GPLv3
 */

/* make sure to update the VERSION file before running Makefile.pl to generate the script */

#####################################################################
# Tools:
# Zycache Setup, zysys.cachefly.net for https.  https (js goes on js - leave relative alone, css goes on cdn2 - leave relative alone, all others zycache)
# WP_Cron Setup
# wp-config.php securing
# file & dir chmodding
# set file permissions according to the Hardening Wordpress guide
# image optimization
# auto-update
#####################################################################

zysyshosting_define_constants();

/* Define all constants.  This runs early on and should be called in API.
 * @since 0.5.5
 * @param NONE
 * @return NONE
 */

function zysyshosting_define_constants() {

    if (!defined('ZYLOG'))
        define('ZYLOG', '/var/log/zysyshostingwp.log');

    if (!defined('ZYSYS_IS_SUBBLOG'))
        define('ZYSYS_IS_SUBBLOG', get_current_blog_id() == 1? 0 : 1);

    if (!defined('WP_CONTENT_DIR') || !WP_CONTENT_DIR)
        wp_die( "PLEASE DEFINE WP_CONTENT_DIR IN wp-config.php, if you are not sure what to do, add the following to the end of your page: define('WP_CONTENT_DIR', ABSPATH . 'wp-content/')");

    if (!defined('WPINC') || !WPINC)
        wp_die( "PLEASE DEFINE WPINC IN wp-config.php, if you are not sure what to do, add the following to the end of your page: define('WPINC', 'wp-includes')");

    if (!defined('ZYCACHE_HTTPS'))
        define('ZYCACHE_HTTPS', 'https://zysyshosting.cachefly.net');

    /*if ((!isset($_SERVER['HTTPS']) || !$_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != 443) {
        if (!defined('ZYCACHE'))
            define('ZYCACHE', 'http://www.zycache.com');
        if (!defined('ZYCACHE_JS'))
            define('ZYCACHE_JS', 'http://js.zycache.com');
        if (!defined('ZYCACHE_CSS'))
            define('ZYCACHE_CSS', 'http://css.zycache.com');
        if (!defined('ZYCACHE_IMAGE'))
            define('ZYCACHE_IMAGE', 'http://img.zycache.com');
        } else {*/
        if (!defined('ZYCACHE'))
            define('ZYCACHE', ZYCACHE_HTTPS);
        if (!defined('ZYCACHE_JS'))
            define('ZYCACHE_JS', ZYCACHE_HTTPS);
        if (!defined('ZYCACHE_CSS'))
            define('ZYCACHE_CSS', ZYCACHE_HTTPS);
        if (!defined('ZYCACHE_IMAGE'))
            define('ZYCACHE_IMAGE', ZYCACHE_HTTPS);
        /*}*/

    if (!defined('ZYSYS_HOSTING_OBJECT_CACHE_LATEST_VERSION'))
        define('ZYSYS_HOSTING_OBJECT_CACHE_LATEST_VERSION', '1.0');

    if (!defined('ZYSYSHOSTING_OPTIMIZATIONS_VERSION'))
        define('ZYSYSHOSTING_OPTIMIZATIONS_VERSION', '{VERSION}');

    if(!defined('ZYSYS_HOSTING_URL_PREP_REGEX'))
        define('ZYSYS_HOSTING_URL_PREP_REGEX', '|(https?:){0,1}//(www\.){0,1}|');

}

require_once('includes/activation-deactivation-upgrade.php');
require_once('includes/file-io.php');
require_once('includes/image-optimization.php');
require_once('includes/library-functions.php');
require_once('includes/license-keys.php');
require_once('includes/maintenance.php');
require_once('includes/optimizations.php');
require_once('includes/plugin-updater.php');
require_once('includes/security.php');
require_once('includes/settings-ui.php');
require_once('includes/update-system.php');
require_once('includes/zycache.php');
