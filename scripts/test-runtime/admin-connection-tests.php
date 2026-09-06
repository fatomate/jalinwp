<?php
/** Current disposable coverage for connection setup, OAuth history, and admin guards. */
define('FG_TEST_DISPOSABLE', true);
require '/wordpress/wp-load.php';
require_once '/wordpress/wp-admin/includes/template.php';
$plugin = '/wordpress/wp-content/plugins/jalin-mcp-gateway/';
foreach (['core', 'settings', 'auth', 'oauth', 'connection', 'finance-admin', 'admin'] as $module) {
    require_once $plugin . 'includes/class-' . $module . '.php';
}
if (!defined('FG_FILE')) { define('FG_FILE', $plugin . 'jalin-mcp-gateway.php'); }
if (!defined('FG_VERSION')) { define('FG_VERSION', '0.3.3-test'); }
global $wpdb;
$wpdb->suppress_errors(true);
$admin_id = (int) get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
wp_set_current_user($admin_id);
$initial = ['enabled'=>false, 'oauth_enabled'=>false, 'writes'=>false, 'write_mode'=>'reviewed', 'sensitive'=>false, 'users'=>[], 'origins'=>['https://client.example'], 'finance_mappings'=>['stripe'=>['fee_key'=>'_fee', 'fee_divisor'=>100]]];
update_option('fg_settings', $initial, false);
FG_Core::activate();
FG_Admin::boot();
$cases = [];
function ac_check(string $name, bool $pass): void { global $cases; $cases[]=['name'=>$name, 'pass'=>$pass]; if (!$pass) { throw new RuntimeException($name); } }
function ac_settings(): array { return FG_Settings::snapshot('connection')['settings']; }
function ac_page(string $tab): string { $_GET=['tab'=>$tab]; ob_start(); FG_Admin::page(); return ob_get_clean(); }
function ac_tab(string $url): string { parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query); return $query['tab'] ?? ''; }
class AC_Redirect extends RuntimeException {}
class AC_Die extends RuntimeException {}
add_filter('wp_redirect', static function ($location) { throw new AC_Redirect($location); });
add_filter('wp_die_handler', static fn() => static function ($message, $title='', $args=[]) { throw new AC_Die(wp_strip_all_tags((string) $message), is_array($args) ? (int) ($args['response'] ?? 0) : 0); });
function ac_submit(string $method, string $nonce, array $post=[]): string {
    $_SERVER['REQUEST_METHOD']='POST';
    $_POST=array_replace(['_wpnonce'=>wp_create_nonce($nonce), 'settings_version'=>FG_Settings::fingerprint('connection')], $post);
    $_REQUEST=$_POST;
    try { FG_Admin::$method(); } catch (AC_Redirect $redirect) { return $redirect->getMessage(); }
    throw new RuntimeException('Expected redirect after ' . $method);
}
function ac_grant(string $name, string $status='active'): string {
    global $wpdb, $admin_id;
    $client=wp_generate_uuid4(); $grant=wp_generate_uuid4();
    $hash=new ReflectionMethod(FG_OAuth::class, 'user_hash'); $hash->setAccessible(true);
    $wpdb->insert(FG_Core::table('oauth_clients'), ['id'=>$client, 'name'=>$name, 'auth_method'=>'none', 'redirects'=>'[]', 'authorized'=>1, 'created'=>time(), 'expires'=>0]);
    $wpdb->insert(FG_Core::table('oauth_grants'), ['id'=>$grant, 'client_id'=>$client, 'user_id'=>$admin_id, 'user_hash'=>$hash->invoke(null, $admin_id), 'resource'=>FG_OAuth::resource(), 'status'=>$status, 'created'=>time()-60, 'expires'=>time()+3600, 'last_used'=>0, 'consent_recorded_at'=>time()-60]);
    if ($wpdb->last_error) { throw new RuntimeException('Fixture grant could not be stored: ' . $wpdb->last_error); }
    return $grant;
}

$url=ac_submit('quick_connect', 'fg_quick_connect');
$settings=ac_settings();
ac_check('Quick setup enables gateway and OAuth for the current administrator', $settings['enabled'] && $settings['oauth_enabled'] && $settings['users']===[$admin_id]);
ac_check('Quick setup keeps writes and sensitive access disabled', !$settings['writes'] && !$settings['sensitive']);
ac_check('Quick setup preserves finance mappings and origins', $settings['finance_mappings']===$initial['finance_mappings'] && $settings['origins']===$initial['origins']);
ac_check('Quick setup returns to Connection Setup with a current settings version', ac_tab($url)==='connect' && strlen(FG_Settings::fingerprint('connection'))===64);

$tab_markers=['connect'=>'id="fg-connect-title"', 'connections'=>'id="fg-connections"', 'access'=>'id="fg-access"', 'finance'=>'<h2>Finance Setup</h2>', 'changes'=>'<h2>Changes And Review</h2>', 'audit'=>'<h2>Activity Log</h2>'];
foreach ($tab_markers as $tab=>$marker) {
    $html=ac_page($tab);
    ac_check($tab . ' renders as its own admin tab', str_contains($html, $marker) && substr_count($html, 'aria-current="page"')===1);
}
ac_check('Connection Setup contains no hidden Access Controls form', !str_contains(ac_page('connect'), 'id="fg-access"'));

$url=ac_submit('save', 'fg_save', ['change_mode'=>'readonly', 'oauth_enabled'=>'1', 'sensitive'=>'1', 'origins'=>'']);
ac_check('Section-scoped Access save preserves finance mappings', ac_tab($url)==='access' && ac_settings()['finance_mappings']===$initial['finance_mappings'] && !ac_settings()['writes'] && ac_settings()['sensitive']);

$grant=ac_grant('ChatGPT & Co. <script>alert(1)</script>');
$html=ac_page('connections');
ac_check('Actual OAuth fixture row is escaped and renders a revoke control', !str_contains($html, '<script>') && str_contains($html, 'ChatGPT &amp; Co.') && str_contains($html, 'name="grant_id" value="' . $grant . '"'));
$url=ac_submit('revoke_connection', 'fg_revoke_connection', ['grant_id'=>$grant]);
ac_check('Individual revoke uses the real grant and returns to OAuth Connections', ac_tab($url)==='connections' && $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . FG_Core::table('oauth_grants') . ' WHERE id=%s', $grant))==='revoked');

$failed=ac_grant('Failure fixture');
$failure=static fn($sql) => str_starts_with(ltrim($sql), 'UPDATE') && str_contains($sql, FG_Core::table('oauth_grants')) ? 'UPDATE ac_missing_grants SET status=1' : $sql;
add_filter('query', $failure); $blocked=false;
try { ac_submit('revoke_connection', 'fg_revoke_connection', ['grant_id'=>$failed]); }
catch (AC_Die $error) { $blocked=$error->getCode()===503 && str_contains($error->getMessage(), 'It may still be active'); }
finally { remove_filter('query', $failure); }
ac_check('Failed individual revoke returns actionable 503 without success redirect', $blocked);

$failure=static fn($sql) => str_starts_with(ltrim($sql), 'UPDATE') && str_contains($sql, FG_Core::table('oauth_grants')) ? 'UPDATE ac_missing_grants SET status=1' : $sql;
add_filter('query', $failure); $url=ac_submit('save', 'fg_save', ['change_mode'=>'readonly', 'sensitive'=>'1', 'origins'=>'']); remove_filter('query', $failure);
ac_check('Failed OAuth revocation leaves access disabled and returns an Access error', ac_tab($url)==='access' && !ac_settings()['oauth_enabled'] && !empty(ac_settings()['_oauth_revoke_pending']));
$recovered=FG_Settings::enable_for_user($admin_id, FG_Settings::fingerprint('connection'));
ac_check('Recovery completes pending revocation before re-enabling OAuth', !is_wp_error($recovered) && ac_settings()['enabled'] && ac_settings()['oauth_enabled'] && empty(ac_settings()['_oauth_revoke_pending']));

foreach (['quick_connect'=>'fg_quick_connect', 'revoke_connection'=>'fg_revoke_connection', 'connection_check'=>'fg_connection_check'] as $method=>$nonce) {
    foreach (['GET', 'nonce', 'user'] as $mode) {
        wp_set_current_user($mode==='user' ? 0 : $admin_id); $_SERVER['REQUEST_METHOD']=$mode==='GET' ? 'GET' : 'POST';
        $_POST=['_wpnonce'=>$mode==='nonce' ? 'invalid' : wp_create_nonce($nonce), 'grant_id'=>$grant]; $_REQUEST=$_POST; $blocked=false;
        try { FG_Admin::$method(); } catch (AC_Die $error) { $blocked=str_contains($error->getMessage(), ['GET'=>'POST required', 'nonce'=>'link you followed has expired', 'user'=>'Administrator access required'][$mode]); }
        ac_check($method . ' rejects ' . $mode . ' without authorization', $blocked);
    }
}
wp_set_current_user($admin_id);
echo wp_json_encode(['passed'=>count($cases), 'total'=>count($cases), 'cases'=>$cases], JSON_PRETTY_PRINT);
