<?php

/**
 * Pinned Ed25519 public key (base64, 32 bytes) that signs update manifests.
 *
 * PLACEHOLDER: replace with the production public key before releasing; see
 * docs/RELEASING.md. While this is not a valid key the updater fails closed
 * and offers/installs nothing.
 */
define('ZYSYSHOSTING_UPDATE_PUBKEY', 'REPLACE_WITH_BASE64_ED25519_PUBLIC_KEY');

add_action('init', 'zysyshosting_updater_init');

/**
 * Load and Activate Plugin Updater Class.
 * @since 0.1.0
 */
function zysyshosting_updater_init() {

    /* Updater Config */
    $config = array(
        'base' => plugin_basename(__FILE__), //required
        'repo_uri' => 'https://zysys.org/',
        'repo_slug' => 'zysys-hosting-optimizations',
    );

    /* Load Updater Class */
    new Plugin_Updater($config);
}


/**
 * Plugin Updater Class
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version 0.1.3
 * @author David Chandra Purnama <david@shellcreeper.com>
 * @link http://autohosted.com/
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @copyright Copyright (c) 2013, David Chandra Purnama
 */
class Plugin_Updater {

    /**
     * @var $config the config for the updater
     * @access public
     */
    var $config;


    /**
     * Class Constructor
     *
     * @param array $config the configuration required for the updater to work
     * @return void
     * @since 0.1.0
     */
    public function __construct($config = array()) {

        /* default config */
        $defaults = array(
            'base' => '',
            'repo_uri' => '',
            'repo_slug' => '',
            'key' => '',
            'dashboard' => false,
            'username' => false,
            'autohosted' => 'plugin.0.1.3',
            'pubkey' => ZYSYSHOSTING_UPDATE_PUBKEY,
        );

        /* merge configs and defaults */
        $this->config = wp_parse_args($config, $defaults);

        /* disable request to wp.org repo */
        add_filter('http_request_args', array(&$this, 'disable_wporg_request'), 5, 2);

        /* check minimum config before doing stuff */
        if (!empty($this->config['base']) && !empty ($this->config['repo_uri']) && !empty ($this->config['repo_slug'])) {

            /* filters for admin area only */
            if (is_admin()) {

                /* filter site transient "update_plugins" */
                add_filter('pre_set_site_transient_update_plugins', array(&$this, 'transient_update_plugins'));

                /* filter plugins api */
                add_filter('plugins_api_result', array(&$this, 'plugins_api_result'), 10, 3);

                /* forder name fix */
                add_filter('upgrader_post_install', array(&$this, 'upgrader_post_install'), 10, 3);

                /* verify the signed package hash before WordPress installs it */
                add_filter('upgrader_pre_download', array(&$this, 'upgrader_pre_download'), 10, 4);

                /* add dashboard widget for activation key */
                if (true === $this->config['dashboard']) {
                    add_action('wp_dashboard_setup', array(&$this, 'add_dashboard_widget'));
                }
            }
        }
    }


    /**
     * Disable request to wp.org plugin repository
     * @link http://markjaquith.wordpress.com/2009/12/14/excluding-your-plugin-or-theme-from-update-checks/
     * @since 0.1.2
     */
    public function disable_wporg_request($r, $url) {

        /* If it's not a plugin request, bail early */
        if (0 !== strpos($url, 'http://api.wordpress.org/plugins/update-check'))
            return $r;

        /* this plugin slug */
        $plugin_slug = dirname($this->config['base']);

        /* unserialize data */
        $plugins = unserialize($r['body']['plugins']);

        /* default value */
        $to_disable = '';

        /* check if plugins object is set */
        if (isset($plugins->plugins)) {

            $all_plugins = $plugins->plugins;

            /* loop all plugins */
            foreach ($all_plugins as $plugin_base => $plugin_data) {

                /* only if the plugin have the same folder */
                if (dirname($plugin_base) == $plugin_slug) {

                    /* get plugin to disable */
                    $to_disable = $plugin_base;
                }
            }
        }
        /* unset this plugin only */
        if (!empty($to_disable))
            unset($plugins->plugins[$to_disable]);

        /* serialize it back */
        $r['body']['plugins'] = serialize($plugins);
        return $r;
    }


    /**
     * Data needed in an array to make everything simple.
     *
     * @return array
     * @since 0.1.0
     */
    public function updater_data() {

        /* Updater data: Hana Tul Set! */
        $updater_data = array();

        /* Base name */
        $updater_data['basename'] = $this->config['base'];

        /* Plugin slug */
        $slug = dirname($this->config['base']);
        $updater_data['slug'] = $slug;

        /* Main plugin file */
        $updater_data['file'] = basename($this->config['base']);

        /* Updater class location is in the main plugin folder  */
        $file_path = plugin_dir_path(__FILE__) . $updater_data['file'];

        /* if it's in sub folder */
        if (basename(dirname(dirname(__FILE__))) == $updater_data['slug'])
            $file_path = plugin_dir_path(dirname(__FILE__)) . $updater_data['file'];

        /* Get plugin data from main plugin file */
        $get_plugin_data = get_plugin_data($file_path);

        /* Plugin name */
        $updater_data['name'] = strip_tags($get_plugin_data['Name']);

        /* Plugin version */
        $updater_data['version'] = strip_tags($get_plugin_data['Version']);

        /* Plugin uri / uri */
        $uri = '';
        if ($get_plugin_data['PluginURI']) $uri = esc_url($get_plugin_data['PluginURI']);
        $updater_data['uri'] = $uri;

        /* Author with link to author uri */
        $author = strip_tags($get_plugin_data['Author']);
        $author_uri = $get_plugin_data['AuthorURI'];
        if ($author && $author_uri) $author = '<a href="' . esc_url_raw($author_uri) . '">' . $author . '</a>';
        $updater_data['author'] = $author;

        /* by user role */
        if (false === $this->config['username'])
            $updater_data['role'] = false;
        else
            $updater_data['role'] = true;

        /* User name / login */
        $username = '';
        if (false !== $this->config['username'] && false === $this->config['dashboard'])
            $username = $this->config['username'];
        if (true === $this->config['username'] && true === $this->config['dashboard']) {
            $widget_id = 'ahp_' . $slug . '_activation_key';
            $widget_option = get_option($widget_id);
            $username = (isset($widget_option['username']) && !empty($widget_option['username'])) ? $widget_option['username'] : '';
        }
        $updater_data['login'] = $username;

        /* Activation key */
        $key = '';
        if ($this->config['key']) $key = md5($this->config['key']);
        if (empty($key) && true === $this->config['dashboard']) {
            $widget_id = 'ahp_' . $slug . '_activation_key';
            $widget_option = get_option($widget_id);
            $key = (isset($widget_option['key']) && !empty($widget_option['key'])) ? md5($widget_option['key']) : '';
        }
        $updater_data['key'] = $key;

        /* Domain */
        $updater_data['domain'] = esc_url_raw(get_bloginfo('url'));

        /* Repo uri */
        $repo_uri = '';
        if (!empty($this->config['repo_uri']))
            $repo_uri = trailingslashit(esc_url_raw($this->config['repo_uri']));
        $updater_data['repo_uri'] = $repo_uri;

        /* Repo slug */
        $repo_slug = '';
        if (!empty($this->config['repo_slug']))
            $repo_slug = sanitize_title($this->config['repo_slug']);
        $updater_data['repo_slug'] = $repo_slug;

        /* Updater class id and version */
        $updater_data['autohosted'] = esc_attr($this->config['autohosted']);

        return $updater_data;
    }


    /**
     * Check for plugin updates
     *
     * @since 0.1.0
     */
    public function transient_update_plugins($checked_data) {

        global $wp_version;

        /* Check the data */
        if (empty($checked_data->checked))
            return $checked_data;

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Fetch and verify the signed manifest; anything unverified is not an update */
        $manifest = $this->fetch_verified_manifest($updater_data, 'ahpr_check');
        if (is_wp_error($manifest)) {
            unset($checked_data->response[$updater_data['basename']]);
            return $checked_data;
        }

        /* Create response data object */
        $updates = new stdClass;
        $updates->new_version = $manifest['version'];
        $updates->package = $manifest['package_url'];
        $updates->slug = $updater_data['slug'];
        $updates->url = $updater_data['uri'];

        /* The signed manifest travels with the offer so the download can be re-verified */
        $updates->zysys_manifest = $manifest;

        /* Set response if not set yet. */
        if (!isset($checked_data->response))
            $checked_data->response = array();

        /* Feed the update data */
        $checked_data->response[$updater_data['basename']] = $updates;
        return $checked_data;
    }

    /**
     * Hosts a signed package_url may point at (exact match, https only).
     *
     * @return array
     */
    private static function allowed_package_hosts() {
        return array('github.com', 'objects.githubusercontent.com');
    }

    /**
     * Canonical byte string that is signed: the manifest fields except the
     * signature, keys sorted, compact JSON, unescaped slashes and unicode.
     * scripts/sign-release.php produces exactly this form.
     *
     * @param array $fields manifest without the signature
     * @return string|false false when the fields cannot be encoded
     */
    private static function canonical_manifest($fields) {
        ksort($fields);
        return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode the pinned public key. The placeholder (or anything that is not
     * a base64 Ed25519 public key) makes the updater refuse every update.
     *
     * @param mixed $pubkey base64 public key
     * @return string|WP_Error raw key bytes
     */
    private static function decode_pubkey($pubkey) {
        $raw = is_string($pubkey) ? base64_decode($pubkey, true) : false;
        if (!function_exists('sodium_crypto_sign_verify_detached') || !defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
            return new WP_Error('zysys_update_no_sodium', __('Update signature verification is unavailable (libsodium missing); updates are refused.', 'text-domain'));
        }
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return new WP_Error('zysys_update_pubkey_unset', __('No update signing key is configured; updates are refused.', 'text-domain'));
        }
        return $raw;
    }

    /**
     * Check that a manifest is signed by the pinned key and describes a newer
     * package of this plugin hosted on an allowed host.
     *
     * @param mixed $manifest decoded remote JSON
     * @param string $slug plugin slug the manifest must be for
     * @param string $installed_version currently installed version
     * @param string $pubkey base64 Ed25519 public key
     * @return array|WP_Error the manifest when every check passes
     */
    public static function verify_manifest($manifest, $slug, $installed_version, $pubkey) {
        $key = self::decode_pubkey($pubkey);
        if (is_wp_error($key))
            return $key;

        $invalid = new WP_Error('zysys_update_invalid', __('The update manifest failed verification.', 'text-domain'));

        /* exactly the five signed-or-signature fields, all strings */
        if (!is_array($manifest))
            return $invalid;
        $names = array_keys($manifest);
        sort($names);
        if ($names !== array('package_url', 'sha256', 'signature', 'slug', 'version'))
            return $invalid;
        foreach ($manifest as $value) {
            if (!is_string($value))
                return $invalid;
        }

        /* signature over the canonical form of the other fields */
        $signature = base64_decode($manifest['signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES)
            return $invalid;
        $signed = $manifest;
        unset($signed['signature']);
        $message = self::canonical_manifest($signed);
        if ($message === false)
            return $invalid;
        try {
            $verified = sodium_crypto_sign_verify_detached($signature, $message, $key);
        } catch (Exception $e) {
            $verified = false;
        }
        if (!$verified)
            return $invalid;

        /* the signed content must describe a newer build of this plugin */
        if ($manifest['slug'] !== $slug)
            return $invalid;
        if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $manifest['version']) || !version_compare($manifest['version'], (string) $installed_version, '>'))
            return $invalid;
        if (!preg_match('/^[0-9a-f]{64}$/', $manifest['sha256']))
            return $invalid;
        if (!self::is_allowed_package_url($manifest['package_url']))
            return $invalid;

        return $manifest;
    }

    /**
     * https only, exact host match against the allow-list, no userinfo or port.
     *
     * @param string $url
     * @return bool
     */
    private static function is_allowed_package_url($url) {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url))
            return false;
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host']))
            return false;
        if (strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']))
            return false;
        return in_array(strtolower($parts['host']), self::allowed_package_hosts(), true);
    }

    /**
     * Ask the update server for the manifest and verify it.
     *
     * @param array $updater_data see updater_data()
     * @param string $query_arg ahpr_check or ahpr_info
     * @return array|WP_Error verified manifest
     */
    private function fetch_verified_manifest($updater_data, $query_arg) {

        global $wp_version;

        /* refuse before any request while no valid signing key is pinned */
        $key = self::decode_pubkey($this->config['pubkey']);
        if (is_wp_error($key))
            return $key;

        $remote_url = add_query_arg(array('plugin_repo' => $updater_data['repo_slug'], $query_arg => $updater_data['version']), $updater_data['repo_uri']);
        $remote_request = array('timeout' => 20, 'body' => array('key' => $updater_data['key'], 'login' => $updater_data['login'], 'autohosted' => $updater_data['autohosted']), 'user-agent' => 'WordPress/' . $wp_version . '; ' . $updater_data['domain']);
        $raw_response = wp_remote_post($remote_url, $remote_request);

        if (is_wp_error($raw_response))
            return $raw_response;
        if (wp_remote_retrieve_response_code($raw_response) != 200)
            return new WP_Error('zysys_update_http', __('The update server did not return a manifest.', 'text-domain'));

        /* JSON only: remote data is never unserialized */
        $body = wp_remote_retrieve_body($raw_response);
        if (!is_string($body) || strlen($body) > 16384)
            return new WP_Error('zysys_update_invalid', __('The update manifest failed verification.', 'text-domain'));

        return self::verify_manifest(json_decode($body, true), $updater_data['slug'], $updater_data['version'], $this->config['pubkey']);
    }

    /**
     * Download this plugin's package ourselves and install it only when its
     * sha256 matches the signed manifest. Other packages pass through.
     *
     * @param bool|string|WP_Error $reply
     * @param string $package package URL
     * @param object $upgrader
     * @param array $hook_extra
     * @return bool|string|WP_Error temp file path on success
     */
    public function upgrader_pre_download($reply, $package, $upgrader = null, $hook_extra = array()) {

        $base = $this->config['base'];
        $offer = $this->offered_update();

        $ours = false;
        if (is_array($hook_extra) && isset($hook_extra['plugin']) && is_string($hook_extra['plugin']))
            $ours = strtok($hook_extra['plugin'], '/') === strtok($base, '/');
        if (!$ours && $offer !== null && isset($offer->package) && $offer->package === $package)
            $ours = true;
        if (!$ours)
            return $reply;

        /* everything below fails closed */
        $updater_data = $this->updater_data();
        if (!is_object($offer) || !isset($offer->zysys_manifest))
            return new WP_Error('zysys_update_unverified', __('Update refused: no verified manifest for this package.', 'text-domain'));

        $manifest = self::verify_manifest((array) $offer->zysys_manifest, $updater_data['slug'], $updater_data['version'], $this->config['pubkey']);
        if (is_wp_error($manifest))
            return $manifest;
        if ($manifest['package_url'] !== $package)
            return new WP_Error('zysys_update_package_mismatch', __('Update refused: the package is not the one named in the signed manifest.', 'text-domain'));

        $file = download_url($package);
        if (is_wp_error($file))
            return $file;

        $actual = hash_file('sha256', $file);
        if ($actual === false || !hash_equals($manifest['sha256'], $actual)) {
            @unlink($file);
            return new WP_Error('zysys_update_hash_mismatch', __('Update aborted: the downloaded package does not match the signed sha256.', 'text-domain'));
        }
        return $file;
    }

    /**
     * The update offer currently stored for this plugin, if any.
     *
     * @return object|null
     */
    private function offered_update() {
        $transient = get_site_transient('update_plugins');
        if (is_object($transient) && isset($transient->response[$this->config['base']]) && is_object($transient->response[$this->config['base']]))
            return $transient->response[$this->config['base']];
        return null;
    }

    /**
     * Filter Plugin API
     *
     * @since 0.1.0
     */
    public function plugins_api_result($res, $action, $args) {

        global $wp_version;

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Get data only from current plugin, and only when call for "plugin_information" */
        if (isset($args->slug) && $args->slug == $updater_data['slug'] && $action == 'plugin_information') {

            /* Get the signed manifest from the update server */
            $manifest = $this->fetch_verified_manifest($updater_data, 'ahpr_info');

            /* If the manifest could not be retrieved or failed verification */
            if (is_wp_error($manifest)) {
                $res = new WP_Error('plugins_api_failed', '<p>' . __('An Unexpected HTTP Error occurred during the API request.', 'text-domain') . '</p><p><a href="?" onclick="document.location.reload(); return false;">' . __('Try again', 'text-domain') . '</a></p>', $manifest->get_error_message());
            } /* If verified, construct the data */
            else {

                /* Create plugin info data object */
                $info = new stdClass;

                /* Data from the verified manifest */
                $info->version = $manifest['version'];
                $info->download_link = $manifest['package_url'];
                $info->sections = array('description' => $updater_data['name']);

                /* Data from plugin */
                $info->slug = $updater_data['slug'];
                $info->author = $updater_data['author'];
                $info->uri = $updater_data['uri'];

                /* Other data needed */
                $info->external = true;
                $info->downloaded = 0;

                /* Feed plugin information data */
                $res = $info;
            }
        }
        return $res;
    }


    /**
     * Make sure plugin is installed in correct folder
     *
     * @since 0.1.0
     */
    public function upgrader_post_install($true, $hook_extra, $result) {

        /* Check if hook extra is set */
        if (isset($hook_extra)) {

            /* Get needed data */
            $plugin_base = $this->config['base'];
            $plugin_slug = dirname($plugin_base);

            /* Only filter folder in this plugin only */
            if (isset($hook_extra['plugin']) && $hook_extra['plugin'] == $plugin_base) {

                /* wp_filesystem api */
                global $wp_filesystem;

                /* Move & Activate */
                $proper_destination = trailingslashit(WP_PLUGIN_DIR) . $plugin_slug;
                $wp_filesystem->move($result['destination'], $proper_destination);
                $result['destination'] = $proper_destination;
                $activate = activate_plugin(trailingslashit(WP_PLUGIN_DIR) . $plugin_base);

                /* Update message */
                $fail = __('The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'text-domain');
                $success = __('Plugin reactivated successfully. ', 'text-domain');
                echo is_wp_error($activate) ? $fail : $success;
            }
        }
        return $result;
    }


    /**
     * Add Dashboard Widget
     *
     * @since 0.1.0
     */
    public function add_dashboard_widget() {

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Widget ID, prefix with "ahp_" to make sure it's unique */
        $widget_id = 'ahp_' . $updater_data['slug'] . '_activation_key';

        /* Widget name */
        $widget_name = $updater_data['name'] . __(' Plugin Updates', 'text-domain');

        /* role check, in default install only administrator have this cap */
        if (current_user_can('update_plugins')) {

            /* add dashboard widget for acivation key */
            wp_add_dashboard_widget($widget_id, $widget_name, array(&$this, 'dashboard_widget_callback'), array(&$this, 'dashboard_widget_control_callback'));
        }

    }


    /**
     * Dashboard Widget Callback
     *
     * @since 0.1.0
     */
    public function dashboard_widget_callback() {

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Widget ID, prefix with "ahp_" to make sure it's unique */
        $widget_id = 'ahp_' . $updater_data['slug'] . '_activation_key';

        /* edit widget url */
        $edit_url = 'index.php?edit=' . $widget_id . '#' . $widget_id;

        /* get activation key from database */
        $widget_option = get_option($widget_id);

        /* if activation key available/set */
        if (!empty($widget_option) && is_array($widget_option)) {

            /* members only update */
            if (true === $updater_data['role']) {

                /* username */
                $username = isset($widget_option['username']) ? $widget_option['username'] : '';
                echo '<p>' . __('Username: ', 'text-domain') . '<code>' . $username . '</code></p>';

                /* activation key input */
                $key = isset($widget_option['key']) ? $widget_option['key'] : '';
                echo '<p>' . __('Email: ', 'text-domain') . '<code>' . $key . '</code></p>';
            } else {

                /* activation key input */
                $key = isset($widget_option['key']) ? $widget_option['key'] : '';
                echo '<p>' . __('Key: ', 'text-domain') . '<code>' . $key . '</code></p>';
            }


            /* if key status is valid */
            if ($widget_option['status'] == 'valid') {
                _e('<p>Your plugin update is <span style="color:green">active</span></p>', 'text-domain');
            } /* if key is not valid */
            elseif ($widget_option['status'] == 'invalid') {
                _e('<p>Your input is <span style="color:red">not valid</span>, automatic updates is <span style="color:red">not active</span>.</p>', 'text-domain');
                echo '<p><a href="' . $edit_url . '" class="button-primary">' . __('Edit Key', 'text-domain') . '</a></p>';
            } /* else */
            else {
                _e('<p>Unable to validate update activation.</p>', 'text-domain');
                echo '<p><a href="' . $edit_url . '" class="button-primary">' . __('Try again', 'text-domain') . '</a></p>';
            }
        } /* if activation key is not yet set/empty */
        else {
            echo '<p><a href="' . $edit_url . '" class="button-primary">' . __('Add Key', 'text-domain') . '</a></p>';
        }
    }


    /**
     * Dashboard Widget Control Callback
     *
     * @since 0.1.0
     */
    public function dashboard_widget_control_callback() {

        /* Get needed data */
        $updater_data = $this->updater_data();

        /* Widget ID, prefix with "ahp_" to make sure it's unique */
        $widget_id = 'ahp_' . $updater_data['slug'] . '_activation_key';

        /* check options is set before saving */
        if (isset($_POST[$widget_id])) {

            $submit_data = $_POST[$widget_id];

            /* username submitted */
            $username = isset($submit_data['username']) ? strip_tags(trim($submit_data['username'])) : '';

            /* key submitted */
            $key = isset($submit_data['key']) ? strip_tags(trim($submit_data['key'])) : '';

            /* get wp version */
            global $wp_version;

            /* get current domain */
            $domain = $updater_data['domain'];

            /* Get data from server */
            $remote_url = add_query_arg(array('plugin_repo' => $updater_data['repo_slug'], 'ahr_check_key' => 'validate_key'), $updater_data['repo_uri']);
            $remote_request = array('timeout' => 20, 'body' => array('key' => md5($key), 'login' => $username, 'autohosted' => $updater_data['autohosted']), 'user-agent' => 'WordPress/' . $wp_version . '; ' . $updater_data['domain']);
            $raw_response = wp_remote_post($remote_url, $remote_request);

            /* get response */
            $response = '';
            if (!is_wp_error($raw_response) && ($raw_response['response']['code'] == 200))
                $response = trim(wp_remote_retrieve_body($raw_response));

            /* if call to server sucess */
            if (!empty($response)) {

                /* if key is valid */
                if ($response == 'valid') $valid = 'valid';

                /* if key is not valid */
                elseif ($response == 'invalid') $valid = 'invalid';

                /* if response is value is not recognized */
                else $valid = 'unrecognized';
            } /* if response is empty or error */
            else {
                $valid = 'error';
            }

            /* database input */
            $input = array(
                'username' => $username,
                'key' => $key,
                'status' => $valid,
            );

            /* save value */
            update_option($widget_id, $input);
        }

        /* get activation key from database */
        $widget_option = get_option($widget_id);

        /* default key, if it's not set yet */
        $username_option = isset($widget_option['username']) ? $widget_option['username'] : '';
        $key_option = isset($widget_option['key']) ? $widget_option['key'] : '';

        /* display the form input for activation key */ ?>

        <?php if (true === $updater_data['role']) { // members only update ?>

            <p>
                <label for="<?php echo $widget_id; ?>-username"><?php _e('User name', 'text-domain'); ?></label>
            </p>
            <p>
                <input id="<?php echo $widget_id; ?>-username" name="<?php echo $widget_id; ?>[username]" type="text"
                       value="<?php echo $username_option; ?>"/>
            </p>
            <p>
                <label for="<?php echo $widget_id; ?>-key"><?php _e('Email', 'text-domain'); ?></label>
            </p>
            <p>
                <input id="<?php echo $widget_id; ?>-key" class="regular-text" name="<?php echo $widget_id; ?>[key]"
                       type="text" value="<?php echo $key_option; ?>"/>
            </p>

        <?php } else { // activation keys ?>

            <p>
                <label for="<?php echo $widget_id; ?>-key"><?php _e('Activation Key', 'text-domain'); ?></label>
            </p>
            <p>
                <input id="<?php echo $widget_id; ?>-key" class="regular-text" name="<?php echo $widget_id; ?>[key]"
                       type="text" value="<?php echo $key_option; ?>"/>
            </p>

        <?php }
    }
}
