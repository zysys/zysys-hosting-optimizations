<?php


# define the Zycache Setup Procedures...do not use if a subblog.
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
            print 'Please contact your Zysys representative and tell them "Zycache Symlink Present, but still non-symmetric."';
            return -1;
        }
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
    return str_replace('www.', '', str_replace($domain, zysyshosting_clean_domain_prefix(ZYCACHE_IMAGE) . '/' . $domain, $url));
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

    $originalUploadDir = wp_upload_dir(null, false)['baseurl'];
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

    $uploadDir = $https ? ZYCACHE_HTTPS . '/' . $uploadDir : ZYCACHE . '/' . $uploadDir;
    $content = preg_replace('|([\(' . "'" . '"])' . str_replace('/', '/+', $relImageUpload) . '/(.*?)([\)' . "'" . '"])|', "\\1" . $uploadDir . "\\2" . "\\3", $content);
    $content = preg_replace('|https?://(www\.){0,1}' . str_replace('/', '/+', $originalUploadDir) . '(.*?)([ ' . "'" . '\)"])|', $uploadDir . "\\2" . "\\3", $content);

    if (strlen($origContent) > strlen($content))
        return $origContent; # Crisis mode! :-)
    else
        return $content;
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

    $replacedDomain = ($https ? ZYCACHE_HTTPS : ZYCACHE_JS) . '/' . $domain;
    return preg_replace('|' . $originalDomain . '|', $replacedDomain, $url);

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

    $replacedDomain = ($https ? ZYCACHE_HTTPS : ZYCACHE_CSS) . '/' . $domain;
    return preg_replace('|' . $originalDomain . '|', $replacedDomain, $url);
}

/* Runs the /scripts/wp-optimize-domains.pl perpetual updater program on ABSPATH
 *      ...makes sure the abspath is in Zycache
 * @since 0.6.9
 * @param NONE
 * @return NONE
 * @calledby zysyshosting_maintenance
 */

function zysyshosting_plugin_perpetual_updater() {
    shell_exec('/scripts/wp-optimize-domains.pl --abspath="' . ABSPATH . '"');
}
