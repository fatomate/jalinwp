<?php
// Disposable WordPress integration tests. HTTP routing and client flows have separate suites.
require_once '/wordpress/wp-load.php';
require_once '/wordpress/wp-admin/includes/template.php';
define('FG_VERSION', '0.3.0');
define('FG_FILE', '/wordpress/wp-content/plugins/fames-mcp-gateway/fames-mcp-gateway.php');
foreach (['core', 'settings', 'oauth', 'discovery', 'connection-trace', 'admin'] as $module) { require_once '/wordpress/wp-content/plugins/fames-mcp-gateway/includes/class-' . $module . '.php'; }
final class FG_Connection {
    public static function diagnostics(): array { return ['checks' => [['label' => 'OAuth discovery', 'status' => 'pass', 'detail' => 'PRIVATE-SENTINEL'], ['label' => 'GET sign-in challenge', 'status' => 'pass', 'detail' => 'PRIVATE-SENTINEL']], 'checked_at' => gmdate('c')]; }
}
FG_Core::activate(); FG_Connection_Trace::boot(); FG_Admin::boot();
$admin = (int) get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
wp_set_current_user($admin);
update_option('home', 'https://fixture.example'); update_option('siteurl', 'https://fixture.example'); update_option('permalink_structure', '/%postname%/');
class FG_Trace_Redirect extends RuntimeException {}
add_filter('wp_redirect', static function ($url) { throw new FG_Trace_Redirect($url); });
$die = static fn() => static function ($message, $title = '', $args = []) { throw new RuntimeException(wp_strip_all_tags((string) $message), (int) ($args['response'] ?? 0)); };
add_filter('wp_die_handler', $die); add_filter('wp_die_ajax_handler', $die);
$cases = [];
function trace_assert(string $name, bool $pass): void { global $cases; $cases[] = compact('name', 'pass'); if (!$pass) { throw new RuntimeException('Failed: ' . $name); } }
function trace_request(string $route = '/fames-mcp/v1/oauth/register', string $method = 'POST'): WP_REST_Request {
    $request = new WP_REST_Request($method, $route);
    $request->set_header('Authorization', 'Bearer PRIVATE-SENTINEL');
    $request->set_header('Cookie', 'PRIVATE-SENTINEL'); $request->set_header('User-Agent', 'PRIVATE-SENTINEL');
    $request->set_body_params(['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'state' => 'PRIVATE-SENTINEL', 'client_id' => 'PRIVATE-SENTINEL', 'client_secret' => 'PRIVATE-SENTINEL']);
    return $request;
}
function trace_capture(WP_REST_Request $request, int $status = 400, string $error = 'invalid_redirect_uri'): WP_REST_Response {
    $response = new WP_REST_Response(['error' => $error, 'error_description' => 'PRIVATE-SENTINEL', 'access_token' => 'PRIVATE-SENTINEL'], $status);
    trace_assert('Capture leaves response object unchanged', FG_Connection_Trace::capture($response, null, $request) === $response);
    return $response;
}
function trace_submit(string $method, string $nonce): string {
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['_wpnonce' => wp_create_nonce($nonce)]; $_REQUEST = $_POST;
    try { FG_Admin::$method(); } catch (FG_Trace_Redirect $redirect) { return $redirect->getMessage(); }
    throw new RuntimeException('Expected redirect');
}
$queries = [];
$query_watch = static function ($query) use (&$queries) { if (preg_match('/\A(?:UPDATE|INSERT|DELETE|REPLACE)\b/i', $query)) { $queries[] = $query; } return $query; };
add_filter('query', $query_watch);
trace_capture(trace_request());
trace_assert('Trace is off by default with no request writes', get_option('fg_connection_trace') === false && $queries === []);
remove_filter('query', $query_watch);
trace_assert('Admin POST starts a trace and redirects to support section', str_contains(trace_submit('start_connection_trace', 'fg_start_connection_trace'), '#fg-trace'));
$state = FG_Connection_Trace::state();
trace_assert('Trace expires in fifteen minutes', $state['active'] && abs($state['expires'] - time() - 900) <= 1);
trace_assert('Cleanup is scheduled', (bool) wp_next_scheduled('fg_connection_trace_cleanup'));
trace_capture(trace_request());
$report = FG_Connection_Trace::report(); $event = $report['trace']['events'][0] ?? [];
trace_assert('Registration status error scheme and Claude family are captured', ($event['stage'] ?? '') === 'registration' && $event['status'] === 400 && $event['error'] === 'invalid_redirect_uri' && $event['auth_scheme'] === 'bearer' && $event['callback_family'] === 'claude');
trace_assert('Request and response secrets do not reach option or report', !str_contains(wp_json_encode(get_option('fg_connection_trace')), 'PRIVATE-SENTINEL') && !str_contains(wp_json_encode($report), 'PRIVATE-SENTINEL'));
$before = get_option('fg_connection_trace'); trace_capture(trace_request('/wp/v2/users'));
trace_assert('Unrelated REST routes are not captured', get_option('fg_connection_trace') === $before);

foreach (['https://chatgpt.com/connector_platform_oauth_redirect' => 'chatgpt', 'https://claude.com/api/mcp/auth_callback' => 'unsupported', 'https://claude.ai/api/mcp/auth_callback?code=PRIVATE-SENTINEL' => 'unsupported', 'https://chatgpt.com/connector/oauth/' . str_repeat('a', 161) => 'unsupported'] as $uri => $family) {
    update_option('fg_connection_trace_slot', '0', false);
    $request = trace_request(); $request->set_body_params(['redirect_uris' => [$uri]]); trace_capture($request);
    $events = FG_Connection_Trace::report()['trace']['events'];
    trace_assert('Callback classified without saving raw URL: ' . $family, end($events)['callback_family'] === $family);
}
update_option('fg_connection_trace_slot', '0', false);
$request = trace_request('/fames-mcp/v1/mcp', 'GET'); $request->set_header('Authorization', 'Basic PRIVATE-SENTINEL');
trace_capture($request, 403, 'fg_origin'); $events = FG_Connection_Trace::report()['trace']['events'];
trace_assert('Gateway errors retain fixed diagnostic category', end($events)['error'] === 'fg_origin' && end($events)['auth_scheme'] === 'basic');

// At most four stored events in one second, while allowing a rapid protocol burst.
FG_Connection_Trace::start();
$base = time() * 4;
update_option('fg_connection_trace_slot', (string) ($base + 3), false);
trace_capture(trace_request()); trace_capture(trace_request());
$burst = FG_Connection_Trace::report()['trace']['events'];
trace_assert('Atomic fixed counter enforces burst ceiling', count($burst) === 1 || time() * 4 !== $base);
// Force bucket availability between synthetic events to exercise the ring independent of time.
for ($i = 0; $i < 55; $i++) { update_option('fg_connection_trace_slot', '0', false); FG_Connection_Trace::capture(new WP_REST_Response([], 200), null, trace_request()); }
trace_assert('Ring retains no more than fifty events', count(FG_Connection_Trace::report()['trace']['events']) === 50);
$events = FG_Connection_Trace::report()['trace']['events'];
trace_assert('No-error category is stable through export', end($events)['error'] === 'none');
$stored = get_option('fg_connection_trace'); $stored['expires'] = time() - 1; update_option('fg_connection_trace', $stored, false);
$queries = []; add_filter('query', $query_watch); trace_capture(trace_request()); remove_filter('query', $query_watch);
trace_assert('Expired trace performs no request writes', $queries === [] && count(FG_Connection_Trace::report()['trace']['events']) === 50 && !FG_Connection_Trace::state()['active']);

FG_Connection_Trace::remember_checks(['checks' => [['label' => 'GET sign-in challenge', 'status' => 'fail', 'detail' => 'PRIVATE-SENTINEL'], ['label' => 'Proactive MCP resource discovery', 'status' => 'pass'], ['label' => 'PRIVATE-SENTINEL', 'status' => 'pass']]]);
$report = FG_Connection_Trace::report();
trace_assert('Only fixed check labels and statuses are stored', count($report['last_checks']['checks']) === 2 && !str_contains(wp_json_encode($report['last_checks']), 'PRIVATE-SENTINEL'));
$checks = get_option('fg_connection_trace_checks'); $checks['checks'][0]['detail'] = 'PRIVATE-SENTINEL'; $checks['extra'] = 'PRIVATE-SENTINEL'; update_option('fg_connection_trace_checks', $checks, false);
$stored = get_option('fg_connection_trace'); $stored['events'][0]['account'] = 'PRIVATE-SENTINEL'; $stored['events'][0]['error'] = 'PRIVATE-SENTINEL'; update_option('fg_connection_trace', $stored, false);
trace_assert('Export reprojects stored data to redact extra fields', !str_contains(wp_json_encode(FG_Connection_Trace::report()), 'PRIVATE-SENTINEL'));
$manual = FG_Connection_Trace::manual_settings();
trace_assert('Manual config uses canonical endpoints and mcp scope', $manual['server_url'] === FG_OAuth::resource() && $manual['issuer'] === FG_OAuth::issuer() && $manual['authorization_endpoint'] === FG_OAuth::authorization_metadata()['authorization_endpoint'] && $manual['scope'] === 'mcp');
trace_assert('Report includes proactive URL and explains trace limitations', $report['proactive_resource_metadata_url'] === FG_Discovery::protected_resource_url() && str_contains(implode(' ', $report['notes']), 'blocked before PHP'));

$_GET = ['tab' => 'connect']; ob_start(); FG_Admin::page(); $html = ob_get_clean();
file_put_contents('/test-results/connection-trace-render.html', $html);
trace_assert('Admin offers both AI clients and manual readonly settings', str_contains($html, 'ChatGPT or Claude') && str_contains($html, 'Advanced OAuth Settings') && str_contains($html, 'id="fg-oauth-scope"') && str_contains($html, 'readonly value="mcp"'));
trace_assert('Trace start and report download forms use POST actions and nonces', str_contains($html, 'value="fg_start_connection_trace"') && str_contains($html, 'value="fg_connection_report"') && substr_count($html, 'name="_wpnonce"') >= 2);
trace_assert('UI makes pre-PHP and static-file blind spots explicit', str_contains($html, 'Requests blocked before PHP, static discovery files and browser sign-in/consent are not captured'));

foreach (['start_connection_trace' => 'fg_start_connection_trace', 'connection_report' => 'fg_connection_report'] as $method => $nonce) {
    foreach (['GET', 'nonce', 'user'] as $mode) {
        wp_set_current_user($mode === 'user' ? 0 : $admin);
        $_SERVER['REQUEST_METHOD'] = $mode === 'GET' ? 'GET' : 'POST'; $_POST = ['_wpnonce' => $mode === 'nonce' ? 'invalid' : wp_create_nonce($nonce)]; $_REQUEST = $_POST;
        $blocked = false;
        try { FG_Admin::$method(); } catch (RuntimeException $error) { $blocked = !$error instanceof FG_Trace_Redirect; }
        trace_assert($method . ' rejects ' . $mode, $blocked);
    }
}
wp_set_current_user(0);
foreach (['start', 'report', 'state'] as $method) { $blocked = false; try { FG_Connection_Trace::$method(); } catch (RuntimeException $error) { $blocked = $error->getCode() === 403; } trace_assert('Trace helper requires admin: ' . $method, $blocked); }
wp_set_current_user($admin);
$stored = get_option('fg_connection_trace'); $stored['started'] = time() - 86401; update_option('fg_connection_trace', $stored, false);
FG_Connection_Trace::cleanup();
trace_assert('Expired retained trace is removed', get_option('fg_connection_trace') === false && get_option('fg_connection_trace_slot') === false);
FG_Connection_Trace::start();
$fail_queries = static function ($query) { if (str_contains($query, 'fg_connection_trace_slot') && str_starts_with($query, 'UPDATE')) { throw new RuntimeException('simulated storage failure'); } return $query; };
add_filter('query', $fail_queries); trace_capture(trace_request()); remove_filter('query', $fail_queries);
trace_assert('Trace storage exception does not alter gateway response', count(FG_Connection_Trace::report()['trace']['events']) === 0);

delete_option('fg_connection_trace'); update_option('fg_connection_trace_slot', '9', false);
$fail_rate = static fn($new, $old) => $old;
add_filter('pre_update_option_fg_connection_trace_slot', $fail_rate, 10, 2);
trace_assert('Failed rate-row persistence does not activate a new trace', !FG_Connection_Trace::start() && get_option('fg_connection_trace') === false);
remove_filter('pre_update_option_fg_connection_trace_slot', $fail_rate, 10);
FG_Connection_Trace::start();
$stored = get_option('fg_connection_trace'); $stored['started'] = time() - 86401; update_option('fg_connection_trace', $stored, false);
FG_Connection_Trace::remember_checks(FG_Connection::diagnostics());
wp_clear_scheduled_hook('fg_connection_trace_cleanup'); wp_schedule_single_event(time() - 1, 'fg_connection_trace_cleanup');
FG_Connection_Trace::cleanup();
trace_assert('Cleanup reschedules newer check summaries after an older trace expires', get_option('fg_connection_trace') === false && (bool) get_option('fg_connection_trace_checks') && wp_next_scheduled('fg_connection_trace_cleanup') > time() + 86390);
trace_assert('Trace is attached to final REST response filter', has_filter('rest_post_dispatch', [FG_Connection_Trace::class, 'capture']) === PHP_INT_MAX);

// AJAX response exits through wp_die; intercept only in this isolated fixture.
define('DOING_AJAX', true); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['_ajax_nonce' => wp_create_nonce('fg_connection_check')]; $_REQUEST = $_POST;
ob_start(); try { FG_Admin::connection_check_ajax(); } catch (RuntimeException $error) {} $ajax = ob_get_clean();
trace_assert('AJAX diagnostics persist per-admin result and sanitized report summary', is_array(get_transient('fg_connection_check_' . $admin)) && count(FG_Connection_Trace::report()['last_checks']['checks']) === 2 && str_contains($ajax, '"success":true'));
echo wp_json_encode(['passed' => count($cases), 'total' => count($cases), 'cases' => $cases], JSON_PRETTY_PRINT);
