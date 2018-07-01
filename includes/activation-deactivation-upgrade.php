<?php

register_activation_hook(__FILE__, 'zysyshosting_optimizations_activation');
register_deactivation_hook(__FILE__, 'zysyshosting_optimizations_deactivation');
add_action('zysyshosting_optimizations_updates', 'zysyshosting_optimizations_post_upgrade');
add_action('upgrader_process_complete', 'zysyshosting_maintenance');

/* What should happen when the plugin is activated
 * @since 0.0.1
 */
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

/* What should happen when the plugin is deactivated
 * @since 0.0.1
 */

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

/* What should happen after wordpress updates
 * @since 0.0.1
 */
function zysyshosting_optimizations_post_upgrade() {
    global $wpdb;
    if (get_option('zysyshosting_optimizations_version') < ZYSYSHOSTING_OPTIMIZATIONS_VERSION)
        zysyshosting_maintenance();
}
