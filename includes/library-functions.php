<?php

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

/* Converts multiple lines to one line for the purpose of file comparisions
 * @since 0.5.5
 * @param $file_contents
 * @return $single_line_file_contents_for_regex_purposes
 */
function zysyshosting_make_single_line($str) {
    return preg_replace('/(  |\s+|\n|\r)/', ' ', $str);
}


/* Remove https://www. or any variant theirin from the argument
 * @param $domain
 * @return $domain_without_prefix
 */
function zysyshosting_clean_domain_prefix($domain) {
    return preg_replace(ZYSYS_HOSTING_URL_PREP_REGEX, '', $domain);
}

/* Checks to see if the hosting server is part of the zysyshosting network
 * @since 0.6.3
 * @param NONE
 * @return NONE
 * @calledfrom zysyshosting_maintenance, zyapi_keys
 */
function zysyshosting_authorize() {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    if (strpos(shell_exec("hostname"), ".zysyshosting.com") === false) {
        deactivate_plugins(plugin_basename(__FILE__));
        zyerror('PLUGIN_UNAUTHORIZED', __FUNCTION__);
        return false;
    } else {
        return true;
    }
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
    return wp_nonce_url(add_query_arg(array('action' => $action, 'plugin' => $plugin_slug), admin_url($file)), $type . '_' . $plugin_slug);
}
