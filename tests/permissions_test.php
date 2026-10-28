<?php
/* Regression test for zysyshosting_wp_permissions() (includes/security.php).
 *
 * Plain CLI script: `php tests/permissions_test.php`, exits non-zero on failure.
 *
 * The parent process runs the real include in a child process per scenario,
 * because ABSPATH and WP_CONTENT_DIR are constants:
 *   plain       wp-config.php inside ABSPATH
 *   hostile     ABSPATH contains spaces and a shell separator (`; touch INJECTED;`)
 *   parent      wp-config.php one level above ABSPATH
 *
 * Asserted per scenario: wp-config.php stays 0600 (never loosened), WordPress
 * stock files in ABSPATH become 0644, WP_CONTENT_DIR files become 0644, and
 * the WP directories become 0755. In the hostile scenario nothing outside the
 * tree may be created by the shell.
 */

if (!isset($argv[1]) || $argv[1] !== 'child') {
    exit(zy_test_parent());
}

exit(zy_test_child($argv[2], $argv[3]));

function zy_test_parent() {
    $failures = 0;
    $root = sys_get_temp_dir() . '/zyperm-' . getmypid() . '-' . mt_rand();
    $scenarios = array(
        'plain'   => $root . '/plain',
        'hostile' => $root . '/x; touch INJECTED; y',
        'parent'  => $root . '/parent',
    );

    mkdir($root, 0700, true);
    chdir($root); // children inherit it; an injected `touch INJECTED` would land here

    foreach ($scenarios as $name => $dir) {
        echo "== scenario: $name\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($name) . ' ' . escapeshellarg($dir);
        passthru($cmd, $rc);
        if ($rc !== 0)
            $failures++;
    }

    if (file_exists($root . '/INJECTED')) {
        echo "FAIL: shell injection through ABSPATH created $root/INJECTED\n";
        $failures++;
    }

    zy_test_rmdir($root);
    echo $failures === 0 ? "PASS\n" : "FAILED: $failures scenario(s)\n";
    return $failures === 0 ? 0 : 1;
}

function zy_test_child($scenario, $dir) {
    $stock = array('index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php',
        'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php',
        'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php');

    if ($scenario === 'parent') {
        $abs = $dir . '/site/';
        $config = $dir . '/wp-config.php';
    } else {
        $abs = $dir . '/';
        $config = $abs . 'wp-config.php';
    }

    foreach (array($dir, $abs, $abs . 'wp-includes', $abs . 'wp-admin', $abs . 'wp-content', $abs . 'wp-content/plugins') as $d)
        if (!is_dir($d))
            mkdir($d, 0700, true);
    foreach ($stock as $f)
        zy_test_put($abs . $f, 0600);
    zy_test_put($config, 0600);
    zy_test_put($abs . 'wp-includes/version.php', 0600);
    zy_test_put($abs . 'wp-admin/index.php', 0600);
    zy_test_put($abs . 'wp-content/index.php', 0600);
    zy_test_put($abs . 'wp-content/plugins/plugin.php', 0600);

    define('ABSPATH', $abs);
    define('WP_CONTENT_DIR', $abs . 'wp-content');
    define('WPINC', 'wp-includes');

    // WordPress functions includes/security.php needs at load time.
    function add_action() {}

    require dirname(__DIR__) . '/includes/security.php';
    zysyshosting_wp_permissions();

    // The finds are backgrounded; wait for them to settle.
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        if (zy_test_mode($abs . 'index.php') === 0644 && zy_test_mode($abs . 'wp-content/plugins/plugin.php') === 0644
            && zy_test_mode($abs . 'wp-admin/index.php') === 0644 && zy_test_mode($abs . 'wp-includes/version.php') === 0644
            && zy_test_mode($abs . 'wp-content/plugins') === 0755 && zy_test_mode($abs . 'wp-admin') === 0755
            && zy_test_mode($abs . 'wp-includes') === 0755 && zy_test_mode($abs . 'wp-content') === 0755)
            break;
        usleep(50000);
    }
    usleep(500000);

    $expect = array($config => 0600);
    foreach ($stock as $f)
        $expect[$abs . $f] = 0644;
    $expect[$abs . 'wp-includes/version.php'] = 0644;
    $expect[$abs . 'wp-admin/index.php'] = 0644;
    $expect[$abs . 'wp-content/index.php'] = 0644;
    $expect[$abs . 'wp-content/plugins/plugin.php'] = 0644;
    foreach (array('wp-content', 'wp-content/plugins', 'wp-includes', 'wp-admin') as $d)
        $expect[$abs . $d] = 0755;

    $bad = 0;
    foreach ($expect as $path => $want) {
        $got = zy_test_mode($path);
        if ($got !== $want) {
            printf("FAIL: %s is %s, expected %04o\n", $path, $got === false ? 'missing' : sprintf('%04o', $got), $want);
            $bad++;
        }
    }
    return $bad === 0 ? 0 : 1;
}

function zy_test_put($path, $mode) {
    file_put_contents($path, "<?php\n");
    chmod($path, $mode);
}

function zy_test_mode($path) {
    clearstatcache(true, $path);
    $p = @fileperms($path);
    return $p === false ? false : $p & 0777;
}

function zy_test_rmdir($path) {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path))
        return;
    @chmod($path, 0700);
    foreach (scandir($path) as $e)
        if ($e !== '.' && $e !== '..')
            zy_test_rmdir($path . '/' . $e);
    @rmdir($path);
}
