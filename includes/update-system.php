<?php
zysyshosting_do_updates_if_requested();

/* Adds filters for various updates depending on option setting
 * @since 0.6.7
 * @param NONE
 * @return NONE
 */

function zysyshosting_do_updates_if_requested() {
    if (get_option('zysyshosting_update_core_automatically') == 'update1')
        add_filter('auto_update_core', '__return_true');

    if (get_option('zysyshosting_update_plugins_automatically') == 'update1')
        add_filter('auto_update_plugin', '__return_true');

    if (get_option('zysyshosting_update_themes_automatically') == 'update1')
        add_filter('auto_update_theme', '__return_true');
}


