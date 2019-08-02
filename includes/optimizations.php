<?php
add_action('zysyshosting_maintenance_action', 'zysyshosting_wp_cron_setup');
add_action('zysyshosting_maintenance_action', 'zysyshosting_plugin_perpetual_updater');

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
    shell_exec("/scripts/wp-optimize-domains.pl --add-cron='" . $domain . "'");
}
