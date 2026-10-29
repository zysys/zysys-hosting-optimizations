#!/usr/bin/env php
<?php
/* Sign a release zip and print the update manifest JSON.
 *
 * Usage: php scripts/sign-release.php <zip> <version> <secret-key-file>
 *
 * <secret-key-file> holds the base64 Ed25519 secret key (64 bytes) generated
 * once as described in docs/RELEASING.md. It is only ever read from that path;
 * this script embeds no key material.
 *
 * The signature covers the canonical JSON of {package_url, sha256, slug,
 * version}: keys sorted, compact, unescaped slashes and unicode. That must stay
 * byte-identical to Plugin_Updater::canonical_manifest() in
 * includes/plugin-updater.php.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "sign-release.php is a command-line tool.\n");
    exit(2);
}

const ZY_RELEASE_SLUG = 'zysys-hosting-optimizations';
const ZY_RELEASE_REPO = 'zysys/zysys-hosting-optimizations';

function zy_sign_fail($message, $code = 1) {
    fwrite(STDERR, "sign-release: $message\n");
    exit($code);
}

if (!function_exists('sodium_crypto_sign_detached')) {
    zy_sign_fail('the sodium extension is required (PHP 7.2+).');
}
if ($argc !== 4) {
    zy_sign_fail("usage: php scripts/sign-release.php <zip> <version> <secret-key-file>", 2);
}
list(, $zip, $version, $key_file) = $argv;

if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $version)) {
    zy_sign_fail("version '$version' must be dotted numbers, e.g. 0.8.0.");
}
if (!is_file($zip) || !is_readable($zip)) {
    zy_sign_fail("zip '$zip' is not a readable file.");
}
if (!is_file($key_file) || !is_readable($key_file)) {
    zy_sign_fail("secret key file '$key_file' is not a readable file.");
}
if ((fileperms($key_file) & 0077) !== 0) {
    fwrite(STDERR, "sign-release: warning: $key_file is readable by group/others; chmod 600 it.\n");
}

$secret = base64_decode(trim((string) file_get_contents($key_file)), true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    zy_sign_fail('the secret key file must contain a base64 Ed25519 secret key (64 bytes).');
}

$sha256 = hash_file('sha256', $zip);
if ($sha256 === false) {
    zy_sign_fail("could not hash '$zip'.");
}

$fields = array(
    'slug' => ZY_RELEASE_SLUG,
    'version' => $version,
    'package_url' => 'https://github.com/' . ZY_RELEASE_REPO . '/releases/download/v' . $version . '/' . rawurlencode(basename($zip)),
    'sha256' => $sha256,
);
ksort($fields);
$message = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($message === false) {
    zy_sign_fail('could not encode the manifest.');
}

$fields['signature'] = base64_encode(sodium_crypto_sign_detached($message, $secret));
sodium_memzero($secret);

echo json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
