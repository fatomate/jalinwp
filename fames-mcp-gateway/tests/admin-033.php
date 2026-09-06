<?php
/** Real disposable WordPress integration coverage for the 0.3.3 admin workflow. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) {
    throw new RuntimeException('An explicitly disposable WordPress install is required.');
}
require '/wordpress/wp-load.php';
require_once '/wordpress/wp-admin/includes/template.php';
$plugin = '/wordpress/wp-content/plugins/fames-mcp-gateway/';
foreach (['core', 'settings', 'auth', 'oauth', 'finance-admin', 'admin'] as $module) {
    require_once $plugin . 'includes/class-' . $module . '.php';
}
if (!defined('FG_FILE')) { define('FG_FILE', $plugin . 'fames-mcp-gateway.php'); }
if (!defined('FG_VERSION')) { define('FG_VERSION', '0.3.3-test'); }
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REMOTE_ADDR'] = '192.0.2.33';
update_option('home', 'https://gateway.example.test');
update_option('siteurl', 'https://gateway.example.test');
global $wpdb, $wp_rewrite;
$wp_rewrite->set_permalink_structure('/%postname%/');
$wpdb->suppress_errors(true);
$admin_id = (int) get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
wp_set_current_user($admin_id);
delete_option('fg_settings');
FG_Core::activate();
FG_Auth::boot();
FG_Admin::boot();
$cases = [];
function a33_check(string $name, bool $pass): void {
    global $cases;
    $cases[] = ['name' => $name, 'pass' => $pass];
    if (!$pass) { throw new RuntimeException($name); }
}
function a33_state(): array { return FG_Settings::snapshot('connection')['settings']; }
function a33_page($tab, array $extra = []): string {
    $_GET = array_merge(['tab' => $tab], $extra);
    ob_start(); FG_Admin::page(); return ob_get_clean();
}
function a33_tab(string $url): string {
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
    return $query['tab'] ?? '';
}
function a33_query(string $url, string $key): ?string {
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
    return $query[$key] ?? null;
}
function a33_count(): int { global $wpdb; return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . FG_Core::table('oauth_grants')); }
class A33_Redirect extends RuntimeException {}
class A33_Die extends RuntimeException {}
add_filter('wp_redirect', static function ($location) { throw new A33_Redirect($location); });
add_filter('wp_die_handler', static fn() => static function ($message, $title = '', $args = []) {
    throw new A33_Die(wp_strip_all_tags((string) $message), is_array($args) ? (int) ($args['response'] ?? 0) : 0);
});
function a33_submit(string $action, array $data = []): string {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array_replace(['_wpnonce' => wp_create_nonce('fg_' . $action), 'settings_version' => FG_Settings::fingerprint('connection')], $data);
    $_REQUEST = $_POST;
    try { do_action('admin_post_fg_' . $action); }
    catch (A33_Redirect $redirect) { return $redirect->getMessage(); }
    throw new RuntimeException('Expected redirect from ' . $action);
}
function a33_grant(string $status = 'revoked', int $expires = 0, string $name = 'Claude'): array {
    global $wpdb, $admin_id;
    $client = wp_generate_uuid4(); $grant = wp_generate_uuid4();
    $hash = new ReflectionMethod(FG_OAuth::class, 'user_hash'); $hash->setAccessible(true);
    $wpdb->insert(FG_Core::table('oauth_clients'), ['id'=>$client, 'name'=>$name, 'auth_method'=>'none', 'redirects'=>'[]', 'authorized'=>1, 'created'=>time(), 'expires'=>0]);
    $wpdb->insert(FG_Core::table('oauth_grants'), ['id'=>$grant, 'client_id'=>$client, 'user_id'=>$admin_id, 'user_hash'=>$hash->invoke(null, $admin_id), 'resource'=>FG_OAuth::resource(), 'status'=>$status, 'created'=>time()-60, 'expires'=>$expires ?: time()+3600, 'last_used'=>0, 'consent_recorded_at'=>time()-60]);
    if ($wpdb->last_error) { throw new RuntimeException('Fixture grant could not be stored: ' . $wpdb->last_error); }
    return ['id'=>$grant, 'client_id'=>$client];
}
function a33_oauth_request(string $path, array $data): WP_REST_Request {
    $request = new WP_REST_Request('POST', '/fames-mcp/v1/oauth/' . $path);
    if ($path === 'register') { $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($data)); }
    else { $request->set_header('Content-Type', 'application/x-www-form-urlencoded'); $request->set_body_params($data); $request->set_body(http_build_query($data)); }
    return $request;
}

a33_check('Fresh activation defaults to disabled writes and sensitive data', !a33_state()['writes'] && !a33_state()['sensitive']);
$html = a33_page('access');
a33_check('Fresh Access Controls has Read Only checked', (bool) preg_match('/id="fg-mode-readonly"[^>]*checked=/', $html) && !preg_match('/id="fg-mode-yolo"[^>]*checked=/', $html));
$url = a33_submit('quick_connect');
a33_check('Enable button returns to Connection Setup and preserves Read Only', a33_tab($url)==='connect' && a33_state()['enabled'] && a33_state()['oauth_enabled'] && !a33_state()['writes']);

// Exercise the public OAuth flow, not a stubbed connection projection, under fresh defaults.
$session = WP_Session_Tokens::get_instance($admin_id)->create(time()+3600);
$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($admin_id, time()+3600, 'logged_in', $session);
$registration = FG_OAuth::register_client(a33_oauth_request('register', ['client_name'=>'Fresh Read Only', 'redirect_uris'=>['https://claude.ai/api/mcp/auth_callback'], 'token_endpoint_auth_method'=>'none']));
a33_check('Fresh defaults allow real client registration', $registration->get_status()===201);
$client = $registration->get_data(); $verifier = str_repeat('a', 43);
$pending = FG_OAuth::begin_consent(['response_type'=>'code', 'client_id'=>$client['client_id'], 'redirect_uri'=>$client['redirect_uris'][0], 'resource'=>FG_OAuth::resource(), 'scope'=>'mcp', 'state'=>'fixture', 'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method'=>'S256']);
a33_check('Fresh consent cannot request reviewed or YOLO writes', !is_wp_error($pending) && !$pending['request']['allow_writes'] && !$pending['request']['allow_yolo']);
$callback = FG_OAuth::complete_consent($pending['id'], true);
a33_check('Fresh read-only consent completes', is_string($callback));
parse_str((string) wp_parse_url($callback, PHP_URL_QUERY), $callback_query);
$tokens = FG_OAuth::token(a33_oauth_request('token', ['grant_type'=>'authorization_code', 'client_id'=>$client['client_id'], 'redirect_uri'=>$client['redirect_uris'][0], 'resource'=>FG_OAuth::resource(), 'code'=>$callback_query['code'], 'code_verifier'=>$verifier]));
a33_check('Fresh read-only consent exchanges tokens', $tokens->get_status()===200);
$request = new WP_REST_Request('POST', '/fames-mcp/v1/mcp');
$request->set_header('Authorization', 'Bearer ' . $tokens->get_data()['access_token']);
a33_check('Fresh token authenticates but cannot authorize changes', FG_Auth::permission($request)===true && FG_Auth::write_mode()==='read_only' && !FG_Auth::allows_tool(['mutating'=>true, 'sensitive'=>false]));
FG_Auth::reset_context(); wp_set_current_user($admin_id);

$base = a33_state();
foreach (['reviewed', 'yolo'] as $mode) {
    update_option('fg_settings', array_replace($base, ['writes'=>true, 'write_mode'=>$mode]), false);
    $stored = get_option('fg_settings'); FG_Core::activate();
    $html = a33_page('access');
    a33_check('Upgrade preserves explicit ' . $mode . ' mode and its checked UI', get_option('fg_settings')===$stored && (bool) preg_match('/id="fg-mode-'.$mode.'"[^>]*checked=/', $html));
}
$legacy = array_replace($base, ['writes'=>true]); unset($legacy['write_mode']); update_option('fg_settings', $legacy, false);
FG_Core::activate();
a33_check('Legacy writes without a stored mode remain reviewed', get_option('fg_settings')===$legacy && a33_state()['write_mode']==='reviewed' && a33_state()['writes']);
update_option('fg_settings', $base, false);

$tab_checks = [
    'connect'=>['id="fg-connect-title"', 'id="fg-connections"', 'id="fg-access"'],
    'connections'=>['id="fg-connections"', 'id="fg-connect-title"', 'id="fg-access"'],
    'access'=>['id="fg-access"', 'id="fg-connect-title"', 'id="fg-connections"'],
    'finance'=>['<h2>Finance Setup</h2>', 'id="fg-connections"', 'id="fg-access"'],
    'changes'=>['<h2>Changes And Review</h2>', 'id="fg-connections"', 'id="fg-access"'],
    'audit'=>['<h2>Activity Log</h2>', 'id="fg-connections"', 'id="fg-access"'],
];
foreach ($tab_checks as $tab=>$markers) {
    $html = a33_page($tab);
    a33_check($tab . ' tab renders its section without hidden OAuth or access forms', str_contains($html, $markers[0]) && !str_contains($html, $markers[1]) && !str_contains($html, $markers[2]) && substr_count($html, 'aria-current="page"')===1);
}
$html = a33_page('invalid-tab');
a33_check('Unknown tab falls back to Connection Setup', str_contains($html, 'id="fg-connect-title"') && !str_contains($html, 'id="fg-access"'));
$html = a33_page(['access']);
a33_check('Malformed tab falls back without PHP warnings', str_contains($html, 'id="fg-connect-title"') && !str_contains($html, 'Warning:'));
a33_check('Header displays only the version without preview wording', str_contains($html, '<span class="fg-version">' . FG_VERSION . '</span>') && !str_contains($html, 'Staging Preview'));

// Returning to the dedicated Access tab must preserve failed edits and merge current finance.
$state = a33_state(); $state['finance_mappings']=['custom'=>['fee_key'=>'_fixture_fee','fee_divisor'=>100]]; update_option('fg_settings', $state, false);
$before = a33_state();
$url = a33_submit('save', ['change_mode'=>'reviewed', 'oauth_enabled'=>'1', 'origins'=>'https://bad.example/path']);
a33_check('Access validation errors return to Access Controls without mutation', a33_tab($url)==='access' && a33_query($url, 'settings_error')==='1' && a33_state()===$before);
$html = a33_page('access', ['settings_error'=>'1']);
a33_check('Access validation error displays the rejected origin and unsaved selection', str_contains($html, 'https://bad.example/path') && str_contains($html, 'Compare With Current Saved Settings') && (bool) preg_match('/id="fg-mode-reviewed"[^>]*checked=/', $html));
$url = a33_submit('save', ['change_mode'=>'readonly', 'oauth_enabled'=>'1', 'sensitive'=>'1', 'origins'=>'']);
a33_check('Successful Access save returns to Access Controls and preserves finance mappings', a33_tab($url)==='access' && a33_query($url, 'saved')==='changed' && !a33_state()['writes'] && a33_state()['finance_mappings']===$before['finance_mappings']);
$url = a33_submit('save', ['change_mode'=>'readonly', 'oauth_enabled'=>'1', 'sensitive'=>'1', 'origins'=>'']);
a33_check('Unchanged Access save reports unchanged on its own tab', a33_tab($url)==='access' && a33_query($url, 'saved')==='unchanged');
$url = a33_submit('disable_connection');
a33_check('Disable button returns to Connection Setup and disables MCP', a33_tab($url)==='connect' && !a33_state()['enabled']);
$url = a33_submit('quick_connect', ['settings_version'=>'']);
a33_check('Enable error stays in Connection Setup', a33_tab($url)==='connect' && a33_query($url, 'settings_error')==='1');
$html = a33_page('connect', ['settings_error'=>'1']);
a33_check('Connection Setup renders action error without moving in the Access form', str_contains($html, 'notice-error') && !str_contains($html, 'id="fg-access"'));
a33_submit('quick_connect');
$url = a33_submit('disable_connection', ['settings_version'=>'']);
a33_check('Disable error stays in Connection Setup and leaves MCP enabled', a33_tab($url)==='connect' && a33_query($url, 'settings_error')==='1' && a33_state()['enabled']);
$second_admin = wp_create_user('admin33-extra', 'fixture-password', 'admin33-extra@example.test');
get_user_by('id', $second_admin)->set_role('administrator');
$enabled = FG_Settings::enable_for_user((int) $second_admin, FG_Settings::fingerprint('connection'));
a33_check('Additional account fixture is explicitly enabled', !is_wp_error($enabled));
$url = a33_submit('remove_account', ['user_id'=>(string) $second_admin]);
a33_check('Account removal returns to Access Controls', a33_tab($url)==='access' && !in_array($second_admin, a33_state()['users'], true));
$url = a33_submit('remove_account', ['user_id'=>(string) $admin_id, 'settings_version'=>'']);
a33_check('Account removal errors return to Access Controls', a33_tab($url)==='access' && a33_query($url, 'settings_error')==='1');
delete_transient('fg_connection_form_' . $admin_id);

// Clean existing grants through production revocation before destructive-action tests.
FG_OAuth::revoke_all();
FG_OAuth::clear_connections(false);
$active = a33_grant('active', 0, 'Active ChatGPT');
$inactive = a33_grant('revoked', 0, 'Revoked Claude');
$subscriber = wp_create_user('admin33-subscriber', 'fixture-password', 'admin33-subscriber@example.test');
get_user_by('id', $subscriber)->set_role('subscriber');
foreach (['delete_connection', 'clear_connections'] as $action) {
    foreach (['GET', 'missing_nonce', 'bad_nonce', 'subscriber'] as $scenario) {
        wp_set_current_user($scenario==='subscriber' ? $subscriber : $admin_id);
        $_SERVER['REQUEST_METHOD'] = $scenario==='GET' ? 'GET' : 'POST';
        $_POST = ['grant_id'=>$inactive['id'], 'clear_all'=>'1'];
        if ($scenario!=='missing_nonce') { $_POST['_wpnonce'] = $scenario==='bad_nonce' ? 'bad' : wp_create_nonce('fg_' . $action); }
        $_REQUEST = $_POST; $before_count = a33_count(); $blocked=false;
        try { do_action('admin_post_fg_' . $action); }
        catch (A33_Die $error) { $blocked=true; }
        catch (A33_Redirect $redirect) { $blocked=false; }
        a33_check($action . ' rejects ' . $scenario . ' before cleanup', $blocked && a33_count()===$before_count);
    }
}
wp_set_current_user($admin_id);
foreach (['0', '', 'true', ['1'], 1] as $flag) {
    $before_count=a33_count(); $blocked=false;
    try { a33_submit('clear_connections', ['clear_all'=>$flag]); }
    catch (A33_Die $error) { $blocked=$error->getCode()===400; }
    a33_check('Malformed clear-all flag is rejected: ' . wp_json_encode($flag), $blocked && a33_count()===$before_count);
}
$blocked=false;
try { a33_submit('delete_connection', ['grant_id'=>['unexpected']]); }
catch (A33_Die $error) { $blocked=$error->getCode()===400; }
a33_check('Malformed individual ID is rejected before cleanup', $blocked && a33_count()===2);
$blocked=false;
try { a33_submit('delete_connection', ['grant_id'=>$active['id']]); }
catch (A33_Die $error) { $blocked=$error->getCode()===409; }
a33_check('Deleting a still-active connection returns conflict without removing it', $blocked && a33_count()===2);
$html = a33_page('connections');
a33_check('Connections tab offers inactive cleanup and explicit revoke-and-clear action', str_contains($html, 'name="action" value="fg_clear_connections"') && str_contains($html, 'name="clear_all" value="1"') && str_contains($html, 'name="action" value="fg_delete_connection"'));
$url = a33_submit('delete_connection', ['grant_id'=>$inactive['id']]);
a33_check('Delete removes one inactive record and returns the real count to Connections', a33_tab($url)==='connections' && a33_query($url, 'connections_cleared')==='1' && a33_count()===1);
$html = a33_page('connections', ['connections_cleared'=>'1']);
a33_check('Cleanup notice reports removed records and retained activity history', str_contains($html, '1 connection record(s) removed') && str_contains($html, 'Activity Log entries are retained'));
$url = a33_submit('delete_connection', ['grant_id'=>$inactive['id']]);
a33_check('Deleting an already removed row reports zero instead of another deletion', a33_tab($url)==='connections' && a33_query($url, 'connections_cleared')==='0');
a33_grant('revoked'); a33_grant('active', time()-60, 'Expired Claude');
$epoch = get_option('fg_oauth_epoch');
$url = a33_submit('clear_connections');
a33_check('Clear Inactive removes revoked and expired rows while retaining active access', a33_query($url, 'connections_cleared')==='2' && a33_count()===1 && get_option('fg_oauth_epoch')===$epoch);
$url = a33_submit('revoke_connection', ['grant_id'=>$active['id']]);
a33_check('Individual revoke returns to Connections and keeps its history row', a33_tab($url)==='connections' && a33_query($url, 'revoked')==='1' && a33_count()===1);
$another = a33_grant('active');
$url = a33_submit('revoke_connection', ['revoke_all'=>'1']);
a33_check('Revoke All returns to Connections without clearing history', a33_tab($url)==='connections' && a33_count()===2);
$url = a33_submit('clear_connections', ['clear_all'=>'1']);
a33_check('Revoke And Clear All reports exact record count and keeps MCP enabled', a33_tab($url)==='connections' && a33_query($url, 'connections_cleared')==='2' && a33_count()===0 && a33_state()['enabled']);
$audit_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . FG_Core::table('audit'));
a33_check('Cleanup remains recorded in the Activity Log', $audit_count>0 && (int) $wpdb->get_var("SELECT COUNT(*) FROM " . FG_Core::table('audit') . " WHERE tool='gateway_oauth_cleanup'")>=4);

// Real storage errors must not redirect to a false success notice.
$inactive = a33_grant('revoked');
$failure = static fn($sql) => str_starts_with(ltrim($sql), 'DELETE') && str_contains($sql, FG_Core::table('oauth_grants')) ? 'DELETE FROM a33_deliberately_missing_table' : $sql;
add_filter('query', $failure); $blocked=false;
try { a33_submit('delete_connection', ['grant_id'=>$inactive['id']]); }
catch (A33_Die $error) { $blocked=$error->getCode()===503; }
finally { remove_filter('query', $failure); }
a33_check('Failed database deletion returns 503 without a false success redirect', $blocked && a33_count()===1);
$failure = static fn($sql) => str_starts_with(ltrim($sql), 'INSERT') && str_contains($sql, FG_Core::table('audit')) ? 'INSERT INTO a33_deliberately_missing_table (id) VALUES (1)' : $sql;
add_filter('query', $failure); $blocked=false;
try { a33_submit('delete_connection', ['grant_id'=>$inactive['id']]); }
catch (A33_Die $error) { $blocked=$error->getCode()===503 && str_contains($error->getMessage(), 'cleanup completed') && str_contains($error->getMessage(), 'activity entry could not be saved'); }
finally { remove_filter('query', $failure); }
a33_check('Audit failure truthfully reports completed cleanup rather than suggesting no change', $blocked && a33_count()===0);
a33_check('Cleanup failure testing does not erase earlier Activity Log entries', (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . FG_Core::table('audit'))===$audit_count);
$html = a33_page('connections', ['connections_cleared'=>'<script>alert(1)</script>']);
a33_check('Malformed cleanup-count query cannot render an injected notice', !str_contains($html, '<script>alert(1)</script>') && !str_contains($html, 'connection record(s) removed'));

if (defined('FG_ADMIN_033_FIXTURE_DIR')) {
    if (!is_dir(FG_ADMIN_033_FIXTURE_DIR)) { mkdir(FG_ADMIN_033_FIXTURE_DIR, 0777, true); }
    a33_grant('revoked', 0, 'Revoked Claude'); a33_grant('active', 0, 'Active ChatGPT');
    foreach (['connect', 'connections', 'access', 'finance'] as $tab) {
        file_put_contents(FG_ADMIN_033_FIXTURE_DIR . '/' . $tab . '.html', a33_page($tab));
    }
}
echo wp_json_encode(['passed'=>count($cases), 'total'=>count($cases), 'database_class'=>get_class($wpdb), 'cases'=>$cases], JSON_PRETTY_PRINT);
