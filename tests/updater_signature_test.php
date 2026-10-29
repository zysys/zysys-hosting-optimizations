<?php
/* Regression test for the signed-update flow in includes/plugin-updater.php.
 *
 * Plain CLI script: `php tests/updater_signature_test.php`, exits non-zero on failure.
 *
 * WordPress is stubbed just enough to load the real updater class. A throwaway
 * Ed25519 keypair is generated per run; the production keypair is never used
 * here. The canonical signing form is re-implemented below on purpose, so the
 * test pins the wire format rather than echoing the implementation.
 *
 * Cases: valid manifest accepted; tampered version/URL, wrong key, disallowed
 * host, non-increasing version, missing/extra fields and legacy serialized
 * payloads rejected; sha256 mismatch aborts the install; the placeholder
 * public key refuses everything; scripts/sign-release.php output verifies.
 */

error_reporting(E_ALL);

$root = dirname(__DIR__);

/* ---- WordPress stubs ---------------------------------------------------- */

class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

/* Canary: instantiated only if remote data is ever passed to unserialize(). */
class ZyCanary {
    public function __wakeup() { $GLOBALS['zy_canary'] = true; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }
function add_action() {}
function add_filter() {}
function is_admin() { return true; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, $args); }
function plugin_basename($file) { return 'zysys-hosting-optimizations/ZysysHosting.php'; }
function plugin_dir_path($file) { return dirname($file) . '/'; }
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function esc_url($u) { return $u; }
function esc_url_raw($u) { return $u; }
function esc_attr($s) { return $s; }
function sanitize_title($s) { return $s; }
function get_bloginfo($what = '') { return 'https://site.example'; }
function __($s, $d = '') { return $s; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function get_option($name, $default = false) { return $default; }
function get_plugin_data($file) {
    return array(
        'Name' => 'Zysys Hosting Optimizations',
        'Version' => $GLOBALS['zy_installed_version'],
        'PluginURI' => 'https://zysys.org/',
        'Author' => 'Zysys',
        'AuthorURI' => 'https://zysys.org',
    );
}
function maybe_unserialize($data) {
    return (is_string($data) && preg_match('/^[aOsbidN]:/', $data)) ? unserialize($data) : $data;
}
function wp_remote_post($url, $args = array()) {
    $GLOBALS['zy_remote_calls'][] = $url;
    return array('response' => array('code' => 200), 'body' => $GLOBALS['zy_remote_body']);
}
function wp_remote_retrieve_body($response) { return isset($response['body']) ? $response['body'] : ''; }
function wp_remote_retrieve_response_code($response) {
    return isset($response['response']['code']) ? $response['response']['code'] : 0;
}
function get_site_transient($name) { return $GLOBALS['zy_transient']; }
function download_url($url) {
    $GLOBALS['zy_download_calls'][] = $url;
    if (!isset($GLOBALS['zy_downloads'][$url])) {
        return new WP_Error('http_404', 'not found');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'zyupd');
    file_put_contents($tmp, $GLOBALS['zy_downloads'][$url]);
    $GLOBALS['zy_tmp_files'][] = $tmp;
    return $tmp;
}

require $root . '/includes/plugin-updater.php';

/* ---- helpers ------------------------------------------------------------ */

const ZY_BASE = 'zysys-hosting-optimizations/ZysysHosting.php';
const ZY_SLUG = 'zysys-hosting-optimizations';
const ZY_URL = 'https://github.com/zysys/zysys-hosting-optimizations/releases/download/v0.8.0/ZysysHostingOptimizations-0.8.0.zip';

$failures = 0;
$GLOBALS['zy_installed_version'] = '0.7.9';

function zy_check($name, $ok, $detail = '') {
    global $failures;
    if ($ok) {
        echo "ok   $name\n";
        return;
    }
    $failures++;
    echo "FAIL $name" . ($detail === '' ? '' : " -- $detail") . "\n";
}

/* Run one case; an Error/Exception (e.g. the new API is missing) is a failure. */
function zy_case($name, $fn) {
    try {
        $fn($name);
    } catch (Throwable $e) {
        zy_check($name, false, get_class($e) . ': ' . $e->getMessage());
    }
}

function zy_keypair() {
    $kp = sodium_crypto_sign_keypair();
    return array(
        'pub' => base64_encode(sodium_crypto_sign_publickey($kp)),
        'sec' => sodium_crypto_sign_secretkey($kp),
    );
}

/* Canonical signing form: the four fields, keys sorted, compact JSON. */
function zy_canonical($fields) {
    ksort($fields);
    return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function zy_manifest($sec, $overrides = array(), $zip = 'zip-bytes') {
    $fields = array(
        'slug' => ZY_SLUG,
        'version' => '0.8.0',
        'package_url' => ZY_URL,
        'sha256' => hash('sha256', $zip),
    );
    $fields = array_merge($fields, $overrides);
    $fields['signature'] = base64_encode(sodium_crypto_sign_detached(zy_canonical($fields), $sec));
    return $fields;
}

function zy_updater($pub = null) {
    $config = array('base' => ZY_BASE, 'repo_uri' => 'https://zysys.org/', 'repo_slug' => ZY_SLUG);
    if ($pub !== null) {
        $config['pubkey'] = $pub;
    }
    return new Plugin_Updater($config);
}

/* Serve $body as the remote response and return the update entry, if any. */
function zy_check_update($updater, $body) {
    $GLOBALS['zy_remote_body'] = $body;
    $checked = (object) array('checked' => array(ZY_BASE => $GLOBALS['zy_installed_version']));
    $result = $updater->transient_update_plugins($checked);
    return isset($result->response[ZY_BASE]) ? $result->response[ZY_BASE] : null;
}

function zy_accepts($name, $updater, $manifest) {
    $update = zy_check_update($updater, is_string($manifest) ? $manifest : json_encode($manifest));
    zy_check($name, $update !== null && $update->package === ZY_URL && $update->new_version === '0.8.0');
}

function zy_rejects($name, $updater, $manifest) {
    $update = zy_check_update($updater, is_string($manifest) ? $manifest : json_encode($manifest));
    zy_check($name, $update === null, $update === null ? '' : 'update was offered: ' . $update->package);
}

/* ---- cases -------------------------------------------------------------- */

$good = zy_keypair();
$other = zy_keypair();

zy_case('valid manifest is accepted', function ($n) use ($good) {
    zy_accepts($n, zy_updater($good['pub']), zy_manifest($good['sec']));
});

zy_case('tampered version is rejected', function ($n) use ($good) {
    $m = zy_manifest($good['sec']);
    $m['version'] = '9.9.9';
    zy_rejects($n, zy_updater($good['pub']), $m);
});

zy_case('tampered package_url is rejected', function ($n) use ($good) {
    $m = zy_manifest($good['sec']);
    $m['package_url'] = 'https://github.com/attacker/evil/releases/download/v0.8.0/evil.zip';
    zy_rejects($n, zy_updater($good['pub']), $m);
});

zy_case('tampered sha256 is rejected', function ($n) use ($good) {
    $m = zy_manifest($good['sec']);
    $m['sha256'] = hash('sha256', 'something else');
    zy_rejects($n, zy_updater($good['pub']), $m);
});

zy_case('manifest signed by a different key is rejected', function ($n) use ($good, $other) {
    zy_rejects($n, zy_updater($good['pub']), zy_manifest($other['sec']));
});

zy_case('malformed signature is rejected', function ($n) use ($good) {
    $m = zy_manifest($good['sec']);
    $m['signature'] = 'not-base64!!';
    zy_rejects($n . ' (garbage)', zy_updater($good['pub']), $m);
    $m['signature'] = base64_encode('short');
    zy_rejects($n . ' (short)', zy_updater($good['pub']), $m);
});

zy_case('validly signed manifest on a disallowed host is rejected', function ($n) use ($good) {
    $bad_urls = array(
        'https://evil.example/ZysysHostingOptimizations-0.8.0.zip',
        'https://github.com.evil.example/zysys/x.zip',
        'https://evilgithub.com/zysys/x.zip',
        'https://github.com@evil.example/zysys/x.zip',
        'http://github.com/zysys/x.zip',
        'ftp://github.com/zysys/x.zip',
        'https://github.com:8443/zysys/x.zip',
    );
    foreach ($bad_urls as $url) {
        zy_rejects($n . ' ' . $url, zy_updater($good['pub']), zy_manifest($good['sec'], array('package_url' => $url)));
    }
});

zy_case('objects.githubusercontent.com is an allowed host', function ($n) use ($good) {
    $url = 'https://objects.githubusercontent.com/github-production-release-asset/1/x.zip';
    $update = zy_check_update(zy_updater($good['pub']), json_encode(zy_manifest($good['sec'], array('package_url' => $url))));
    zy_check($n, $update !== null && $update->package === $url);
});

zy_case('version must be greater than the installed one', function ($n) use ($good) {
    foreach (array('0.7.9', '0.7.8', '0.1.0') as $v) {
        zy_rejects($n . " ($v)", zy_updater($good['pub']), zy_manifest($good['sec'], array('version' => $v)));
    }
});

zy_case('slug must match this plugin', function ($n) use ($good) {
    zy_rejects($n, zy_updater($good['pub']), zy_manifest($good['sec'], array('slug' => 'some-other-plugin')));
});

zy_case('missing and unexpected fields are rejected', function ($n) use ($good) {
    $m = zy_manifest($good['sec']);
    $missing = $m;
    unset($missing['sha256']);
    zy_rejects($n . ' (missing sha256)', zy_updater($good['pub']), $missing);
    $unsigned = $m;
    unset($unsigned['signature']);
    zy_rejects($n . ' (missing signature)', zy_updater($good['pub']), $unsigned);
    $extra = $m;
    $extra['autoinstall'] = 'yes';
    zy_rejects($n . ' (extra field)', zy_updater($good['pub']), $extra);
    zy_rejects($n . ' (not JSON)', zy_updater($good['pub']), 'garbage');
    zy_rejects($n . ' (JSON scalar)', zy_updater($good['pub']), '"just a string"');
});

zy_case('legacy serialized payload is never unserialized and never offers an update', function ($n) use ($good) {
    $GLOBALS['zy_canary'] = false;
    $evil = new stdClass;
    $evil->new_version = '9.9.9';
    $evil->package = 'https://evil.example/pwn.zip';
    $evil->canary = new ZyCanary;
    $update = zy_check_update(zy_updater($good['pub']), serialize($evil));
    zy_check($n . ' (no update)', $update === null, $update === null ? '' : 'update was offered: ' . $update->package);
    zy_check($n . ' (no unserialize)', $GLOBALS['zy_canary'] === false, 'unserialize ran on remote data');
});

zy_case('placeholder public key refuses a validly signed manifest', function ($n) use ($good) {
    zy_check($n . ' (constant is the placeholder)', defined('ZYSYSHOSTING_UPDATE_PUBKEY') && !preg_match('/^[A-Za-z0-9+\/]{43}=$/', ZYSYSHOSTING_UPDATE_PUBKEY), 'ZYSYSHOSTING_UPDATE_PUBKEY missing or looks like a real key');
    zy_rejects($n . ' (default config)', zy_updater(), zy_manifest($good['sec']));
    zy_rejects($n . ' (explicit placeholder)', zy_updater(ZYSYSHOSTING_UPDATE_PUBKEY), zy_manifest($good['sec']));
    zy_rejects($n . ' (empty key)', zy_updater(''), zy_manifest($good['sec']));
});

/* Install-time checks: upgrader_pre_download. */

function zy_offer($updater, $sec, $zip = 'zip-bytes') {
    $update = zy_check_update($updater, json_encode(zy_manifest($sec, array(), $zip)));
    $GLOBALS['zy_transient'] = (object) array('response' => array(ZY_BASE => $update));
    return $update;
}

zy_case('package matching the signed sha256 is handed to the installer', function ($n) use ($good) {
    $u = zy_updater($good['pub']);
    zy_offer($u, $good['sec']);
    $GLOBALS['zy_downloads'] = array(ZY_URL => 'zip-bytes');
    $r = $u->upgrader_pre_download(false, ZY_URL, null, array('plugin' => ZY_BASE));
    zy_check($n, is_string($r) && file_exists($r) && file_get_contents($r) === 'zip-bytes', is_wp_error($r) ? $r->get_error_message() : 'unexpected return');
});

zy_case('sha256 mismatch aborts the install and discards the download', function ($n) use ($good) {
    $u = zy_updater($good['pub']);
    zy_offer($u, $good['sec']);
    $GLOBALS['zy_downloads'] = array(ZY_URL => 'tampered-bytes');
    $GLOBALS['zy_tmp_files'] = array();
    $r = $u->upgrader_pre_download(false, ZY_URL, null, array('plugin' => ZY_BASE));
    zy_check($n . ' (WP_Error)', is_wp_error($r));
    $leftover = array_filter($GLOBALS['zy_tmp_files'], 'file_exists');
    zy_check($n . ' (temp file removed)', count($GLOBALS['zy_tmp_files']) === 1 && !$leftover);
});

zy_case('install aborts when no verified manifest backs the package', function ($n) use ($good) {
    $u = zy_updater($good['pub']);
    $GLOBALS['zy_downloads'] = array('https://evil.example/pwn.zip' => 'evil');
    $GLOBALS['zy_download_calls'] = array();
    $GLOBALS['zy_transient'] = (object) array('response' => array(ZY_BASE => (object) array(
        'new_version' => '9.9.9', 'package' => 'https://evil.example/pwn.zip', 'slug' => ZY_SLUG,
    )));
    $r = $u->upgrader_pre_download(false, 'https://evil.example/pwn.zip', null, array('plugin' => ZY_BASE));
    zy_check($n . ' (WP_Error)', is_wp_error($r));
    zy_check($n . ' (nothing downloaded)', $GLOBALS['zy_download_calls'] === array());
});

zy_case('install aborts when the package differs from the signed package_url', function ($n) use ($good) {
    $u = zy_updater($good['pub']);
    zy_offer($u, $good['sec']);
    $swapped = 'https://github.com/attacker/evil/releases/download/v0.8.0/evil.zip';
    $GLOBALS['zy_downloads'] = array($swapped => 'zip-bytes');
    $GLOBALS['zy_download_calls'] = array();
    $r = $u->upgrader_pre_download(false, $swapped, null, array('plugin' => ZY_BASE));
    zy_check($n . ' (WP_Error)', is_wp_error($r));
    zy_check($n . ' (nothing downloaded)', $GLOBALS['zy_download_calls'] === array());
});

zy_case('install is refused while the public key is the placeholder', function ($n) use ($good) {
    $u = zy_updater($good['pub']);
    zy_offer($u, $good['sec']);
    $GLOBALS['zy_downloads'] = array(ZY_URL => 'zip-bytes');
    $GLOBALS['zy_download_calls'] = array();
    $r = zy_updater()->upgrader_pre_download(false, ZY_URL, null, array('plugin' => ZY_BASE));
    zy_check($n . ' (WP_Error)', is_wp_error($r));
    zy_check($n . ' (nothing downloaded)', $GLOBALS['zy_download_calls'] === array());
});

zy_case('downloads for other plugins are left alone', function ($n) use ($good) {
    $u = zy_updater($good['pub']);
    $GLOBALS['zy_download_calls'] = array();
    $r = $u->upgrader_pre_download(false, 'https://downloads.wordpress.org/plugin/other.zip', null, array('plugin' => 'other/other.php'));
    zy_check($n, $r === false && $GLOBALS['zy_download_calls'] === array());
});

/* scripts/sign-release.php produces a manifest the updater accepts. */

zy_case('sign-release.php output verifies', function ($n) use ($good, $other, $root) {
    $dir = sys_get_temp_dir() . '/zysign-' . getmypid() . '-' . mt_rand();
    mkdir($dir, 0700, true);
    $zip = $dir . '/ZysysHostingOptimizations-0.8.0.zip';
    file_put_contents($zip, 'zip-bytes');
    $keyfile = $dir . '/secret.key';
    file_put_contents($keyfile, base64_encode($good['sec']));
    chmod($keyfile, 0600);

    $run = function ($args) use ($root) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/sign-release.php');
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>/dev/null', $out, $code);
        return array(implode("\n", $out), $code);
    };

    list($json, $code) = $run(array($zip, '0.8.0', $keyfile));
    zy_check($n . ' (exit 0)', $code === 0, "exit $code");
    $m = json_decode($json, true);
    zy_check($n . ' (fields)', is_array($m) && array_keys($m) !== array() && count($m) === 5 && $m['sha256'] === hash('sha256', 'zip-bytes') && $m['version'] === '0.8.0' && $m['slug'] === ZY_SLUG);
    zy_accepts($n . ' (updater accepts)', zy_updater($good['pub']), $json);
    zy_rejects($n . ' (wrong key rejects)', zy_updater($other['pub']), $json);

    list(, $code) = $run(array($zip, '0.8.0', $dir . '/missing.key'));
    zy_check($n . ' (missing key file fails)', $code !== 0);
    list(, $code) = $run(array($dir . '/missing.zip', '0.8.0', $keyfile));
    zy_check($n . ' (missing zip fails)', $code !== 0);
    list(, $code) = $run(array($zip, 'not-a-version', $keyfile));
    zy_check($n . ' (bad version fails)', $code !== 0);

    $src = @file_get_contents($root . '/scripts/sign-release.php');
    zy_check($n . ' (no embedded secret key)', $src !== false && strpos($src, base64_encode($good['sec'])) === false && strpos($src, 'sodium_crypto_sign_keypair') === false);

    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
});

foreach ($GLOBALS['zy_tmp_files'] ?? array() as $f) {
    @unlink($f);
}

echo $failures === 0 ? "PASS\n" : "FAILED: $failures check(s)\n";
exit($failures === 0 ? 0 : 1);
