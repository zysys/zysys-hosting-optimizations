<?php

add_action('zysyshosting_maintenance_action', 'zysyshosting_remove_installation_files');
add_action('zysyshosting_maintenance_action', 'zysyshosting_wp_secure_files');
add_action('zysyshosting_maintenance_action', 'zysyshosting_wordpress_securing');
add_action('zysyshosting_maintenance_action', 'zysyshosting_wp_permissions');
add_action('zysyshosting_maintenance_action', 'zysyshosting_disable_indexes');
add_action('zysyshosting_maintenance_action', 'zysyshosting_disable_php_execution');
add_action('zysyshosting_maintenance_action', 'zysyshosting_ms_files');
add_action('zysyshosting_maintenance_action', 'zysyshosting_wp_rules_check');


/* Removes certain wordpress installation files
 * @since 0.6.3
 * @param NONE
 * @return NONE
 * @calledfrom zysyshosting_maintenance
 */
function zysyshosting_remove_installation_files() {
    $install_files = array(ABSPATH . 'readme.html', ABSPATH . 'wp-config-sample.php', ABSPATH . 'wp-admin/install.php', ABSPATH . 'license.txt');

    foreach ($install_files as $file)
        if (file_exists($file))
            unlink($file);

}

/* Runs, through find and shell_exec, chmodding of 644 on files and 755 on directories.  Runs on the default files from the original wp installation and then recurses in WP_CONTENT, WP_INCLUDES, and WP_ADMIN
 * @since 0.5.5
 * @param NONE
 * @return NONE
 */
function zysyshosting_wp_permissions() {
    shell_exec('find ' . ABSPATH . ' -maxdepth 1 -type f -name "index.php" -o -name "license.txt" -o -name "readme.html" -o -name "wp-activate.php" -o -name "wp-blog-header.php" -o -name "wp-comments-post.php" -o -name "wp-config-sample.php" -o -name "wp-cron.php" -o -name "wp-links-opml.php" -o -name "wp-load.php" -o -name "wp-login.php" -o -name "wp-mail.php" -o -name "wp-settings.php" -o -name "wp-signup.php" -o -name "wp-trackback.php" -o -name "xmlrpc.php" -o -name "wp-config.php" -exec chmod 644 {} \; &');
    shell_exec("chmod 755 " . escapeshellcmd(WP_CONTENT_DIR) . " &");
    shell_exec("chmod 755 " . escapeshellcmd(ABSPATH . '/' . WPINC . '/') . " &");
    shell_exec("chmod 755 " . escapeshellcmd(ABSPATH . '/wp-admin/') . " &");

    # WP_CONTENT, ABSPATH . WPINC, ABSPATH . '/wp-admin/'
    shell_exec("find " . escapeshellcmd(WP_CONTENT_DIR) . " ! -perm 644 -type f -exec chmod 644 {} \; &");
    shell_exec("find " . escapeshellcmd(WP_CONTENT_DIR) . " ! -perm 755 -type d -exec chmod 755 {} \; &");

    shell_exec("find " . escapeshellcmd(ABSPATH . '/' . WPINC . '/') . " ! -perm 644 -type f -exec chmod 644 {} \; &");
    shell_exec("find " . escapeshellcmd(ABSPATH . '/' . WPINC . '/') . " ! -perm 755 -type d -exec chmod 755 {} \; &");

    shell_exec("find " . escapeshellcmd(ABSPATH . '/wp-admin/') . " ! -perm 644 -type f -exec chmod 644 {} \; &");
    shell_exec("find " . escapeshellcmd(ABSPATH . '/wp-admin/') . " ! -perm 755 -type d -exec chmod 755 {} \; &");
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
    htaccess_adder($disable_php . $allow_msfiles, "## BEGIN ZYSYSHOSTING_DISABLE_PHP_IN_WP_INCLUDES", "## END ZYSYSHOSTING_DISABLE_PHP_IN_WP_INCLUDES", 'wp-includes');
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
