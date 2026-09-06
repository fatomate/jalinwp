<?php
/** Disposable native PHP + MySQL/MariaDB release gate. No remote WordPress access. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !extension_loaded('mysqli') || preg_match('/emscripten|wasm/i', php_uname())) {
    fwrite(STDERR, "Requires native PHP CLI with mysqli. This gate does not accept SQLite or PHP WASM.\n");
    exit(2);
}
$core = realpath(getenv('FG_NATIVE_WP_CORE_DIR') ?: __DIR__ . '/../fixtures/wordpress');
$plugin = realpath(getenv('FG_NATIVE_PLUGIN_DIR') ?: __DIR__ . '/../../../jalin-mcp-gateway');
if (!$core || !is_file($core . '/wp-load.php') || !is_dir($core . '/wp-admin') || !is_dir($core . '/wp-includes') || !$plugin || !is_file($plugin . '/includes/class-oauth.php')) {
    fwrite(STDERR, "Set FG_NATIVE_WP_CORE_DIR to unpacked WordPress core and FG_NATIVE_PLUGIN_DIR to the plugin source.\n");
    exit(2);
}
$socket = getenv('FG_NATIVE_DB_SOCKET') ?: '';
$port = filter_var(getenv('FG_NATIVE_DB_PORT') ?: '3306', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
if ($port === false || ($socket !== '' && (!str_starts_with($socket, '/') || !file_exists($socket)))) {
    fwrite(STDERR, "Use a valid local port or an existing absolute Unix socket path.\n");
    exit(2);
}
// No configurable remote host, database name, or existing WordPress directory is accepted.
$database = 'fg_native_030_' . bin2hex(random_bytes(8));
$fixture = sys_get_temp_dir() . '/' . $database;
$wpdb = null;
$db = null;
$created = false;
$passed = false;
$cleaned = false;
$summary = ['status'=>'failed', 'gate'=>'native-wordpress-database-030', 'php'=>PHP_VERSION, 'database_driver'=>'mysqli', 'cases'=>[]];

function fg_native_copy(string $source, string $target): void {
    if (is_link($source)) { throw new RuntimeException('Source fixture must not contain symbolic links.'); }
    if (is_dir($source)) {
        if (!is_dir($target) && !mkdir($target, 0700, true)) { throw new RuntimeException('Could not create fixture directory.'); }
        foreach (new DirectoryIterator($source) as $entry) {
            if (!$entry->isDot()) { fg_native_copy($entry->getPathname(), $target . '/' . $entry->getFilename()); }
        }
    } elseif (is_file($source) && !copy($source, $target)) { throw new RuntimeException('Could not copy fixture file.'); }
}
function fg_native_remove(string $directory): void {
    if (!is_dir($directory) || is_link($directory)) { return; }
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isDir() && !$entry->isLink()) { fg_native_remove($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($directory);
}
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($socket === '' ? '127.0.0.1' : 'localhost', getenv('FG_NATIVE_DB_USER') ?: 'root', getenv('FG_NATIVE_DB_PASSWORD') ?: '', '', (int) $port, $socket ?: null);
    $summary['database_version'] = $db->server_info;
    $db->query('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    if (!mkdir($fixture, 0700)) { throw new RuntimeException('Could not create disposable WordPress directory.'); }
    file_put_contents($fixture . '/FG-DISPOSABLE', $database);
    // Copy core only: never reuse wp-config, wp-content, MU plugins or a SQLite drop-in.
    foreach (['wp-admin', 'wp-includes'] as $part) { fg_native_copy($core . '/' . $part, $fixture . '/' . $part); }
    foreach (glob($core . '/*.php') as $file) {
        if (!in_array(basename($file), ['wp-config.php', 'wp-config-sample.php'], true)) { fg_native_copy($file, $fixture . '/' . basename($file)); }
    }
    mkdir($fixture . '/wp-content/plugins', 0700, true);
    mkdir($fixture . '/wp-content/themes', 0700, true);
    fg_native_copy($plugin, $fixture . '/wp-content/plugins/jalin-mcp-gateway');
    file_put_contents($fixture . '/wp-config.php', '<?php $table_prefix = "wp_"; require_once ABSPATH . "wp-settings.php";');
    define('ABSPATH', $fixture . '/');
    define('DB_NAME', $database);
    define('DB_USER', getenv('FG_NATIVE_DB_USER') ?: 'root');
    define('DB_PASSWORD', getenv('FG_NATIVE_DB_PASSWORD') ?: '');
    define('DB_HOST', $socket === '' ? '127.0.0.1:' . $port : 'localhost:' . $socket);
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATE', '');
    foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $constant) { define($constant, bin2hex(random_bytes(32))); }
    define('WP_HOME', 'https://fixture.invalid');
    define('WP_SITEURL', 'https://fixture.invalid');
    define('WP_INSTALLING', true);
    define('WP_HTTP_BLOCK_EXTERNAL', true);
    define('DISABLE_WP_CRON', true);
    define('AUTOMATIC_UPDATER_DISABLED', true);
    define('DISALLOW_FILE_MODS', true);
    define('WP_DEBUG', false);
    define('FG_TEST_DISPOSABLE', true);
    $_SERVER['HTTP_HOST'] = 'fixture.invalid';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require $fixture . '/wp-load.php';
    if (get_class($wpdb) !== 'wpdb' || !($wpdb->dbh instanceof mysqli)) { throw new RuntimeException('Refusing non-native WordPress database adapter.'); }
    add_filter('pre_http_request', static fn() => new WP_Error('fg_native_network_disabled', 'Native fixture cannot make HTTP requests.'), PHP_INT_MAX);
    add_filter('pre_wp_mail', '__return_false');
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install('Native Database Fixture', 'native_admin', 'native@example.invalid', false, '', bin2hex(random_bytes(24)));
    update_option('permalink_structure', '/%postname%/');
    require __DIR__ . '/tests.php';
    $passed = !array_filter($summary['cases'], static fn(array $case): bool => !$case['pass']);
    $summary['status'] = $passed ? 'passed' : 'failed';
} catch (Throwable $error) {
    // No connection credentials, token values, or raw SQL are emitted.
    $summary['exception_type'] = get_class($error);
    $summary['error'] = preg_replace('/Access denied for user.*/', 'Local database authentication failed.', $error->getMessage());
} finally {
    if ($wpdb instanceof wpdb) { $wpdb->close(); }
    if ($created && $db instanceof mysqli) {
        try { $db->query('DROP DATABASE `' . $database . '`'); $cleaned = true; }
        catch (Throwable $error) { $summary['cleanup_error'] = 'Could not remove disposable database: ' . $database; }
    }
    if (is_dir($fixture) && @file_get_contents($fixture . '/FG-DISPOSABLE') === $database) { fg_native_remove($fixture); }
    if ($db instanceof mysqli) { $db->close(); }
}
$summary['disposable_database_removed'] = $cleaned;
$summary['passed'] = count(array_filter($summary['cases'], static fn(array $case): bool => $case['pass']));
$summary['total'] = count($summary['cases']);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed && $cleaned ? 0 : 1);
