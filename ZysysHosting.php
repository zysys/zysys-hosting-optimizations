<?php
/*
 * Plugin Name: Zysys Hosting Optimizations
 * Plugin URI: https://codex.zysys.org/bin/view.cgi/Main/WordpressPlugin:ZysysHostingOptimizations
 * Description: This plugin allows for all the default Zysys Hosting Optimizations to be installed at once and continually configured.
 * Version: 0.7.3
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

add_action( 'init', 'zysyshosting_updater_init' );

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
    if (defined('USE_ZYCACHE_IMG') && USE_ZYCACHE_IMG) {
        add_filter('the_content', 'zysyshosting_zycache_uploads_setup');
        add_filter('wp_get_attachment_url', 'zycache_thumbnail_setup');
    }

    if (defined('USE_ZYCACHE_JS') && USE_ZYCACHE_JS)
        add_filter('script_loader_src', 'zysyshosting_zycache_script_setup');
    if (defined('USE_ZYCACHE_CSS') && USE_ZYCACHE_CSS)
        add_filter('style_loader_src', 'zysyshosting_zycache_style_setup');
    if ((defined('USE_ZYCACHE_IMG') && USE_ZYCACHE_IMG) || defined('USE_ZYCACHE_JS') && USE_ZYCACHE_JS || defined('USE_ZYCACHE_CSS') && USE_ZYCACHE_CSS)
        add_action('wp_head', 'zysyshosting_zycache_dns_prefetch');
}

function zysyshosting_optimizations_activation() {
    $zycache = <<<EOC
if (!defined('USE_ZYCACHE_IMG')) {
    define('USE_ZYCACHE_IMG', true);
}
if (!defined('USE_ZYCACHE_CSS')) {
    define('USE_ZYCACHE_CSS', true);
}
if (!defined('USE_ZYCACHE_JS')) {
    define('USE_ZYCACHE_JS', true);
}
EOC;
    wpconfig_adder($zycache, "## BEGIN ZYCACHE_SETTINGS", "## END ZYCACHE_SETTINGS");

    if( !wp_next_scheduled( 'zysyshosting_optimizations_updates' ) ) {
        wp_schedule_event( time(), 'daily', 'zysyshosting_optimizations_updates' );
    }
    zysyshosting_maintenance();
    zyapi_keys();
}

function zysyshosting_optimizations_deactivation() {
    wp_clear_scheduled_hook('zysyshosting_optimizations_updates');
    wp_clear_scheduled_hook('zysyshosting_maintenance_hourly');
    if (wp_next_scheduled('zysyshosting_optimize_images'))
        wp_clear_scheduled_hook('zysyshosting_optimize_images');
    zyapi_keys_disable();
    wpconfig_adder('', "## BEGIN ZYSYSHOSTING_CRON_SETTINGS", "## END ZYSYSHOSTING_CRON_SETTINGS");
    wpconfig_adder('', "## BEGIN ZYCACHE_SETTINGS", "## END ZYCACHE_SETTINGS");
    wpconfig_adder('', "## BEGIN ZYSYSHOSTING_SET_DEFAULT_WORDPRESS_FILE_PERMISSIONS", "## END ZYSYSHOSTING_SET_DEFAULT_WORDPRESS_FILE_PERMISSIONS");
    htaccess_adder('', "## BEGIN ZYSYSHOSTING_WORDPRESS_SECURING", "## END ZYSYSHOSTING_WORDPRESS_SECURING");
    htaccess_adder('', "## BEGIN ZYSYSHOSTING_DISABLE_INDEXES", "## END ZYSYSHOSTING_DISABLE_INDEXES");
    htaccess_adder('', "## BEGIN ZYSYSHOSTING_DISABLE_PHP_IN_UPLOADS", "## END ZYSYSHOSTING_DISABLE_PHP_IN_UPLOADS", 'uploads');
    htaccess_adder('', "## BEGIN ZYSYSHOSTING_DISABLE_PHP_IN_WP_INCLUDES", "## END ZYSYSHOSTING_DISABLE_PHP_IN_WP_INCLUDES", 'wp-includes');
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
 * @calledfrom zysyshosting_maintenance, zyapi_keys
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
/*
 * array(Purpose, Plugin Name, Internal ID, WordPress Plugin Identifier, WordPress Plugin Path, Update URL if not in the repo, License Key API ID)
 */
    $thirdPartyPlugins = array(
        array('SEO', 'The SEO Framework', 'seo-framework', 'autodescription', 'autodescription/autodescription.php', null, null),
        array('Drag & Drop Editor', 'Beaver Builder Plugin (Standard Version)', 'beaver-builder', 'bb-plugin', 'bb-plugin/fl-builder.php', 'http://updates.wpbeaverbuilder.com/?fl-api-method=download_update&domain=' . site_url() . '&license=7465622e666c666c6d4075706e6d&product=Beaver+Builder+Plugin+%28Standard+Version%29&slug=bb-plugin&release=stable', 'BEAVER_BUILDER'),
        array('Spam Protection', 'Akismet Anti-Spam', 'akismet', 'akismet', 'akismet/akismet.php', null, 'AKISMET'),
        array('Lazy Loading', 'Lazy Load by WP Rocket', 'lazy-load', 'rocket-lazy-load', 'rocket-lazy-load/rocket-lazy-load.php', null, null),
    ); 
    if (!current_user_can('update_core')) {
        wp_die( __('You do not have sufficient permissions to access this page.') );
    }
    $maint = 0;
    if (isset($_POST['ZysysHostingMaintenance']) && $_POST['ZysysHostingMaintenance'] == "Run Zysys Hosting Maintenance Procedures") {
        zysyshosting_maintenance();
        $maint = 1;
    } elseif (isset($_POST['ZysysHostingOptions'])) { 
        if (isset($_POST['ZysysHostingCoreUpdater']) && $_POST['ZysysHostingCoreUpdater'] == 1)
            $keepCoreUpToDate = 'update1';
        else
            $keepCoreUpToDate = '';

        if (isset($_POST['ZysysHostingPluginUpdater']) && $_POST['ZysysHostingPluginUpdater'] == 1)
            $keepPluginsUpToDate = 'update1';
        else
            $keepPluginsUpToDate = '';

        if (isset($_POST['ZysysHostingThemeUpdater']) && $_POST['ZysysHostingThemeUpdater'] == 1)
            $keepThemesUpToDate = 'update1';
        else
            $keepThemesUpToDate = '';

        /* these are structured this way to prevent someone from adding code to the wp-config
         * by sending malicious code to a ZycacheSetting field.
         */
        $zycacheLine = 'if (!defined('."'%s'".')){' . PHP_EOL . '    define(' . "'%s'" . ', %s);' . PHP_EOL . '}';
        if (isset($_POST['ZycacheImages']) && $_POST['ZycacheImages'] == 1)
            $zycacheimages = "true";
        else
            $zycacheimages = "false";
        if (isset($_POST['ZycacheCSS']) && $_POST['ZycacheCSS'] == 1)
            $zycachecss = "true";
        else
            $zycachecss = "false";
        if (isset($_POST['ZycacheJS']) && $_POST['ZycacheJS'] == 1)
            $zycachejs = "true";
        else
            $zycachejs = "false";

         if (isset($_POST['ZysysHostingImageCompression']) && $_POST['ZysysHostingImageCompression'] == 1) {
            $keepImagesCompressed = 'compress1';
            if(!wp_next_scheduled('zysyshosting_optimize_images'))
                wp_schedule_event(time(), 'daily', 'zysyshosting_optimize_images');

        } else {
            $keepImagesCompressed = '';
            if (wp_next_scheduled('zysyshosting_optimize_images'))
                wp_clear_scheduled_hook('zysyshosting_optimize_images');
        }

        update_option('zysyshosting_update_core_automatically', $keepCoreUpToDate);
        update_option('zysyshosting_update_plugins_automatically', $keepPluginsUpToDate);
        update_option('zysyshosting_update_themes_automatically', $keepThemesUpToDate);
        update_option('zysyshosting_keep_images_compressed', $keepImagesCompressed);
        wpconfig_adder(sprintf($zycacheLine, "USE_ZYCACHE_IMG", "USE_ZYCACHE_IMG", $zycacheimages) . PHP_EOL . sprintf($zycacheLine, "USE_ZYCACHE_CSS", "USE_ZYCACHE_CSS", $zycachecss) . PHP_EOL . sprintf($zycacheLine, "USE_ZYCACHE_JS", "USE_ZYCACHE_JS", $zycachejs), "## BEGIN ZYCACHE_SETTINGS", "## END ZYCACHE_SETTINGS");
        $optset = 1;
        print '<script type="text/javascript">location.reload()</script>';
    }

    if (isset($_REQUEST['install-plugin'])) {
        foreach ($thirdPartyPlugins as $plugin) {
            if ($plugin[2] == $_REQUEST['install-plugin']) {
                if ($plugin[5] != null) {
                    $link = $plugin[5]; 
                } else {
                    include_once( ABSPATH . 'wp-admin/includes/plugin-install.php' ); //for plugins_api..
                    $api = plugins_api( 'plugin_information', array(
                        'slug' => $plugin[3],
                        'fields' => array(
                            'short_description' => false,
                            'sections' => false,
                            'requires' => false,
                            'rating' => false,
                            'ratings' => false,
                            'downloaded' => false,
                            'last_updated' => false,
                            'added' => false,
                            'tags' => false,
                            'compatibility' => false,
                            'homepage' => false,
                            'donate_link' => false,
                        ),
                    ) );
                    $link = $api->download_link;
                }
                include_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );
                $updater = new Plugin_Upgrader(new Plugin_Installer_Skin(compact('title', 'url', 'nonce', 'plugin', 'api')));
                $updater->install($link);
            } elseif ($plugin[2] == $_REQUEST['activate-plugin']) {
                foreach ($thirdPartyPlugins as $plugin) {
                    if ($plugin[2] == $_REQUEST['activate-plugin']) {
                        activate_plugin( $plugin[4], '', ! empty( $_GET['networkwide'] ), true );
						if ($plugin[6] != null) {
							zyenable_service($plugin[6]);
						}
                    }
                }
            }
        }
    }
    if (isset($_POST['rekey'])) {
        zyapi_keys();
    }
?>
<style type="text/css">
.wrap{display:none;}.padtd{padding-top:11px;}
</style>
<div class="wrap" style="display:block!important">
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
<h2>Zycache Settings</h2>
<input type="checkbox" id="ZycacheImages" name="ZycacheImages" <?php checked(USE_ZYCACHE_IMG); ?> value='1' /><label for="ZycacheImages">Use Zycache for images (<em>recommended</em>)</input><br />
<input type="checkbox" id="ZycacheCSS" name="ZycacheCSS" <?php checked(USE_ZYCACHE_CSS); ?> value='1' /><label for="ZycacheCSS">Use Zycache for stylesheets (<em>recommended</em>)</input><br />
<input type="checkbox" id="ZycacheJS" name="ZycacheJS" <?php checked(USE_ZYCACHE_JS); ?> value='1' /><label for="ZycacheJS">Use Zycache for scripts (<em>recommended</em>)</input><br />
<h3>Speed Optimizations</h3>
<input type="checkbox" id="ZysysHostingImageCompression" name="ZysysHostingImageCompression" <?php checked(get_option('zysyshosting_keep_images_compressed'), 'compress1'); ?> value='1' /><label for="ZysysHostingImageCompression">Keep Images Compressed without Loosing Quality</label><br />
<?php if(isset($optset) && $optset) { ?>
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
<h2>Install Extra Plugins</h2>
<p>We recommend certain plugins (some of which we've purchased licenses, others are open source).  Use the buttons below to install any of them.</p>  
</form>
<p>We have licensed software for you to use!  Install the software below and it will install the license key after it activates the plugin.  If you need to reinstall all license keys, click this button:</p>
<form name="zysyshostingrekey" id="zysyshostingrekey" method="post" action="">
<?php if(isset($_POST['rekey'])) { ?>
<input type="submit" name="rekey" disabled style="font-style:italic" class="button-primary" value="Reinstallation of License Keys Complete." />
<?php } else { ?>
<input type="submit" value="Install All License Keys" id="rekey" name="rekey" class="button-primary"/>
<?php } ?>

</form>
<br />
<form name="zysyshostingplugins" id="zysyshostingplugins" method="post" action="">
<table>
<tr><th>Plugin Purpose</th><th>Plugin Name</th><th>Link to Install/Activate</th></tr>
<?php
foreach ($thirdPartyPlugins as $plugin) {
?>
<tr style="text-align:center;border:5px solid #000"><td class="padtd"><?php echo $plugin[0]; ?><hr /></td><td class="padtd"><?php echo $plugin[1]; ?><hr /></td><td><a <?php if (!zysyshosting_is_plugin_installed($plugin[1])) {print "id='install-".$plugin[2]."' value='INSTALL " .strtoupper($plugin[1])."' class='button' onclick='javascript:jQuery(\"#install-plugin\").attr(\"value\", \"" . $plugin[2] . "\");jQuery(\"#zysyshostingplugins\").submit();'>INSTALL " . strtoupper($plugin[1]);}else{if (is_plugin_active($plugin[4])) { print 'disabled="disabled"';} print "id='activate-" . $plugin[2] . "' class='button' onclick='javascript:jQuery(\"#activate-plugin\").attr(\"value\", \"" . $plugin[2] . "\");jQuery(\"#zysyshostingplugins\").submit();''>ACTIVATE " . strtoupper($plugin[1]);}; ?></a><hr /></td></tr>
<?php
}
?>
</table>
<input type='hidden' name='install-plugin' id='install-plugin' value='' />
<input type='hidden' name='activate-plugin' id='activate-plugin' value='' />
</form>
<?php
}

/* Checks to see if a plugin is installed.
* @since 0.7.2
* @param The plugin title
* @return true/false
* @author https://gist.github.com/lucatume/85b0a5dcd4689d11a380
*/
function zysyshosting_is_plugin_installed($pluginTitle) {
    $installedPlugins = get_plugins();
    foreach ($installedPlugins as $installedPlugin => $data)
        if (trim(strtolower($data['Title'])) == trim(strtolower($pluginTitle)))
            return true;

    return false;
}

/* Returns a link to install a 3rd party plugin
 * @since 0.7.2
 * @param The plugin slug
 * @return href_value
 * @author https://wordpress.stackexchange.com/questions/149928/how-can-i-create-a-plugin-installation-link
 */
function zysyshosting_install_other_plugin_link($type, $plugin_slug) {
    if ($type == 'install') {
        $action = 'install-plugin';
        $type = 'install-plugin';
        $file = 'update.php';
    } elseif ($type == 'activate') {
        $action = 'activate';
        $type = 'activate-plugin';
        $file = 'plugins.php';
    } else {
        return;
    }
    return wp_nonce_url( add_query_arg( array( 'action' => $action, 'plugin' => $plugin_slug), admin_url( $file )), $type.'_'.$plugin_slug);
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
        define('ZYSYSHOSTING_OPTIMIZATIONS_VERSION', '0.7.3');

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

    if (strpos($url, '.php') === 0)
        return $url;

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

    if (strpos($url, '.php') === 0)
        return $url;

    $replacedDomain = ($https? ZYCACHE_HTTPS : ZYCACHE_CSS) . '/' .$domain;
    return preg_replace('|'.$originalDomain.'|', $replacedDomain, $url);
} 

/* This was formerly in includes/license-keys.php */

/* Installs all API Keys
 * @since 0.7.2
 * @param NONE
 * @return NONE
 */
function zyapi_keys() {
    if (zysyshosting_authorize()) {
        zyenable_service('AKISMET');
        if (is_plugin_active('bb-plugin/fl-builder.php'))
            zyenable_service('BEAVER_BUILDER');
        if (is_plugin_active('audiotheme/audiotheme.php'))
            zyenable_service('AUDIOTHEME_GOOGLE_MAPS');
    }
}

/* Uninstalls all API Keys
 * @since 0.7.2
 * @param NONE
 * @return NONE
 */
function zyapi_keys_disable() {
    zydisable_service('AKISMET');
    zydisable_service('BEAVER_BUILDER');
    zydisable_service('AUDIOTHEME_GOOGLE_MAPS');

}

/* Gets an API key based on parameter
 * @since 0.7.2
 * @param service to get the api key
 * @return api key
 * @calledfrom zyenable_service, zydisable_service
 */ 
function zyget_key($service) {
    return exec('/scripts/get-api.pl ' . $service);
}

/* Enables a service based on parameter
 * @since 0.7.2
 * @param service to enable
 * @return NONE
 * @calledby zyapi_keys
 */ 
function zyenable_service($service) {
    if ($service == 'BEAVER_BUILDER') {
        # Run this in the maintenance proc...
        include_once(WP_PLUGIN_DIR . '/bb-plugin/includes/updater/classes/class-fl-updater.php');
        FLUpdater::save_subscription_license(zyget_key('beaver-builder'));
    } elseif ($service == 'AKISMET') {
        # Akismet API Key
$akismet = <<<EOC
# Make use of the Zysys Hosting AKISMET API key if it isn't already defined.
if (!defined('WPCOM_API_KEY')) {
    define('WPCOM_API_KEY', 'AKISMET_API_KEY');
}
EOC;
    $akismet = str_replace('AKISMET_API_KEY', zyget_key('akismet'), $akismet);
    wpconfig_adder($akismet, "## BEGIN ZYSYSHOSTING_AKISMET_API", "## END ZYSYSHOSTING_AKISMET_API");
    } elseif ($service == 'AUDIOTHEME_GOOGLE_MAPS') {
        update_option('audiotheme_google_maps_api_key', zyget_key('audiotheme-google-maps'));
    }
}

/* Disnables a service based on parameter
 * @since 0.7.2
 * @param service to disable
 * @return NONE
 * @calledby zyapi_keys_disable
 */ 
function zydisable_service($service) {
    if ($service == 'BEAVER_BUILDER') {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_value = %s;", zyget_key('beaver-builder') ));
    } elseif ($service == 'AKISMET') {
        # Akismet API Key
$akismet = <<<EOC
# Zysys Hosting AKISMET API key removed.  Enable the plugin to have it activated.
EOC;
    wpconfig_adder($cron, "## BEGIN ZYSYSHOSTING_AKISMET_API", "## END ZYSYSHOSTING_AKISMET_API");
    } elseif ($service == 'AUDIOTHEME_GOOGLE_MAPS') {
        if (get_option('audiotheme_google_maps_api_key') == zyget_key('audiotheme_google_maps_api_key'))
            delete_option('audiotheme_google_maps_api_key');
    }

}

/* This was formerly in includes/plugin-updater.php */

/**
 * Plugin Updater Class
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version 0.1.3
 * @author David Chandra Purnama <david@shellcreeper.com>
 * @link http://autohosted.com/
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @copyright Copyright (c) 2013, David Chandra Purnama
 */
class Plugin_Updater{

    /**
     * @var $config the config for the updater
     * @access public
     */
    var $config;


    /**
     * Class Constructor
     *
     * @since 0.1.0
     * @param array $config the configuration required for the updater to work
     * @return void
     */
    public function __construct( $config = array() ) {

        /* default config */
        $defaults = array(
            'base'        => '',
            'repo_uri'    => '',
            'repo_slug'   => '',
            'key'         => '',
            'dashboard'   => false,
            'username'    => false,
            'autohosted'  => 'plugin.0.1.3',
        );

        /* merge configs and defaults */
        $this->config = wp_parse_args( $config, $defaults );

        /* disable request to wp.org repo */
        add_filter( 'http_request_args', array( &$this, 'disable_wporg_request' ), 5, 2 );

        /* check minimum config before doing stuff */
        if ( !empty( $this->config['base'] ) && !empty ( $this->config['repo_uri'] ) && !empty ( $this->config['repo_slug'] ) ){

            /* filters for admin area only */
            if ( is_admin() ) {

                /* filter site transient "update_plugins" */
                add_filter( 'pre_set_site_transient_update_plugins', array( &$this, 'transient_update_plugins' ) );

                /* filter plugins api */
                add_filter( 'plugins_api_result', array( &$this, 'plugins_api_result' ), 10, 3 );

                /* forder name fix */
                add_filter( 'upgrader_post_install', array( &$this, 'upgrader_post_install' ), 10, 3 );

                /* add dashboard widget for activation key */
                if ( true === $this->config['dashboard'] ){
                    add_action( 'wp_dashboard_setup', array( &$this, 'add_dashboard_widget' ) );
                }
            }
        }
    }


    /**
     * Disable request to wp.org plugin repository
     * @link http://markjaquith.wordpress.com/2009/12/14/excluding-your-plugin-or-theme-from-update-checks/
     * @since 0.1.2
     */
    public function disable_wporg_request( $r, $url ){

        /* If it's not a plugin request, bail early */
        if ( 0 !== strpos( $url, 'http://api.wordpress.org/plugins/update-check' ) )
            return $r;

        /* this plugin slug */
        $plugin_slug = dirname( $this->config['base'] );

        /* unserialize data */
        $plugins = unserialize( $r['body']['plugins'] );

        /* default value */
        $to_disable = '';

        /* check if plugins object is set */
        if  ( isset( $plugins->plugins ) ){

            $all_plugins = $plugins->plugins;

            /* loop all plugins */
            foreach ( $all_plugins as $plugin_base => $plugin_data ){

                /* only if the plugin have the same folder */
                if ( dirname( $plugin_base ) == $plugin_slug ){

                    /* get plugin to disable */
                    $to_disable = $plugin_base;
                }
            }
        }
        /* unset this plugin only */
        if ( !empty( $to_disable ) )
            unset( $plugins->plugins[ $to_disable ] );

        /* serialize it back */
        $r['body']['plugins'] = serialize( $plugins );
        return $r;
    }


    /**
     * Data needed in an array to make everything simple.
     * 
     * @since 0.1.0
     * @return array
     */
    public function updater_data(){

        /* Updater data: Hana Tul Set! */
        $updater_data = array();

        /* Base name */
        $updater_data['basename'] = $this->config['base'];

        /* Plugin slug */
        $slug = dirname( $this->config['base'] );
        $updater_data['slug'] = $slug;

        /* Main plugin file */
        $updater_data['file'] = basename( $this->config['base'] );

        /* Updater class location is in the main plugin folder  */
        $file_path = plugin_dir_path( __FILE__ ) . $updater_data['file'];

        /* if it's in sub folder */
        if ( basename( dirname( dirname( __FILE__ ) ) ) == $updater_data['slug'] )
            $file_path = plugin_dir_path(  dirname( __FILE__ ) ) . $updater_data['file'];

        /* Get plugin data from main plugin file */
        $get_plugin_data = get_plugin_data( $file_path );

        /* Plugin name */
        $updater_data['name'] = strip_tags( $get_plugin_data['Name'] );

        /* Plugin version */
        $updater_data['version'] = strip_tags( $get_plugin_data['Version'] );

        /* Plugin uri / uri */
        $uri = '';
        if ( $get_plugin_data['PluginURI'] ) $uri = esc_url( $get_plugin_data['PluginURI'] );
        $updater_data['uri'] = $uri;

        /* Author with link to author uri */
        $author = strip_tags( $get_plugin_data['Author'] );
        $author_uri = $get_plugin_data['AuthorURI'];
        if ( $author && $author_uri ) $author = '<a href="' . esc_url_raw( $author_uri ) . '">' . $author . '</a>';
        $updater_data['author'] = $author;

        /* by user role */
        if ( false === $this->config['username'] )
            $updater_data['role'] = false;
        else
            $updater_data['role'] = true;

        /* User name / login */
        $username = '';
        if ( false !== $this->config['username'] && false === $this->config['dashboard'] ) 
            $username = $this->config['username'];
        if ( true === $this->config['username'] && true === $this->config['dashboard'] ){
            $widget_id = 'ahp_' . $slug . '_activation_key';
            $widget_option = get_option( $widget_id );
            $username = ( isset( $widget_option['username'] ) && !empty( $widget_option['username'] ) ) ? $widget_option['username'] : '' ;
        }
        $updater_data['login'] = $username;

        /* Activation key */
        $key = '';
        if ( $this->config['key'] ) $key = md5( $this->config['key']);
        if ( empty( $key ) && true === $this->config['dashboard'] ){
            $widget_id = 'ahp_' . $slug . '_activation_key';
            $widget_option = get_option( $widget_id );
            $key = ( isset( $widget_option['key'] ) && !empty( $widget_option['key'] ) ) ? md5( $widget_option['key'] ) : '' ;
        }
        $updater_data['key'] = $key;

        /* Domain */
        $updater_data['domain'] = esc_url_raw( get_bloginfo( 'url' ) );

        /* Repo uri */
        $repo_uri = '';
        if ( !empty( $this->config['repo_uri'] ) )
            $repo_uri = trailingslashit( esc_url_raw( $this->config['repo_uri'] ) );
        $updater_data['repo_uri'] = $repo_uri;

        /* Repo slug */
        $repo_slug = '';
        if ( !empty( $this->config['repo_slug'] ) )
            $repo_slug = sanitize_title( $this->config['repo_slug'] );
        $updater_data['repo_slug'] = $repo_slug;

        /* Updater class id and version */
        $updater_data['autohosted'] = esc_attr( $this->config['autohosted'] );

        return $updater_data;
    }


    /**
     * Check for plugin updates
     * 
     * @since 0.1.0
     */
    public function transient_update_plugins( $checked_data ) {

        global $wp_version;

        /* Check the data */
        if ( empty( $checked_data->checked ) )
            return $checked_data;

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Get data from server */
        $remote_url = add_query_arg( array( 'plugin_repo' => $updater_data['repo_slug'], 'ahpr_check' => $updater_data['version'] ), $updater_data['repo_uri'] );
        $remote_request = array( 'timeout' => 20, 'body' => array( 'key' => $updater_data['key'], 'login' => $updater_data['login'], 'autohosted' => $updater_data['autohosted'] ), 'user-agent' => 'WordPress/' . $wp_version . '; ' . $updater_data['domain'] );
        $raw_response = wp_remote_post( $remote_url, $remote_request );

        /* Error check */
        $response = '';
        if ( !is_wp_error( $raw_response ) && ( $raw_response['response']['code'] == 200 ) )
            $response = maybe_unserialize( wp_remote_retrieve_body( $raw_response ) );

        /* Check response data */
        if ( is_object( $response ) && !empty( $response )){

            /* Check the data is available */
            if ( isset( $response->new_version ) && !empty( $response->new_version ) && isset( $response->package ) && !empty( $response->package ) ){

                /* Create response data object */
                $updates = new stdClass;
                $updates->new_version = $response->new_version;
                $updates->package = $response->package;
                $updates->slug = $updater_data['slug'];
                $updates->url = $updater_data['uri'];

                /* Set response if not set yet. */
                if ( !isset( $checked_data->response ) )
                    $checked_data->response = array();

                /* Feed the update data */
                $checked_data->response[$updater_data['basename']] = $updates;
            }
        }
        return $checked_data;
    }

    /**
     * Filter Plugin API
     * 
     * @since 0.1.0
     */
    public function plugins_api_result( $res, $action, $args ) {

        global $wp_version;

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Get data only from current plugin, and only when call for "plugin_information" */
        if ( isset( $args->slug ) && $args->slug == $updater_data['slug'] && $action == 'plugin_information' ){

            /* Get data from server */
            $remote_url = add_query_arg( array( 'plugin_repo' => $updater_data['repo_slug'], 'ahpr_info' => $updater_data['version'] ), $updater_data['repo_uri'] );
            $remote_request = array( 'timeout' => 20, 'body' => array( 'key' => $updater_data['key'], 'login' => $updater_data['login'], 'autohosted' => $updater_data['autohosted'] ), 'user-agent' => 'WordPress/' . $wp_version . '; ' . $updater_data['domain'] );
            $request = wp_remote_post( $remote_url, $remote_request );

            /* If error on retriving the data from repo */
            if ( is_wp_error( $request ) ) {
                $res = new WP_Error( 'plugins_api_failed', '<p>' . __( 'An Unexpected HTTP Error occurred during the API request.', 'text-domain' ) . '</p><p><a href="?" onclick="document.location.reload(); return false;">' . __( 'Try again', 'text-domain' ) . '</a></p>', $request->get_error_message() );
            }

            /* If no error, construct the data */
            else {

                /* Unserialize the data */
                $requested_data = maybe_unserialize( wp_remote_retrieve_body( $request ) );

                /* Check response data is available */
                if ( is_object( $requested_data ) && !empty( $requested_data )){

                    /* Check the data is available */
                    if ( isset( $requested_data->version ) && !empty( $requested_data->version ) && isset( $requested_data->download_link ) && !empty( $requested_data->download_link ) ){

                        /* Create plugin info data object */
                        $info = new stdClass;

                        /* Data from repo */
                        $info->version = $requested_data->version;
                        $info->download_link = $requested_data->download_link;
                        $info->requires = $requested_data->requires;
                        $info->tested = $requested_data->tested;
                        $info->sections = $requested_data->sections;

                        /* Data from plugin */
                        $info->slug = $updater_data['slug'];
                        $info->author = $updater_data['author'];
                        $info->uri = $updater_data['uri'];

                        /* Other data needed */
                        $info->external = true;
                        $info->downloaded = 0;

                        /* Feed plugin information data */
                        $res = $info;
                    }
                }

                /* If data is empty or not an object */
                else{
                    $res = new WP_Error( 'plugins_api_failed', __( 'An unknown error occurred', 'text-domain' ), wp_remote_retrieve_body( $request ) );
                
                }
            }
        }
        return $res;
    }


    /**
     * Make sure plugin is installed in correct folder
     * 
     * @since 0.1.0
     */
    public function upgrader_post_install( $true, $hook_extra, $result ) {

        /* Check if hook extra is set */
        if ( isset( $hook_extra ) ){

            /* Get needed data */
            $plugin_base = $this->config['base'];
            $plugin_slug = dirname( $plugin_base );

            /* Only filter folder in this plugin only */
            if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] == $plugin_base ){

                /* wp_filesystem api */
                global $wp_filesystem;

                /* Move & Activate */
                $proper_destination = trailingslashit( WP_PLUGIN_DIR ) . $plugin_slug;
                $wp_filesystem->move( $result['destination'], $proper_destination );
                $result['destination'] = $proper_destination;
                $activate = activate_plugin( trailingslashit( WP_PLUGIN_DIR ) . $plugin_base );

                /* Update message */
                $fail = __( 'The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'text-domain' );
                $success = __( 'Plugin reactivated successfully. ', 'text-domain' );
                echo is_wp_error( $activate ) ? $fail : $success;
            }
        }
        return $result;
    }


    /**
     * Add Dashboard Widget
     * 
     * @since 0.1.0
     */
    public function add_dashboard_widget() {

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Widget ID, prefix with "ahp_" to make sure it's unique */
        $widget_id = 'ahp_' . $updater_data['slug'] . '_activation_key';

        /* Widget name */
        $widget_name = $updater_data['name'] . __( ' Plugin Updates', 'text-domain' );

        /* role check, in default install only administrator have this cap */
        if ( current_user_can( 'update_plugins' ) ) {

            /* add dashboard widget for acivation key */
            wp_add_dashboard_widget( $widget_id, $widget_name, array( &$this, 'dashboard_widget_callback' ), array( &$this, 'dashboard_widget_control_callback' ) );
        }
    
    }


    /**
     * Dashboard Widget Callback
     * 
     * @since 0.1.0
     */
    public function dashboard_widget_callback() {

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Widget ID, prefix with "ahp_" to make sure it's unique */
        $widget_id = 'ahp_' . $updater_data['slug'] . '_activation_key';

        /* edit widget url */
        $edit_url = 'index.php?edit=' . $widget_id . '#' . $widget_id;

        /* get activation key from database */
        $widget_option = get_option( $widget_id );

        /* if activation key available/set */
        if ( !empty( $widget_option ) && is_array( $widget_option ) ){

            /* members only update */
            if ( true === $updater_data['role'] ){

                /* username */
                $username = isset( $widget_option['username'] ) ? $widget_option['username'] : '';
                echo '<p>'. __( 'Username: ', 'text-domain' ) . '<code>' . $username . '</code></p>';

                /* activation key input */
                $key = isset( $widget_option['key'] ) ? $widget_option['key'] : '' ;
                echo '<p>'. __( 'Email: ', 'text-domain' ) . '<code>' . $key . '</code></p>';
            }
            else{

                /* activation key input */
                $key = isset( $widget_option['key'] ) ? $widget_option['key'] : '' ;
                echo '<p>'. __( 'Key: ', 'text-domain' ) . '<code>' . $key . '</code></p>';
            }


            /* if key status is valid */
            if ( $widget_option['status'] == 'valid' ){
                _e( '<p>Your plugin update is <span style="color:green">active</span></p>', 'text-domain' );
            }
            /* if key is not valid */
            elseif( $widget_option['status'] == 'invalid' ){
                _e( '<p>Your input is <span style="color:red">not valid</span>, automatic updates is <span style="color:red">not active</span>.</p>', 'text-domain' );
                echo '<p><a href="' . $edit_url . '" class="button-primary">' . __( 'Edit Key', 'text-domain' ) . '</a></p>';
            }
            /* else */
            else{
                _e( '<p>Unable to validate update activation.</p>', 'text-domain' );
                echo '<p><a href="' . $edit_url . '" class="button-primary">' . __( 'Try again', 'text-domain' ) . '</a></p>';
            }
        }
        /* if activation key is not yet set/empty */
        else{
            echo '<p><a href="' . $edit_url . '" class="button-primary">' . __( 'Add Key', 'text-domain' ) . '</a></p>';
        }
    }


    /**
     * Dashboard Widget Control Callback
     * 
     * @since 0.1.0
     */
    public function dashboard_widget_control_callback() {

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Widget ID, prefix with "ahp_" to make sure it's unique */
        $widget_id = 'ahp_' . $updater_data['slug'] . '_activation_key';

        /* check options is set before saving */
        if ( isset( $_POST[$widget_id] ) ){
        
            $submit_data = $_POST[$widget_id];

            /* username submitted */
            $username = isset( $submit_data['username'] ) ? strip_tags( trim( $submit_data['username'] ) ) : '' ;

            /* key submitted */
            $key = isset( $submit_data['key'] ) ? strip_tags( trim( $submit_data['key'] ) ) : '' ;

            /* get wp version */
            global $wp_version;

            /* get current domain */
            $domain = $updater_data['domain'];

            /* Get data from server */
            $remote_url = add_query_arg( array( 'plugin_repo' => $updater_data['repo_slug'], 'ahr_check_key' => 'validate_key' ), $updater_data['repo_uri'] );
            $remote_request = array( 'timeout' => 20, 'body' => array( 'key' => md5( $key ), 'login' => $username, 'autohosted' => $updater_data['autohosted'] ), 'user-agent' => 'WordPress/' . $wp_version . '; ' . $updater_data['domain'] );
            $raw_response = wp_remote_post( $remote_url, $remote_request );

            /* get response */
            $response = '';
            if ( !is_wp_error( $raw_response ) && ( $raw_response['response']['code'] == 200 ) )
                $response = trim( wp_remote_retrieve_body( $raw_response ) );

            /* if call to server sucess */
            if ( !empty( $response ) ){

                /* if key is valid */
                if ( $response == 'valid' ) $valid = 'valid';

                /* if key is not valid */
                elseif ( $response == 'invalid' ) $valid = 'invalid';

                /* if response is value is not recognized */
                else $valid = 'unrecognized';
            }
            /* if response is empty or error */
            else{
                $valid = 'error';
            }

            /* database input */
            $input = array(
                'username' => $username,
                'key' => $key,
                'status' => $valid,
            );

            /* save value */
            update_option( $widget_id, $input );
        }

        /* get activation key from database */
        $widget_option = get_option( $widget_id );

        /* default key, if it's not set yet */
        $username_option = isset( $widget_option['username'] ) ? $widget_option['username'] : '' ;
        $key_option = isset( $widget_option['key'] ) ? $widget_option['key'] : '' ;

        /* display the form input for activation key */ ?>

        <?php if ( true === $updater_data['role'] ) { // members only update ?>

        <p>
            <label for="<?php echo $widget_id; ?>-username"><?php _e( 'User name', 'text-domain' ); ?></label>
        </p>
        <p>
            <input id="<?php echo $widget_id; ?>-username" name="<?php echo $widget_id; ?>[username]" type="text" value="<?php echo $username_option;?>"/>
        </p>
        <p>
            <label for="<?php echo $widget_id; ?>-key"><?php _e( 'Email', 'text-domain' ); ?></label>
        </p>
        <p>
            <input id="<?php echo $widget_id; ?>-key" class="regular-text" name="<?php echo $widget_id; ?>[key]" type="text" value="<?php echo $key_option;?>"/>
        </p>

        <?php } else { // activation keys ?>

        <p>
            <label for="<?php echo $widget_id; ?>-key"><?php _e( 'Activation Key', 'text-domain' ); ?></label>
        </p>
        <p>
            <input id="<?php echo $widget_id; ?>-key" class="regular-text" name="<?php echo $widget_id; ?>[key]" type="text" value="<?php echo $key_option;?>"/>
        </p>

        <?php }
    }
}


