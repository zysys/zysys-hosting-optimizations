<?php
/*
 * Plugin Name: Zysys Hosting Optimizations
 * Plugin URI: https://codex.zysys.org/bin/view.cgi/Main/WordpressPlugin:ZysysHostingOptimizations
 * Description: This plugin allows for all the default Zysys Hosting Optimizations to be installed at once and continually configured
 * Version: 0.7.1
 * Author: Z. Bornheimer (Zysys)
 * Author URI: http://zysys.org
 * License: GPLv3
 */

/* NOTE TO MAINTAINER:
 *     make sure to update ZYSYSHOSTING_OPTIMIZATIONS_VERSION
 *     in the zysyshosting_define_constants()
 */


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

/**
 * Load and Activate Plugin Updater Class.
 * @since 0.1.0
 */
function zysyshosting_updater_init() {

    /* Load Plugin Updater */
    require_once( trailingslashit( plugin_dir_path( __FILE__ ) ) . 'includes/plugin-updater.php' );

    /* Updater Config */
    $config = array(
        'base'         => plugin_basename( __FILE__ ), //required
        'repo_uri'     => 'https://zysys.org/',
        'repo_slug'    => 'zysys-hosting-optimizations',
    );

    /* Load Updater Class */
    new Plugin_Updater( $config );
}

zysyshosting_define_constants();

# If the plugin updater is not there, we need to reinstall the plugin by force as it was corrupted.
# If the plugin updater is not there, we need to reinstall the plugin by force as it was corrupted.
if ( file_exists(ABSPATH . 'wp-admin/includes/plugin.php') ) {
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    $included = true;
}
elseif ( defined('WP_ADMIN_PATH') && !file_exists(WP_ADMIN_PATH . '/includes/plugin.php') ) {
    require_once(WP_ADMIN_PATH . '/includes/plugin.php');
    $included = true;
} else {
    $included = false;
}
if ($included) {
    if (!file_exists(trailingslashit( plugin_dir_path( __FILE__ ) ) . 'includes/plugin-updater.php')) {
        exec('/scripts/wp-optimize-domains.pl --emergency-reinstall --abspath="'.ABSPATH.'" ');
        deactivate_plugins(plugin_basename( __FILE__ )); 
    } else {
        add_action( 'init', 'zysyshosting_updater_init' );
    }   
}

add_action('admin_menu', 'zysyshosting_add_pages');
add_action('upgrader_process_complete', 'zysyshosting_maintenance');
register_activation_hook(__FILE__, 'zysyshosting_optimizations_activation');
register_deactivation_hook(__FILE__, 'zysyshosting_optimizations_deactivation');
add_action('zysyshosting_optimizations_updates', 'zysyshosting_optimizations_post_upgrade');
add_action('zysyshosting_maintenance_hourly', 'zysyshosting_maintenance');
add_action('zysyshosting_optimize_images', 'zysyshosting_optimize_images_proc');
zysyshosting_do_updates_if_requested();

if (!ZYSYS_IS_SUBBLOG) {
    # Setup Zycache
    add_filter('the_content', 'zysyshosting_zycache_uploads_setup');
    add_filter('script_loader_src', 'zysyshosting_zycache_script_setup');
    add_filter('style_loader_src', 'zysyshosting_zycache_style_setup');
    add_action('wp_head', 'zysyshosting_zycache_dns_prefetch');
    add_filter('wp_get_attachment_url', 'zycache_thumbnail_setup');
}

function zysyshosting_optimizations_activation() {
    if( !wp_next_scheduled( 'zysyshosting_optimizations_updates' ) ) {
        wp_schedule_event( time(), 'daily', 'zysyshosting_optimizations_updates' );
    }
    zysyshosting_maintenance();
}

function zysyshosting_optimizations_deactivation() {
    wp_clear_scheduled_hook('zysyshosting_optimizations_updates');
    wp_clear_scheduled_hook('zysyshosting_maintenance_hourly');
    if (wp_next_scheduled('zysyshosting_optimize_images'))
        wp_clear_scheduled_hook('zysyshosting_optimize_images');
}

function zysyshosting_optimizations_post_upgrade() {
    global $wpdb;
    if (get_option('zysyshosting_optimizations_version') < ZYSYSHOSTING_OPTIMIZATIONS_VERSION)
        zysyshosting_maintenance();
}

/* Checks to see if the hosting server is part of the zysyshosting network
 * @since 0.6.3
 * @param NONE
 * @return NONE
 * @calledfrom zysyshosting_maintenance
 */
function zysyshosting_authorize() {
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    if (strpos(shell_exec("hostname"), ".zysyshosting.com") === false) {
        deactivate_plugins(plugin_basename( __FILE__ ));
        zyerror('PLUGIN_UNAUTHORIZED', __FUNCTION__);
        return false;
    } else {
        return true;
    }
}

/* Removes certain wordpress installation files
 * @since 0.6.3
 * @param NONE
 * @return NONE
 * @calledfrom zysyshosting_maintenance
 */
function zysyshosting_remove_installation_files() {
    $install_files = array(ABSPATH.'readme.html', ABSPATH.'wp-config-sample.php', ABSPATH.'wp-admin/install.php', ABSPATH.'license.txt');

    foreach ($install_files as $file)
        if (file_exists($file))
            unlink($file);
    
}

function zysyshosting_add_pages() {
    add_submenu_page('options-general.php', 'Zysys Hosting Settings', 'Zysys Hosting', 'update_core', 'zysys-hosting-settings', 'zysyshosting_admin_panel');
}

function zysyshosting_admin_panel() {
    if (!current_user_can('update_core')) {
        wp_die( __('You do not have sufficient permissions to access this page.') );
    }
    $maint = 0;
    if (isset($_POST['ZysysHostingMaintenance']) && $_POST['ZysysHostingMaintenance'] == "Run Zysys Hosting Maintenance Procedures") {
        zysyshosting_maintenance();
        $maint = 1;
    } elseif (isset($_POST['ZysysHostingOptions'])) { 
        $keepCoreUpToDate = $_POST['ZysysHostingCoreUpdater'];
        if ($keepCoreUpToDate == 1)
            $keepCoreUpToDate = 'update1';
        else
            $keepCoreUpToDate = '';
        $keepPluginsUpToDate = $_POST['ZysysHostingPluginUpdater'];
        if ($keepPluginsUpToDate == 1)
            $keepPluginsUpToDate = 'update1';
        else
            $keepPluginsUpToDate = '';
        $keepThemesUpToDate = $_POST['ZysysHostingThemeUpdater'];
        if ($keepThemesUpToDate == 1)
            $keepThemesUpToDate = 'update1';
        else
            $keepThemesUpToDate = '';
        $keepImagesCompressed = $_POST['ZysysHostingImageCompression'];
        if ($keepImagesCompressed == 1) {
            $keepImagesCompressed = 'compress1';
            if(!wp_next_scheduled('zysyshosting_optimize_images') ) { 
                wp_schedule_event( time(), 'daily', 'zysyshosting_optimize_images' );
            }
        } else {
            $keepImagesCompressed = '';
            if (wp_next_scheduled('zysyshosting_optimize_images'))
                wp_clear_scheduled_hook('zysyshosting_optimize_images');
        }
        update_option('zysyshosting_update_core_automatically', $keepCoreUpToDate);
        update_option('zysyshosting_update_plugins_automatically', $keepPluginsUpToDate);
        update_option('zysyshosting_update_themes_automatically', $keepThemesUpToDate);
        update_option('zysyshosting_keep_images_compressed', $keepImagesCompressed);
        
        $optset = 1;
    }
?>
<div class="wrap">
<img src="//zysyshosting.cachefly.net/zysys.org/images/retina-zysys-logo.png" style="width:198px;" alt="Zysys Logo" /> 
<h2>Zysys Hosting</h2>
<p>This panel will give you options to control your site in, hopefully useful ways.  If you have any suggestions, contact your Zysys Representative.</p>
</div>
<hr />
<form name="zysyshostingprefs" method="post" action="">
<h3>Automatic Updates</h3>
<p class="caption">Turning on automatic updates can break your site long term.  If you don't regularly update your site, it is recommended that you turn these 3 options on.</p>
<input type="checkbox" id="ZysysCoreUpdater" name="ZysysHostingCoreUpdater" <?php checked(get_option('zysyshosting_update_core_automatically'), 'update1'); ?> value='1' /><label for="ZysysHostingCoreUpdater">Keep the WordPress Core Updated</input><br />
<input type="checkbox" id="ZysysPluginUpdater" name="ZysysHostingPluginUpdater" <?php checked(get_option('zysyshosting_update_plugins_automatically'), 'update1'); ?> value='1' /><label for="ZysysHostingPluginUpdater">Keep WordPress Plugins Updated</input><br />
<input type="checkbox" id="ZysysThemeUpdater" name="ZysysHostingThemeUpdater" <?php checked(get_option('zysyshosting_update_themes_automatically'), 'update1'); ?> value='1' /><label for="ZysysHostingThemeUpdater">Keep WordPress Themes Updated</input><br />
<h3>Speed Optimizations</h3>
<input type="checkbox" id="ZysysHostingImageCompression" name="ZysysHostingImageCompression" <?php checked(get_option('zysyshosting_keep_images_compressed'), 'compress1'); ?> value='1' /><label for="ZysysHostingImageCompression">Keep Images Compressed without Loosing Quality</label><br /><br />
<?php if($optset) { ?>
<input type="submit" name="ZysysHostingOptions" disabled style="font-style:italic" class="button-primary" value="Settings Updated." />
<?php } else { ?>
<input type="submit" name="ZysysHostingOptions" class="button-primary" value="Update Settings" />
<?php } ?>
</form>
<hr />
<h2>Maintenance</h2>
<p>Run the maintenance procedures if you've made a very significant level of adjustments, can't wait for the regularly scheduled maintenance interval, or something is wrong.</p>
<form name="zysyshostingmaintenance" method="post" action="">
<?php if($maint) { ?>
<input type="submit" name="ZysysHostingMaintenance" disabled style="font-style:italic" class="button-primary" value="Zysys Hosting Maintenance Procedures Complete." />
<?php } else { ?>
<input type="submit" name="ZysysHostingMaintenance" class="button-primary" value="Run Zysys Hosting Maintenance Procedures" />
<?php } ?>
<hr />


</form>
<?php
}

/* Adds filters for various updates depending on option setting
 * @since 0.6.7
 * @param NONE
 * @return NONE
 */

function zysyshosting_do_updates_if_requested() {
    if (get_option('zysyshosting_update_core_automatically') == 'update1')
        add_filter( 'auto_update_core', '__return_true' );

    if (get_option('zysyshosting_update_plugins_automatically') == 'update1')
        add_filter( 'auto_update_plugin', '__return_true' );

    if (get_option('zysyshosting_update_themes_automatically') == 'update1')
        add_filter( 'auto_update_theme', '__return_true' );
}

/* Runs the various maintenance procedures
 * Called on plugin activation, core update, plugin updated, and when run throught the admin panel
 * @since 0.5.5
 * @param NONE
 * @return NONE
 */

function zysyshosting_maintenance() {

    if( !wp_next_scheduled( 'zysyshosting_maintenance_hourly' ) ) { 
        wp_schedule_event( time(), 'hourly', 'zysyshosting_maintenance_hourly' );
    } 
    if((get_option('zysyshosting_keep_images_compressed') == 'compress1') && !wp_next_scheduled('zysyshosting_optimize_images') ) { 
        wp_schedule_event( time(), 'daily', 'zysyshosting_optimize_images' );
    }

    zysyshosting_authorize();

    zysyshosting_define_constants();

    if (!ZYSYS_IS_SUBBLOG)
        zysyshosting_zycache_setup();

    zysyshosting_remove_installation_files();
    zysyshosting_wp_cron_setup();
    zysyshosting_wp_secure_files();
    zysyshosting_wordpress_securing();
    zysyshosting_wp_permissions();
    zysyshosting_disable_indexes();
    zysyshosting_disable_php_execution();
    zysyshosting_ms_files();
    zysyshosting_plugin_perpetual_updater();
    zysyshosting_wp_rules_check();
    global $wpdb;
    update_option('zysyshosting_optimizations_version', ZYSYSHOSTING_OPTIMIZATIONS_VERSION);
}

/* Runs the /scripts/optimize-images.pl program on ABSPATH
 * @since 0.6.9
 * @param NONE
 * @return NONE
 * @calledby zysyshosting_optimize_images hook
 */
function zysyshosting_optimize_images_proc() {
    system('perl /scripts/optimize-images.pl --path=' . ABSPATH. ' --quiet 3>&2 2>&1 1>/dev/null');
}

function zysyshosting_plugin_perpetual_updater() {
    shell_exec('/scripts/wp-optimize-domains.pl --abspath="'.ABSPATH.'"');
}

/* Creates the symlinks in client-resources directory and triggers the CDN upload script
 * @since 0.5.5
 * @param NONE
 * @return NONE
 * @errorcode -1: Symlink exists, but file isn't present in Zycache
 */

function zysyshosting_zycache_setup() {
    if (file_get_contents(ZYCACHE_JS . '/' . zysyshosting_clean_domain_prefix(site_url()) . '/' . WPINC . '/js/wp-emoji.js')) {
        return 1;
    } else {
        # check for a symlink
        if (shell_exec('readlink /var/www/vhosts/zysys.org/client-resources/' . zysyshosting_clean_domain_prefix(site_url())) == '') {
           # No symlink 
            shell_exec("/scripts/wp-optimize-domains.pl --zycache-add='" . zysyshosting_clean_domain_prefix(site_url()) . "' --abspath='" . ABSPATH . "'");
            shell_exec("/scripts/wp-optimize-domains.pl --run-zycache");
        } else {
            # symlink exists, but files aren't accessible.
            zyerror('SYMLINK_EXISTS_BUT_ZYCACHE_NOT_YET_ACTIVE', __FUNCTION__);
            print 'Please contact your Zysys representative and tell them "Zycache Symlink Present, but still non-symmetric."' ;
            return -1;
        }
    }
}


/* Is a method that insures file integrity when updating a file
 * @since 0.7.0
 * @param filename, new_file_contents, 
 *        additional_data_to_log_ie_calling_function, allow_file_to_start_as_null, 
 *        internal_recursion_marker
 * @return -1 [on consistancy error], 1 [on success], 0 [if not updated], ...log 
 *         /var/log/zysyshostingwp.log updated with other errors
 */
function zysys_file_write($file, $contents, $addl_log = null, $override_null_init = 0, $recursion_depth = 0) {
    if ($recursion_depth > 10)
        return zyerror('RECURSION_DEPTH_GREATER_THAN_10_FILE_ACCURACY_DISPUTED', $addl_log);

    if (!file_exists($file))
        return zyerror('FILE_NOT_EXIST', $addl_log);

    $current_contents = file_get_contents($file); 

    if (strlen($current_contents) == 0 && !$override_null_init)
        return zyerror('CURRENT_FILE_EMPTY', $addl_log);
    
    if ($contents == "")
        return zyerror('CONTENTS_NULL', $addl_log);

    if ($current_contents == $contents)
        return 0;

    # use this mode to prevent clobbering the file if we can't get a lock
    $tempfile = fopen($file, "r+");
    if (flock($tempfile, LOCK_EX)) {
        ftruncate($tempfile, 0);
        fwrite($tempfile, $contents);
        fflush($tempfile);
        sleep(5);
        if (file_get_contents($file) != $contents) {
            flock($tempfile, LOCK_UN);
            fclose($tempfile);
            zyerror('REQUIRED_RECURSE_DUE_TO_NONSYMMETRY', $addl_log);

            if (is_resource($tempfile)) {
                flock($tempfile, LOCK_UN);
                fclose($tempfile);
            }

            zysys_file_write($file, $contents, $addl_log, 1, 1+$recursion_depth);
        } else {
            flock($tempfile, LOCK_UN);
            fclose($tempfile);
        }
    } else {
        if ($recursion_depth > 0)
            return zyerror('TEMPFILE_NOT_SET_WITH_RECURSION_LEVEL_' . $recursion_depth, $addl_log);
        return zyerror('COULD_NOT_GET_EXCLUSIVE_LOCK_ON_OUT_FILE', $addl_log);
    }



    if ($recursion_depth > 0)
        zyerror('FILE_UPDATE_SUCCESS_ON_RECURSE_' . $recursion_depth, $addl_log);

    return 1;

}

/* Allows for accurate debugging in the future to the log file.  It writes the 
 * error code to the log file along with timestamp, and caller. NOTE: make sure 
 * to mark in the error code where you would need to look for a bug.  So, it'll 
 * mark that the bug is in the zysys_file_write function, but the problem may 
 * be originated by the htaccess_adder, or more specifically in the 
 * zysyshosting_wordpress_securing() function.
 * @since 0.7.0
 * @param error_code
 * @return NONE (check ZYLOG for details)
 */
function zyerror($code, $extra = null) {
    $caller = debug_backtrace()[1]['function'];
    if ($extra)
        $fullcode = $code . '[' . $extra . ']';
    else
        $fullcode = $code;
    $line = sprintf("%s - %s() %s %s\n", date("M d, Y H:i:s"), $caller, $fullcode, __FILE__);
    file_put_contents(ZYLOG, $line, FILE_APPEND | LOCK_EX); 
}


/* Adds param 0 to the file between param1 and param2 OR updates the content 
 * between param1 and param2
 * Adds to ABSPATH . wp_config.php
 * @since 0.5.5
 * @param $content, $header, $footer
 * @return NONE
 */
function wpconfig_adder($code, $openingtag, $closingtag) {
    $wpconfigPath = ABSPATH . 'wp-config.php';
    $wpconfigContent = file_get_contents($wpconfigPath);
    if (strpos($wpconfigContent, "<?") === false) {
        return -2;
    }
    if (strpos(zysyshosting_make_single_line($wpconfigContent), zysyshosting_make_single_line($openingtag) . PHP_EOL . zysyshosting_make_single_line($code) . PHP_EOL . zysyshosting_make_single_line($closingtag)) !== false) {
        return -1;
    } else {
        if (strpos(zysyshosting_make_single_line($wpconfigContent), zysyshosting_make_single_line($openingtag)) !== false && strpos(zysyshosting_make_single_line($wpconfigContent), zysyshosting_make_single_line($closingtag)) !== false) {
            $wpconfigContent = preg_replace('/' . preg_quote($openingtag) . '.*' . preg_quote($closingtag) . '/s', $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag, $wpconfigContent);
        } else {
            $mark = "/* That's all, stop editing! Happy blogging. */";
            if (strpos(zysyshosting_make_single_line($wpconfigContent), $mark) !== false) {
                $wpconfigContent = str_replace($mark, PHP_EOL . PHP_EOL . $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag . PHP_EOL . PHP_EOL . $mark, $wpconfigContent);
            } else {
                $wpconfigContent .= PHP_EOL . $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag . PHP_EOL . $mark;
            }
        }
        zysys_file_write($wpconfigPath, $wpconfigContent, debug_backtrace()[1]['function']);
    }
}

/* Adds $code right before readfile() line in the closing of the file
 * Adds to ABSPATH . WPINC /ms-files.php
 * @since 0.5.5
 * @param $code
 * @return NONE
 */
function msfiles_adder($code) {
    $msfilesPath = ABSPATH . WPINC . '/ms-files.php';
    $msfilesContent = file_get_contents($msfilesPath);
    if (strpos(zysyshosting_make_single_line($msfilesContent), zysyshosting_make_single_line($code)) !== false) {
        return -1;
    } else {
        $msfilesContent = preg_replace('/\?>\s*$/', '', $msfilesContent);
        $mark = 'readfile( $file );';
        $beforeMark = strpos(zysyshosting_make_single_line($msfilesContent), $mark);
        if ($beforeMark !== false) {
            $msfilesContent = str_replace($mark, PHP_EOL.$code.PHP_EOL.$mark, $msfilesContent);
        } else {
            $msfilesContent .= $code . PHP_EOL.$mark;
        }
        zysys_file_write($msfilesPath, $msfilesContent, debug_backtrace()[1]['function']);
    }
}

/* Runs, through find and shell_exec, chmodding of 644 on files and 755 on directories.  Runs on the default files from the original wp installation and then recurses in WP_CONTENT, WP_INCLUDES, and WP_ADMIN
 * @since 0.5.5
 * @param NONE
 * @return NONE
 */
function zysyshosting_wp_permissions() {
    shell_exec('find * -maxdepth 0 -type f -name "index.php" -o -name "license.txt" -o -name "readme.html" -o -name "wp-activate.php" -o -name "wp-blog-header.php" -o -name "wp-comments-post.php" -o -name "wp-config-sample.php" -o -name "wp-cron.php" -o -name "wp-links-opml.php" -o -name "wp-load.php" -o -name "wp-login.php" -o -name "wp-mail.php" -o -name "wp-settings.php" -o -name "wp-signup.php" -o -name "wp-trackback.php" -o -name "xmlrpc.php" -o -name "wp-config.php" -exec chmod 644 {} \; &');
    shell_exec("chmod 755 " . escapeshellcmd(WP_CONTENT_DIR) . " &");
    shell_exec("chmod 755 " . escapeshellcmd(ABSPATH. '/'.WPINC.'/') . " &");
    shell_exec("chmod 755 " . escapeshellcmd(ABSPATH. '/wp-admin/') . " &");

    # WP_CONTENT, ABSPATH . WPINC, ABSPATH . '/wp-admin/'
    shell_exec("find " . escapeshellcmd(WP_CONTENT_DIR) . " ! -perm 644 -type f -exec chmod 644 {} \; &");
    shell_exec("find " . escapeshellcmd(WP_CONTENT_DIR) . " ! -perm 755 -type d -exec chmod 755 {} \; &");

    shell_exec("find " . escapeshellcmd(ABSPATH.'/'.WPINC.'/') . " ! -perm 644 -type f -exec chmod 644 {} \; &");
    shell_exec("find " . escapeshellcmd(ABSPATH.'/'.WPINC.'/') . " ! -perm 755 -type d -exec chmod 755 {} \; &");

    shell_exec("find " . escapeshellcmd(ABSPATH.'/wp-admin/') . " ! -perm 644 -type f -exec chmod 644 {} \; &");
    shell_exec("find " . escapeshellcmd(ABSPATH.'/wp-admin/') . " ! -perm 755 -type d -exec chmod 755 {} \; &");
}

/* Checks if the default WordPress rules are present.  Otherwise it uses wp-cli to flush them to .htaccess
 * @since 0.6.6
 * @param NONE
 * @return NONE
 * @calledfrom zysyshosting_maintenance()
 */
function zysyshosting_wp_rules_check() {
    $htaccessPath = ABSPATH . '.htaccess';

    if (file_exists($htaccessPath))
        $htaccessContent = file_get_contents($htaccessPath);
    else
        $htaccessContent = ""; 

    $config = <<<EOL
apache_modules:
  - mod_rewrite
EOL;
    $openingtag = "# BEGIN WordPress";
    $closingtag = "# END WordPress";
    if (strpos(zysyshosting_make_single_line($htaccessContent), zysyshosting_make_single_line($openingtag)) === false || strpos(zysyshosting_make_single_line($htaccessContent), zysyshosting_make_single_line($closingtag)) === false) {
        touch("wp-cli.local.yml");
        zysys_file_write("wp-cli.local.yml", $config, debug_backtrace()[1]['function']);
        system("/usr/sbin/wp --allow-root rewrite flush --hard 2>/dev/null 1>/dev/null 3>/dev/null");
        unlink("wp-cli.local.yml");
    }   
}

/* Adds param 0 to the file between param1 and param2 OR updates the content between param1 and param2
 * IN 0.6.4, Variable htaccess based on 4th param.  Adds to ABSPATH . .htaccess by default
 * @since 0.5.5
 * @param $content, $header, $footer, $path (optional - options are 'uploads', 'wp-includes', and [null or default])
 * @return NONE
 */
function htaccess_adder($code, $openingtag, $closingtag, $path = null) {
    if ($path == null || $path == 'default') {
        $htaccessPath = ABSPATH . '.htaccess';
    } elseif ($path == 'uploads') {
        $htaccessPath = wp_upload_dir( null, false )['basedir'] . '/.htaccess';
        if (!is_dir(wp_upload_dir( null, false )['basedir'])) {
           return;
        }
    } elseif ($path == 'wp-includes') {
        $htaccessPath = ABSPATH . WPINC . '/.htaccess';
    }

    if (file_exists($htaccessPath)) {
        $htaccessContent = file_get_contents($htaccessPath);
        if ($htaccessContent == "") {
            return -2;
        }
    } else {
        $htaccessContent = "";
    }

    if (strpos(zysyshosting_make_single_line($htaccessContent), zysyshosting_make_single_line($openingtag) . PHP_EOL . zysyshosting_make_single_line($code) . PHP_EOL . zysyshosting_make_single_line($closingtag)) !== false) {
        return -1;
    } else {
        if (strpos(zysyshosting_make_single_line($htaccessContent), zysyshosting_make_single_line($openingtag)) !== false && strpos(zysyshosting_make_single_line($htaccessContent), zysyshosting_make_single_line($closingtag)) !== false) {
            $htaccessContent = preg_replace('/' . preg_quote($openingtag) . '.*' . preg_quote($closingtag) . '/s', $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag, $htaccessContent);
        } else {
            $htaccessContent .= PHP_EOL . $openingtag . PHP_EOL . $code . PHP_EOL . $closingtag . PHP_EOL;
        }
        zysys_file_write($htaccessPath, $htaccessContent, debug_backtrace()[1]['function']);
    }
}

/* Converts multiple lines to one line for the purpose of file comparisions
 * @since 0.5.5
 * @param $file_contents
 * @return $single_line_file_contents_for_regex_purposes
 */
function zysyshosting_make_single_line($str) {
    return preg_replace('/(  |\s+|\n|\r)/', ' ', $str);
}

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

    if ((!isset($_SERVER['HTTPS']) || !$_SERVER['HTTPS']) && $_SERVER['SERVER_PORT'] != 443) {
        if (!defined('ZYCACHE'))
            define('ZYCACHE', 'http://www.zycache.com');
        if (!defined('ZYCACHE_JS'))
            define('ZYCACHE_JS', 'http://js.zycache.com');
        if (!defined('ZYCACHE_CSS'))
            define('ZYCACHE_CSS', 'http://css.zycache.com');
        if (!defined('ZYCACHE_IMAGE'))
            define('ZYCACHE_IMAGE', 'http://img.zycache.com');
    } else {
        if (!defined('ZYCACHE'))
            define('ZYCACHE', ZYCACHE_HTTPS);
        if (!defined('ZYCACHE_JS'))
            define('ZYCACHE_JS', ZYCACHE_HTTPS);
        if (!defined('ZYCACHE_CSS'))
            define('ZYCACHE_CSS', ZYCACHE_HTTPS);
        if (!defined('ZYCACHE_IMAGE'))
            define('ZYCACHE_IMAGE', ZYCACHE_HTTPS);
    }

    if (!defined('ZYSYS_HOSTING_OBJECT_CACHE_LATEST_VERSION'))
        define('ZYSYS_HOSTING_OBJECT_CACHE_LATEST_VERSION', '1.0');

    if (!defined('ZYSYSHOSTING_OPTIMIZATIONS_VERSION'))
        define('ZYSYSHOSTING_OPTIMIZATIONS_VERSION', '0.7.1');

    if(!defined('ZYSYS_HOSTING_URL_PREP_REGEX'))
        define('ZYSYS_HOSTING_URL_PREP_REGEX', '|(https?:){0,1}//(www\.){0,1}|');

}

/* Adds DNS Prefetch params to the head
 * @since 0.6.1
 * @param NONE
 * @return NONE
 * @hooksto wp_head
 */

function zysyshosting_zycache_dns_prefetch() {
    if (ZYCACHE == ZYCACHE_HTTPS)
        $https = true;
    else
        $https = false;

    if ($https) {
        $domains = array(ZYCACHE_HTTPS);
    } else {
        $domains = array(ZYCACHE, ZYCACHE_JS, ZYCACHE_CSS, ZYCACHE_IMAGE);
    }
    foreach ($domains as $domain) {
        echo '<link rel="dns-prefetch" href="' . $domain . '" />';
    }
}

/* Gets the images sent through the thumbnails to reflect Zycache
 * @since 0.6.2
 * @param $image_source_url
 * @return $modified_source_url
 * @hooksto wp_get_attachment_url
 */
function zycache_thumbnail_setup($url) {
    if (is_admin())
        return $url;
    $originalDomain = get_bloginfo('url');
    $domain = zysyshosting_clean_domain_prefix($originalDomain);
    return str_replace($domain, zysyshosting_clean_domain_prefix(ZYCACHE_IMAGE) . '/' . $domain, $url); 
}

/* Replace urls in the_content of relative and explict urls
 * @since 0.5.5
 * @param NONE
 * @return the_content
 */

function zysyshosting_zycache_uploads_setup($content) {
    if (is_admin())
        return $content;
    if (ZYSYS_IS_SUBBLOG)
        return $content;

    $origContent = $content;

    $originalUploadDir = wp_upload_dir( null, false )['baseurl'];
    $originalURL = get_bloginfo('url');
    $relImageUpload = substr($originalUploadDir, strlen($originalURL));

    if (strpos($relImageUpload, '/') !== 0)
        $relImageUpload = '/' . $relImageUpload;

    if (ZYCACHE == ZYCACHE_HTTPS)
        $https = true;
    else
        $https = false;

    $originalUploadDir = zysyshosting_clean_domain_prefix($originalUploadDir);
    $uploadDir = $originalUploadDir;

    $uploadDir = $https? ZYCACHE_HTTPS  . '/' . $uploadDir : ZYCACHE . '/' .$uploadDir;
    $content = preg_replace('|([\(' . "'" .'"])'.str_replace('/', '/+', $relImageUpload).'/(.*?)([\)'."'" . '"])|', "\\1".$uploadDir."\\2"."\\3", $content);
    $content = preg_replace('|https?://(www\.){0,1}'.str_replace('/', '/+', $originalUploadDir).'(.*?)([ '."'" . '\)"])|', $uploadDir."\\2"."\\3", $content);

    if (strlen($origContent) > strlen($content))
        return $origContent; # Crisis mode! :-)
    else    
        return $content;
}

/* Remove https://www. or any variant theirin from the argument
 * @param $domain
 * @return $domain_without_prefix
 */
function zysyshosting_clean_domain_prefix($domain) {
    return preg_replace(ZYSYS_HOSTING_URL_PREP_REGEX, '', $domain);
}

/* Disables WP CRON from running on pageloads so the server can run the wp-cron automatically
 * @calls wpconfig_adder && uses the wp-optimize-domains script to add it to cron automatically
 * @param NONE
 * @return NONE
 */
function zysyshosting_wp_cron_setup() {
    $cron = <<<EOC
# WP Cron is called directly from the Zysys Server
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

if (!defined('WP_CRON_LOCK_TIMEOUT')) {
    define( 'WP_CRON_LOCK_TIMEOUT', 600 ) ;
}
EOC;
    wpconfig_adder($cron, "## BEGIN ZYSYSHOSTING_CRON_SETTINGS", "## END ZYSYSHOSTING_CRON_SETTINGS");
    $domain = zysyshosting_clean_domain_prefix(site_url());
    shell_exec("/scripts/wp-optimize-domains.pl --add-cron='".$domain."'");
}

/* Sets the default files permissions for wordpress to use
 * @calls wpconfig_adder
 * @param NONE
 * @return NONE
 */
function zysyshosting_wp_secure_files() {
    $secure = <<<EOC
if (!defined('FS_CHMOD_DIR')) {
    define( 'FS_CHMOD_DIR', ( 0755 & ~ umask() ) );
}

if (!defined('FS_CHMOD_FILE')) {
    define( 'FS_CHMOD_FILE', ( 0644 & ~ umask() ) );
}
EOC;
    wpconfig_adder($secure, "## BEGIN ZYSYSHOSTING_SET_DEFAULT_WORDPRESS_FILE_PERMISSIONS", "## END ZYSYSHOSTING_SET_DEFAULT_WORDPRESS_FILE_PERMISSIONS");

}

/* Prevents direct access to wp-includes and wp-config
 * @calls htaccess_adder
 * @param NONE
 * @return NONE
 */
function zysyshosting_wordpress_securing() {
    $protect_wp = <<<EOC
# Source: https://codex.wordpress.org/Hardening_WordPress#Securing_wp-includes
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^wp-admin/includes/ - [F,L]
RewriteRule !^wp-includes/ - [S=3]
RewriteRule ^wp-includes/[^/]+\.php$ - [F,L]
RewriteRule ^wp-includes/js/tinymce/langs/.+\.php - [F,L]
RewriteRule ^wp-includes/theme-compat/ - [F,L]
</IfModule>

# Source: https://codex.wordpress.org/Hardening_WordPress#Securing_wp-config.php
<Files wp-config.php>
order allow,deny
deny from all
</Files>
EOC;
    htaccess_adder($protect_wp, "## BEGIN ZYSYSHOSTING_WORDPRESS_SECURING", "## END ZYSYSHOSTING_WORDPRESS_SECURING");
    return;
}

/* Prevents indexes from being shown if no files in directory
 * @calls htaccess_adder
 * @param NONE
 * @return NONE
 */
function zysyshosting_disable_indexes() {
    $disable_indexes = <<<EOC
Options All -Indexes
EOC;
    htaccess_adder($disable_indexes, "## BEGIN ZYSYSHOSTING_DISABLE_INDEXES", "## END ZYSYSHOSTING_DISABLE_INDEXES");
}

/* Prevents php execution in wp-includes (allows ms-files.php execution) and uploads
 * @calls htaccess_adder
 * @param NONE
 * @return NONE
 */
function zysyshosting_disable_php_execution() {
    $disable_php = <<<EOC
<Files *.php>
deny from all
</Files>
EOC;
    $allow_msfiles = <<<EOC

<Files ms-files.php>
allow from all
</Files>
EOC;
    htaccess_adder($disable_php, "## BEGIN ZYSYSHOSTING_DISABLE_PHP_IN_UPLOADS", "## END ZYSYSHOSTING_DISABLE_PHP_IN_UPLOADS", 'uploads');
    htaccess_adder($disable_php.$allow_msfiles, "## BEGIN ZYSYSHOSTING_DISABLE_PHP_IN_WP_INCLUDES", "## END ZYSYSHOSTING_DISABLE_PHP_IN_WP_INCLUDES", 'wp-includes');
}

/* Adds ob_clean() and flush() to ms_files.php which allows multisite to render files for multi-domain and domain mapping
 * @calls msfiles_adder($content)
 * @since 0.5.5
 * @param NONE
 * @return NONE
 */

function zysyshosting_ms_files() {
    $msfiles = <<<EOC
ob_clean();
flush();
EOC;
    msfiles_adder($msfiles);
}

/* Filters JS for hooked assets if it's not a subblog in multisite
 * @since 0.5.5
 * @calledfrom script_loader_src hook
 * @param $url
 * @return $url_with_adjusted_domain
 */
function zysyshosting_zycache_script_setup($url) {
    $originalDomain = get_bloginfo('url');
    $domain = zysyshosting_clean_domain_prefix($originalDomain);

    if (ZYCACHE == ZYCACHE_HTTPS)
        $https = true;
    else
        $https = false;

    $replacedDomain = ($https? ZYCACHE_HTTPS : ZYCACHE_JS) . '/' .$domain;
    return preg_replace('|'.$originalDomain.'|', $replacedDomain, $url);

}

/* Filters CSS for hooked assets if it's not a subblog in multisite
 * @since 0.5.5
 * @calledfrom style_loader_src hook
 * @param $url
 * @return $url_with_adjusted_domain
 */
function zysyshosting_zycache_style_setup($url) {
    $originalDomain = get_bloginfo('url');
    $domain = zysyshosting_clean_domain_prefix($originalDomain);

    if (ZYCACHE == ZYCACHE_HTTPS)
        $https = true;
    else
        $https = false;

    $replacedDomain = ($https? ZYCACHE_HTTPS : ZYCACHE_CSS) . '/' .$domain;
    return preg_replace('|'.$originalDomain.'|', $replacedDomain, $url);
} 
