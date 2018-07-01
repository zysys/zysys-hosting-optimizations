<?php

add_action('zysyshosting_maintenance_hourly', 'zysyshosting_maintenance');

/* Runs the various maintenance procedures
 * Called on plugin activation, core update, plugin updated, and when run throught the admin panel
 * Hook added in 0.7.4
 * @since 0.5.5
 * @param NONE
 * @return NONE
 * @hashook zysyshosting_maintenance_action
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

    do_action('zysyshosting_maintenance_action');

    global $wpdb;
    update_option('zysyshosting_optimizations_version', ZYSYSHOSTING_OPTIMIZATIONS_VERSION);
}


