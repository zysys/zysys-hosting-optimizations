<?php

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
    zydisable_service('WPFORMS');
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
    } elseif ($service == 'WPFORMS') {
        $option                = array(key=>zyget_key('wpforms'));
        $option['type']        = 'elite';
        $option['is_expired']  = false;
        $option['is_disabled'] = false;
        $option['is_invalid']  = false;
        update_option( 'wpforms_license', $option );
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
