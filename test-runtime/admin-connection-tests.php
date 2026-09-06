<?php
// Disposable WordPress admin tests. OAuth and diagnostics are collaborators stubbed here;
// real protocol, token storage, and loopback behavior are covered in their own suites.
require_once '/wordpress/wp-load.php';
require_once '/wordpress/wp-admin/includes/template.php';
define('FG_VERSION', '0.2.0');
define('FG_FILE', '/wordpress/wp-content/plugins/fames-mcp-gateway/fames-mcp-gateway.php');
final class FG_OAuth {
    public static int $revoked_all = 0;
    public static array $revoked = [];
    public static array $rows = [];
    public static bool $fail_revoke = false;
    public static function install(): void {}
    public static function connections(): array { return self::$rows; }
    public static function revoke_all(): bool { self::$revoked_all++; return !self::$fail_revoke; }
    public static function revoke(string $id): bool { self::$revoked[] = $id; return !self::$fail_revoke; }
}
final class FG_Connection {
    public static function diagnostics(): array { return ['checks' => [['label' => '<script>check</script>', 'status' => 'pass', 'detail' => '<script>detail</script>']], 'checked_at' => time()]; }
}
foreach (['core', 'admin'] as $module) { require_once '/wordpress/wp-content/plugins/fames-mcp-gateway/includes/class-' . $module . '.php'; }
FG_Core::activate();
$admins = get_users(['role' => 'administrator', 'number' => 1]);
$admin_id = (int) $admins[0]->ID;
wp_set_current_user($admin_id);
class FG_Admin_Test_Redirect extends RuntimeException {}
add_filter('wp_redirect', static function ($location) { throw new FG_Admin_Test_Redirect($location); });
add_filter('wp_die_handler', static fn() => static function ($message, $title = '', $args = []) { throw new RuntimeException(wp_strip_all_tags((string) $message), (int) ($args['response'] ?? 0)); });
$cases = [];
function fg_admin_assert(string $name, bool $pass): void {
    global $cases;
    $cases[] = ['name' => $name, 'pass' => $pass];
    if (!$pass) { throw new RuntimeException('Failed: ' . $name); }
}
function fg_admin_submit(string $method, string $nonce, array $post = []): string {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array_merge(['_wpnonce' => wp_create_nonce($nonce)], $post);
    $_REQUEST = $_POST;
    try { FG_Admin::$method(); }
    catch (FG_Admin_Test_Redirect $redirect) { return $redirect->getMessage(); }
    throw new RuntimeException('Expected redirect after ' . $method);
}
$initial = ['enabled' => false, 'oauth_enabled' => false, 'writes' => false, 'sensitive' => false, 'users' => [], 'origins' => ['https://client.example'], 'finance_mappings' => ['stripe' => ['fee_key' => '_fee', 'fee_divisor' => 100]]];
update_option('fg_settings', $initial, false);
$url = fg_admin_submit('quick_connect', 'fg_quick_connect');
$settings = FG_Core::settings();
fg_admin_assert('Quick setup enables gateway and OAuth for current account', $settings['enabled'] && $settings['oauth_enabled'] && $settings['users'] === [$admin_id]);
fg_admin_assert('Quick setup does not enable write or sensitive data access', !$settings['writes'] && !$settings['sensitive']);
fg_admin_assert('Quick setup preserves finance mappings and origins', $settings['finance_mappings'] === $initial['finance_mappings'] && $settings['origins'] === $initial['origins']);
fg_admin_assert('Quick setup redirects back to connection page', str_contains($url, 'connected_setup=1'));
fg_admin_submit('quick_connect', 'fg_quick_connect');
fg_admin_assert('Repeated quick setup does not duplicate allowed users', FG_Core::settings()['users'] === [$admin_id]);

FG_OAuth::$rows = [['id' => 'fixture-grant', 'user_id' => $admin_id, 'client_name' => '<script>client</script>', 'created' => time(), 'last_used' => 0, 'writes' => false, 'sensitive' => false]];
$_GET = ['tab' => 'connect'];
ob_start(); FG_Admin::page(); $html = ob_get_clean();
file_put_contents('/test-results/admin-connection-render.html', $html);
fg_admin_assert('Connect page offers OAuth with automatic registration', str_contains($html, 'Connect in three steps') && str_contains($html, 'blank for automatic registration'));
fg_admin_assert('Copy URL is available without JavaScript', str_contains($html, 'id="fg-endpoint"') && str_contains($html, 'readonly value='));
fg_admin_assert('Connection checks have a POST fallback', str_contains($html, 'id="fg-check-form"') && str_contains($html, 'name="action" value="fg_connection_check"'));
fg_admin_assert('Connection metadata is escaped and revoke control rendered', !str_contains($html, '<script>client</script>') && str_contains($html, '&lt;script&gt;client&lt;/script&gt;') && str_contains($html, 'name="grant_id" value="fixture-grant"'));
fg_admin_assert('Version comes from plugin constant', str_contains($html, '0.2.0 · Staging preview'));

$_GET = ['tab' => 'finance'];
ob_start(); FG_Admin::page(); $html = ob_get_clean();
fg_admin_assert('Finance tab preserves OAuth and user inputs in hidden access section', (bool) preg_match('/name="oauth_enabled"[^>]+checked=/', $html) && (bool) preg_match('/name="users\[\]"[^>]+checked=/', $html));

fg_admin_submit('save', 'fg_save', ['enabled' => '1', 'oauth_enabled' => '1', 'users' => [$admin_id], 'finance' => [['gateway' => 'stripe', 'fee_key' => '_fee', 'fee_divisor' => '100']]]);
fg_admin_assert('Settings save persists enabled OAuth without revoking connections', FG_Core::settings()['oauth_enabled'] === true && FG_OAuth::$revoked_all === 0);
fg_admin_submit('save', 'fg_save', ['enabled' => '1', 'users' => [$admin_id]]);
fg_admin_assert('Disabling OAuth revokes existing connections', FG_Core::settings()['oauth_enabled'] === false && FG_OAuth::$revoked_all === 1);
fg_admin_submit('revoke_connection', 'fg_revoke_connection', ['grant_id' => 'fixture-grant']);
fg_admin_assert('Per-connection revoke targets the submitted grant', FG_OAuth::$revoked === ['fixture-grant']);
fg_admin_submit('revoke_connection', 'fg_revoke_connection', ['revoke_all' => '1']);
fg_admin_assert('Explicit revoke-all invokes OAuth revocation', FG_OAuth::$revoked_all === 2);

FG_OAuth::$fail_revoke = true;
foreach ([['grant_id' => 'fixture-grant'], ['revoke_all' => '1']] as $post) {
    $blocked = false;
    try { fg_admin_submit('revoke_connection', 'fg_revoke_connection', $post); }
    catch (RuntimeException $error) { $blocked = $error->getCode() === 503 && str_contains($error->getMessage(), 'It may still be active'); }
    fg_admin_assert(isset($post['revoke_all']) ? 'Failed revoke-all returns actionable 503 without success redirect' : 'Failed individual revoke returns actionable 503 without success redirect', $blocked);
}
$blocked = false;
try { fg_admin_submit('save', 'fg_save', ['enabled' => '1', 'users' => [$admin_id]]); }
catch (RuntimeException $error) { $blocked = $error->getCode() === 503 && str_contains($error->getMessage(), 'before re-enabling access'); }
fg_admin_assert('Failed revocation during settings save reports 503 without success redirect', $blocked);
fg_admin_assert('OAuth stays disabled after saved settings with revocation failure', FG_Core::settings()['oauth_enabled'] === false);
FG_OAuth::$fail_revoke = false;
FG_OAuth::$rows = [];
$_GET = ['tab' => 'connect'];
ob_start(); FG_Admin::page(); $html = ob_get_clean();
fg_admin_assert('Revoke-all remains available with no listed grants so failed revocation can be retried', str_contains($html, 'name="revoke_all" value="1"'));
$block_settings = static fn($new, $old) => $old;
add_filter('pre_update_option_fg_settings', $block_settings, 10, 2);
$blocked = false;
try { fg_admin_submit('quick_connect', 'fg_quick_connect'); }
catch (RuntimeException $error) { $blocked = $error->getCode() === 503 && str_contains($error->getMessage(), 'Settings could not be saved'); }
remove_filter('pre_update_option_fg_settings', $block_settings, 10);
fg_admin_assert('Blocked settings persistence reports 503 without setup success redirect', $blocked);
fg_admin_assert('Blocked settings persistence leaves the prior disabled policy intact', FG_Core::settings()['oauth_enabled'] === false);

fg_admin_submit('connection_check', 'fg_connection_check');
fg_admin_assert('POST diagnostics fallback retains report for current administrator', is_array(get_transient('fg_connection_check_' . $admin_id)));
$_GET = ['tab' => 'connect', 'checked' => '1'];
ob_start(); FG_Admin::page(); $html = ob_get_clean();
fg_admin_assert('Diagnostic labels and details are escaped', !str_contains($html, '<script>check</script>') && str_contains($html, '&lt;script&gt;detail&lt;/script&gt;'));

foreach (['quick_connect' => 'fg_quick_connect', 'revoke_connection' => 'fg_revoke_connection', 'connection_check' => 'fg_connection_check'] as $method => $nonce) {
    foreach (['GET', 'nonce', 'user'] as $mode) {
        wp_set_current_user($mode === 'user' ? 0 : $admin_id);
        $_SERVER['REQUEST_METHOD'] = $mode === 'GET' ? 'GET' : 'POST';
        $_POST = ['_wpnonce' => $mode === 'nonce' ? 'invalid' : wp_create_nonce($nonce), 'grant_id' => 'fixture-grant'];
        $_REQUEST = $_POST;
        $blocked = false;
        try { FG_Admin::$method(); }
        catch (FG_Admin_Test_Redirect $error) { $blocked = false; }
        catch (RuntimeException $error) {
            $expected = ['GET' => 'POST required', 'nonce' => 'link you followed has expired', 'user' => 'Administrator access required'][$mode];
            $blocked = str_contains($error->getMessage(), $expected);
        }
        fg_admin_assert($method . ' rejects ' . $mode . ' without authorization', $blocked);
    }
}
echo wp_json_encode(['passed' => count($cases), 'total' => count($cases), 'cases' => $cases], JSON_PRETTY_PRINT);
