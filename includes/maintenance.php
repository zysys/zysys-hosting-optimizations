<?php

add_action('zysyshosting_maintenance_hourly', 'zysyshosting_maintenance');

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


