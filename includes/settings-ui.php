<?php

/* Sets up the admin panel in Settings
* @since 0.0.1
*/

add_action('admin_menu', 'zysyshosting_add_pages');

function zysyshosting_add_pages() {
    add_submenu_page('options-general.php', 'Zysys Hosting Settings', 'Zysys Hosting', 'update_core', 'zysys-hosting-settings', 'zysyshosting_admin_panel');
}

/* Defines the code for the admin panel
* @since 0.0.1
*/
function zysyshosting_admin_panel() {
/*
 * array(Purpose, Plugin Name, Internal ID, WordPress Plugin Identifier, WordPress Plugin Path, Update URL if not in the repo, License Key API ID)
 */
    $thirdPartyPlugins = array(
        array('SEO', 'The SEO Framework', 'seo-framework', 'autodescription', 'autodescription/autodescription.php', null, null),
        array('Drag & Drop Editor', 'Beaver Builder Plugin (Standard Version)', 'beaver-builder', 'bb-plugin', 'bb-plugin/fl-builder.php', 'http://updates.wpbeaverbuilder.com/?fl-api-method=download_update&domain=' . site_url() . '&license=7465622e666c666c6d4075706e6d&product=Beaver+Builder+Plugin+%28Standard+Version%29&slug=bb-plugin&release=stable', 'BEAVER_BUILDER'),
        array('Spam Protection', 'Akismet Anti-Spam', 'akismet', 'akismet', 'akismet/akismet.php', null, 'AKISMET'),
        array('Lazy Loading', 'Lazy Load by WP Rocket', 'lazy-load', 'rocket-lazy-load', 'rocket-lazy-load/rocket-lazy-load.php', null, null),
        array('Contact and Other Forms', 'WPForms', 'wp-forms', 'wpforms', 'wpforms/wpforms.php', 'https://zysys.org/zycms/uploads/2019/05/wpforms.zip', 'WPForms'),
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
