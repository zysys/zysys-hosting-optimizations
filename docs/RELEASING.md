# Releasing Zysys Hosting Optimizations

Sites install an update only when its manifest carries a valid Ed25519
signature from the key pinned in `ZYSYSHOSTING_UPDATE_PUBKEY`
(`includes/plugin-updater.php`). Until that constant holds a real public key
the updater refuses every update.

## One-time: generate the signing keypair

Run on a trusted machine, never in CI logs or a shared shell history:

```sh
php -r '
$kp  = sodium_crypto_sign_keypair();
file_put_contents("secret.key", base64_encode(sodium_crypto_sign_secretkey($kp)) . "\n");
chmod("secret.key", 0600);
echo "PUBLIC KEY: ", base64_encode(sodium_crypto_sign_publickey($kp)), "\n";
'
```

1. Store the contents of `secret.key` in 1Password (item: _Zysys Hosting
   Optimizations update signing key_), then delete the file from disk.
2. Put the printed public key in `ZYSYSHOSTING_UPDATE_PUBKEY` in
   `includes/plugin-updater.php` (replacing the placeholder), and ship that in a
   release. Sites that predate the pinned key cannot verify updates and need one
   manual install of that release.
3. Never commit the secret key or paste it into an issue, PR or chat.

Rotating the key means shipping a release whose pinned key is the new public
key, signed with the old secret key.

## Per release

1. Update `VERSION` and run `perl Makefile.pl` to build
   `ZysysHostingOptimizations-<version>.zip`.
2. Export the secret key from 1Password to a temporary `0600` file and sign:

   ```sh
   php scripts/sign-release.php ZysysHostingOptimizations-0.8.0.zip 0.8.0 /path/to/secret.key > manifest.json
   shred -u /path/to/secret.key 2>/dev/null || rm -P /path/to/secret.key
   ```

   The script prints `{slug, version, package_url, sha256, signature}`.
   `package_url` is
   `https://github.com/zysys/zysys-hosting-optimizations/releases/download/v<version>/<zip name>`;
   the signature covers the other four fields as canonical JSON (keys sorted,
   compact, unescaped slashes and unicode).
3. Create the GitHub release `v<version>` and upload both
   `ZysysHostingOptimizations-<version>.zip` and `manifest.json` as release
   assets. The zip's file name must match the one given to the script.
4. The update endpoint on `https://zysys.org/` must return the contents of
   `manifest.json` verbatim (JSON, HTTP 200). Do not re-serialize it: any change
   to the signed fields invalidates the signature.

## What the updater enforces

A manifest is accepted only if all of these hold:

- the signature verifies against the pinned public key;
- `slug` is `zysys-hosting-optimizations` and the manifest has exactly the five
  fields above;
- `version` is greater than the installed version;
- `package_url` is `https` on `github.com` or `objects.githubusercontent.com`
  (no userinfo, no port).

At install time (`upgrader_pre_download`) the package is downloaded to a temp
file and its sha256 is compared with the signed value; a mismatch aborts the
install with an error and deletes the download.
