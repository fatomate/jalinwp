<?php
/** OAuth integration/security cases. Never run this against a real WordPress site. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { exit('Disposable test harness required.'); }
require_once '/wordpress/wp-load.php';
foreach (['core', 'tools', 'auth', 'approvals', 'oauth', 'server'] as $fg_oauth_module) {
    require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-' . $fg_oauth_module . '.php';
}
if (!defined('FG_VERSION')) { define('FG_VERSION', '0.2.2-test'); }
if (!defined('REST_REQUEST')) { define('REST_REQUEST', true); }
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REMOTE_ADDR'] = '192.0.2.25';
update_option('home', 'https://gateway.example.test');
update_option('siteurl', 'https://gateway.example.test');
global $wp_rewrite;
$wp_rewrite->set_permalink_structure('/%postname%/');
FG_Core::activate();
FG_OAuth::install();
FG_Auth::boot();
FG_OAuth::boot();
$fg_oauth_results = [];
$fg_oauth_admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$fg_oauth_settings = ['enabled' => true, 'oauth_enabled' => true, 'writes' => true, 'sensitive' => true, 'users' => [$fg_oauth_admin->ID], 'origins' => [], 'finance_mappings' => []];
update_option('fg_settings', $fg_oauth_settings);
wp_set_current_user($fg_oauth_admin->ID);
$fg_oauth_effects = 0;

function fg_oauth_assert(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function fg_oauth_case(string $name, callable $case): void {
    global $fg_oauth_results, $fg_oauth_settings, $fg_oauth_admin, $wpdb;
    $database = $wpdb;
    try { $case(); $fg_oauth_results[] = ['name' => $name, 'pass' => true]; }
    catch (Throwable $error) { $fg_oauth_results[] = ['name' => $name, 'pass' => false, 'error' => $error->getMessage()]; }
    finally {
        $wpdb = $database;
        update_option('fg_settings', $fg_oauth_settings);
        $_SERVER['HTTPS'] = 'on'; $_SERVER['SERVER_PORT'] = '443'; $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_ORIGIN']);
        $_GET = []; $_POST = []; $_REQUEST = [];
        wp_set_current_user($fg_oauth_admin->ID);
        // Each case represents separate HTTP requests; isolate fixed-window limits too.
        $wpdb->query('DELETE FROM ' . FG_Core::table('limits'));
    }
}
function fg_oauth_request(string $path, array $params = [], string $method = 'POST', array $headers = []): WP_REST_Request {
    $request = new WP_REST_Request($method, '/jalin-mcp/v1' . $path);
    if ($method === 'GET') { $request->set_query_params($params); }
    elseif ($path === '/oauth/register' || $path === '/mcp') {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($params));
    } else {
        $request->set_header('Content-Type', 'application/x-www-form-urlencoded');
        $request->set_body_params($params);
        $request->set_body(http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
    foreach ($headers as $name => $value) { $request->set_header($name, $value); }
    return $request;
}
function fg_oauth_dispatch(string $path, array $params = [], string $method = 'POST', array $headers = []): WP_REST_Response {
    return rest_ensure_response(rest_do_request(fg_oauth_request($path, $params, $method, $headers)));
}
function fg_oauth_ok(WP_REST_Response $response, string $operation): array {
    fg_oauth_assert($response->get_status() >= 200 && $response->get_status() < 300, $operation . ' returned HTTP ' . $response->get_status() . ' (' . ($response->get_data()['error'] ?? $response->get_data()['code'] ?? 'unexpected response') . ').');
    return $response->get_data();
}
function fg_oauth_error(WP_REST_Response $response, string $expected): void {
    fg_oauth_assert($response->get_status() >= 400, 'Rejected OAuth input unexpectedly succeeded.');
    fg_oauth_assert(($response->get_data()['error'] ?? '') === $expected, 'Unexpected OAuth error; expected ' . $expected . '.');
}
function fg_oauth_client(string $auth = 'none', array $override = []): array {
    return fg_oauth_ok(fg_oauth_dispatch('/oauth/register', array_replace([
        'client_name' => 'Disposable OAuth integration fixture',
        'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => $auth,
        'scope' => 'mcp',
    ], $override)), 'Client registration');
}
function fg_oauth_pkce(): array {
    $verifier = str_repeat('p', 43);
    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}
function fg_oauth_session(int $user_id): void {
    wp_set_current_user($user_id);
    $expires = time() + HOUR_IN_SECONDS;
    $session = WP_Session_Tokens::get_instance($user_id)->create($expires);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user_id, $expires, 'logged_in', $session);
    fg_oauth_assert(wp_get_session_token() === $session, 'Fixture did not establish a real WordPress login session.');
}
function fg_oauth_authorization_params(array $client, array $override = []): array {
    [, $challenge] = fg_oauth_pkce();
    return array_replace(['response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => $client['redirect_uris'][0],
        'resource' => FG_OAuth::resource(), 'scope' => 'mcp', 'state' => 'synthetic-csrf-state', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'], $override);
}
final class FG_OAuth_Test_Redirect extends RuntimeException {
    public string $location;
    public function __construct(string $location) { parent::__construct('Synthetic redirect captured.'); $this->location = $location; }
}
/** Interleave a second operation at a real WordPress database boundary. */
final class FG_OAuth_Test_DB_Proxy {
    private $database;
    private $before;
    public function __construct($database, callable $before) { $this->database = $database; $this->before = $before; }
    public function __get($name) { return $this->database->$name; }
    public function __set($name, $value) { $this->database->$name = $value; }
    public function __call($name, $arguments) {
        $override = ($this->before)($name, $arguments);
        return is_array($override) ? $override[0] : $this->database->$name(...$arguments);
    }
}
function fg_oauth_submit(string $id, string $decision = 'allow', ?string $nonce = null, array $extra = []): string {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array_merge($extra, ['request_id' => $id, 'decision' => $decision, '_wpnonce' => $nonce ?? wp_create_nonce('fg_oauth_consent_' . $id)]);
    $_REQUEST = $_POST;
    $redirect = static function($location) { throw new FG_OAuth_Test_Redirect($location); };
    $die = static fn() => static function($message) { throw new RuntimeException(wp_strip_all_tags((string) $message)); };
    add_filter('wp_redirect', $redirect, 9999);
    add_filter('wp_die_handler', $die);
    try { FG_OAuth::authorize_page(); }
    catch (FG_OAuth_Test_Redirect $result) { return $result->location; }
    finally { remove_filter('wp_redirect', $redirect, 9999); remove_filter('wp_die_handler', $die); }
    throw new RuntimeException('Consent handler did not redirect.');
}
function fg_oauth_code(array $client, array $override = [], ?int $user_id = null): array {
    global $fg_oauth_admin;
    fg_oauth_session($user_id ?? $fg_oauth_admin->ID);
    $params = fg_oauth_authorization_params($client, $override);
    $consent = FG_OAuth::begin_consent($params);
    fg_oauth_assert(!is_wp_error($consent), 'Could not begin the disposable consent request.');
    $location = fg_oauth_submit($consent['id']);
    parse_str(wp_parse_url($location, PHP_URL_QUERY) ?? '', $query);
    fg_oauth_assert(isset($query['code']) && ($query['state'] ?? '') === $params['state'], 'Consent did not issue a code with the original state.');
    fg_oauth_assert(($query['iss'] ?? '') === FG_OAuth::issuer(), 'Authorization redirect did not bind the issuer.');
    return ['code' => $query['code'], 'consent_id' => $consent['id'], 'location' => $location];
}
function fg_oauth_tokens(array $client, ?int $user_id = null): array {
    [$verifier] = fg_oauth_pkce();
    $authorized = fg_oauth_code($client, [], $user_id);
    $params = fg_oauth_token_params($client, $authorized['code'], $verifier);
    $headers = [];
    if (($client['token_endpoint_auth_method'] ?? 'none') === 'client_secret_post') { $params['client_secret'] = $client['client_secret']; }
    if (($client['token_endpoint_auth_method'] ?? 'none') === 'client_secret_basic') { $headers['Authorization'] = 'Basic ' . base64_encode($client['client_id'] . ':' . $client['client_secret']); unset($params['client_id']); }
    return fg_oauth_ok(fg_oauth_dispatch('/oauth/token', $params, 'POST', $headers), 'Authorization-code exchange');
}
function fg_oauth_refresh(array $client, string $refresh, array $override = []): WP_REST_Response {
    return fg_oauth_dispatch('/oauth/token', array_replace(['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $client['client_id'], 'resource' => FG_OAuth::resource()], $override));
}
function fg_oauth_token_params(array $client, string $code, string $verifier): array {
    return ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier,
        'client_id' => $client['client_id'], 'redirect_uri' => $client['redirect_uris'][0], 'resource' => FG_OAuth::resource()];
}
function fg_oauth_mcp(string $token, string $method, array $params = []): WP_REST_Response {
    wp_set_current_user(0);
    return fg_oauth_dispatch('/mcp', ['jsonrpc' => '2.0', 'id' => 'oauth-integration', 'method' => $method, 'params' => $params ?: (object) []], 'POST', [
        'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json, text/event-stream',
    ]);
}
function fg_oauth_wire(string $route, array $params, array $headers = [], string $method = 'POST'): array {
    global $HTTP_RAW_POST_DATA;
    $_GET = []; $_POST = []; $_REQUEST = [];
    $_SERVER['REQUEST_METHOD'] = $method;
    $url = rest_url(ltrim($route, '/'));
    $query = (string) wp_parse_url($url, PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = wp_parse_url($url, PHP_URL_PATH) . ($query === '' ? '' : '?' . $query);
    if ($query !== '') { parse_str($query, $_GET); $_REQUEST = $_GET; }
    $_SERVER['CONTENT_TYPE'] = $route === '/jalin-mcp/v1/mcp' ? 'application/json' : 'application/x-www-form-urlencoded';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_ORIGIN']);
    unset($_COOKIE[LOGGED_IN_COOKIE], $_COOKIE[AUTH_COOKIE], $_COOKIE[SECURE_AUTH_COOKIE]);
    foreach ($headers as $key => $value) { $_SERVER[$key] = $value; }
    $HTTP_RAW_POST_DATA = $_SERVER['CONTENT_TYPE'] === 'application/json' ? wp_json_encode($params) : http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    unset($GLOBALS['current_user'], $GLOBALS['wp_rest_application_password_status']);
    wp_get_current_user();
    $captured = null;
    $observe = static function($served, $response) use (&$captured) { $captured = $response; return $served; };
    add_filter('rest_pre_serve_request', $observe, 9999, 2);
    ob_start();
    try { rest_get_server()->serve_request($route); $body = ob_get_clean(); }
    finally { remove_filter('rest_pre_serve_request', $observe, 9999); }
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $HTTP_RAW_POST_DATA);
    fg_oauth_assert($captured instanceof WP_REST_Response, 'REST wire request did not reach the response observer.');
    return ['status' => $captured->get_status(), 'headers' => $captured->get_headers(), 'body' => json_decode($body, true), 'raw_empty' => $body === ''];
}

// No real content, orders, email, or payment side effects are used by this suite.
FG_Tools::register('oauth_fixture_read', 'Synthetic OAuth read.', FG_Tools::schema(), static fn() => ['fixture' => true], false, 'read');
FG_Tools::register('oauth_fixture_private', 'Synthetic sensitive read.', FG_Tools::schema(), static fn() => ['private_fixture' => true], false, 'read', true);
FG_Tools::register('oauth_fixture_edit', 'Synthetic capability read.', FG_Tools::schema(), static fn() => ['editor_fixture' => true], false, 'edit_posts');
FG_Tools::register('oauth_fixture_write', 'Synthetic OAuth side effect.', FG_Tools::schema(), static function() {
    global $fg_oauth_effects; ++$fg_oauth_effects; return ['fixture_effects' => $fg_oauth_effects];
}, true, 'read');
rest_get_server();
FG_OAuth::register();
FG_Server::register();

// Authorization helpers and behavioral cases follow below.

fg_oauth_case('upgrading an active 0.1 installation creates OAuth tables without altering legacy settings or data', static function(): void {
    global $wpdb, $fg_oauth_admin;
    $legacy = ['enabled' => true, 'writes' => true, 'sensitive' => true, 'users' => [$fg_oauth_admin->ID], 'origins' => ['https://existing-client.example'],
        'finance_mappings' => ['stripe' => ['fee_key' => '_existing_fee', 'fee_divisor' => 100, 'currency_key' => '_existing_currency']]];
    update_option('fg_settings', $legacy);
    $change_id = wp_generate_uuid4();
    $saved = $wpdb->insert(FG_Core::table('changes'), ['id' => $change_id, 'user_id' => $fg_oauth_admin->ID, 'credential_id' => wp_generate_uuid4(),
        'tool' => 'oauth_fixture_write', 'arguments' => '{}', 'digest' => hash('sha256', '{}'), 'status' => 'pending', 'created' => time(), 'expires' => time() + 900, 'reviewer_id' => 0]);
    fg_oauth_assert($saved !== false && FG_Core::audit('upgrade_fixture', 'preserved', $change_id), 'Could not prepare existing 0.1 records.');
    $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . FG_Core::table('changes') . ' WHERE id=%s', $change_id), ARRAY_A);
    foreach (['tokens', 'codes', 'grants', 'requests', 'clients'] as $table) { $wpdb->query('DROP TABLE IF EXISTS ' . FG_Core::table('oauth_' . $table)); }
    foreach (['fg_db_version', 'fg_oauth_schema', 'fg_oauth_epoch'] as $option) { delete_option($option); }
    FG_Core::maybe_upgrade();
    foreach (['clients', 'requests', 'grants', 'codes', 'tokens'] as $table) {
        $name = FG_Core::table('oauth_' . $table);
        fg_oauth_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) === $name, 'Active-plugin upgrade omitted an OAuth table.');
    }
    fg_oauth_assert(get_option('fg_db_version') === '0.3.0' && get_option('fg_oauth_schema') !== false && wp_is_uuid(get_option('fg_oauth_epoch')), 'Active-plugin upgrade omitted completed schema markers.');
    fg_oauth_assert(get_option('fg_settings') === $legacy && empty(FG_Core::settings()['oauth_enabled']), 'Upgrade changed existing finance/access settings or automatically enabled OAuth.');
    $after = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . FG_Core::table('changes') . ' WHERE id=%s', $change_id), ARRAY_A);
    $before['status'] = 'revoked';
    fg_oauth_assert($before === $after, 'Upgrade must preserve old proposal data while revoking requests without the current policy snapshot.');
    fg_oauth_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . FG_Core::table('audit') . ' WHERE change_id=%s', $change_id)) === 1, 'Upgrade discarded existing audit history.');
    FG_Core::maybe_upgrade();
    fg_oauth_assert(get_option('fg_settings') === $legacy, 'A repeated upgrade was not idempotent for legacy settings.');
});

fg_oauth_case('public discovery advertises the exact MCP audience and S256 OAuth endpoints', static function(): void {
    wp_set_current_user(0);
    $metadata = fg_oauth_ok(fg_oauth_dispatch('/oauth/authorization-server', [], 'GET'), 'Authorization metadata');
    fg_oauth_assert($metadata['issuer'] === untrailingslashit(rest_url('jalin-mcp/v1/oauth/issuer.json')) && $metadata['issuer'] === FG_OAuth::issuer(), 'Issuer is not the stable REST-path identifier.');
    fg_oauth_assert(($metadata['authorization_endpoint'] ?? '') === add_query_arg('action', 'fg_oauth_authorize', admin_url('admin-post.php')), 'Discovery changed the native WordPress consent endpoint.');
    foreach (['token_endpoint' => 'token', 'registration_endpoint' => 'register', 'revocation_endpoint' => 'revoke'] as $field => $path) {
        fg_oauth_assert(($metadata[$field] ?? '') === rest_url('jalin-mcp/v1/oauth/' . $path), 'Discovery endpoint does not match the configured WordPress REST URL.');
    }
    fg_oauth_assert(in_array('S256', $metadata['code_challenge_methods_supported'] ?? [], true), 'S256 is not advertised.');
    fg_oauth_assert(!in_array('plain', $metadata['code_challenge_methods_supported'] ?? [], true), 'Plain PKCE is advertised.');
    $resource = fg_oauth_ok(fg_oauth_dispatch('/oauth/protected-resource', [], 'GET'), 'Protected resource metadata');
    fg_oauth_assert($resource['resource'] === FG_OAuth::resource(), 'Discovery audience differs from the MCP endpoint.');
    fg_oauth_assert(in_array(FG_OAuth::issuer(), $resource['authorization_servers'] ?? [], true), 'Resource does not identify its authorization server.');
});

fg_oauth_case('public discovery provides OAuth metadata without claiming unsupported OpenID Connect services', static function(): void {
    wp_set_current_user(0);
    $response = fg_oauth_dispatch('/oauth/authorization-server', [], 'GET');
    $metadata = fg_oauth_ok($response, 'OAuth metadata');
    fg_oauth_assert($metadata === FG_OAuth::authorization_metadata(), 'Metadata differs from the advertised authorization server.');
    fg_oauth_assert(($response->get_headers()['Cache-Control'] ?? '') === 'no-store', 'Discovery metadata may be cached by intermediaries.');
    fg_oauth_assert(!in_array('openid', $metadata['scopes_supported'] ?? [], true) && !isset($metadata['jwks_uri']), 'OAuth discovery incorrectly claims an OIDC provider.');
    foreach (['/oauth/issuer.json/.well-known/openid-configuration', '/oauth/.well-known/openid-configuration'] as $path) {
        fg_oauth_assert(fg_oauth_dispatch($path, [], 'GET')->get_status() === 404, 'Unsupported OIDC discovery route returned an OAuth-only metadata document.');
    }
    fg_oauth_assert(fg_oauth_dispatch('/oauth/authorization-server')->get_status() >= 400, 'Metadata discovery accepted a POST request.');
});

fg_oauth_case('canonical discovery supports root, subdirectory, custom REST prefix, index permalinks and port installations', static function(): void {
    global $wp_rewrite;
    $structure = get_option('permalink_structure');
    $cases = [
        ['https://gateway.example.test', 'wp-json', 'https://gateway.example.test', '/wp-json/jalin-mcp/v1/oauth/issuer.json', '/%postname%/'],
        ['https://gateway.example.test/shop', 'wp-json', 'https://gateway.example.test', '/shop/wp-json/jalin-mcp/v1/oauth/issuer.json', '/%postname%/'],
        ['https://gateway.example.test/shop', 'api', 'https://gateway.example.test', '/shop/api/jalin-mcp/v1/oauth/issuer.json', '/%postname%/'],
        ['https://gateway.example.test:8443/shop', 'wp-json', 'https://gateway.example.test:8443', '/shop/wp-json/jalin-mcp/v1/oauth/issuer.json', '/%postname%/'],
        ['https://gateway.example.test/shop', 'wp-json', 'https://gateway.example.test', '/shop/index.php/wp-json/jalin-mcp/v1/oauth/issuer.json', '/index.php/%postname%/'],
    ];
    try {
        foreach ($cases as [$base, $prefix, $origin, $path, $permalinks]) {
            $wp_rewrite->set_permalink_structure($permalinks);
            $filter = static fn() => $prefix;
            // Playground fixes WP_HOME/WP_SITEURL; pre-option filters model actual hosting URLs.
            $base_filter = static fn() => $base;
            add_filter('pre_option_home', $base_filter, 999);
            add_filter('pre_option_siteurl', $base_filter, 999);
            add_filter('rest_url_prefix', $filter);
            try {
                $expected = [$origin . '/.well-known/oauth-authorization-server' . $path];
                fg_oauth_assert(FG_OAuth::issuer() === $origin . $path, 'Issuer lost the installation path, REST prefix or port: ' . FG_OAuth::issuer());
                fg_oauth_assert(FG_OAuth::authorization_metadata_urls() === $expected, 'Discovery URL differs from the canonical RFC 8414 location.');
                $metadata = fg_oauth_ok(fg_oauth_dispatch('/oauth/authorization-server', [], 'GET'), 'Configured discovery');
                fg_oauth_assert($metadata['issuer'] === $origin . $path && FG_OAuth::resource_metadata()['authorization_servers'] === [$origin . $path], 'Discovery documents disagree on configured issuer.');
            } finally { remove_filter('rest_url_prefix', $filter); remove_filter('pre_option_home', $base_filter, 999); remove_filter('pre_option_siteurl', $base_filter, 999); }
        }
    } finally { $wp_rewrite->set_permalink_structure($structure); }
});

fg_oauth_case('query-based REST URLs cannot enable OAuth with an invalid issuer identifier', static function(): void {
    global $wp_rewrite;
    $structure = get_option('permalink_structure');
    try {
        $wp_rewrite->set_permalink_structure('');
        fg_oauth_assert(wp_parse_url(FG_OAuth::issuer(), PHP_URL_QUERY) !== null, 'Fixture did not produce a query-based REST URL.');
        fg_oauth_assert(FG_OAuth::authorization_metadata_urls() === [], 'Query-based issuer produced invalid discovery URL candidates.');
        $response = fg_oauth_dispatch('/oauth/register', ['redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'], 'token_endpoint_auth_method' => 'none']);
        fg_oauth_assert($response->get_status() === 503, 'OAuth accepted a query-based issuer identifier.');
    } finally { $wp_rewrite->set_permalink_structure($structure); }
});

fg_oauth_case('discovery upgrade preserves settings, registrations and existing resource-bound opaque tokens', static function(): void {
    global $wpdb;
    $client = fg_oauth_client(); $tokens = fg_oauth_tokens($client);
    $settings = get_option('fg_settings'); $epoch = get_option('fg_oauth_epoch');
    $before = [];
    foreach (['clients', 'grants', 'tokens'] as $name) { $before[$name] = $wpdb->get_results('SELECT * FROM ' . FG_Core::table('oauth_' . $name) . ' ORDER BY ' . ($name === 'tokens' ? 'hash' : 'id'), ARRAY_A); }
    // v0.2.0's opaque rows have no issuer field; discovery is not a credential migration.
    FG_Core::maybe_upgrade(); FG_OAuth::maybe_upgrade();
    fg_oauth_assert(get_option('fg_settings') === $settings && get_option('fg_oauth_epoch') === $epoch, 'Discovery upgrade changed access settings or globally revoked connections.');
    foreach ($before as $name => $rows) {
        $after = $wpdb->get_results('SELECT * FROM ' . FG_Core::table('oauth_' . $name) . ' ORDER BY ' . ($name === 'tokens' ? 'hash' : 'id'), ARRAY_A);
        fg_oauth_assert($rows === $after, 'Discovery upgrade altered existing OAuth credentials.');
    }
    fg_oauth_assert(fg_oauth_mcp($tokens['access_token'], 'ping')->get_status() === 200, 'Existing opaque access token stopped working after discovery upgrade.');
    $refreshed = fg_oauth_ok(fg_oauth_refresh($client, $tokens['refresh_token']), 'Existing refresh token');
    fg_oauth_assert(fg_oauth_mcp($refreshed['access_token'], 'ping')->get_status() === 200, 'Existing connection could not refresh after discovery upgrade.');
});

fg_oauth_case('dynamic registration returns a usable public client and supported confidential clients', static function(): void {
    wp_set_current_user(0);
    foreach (['none', 'client_secret_basic', 'client_secret_post'] as $method) {
        $client = fg_oauth_client($method);
        fg_oauth_assert(is_string($client['client_id'] ?? null) && strlen($client['client_id']) > 15, 'Registration omitted its client identifier.');
        fg_oauth_assert(($client['token_endpoint_auth_method'] ?? '') === $method, 'Registration changed the requested authentication method.');
        if ($method === 'none') { fg_oauth_assert(empty($client['client_secret']), 'Public client unexpectedly received a secret.'); }
        else { fg_oauth_assert(is_string($client['client_secret'] ?? null) && strlen($client['client_secret']) >= 32, 'Confidential client received no strong secret.'); }
    }
});

fg_oauth_case('registration rejects redirect destinations outside the exact trusted callback rules', static function(): void {
    foreach ([
        'http://chatgpt.com/connector_platform_oauth_redirect',
        'https://attacker.example/connector_platform_oauth_redirect',
        'https://chatgpt.com.attacker.example/connector_platform_oauth_redirect',
        'https://chatgpt.com@attacker.example/connector_platform_oauth_redirect',
        'https://chatgpt.com/connector_platform_oauth_redirect/extra',
        'https://chatgpt.com/connector_platform_oauth_redirect?forward=https://attacker.example',
        'https://chatgpt.com/connector_platform_oauth_redirect#fragment',
        'https://chatgpt.com:444/connector_platform_oauth_redirect',
        'https://127.0.0.1/connector_platform_oauth_redirect',
        'https://*.chatgpt.com/connector_platform_oauth_redirect',
    ] as $redirect) {
        $response = fg_oauth_dispatch('/oauth/register', ['client_name' => 'Rejected callback fixture', 'redirect_uris' => [$redirect], 'token_endpoint_auth_method' => 'none']);
        fg_oauth_assert($response->get_status() >= 400, 'Untrusted registration callback was accepted.');
    }
});

fg_oauth_case('registration rejects unsupported grants and authentication methods', static function(): void {
    foreach ([['grant_types' => ['password']], ['response_types' => ['token']], ['token_endpoint_auth_method' => 'client_secret_jwt'], ['scope' => 'admin']] as $override) {
        $response = fg_oauth_dispatch('/oauth/register', array_replace(['redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'], 'token_endpoint_auth_method' => 'none'], $override));
        fg_oauth_assert($response->get_status() >= 400, 'Unsupported client capabilities were accepted.');
    }
});

fg_oauth_case('Claude registration permits the exact hosted callback and rejects lookalikes and callback suffixes', static function(): void {
    $callback = 'https://claude.ai/api/mcp/auth_callback';
    $client = fg_oauth_client('none', ['client_name' => 'Claude', 'redirect_uris' => [$callback]]);
    fg_oauth_assert($client['redirect_uris'] === [$callback] && !isset($client['client_secret']), 'Hosted Claude did not register as a public PKCE client.');
    foreach ([
        'http://claude.ai/api/mcp/auth_callback',
        'https://claude.ai.attacker.example/api/mcp/auth_callback',
        'https://claude.ai@attacker.example/api/mcp/auth_callback',
        'https://attacker.example@claude.ai/api/mcp/auth_callback',
        'https://subdomain.claude.ai/api/mcp/auth_callback',
        'https://claude.ai:444/api/mcp/auth_callback',
        'https://claude.ai/api/mcp/auth_callback/',
        'https://claude.ai/api/mcp/auth_callback/extra',
        'https://claude.ai/api/mcp/auth_callback?next=https://attacker.example',
        'https://claude.ai/api/mcp/auth_callback#fragment',
        'https://claude.ai/api/mcp/%61uth_callback',
        'https://claude.ai/api/mcp/../mcp/auth_callback',
    ] as $redirect) {
        $response = fg_oauth_dispatch('/oauth/register', ['client_name' => 'Rejected Claude callback', 'redirect_uris' => [$redirect], 'token_endpoint_auth_method' => 'none']);
        fg_oauth_error($response, 'invalid_redirect_uri');
    }
});

fg_oauth_case('hosted Claude preserves its callback through WordPress login, nonce consent, code exchange and refresh', static function(): void {
    $callback = 'https://claude.ai/api/mcp/auth_callback';
    $client = fg_oauth_client('none', ['client_name' => 'Claude', 'redirect_uris' => [$callback]]);
    wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = fg_oauth_authorization_params($client);
    $redirect = static function($location) { throw new FG_OAuth_Test_Redirect($location); };
    add_filter('wp_redirect', $redirect, 9999);
    $login = null;
    try { FG_OAuth::authorize_page(); }
    catch (FG_OAuth_Test_Redirect $result) { $login = $result->location; }
    finally { remove_filter('wp_redirect', $redirect, 9999); }
    fg_oauth_assert(is_string($login) && str_starts_with($login, wp_login_url()), 'Anonymous Claude authorization did not go to the WordPress login page.');
    parse_str(wp_parse_url($login, PHP_URL_QUERY) ?? '', $login_query);
    parse_str(wp_parse_url($login_query['redirect_to'] ?? '', PHP_URL_QUERY) ?? '', $return_query);
    fg_oauth_assert(($return_query['redirect_uri'] ?? '') === $callback && ($return_query['resource'] ?? '') === FG_OAuth::resource(), 'WordPress login lost the Claude callback or MCP audience.');
    $authorization = fg_oauth_code($client);
    fg_oauth_assert(str_starts_with($authorization['location'], $callback . '?'), 'Consent did not return to the exact registered Claude callback.');
    $headers = implode("\n", headers_list());
    fg_oauth_assert(str_contains($headers, "form-action 'self' https://claude.ai;") && !str_contains($headers, "form-action 'self' https://chatgpt.com"), 'Consent response CSP did not restrict its external destination to Claude.');
    [$verifier] = fg_oauth_pkce();
    $tokens = fg_oauth_ok(fg_oauth_dispatch('/oauth/token', fg_oauth_token_params($client, $authorization['code'], $verifier)), 'Claude authorization-code exchange');
    $catalog = fg_oauth_ok(fg_oauth_mcp($tokens['access_token'], 'tools/list'), 'Claude OAuth catalog');
    fg_oauth_assert(in_array('oauth_fixture_read', array_column($catalog['result']['tools'] ?? [], 'name'), true), 'Claude did not receive the permitted MCP catalog.');
    $refreshed = fg_oauth_ok(fg_oauth_refresh($client, $tokens['refresh_token']), 'Claude token refresh');
    fg_oauth_assert($refreshed['refresh_token'] !== $tokens['refresh_token'], 'Claude refresh token was not rotated.');
    fg_oauth_error(fg_oauth_refresh($client, $tokens['refresh_token']), 'invalid_grant');
    fg_oauth_assert(fg_oauth_mcp($refreshed['access_token'], 'ping')->get_status() === 401, 'Claude refresh replay did not revoke its grant.');
});

fg_oauth_case('consent CSP uses only the validated callback origin and stays closed for invalid destinations', static function(): void {
    $policy = new ReflectionMethod(FG_OAuth::class, 'consent_policy');
    foreach ([
        'https://claude.ai/api/mcp/auth_callback' => 'https://claude.ai',
        'https://chatgpt.com/connector_platform_oauth_redirect' => 'https://chatgpt.com',
        'https://chatgpt.com/connector/oauth/synthetic-callback' => 'https://chatgpt.com',
        'https://attacker.example/evil' => '',
        "https://claude.ai/api/mcp/auth_callback\r\nInjected: value" => '',
    ] as $callback => $origin) {
        $expected = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'" . ($origin === '' ? '' : ' ' . $origin) . "; frame-ancestors 'none'; base-uri 'none'";
        fg_oauth_assert($policy->invoke(null, $callback) === $expected, 'Consent CSP broadened beyond the validated callback origin.');
    }
});

fg_oauth_case('disabled OAuth and non-HTTPS requests cannot register clients', static function(): void {
    global $fg_oauth_settings;
    $params = ['redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'], 'token_endpoint_auth_method' => 'none'];
    update_option('fg_settings', array_replace($fg_oauth_settings, ['oauth_enabled' => false]));
    fg_oauth_assert(fg_oauth_dispatch('/oauth/register', $params)->get_status() >= 400, 'Disabled OAuth registered a client.');
    update_option('fg_settings', $fg_oauth_settings);
    $_SERVER['HTTPS'] = 'off'; $_SERVER['SERVER_PORT'] = '80';
    fg_oauth_assert(fg_oauth_dispatch('/oauth/register', $params)->get_status() >= 400, 'Plain HTTP registered a client.');
});

fg_oauth_case('cookie-only access and forged bearer tokens cannot enter MCP', static function(): void {
    global $fg_oauth_admin;
    wp_set_current_user($fg_oauth_admin->ID);
    $request = fg_oauth_request('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], 'POST', ['Accept' => 'application/json, text/event-stream']);
    fg_oauth_assert(rest_do_request($request)->get_status() === 401, 'Cookie-only administrator entered MCP.');
    fg_oauth_assert(fg_oauth_mcp(str_repeat('forged', 8), 'ping')->get_status() === 401, 'Forged bearer token entered MCP.');
    $challenge = FG_Auth::challenge();
    fg_oauth_assert(str_starts_with($challenge, 'Bearer ') && str_contains($challenge, FG_OAuth::resource_metadata_url()), 'OAuth rejection does not advertise protected-resource discovery.');
});

fg_oauth_case('real nonce-protected consent completes registration, code exchange, initialize and tool discovery', static function(): void {
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    fg_oauth_assert(($tokens['token_type'] ?? '') === 'Bearer' && ($tokens['scope'] ?? '') === 'mcp', 'Token response changed type or scope.');
    fg_oauth_assert(($tokens['expires_in'] ?? 0) > 0 && $tokens['expires_in'] <= HOUR_IN_SECONDS, 'Access token lifetime is not bounded to one hour.');
    fg_oauth_assert(is_string($tokens['refresh_token'] ?? null), 'Authorization exchange did not issue a refresh token.');
    $reply = fg_oauth_ok(fg_oauth_mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-11-25', 'clientInfo' => (object) ['name' => 'synthetic-chatgpt', 'version' => '1'], 'capabilities' => (object) []]), 'OAuth initialize');
    fg_oauth_assert(($reply['result']['protocolVersion'] ?? '') === '2025-11-25', 'Authenticated initialize did not negotiate MCP.');
    $catalog = fg_oauth_ok(fg_oauth_mcp($tokens['access_token'], 'tools/list'), 'OAuth catalog');
    fg_oauth_assert(in_array('oauth_fixture_read', array_column($catalog['result']['tools'] ?? [], 'name'), true), 'Authenticated user did not receive permitted tools.');
    $result = fg_oauth_ok(fg_oauth_mcp($tokens['access_token'], 'tools/call', ['name' => 'oauth_fixture_read', 'arguments' => (object) []]), 'OAuth read');
    fg_oauth_assert(($result['result']['structuredContent']['data']['fixture'] ?? false) === true, 'Authenticated read did not execute the permitted handler.');
});

fg_oauth_case('authorization rejects missing or downgraded PKCE and mismatched resource, scope, client and callback', static function(): void {
    $client = fg_oauth_client();
    foreach ([
        ['code_challenge_method' => 'plain'], ['code_challenge' => ''], ['code_challenge' => 'short'],
        ['resource' => 'https://attacker.example/mcp'], ['scope' => 'mcp admin'],
        ['redirect_uri' => 'https://attacker.example/callback'], ['client_id' => 'unknown-client'], ['response_type' => 'token'],
    ] as $override) {
        $checked = FG_OAuth::authorization_request(fg_oauth_authorization_params($client, $override));
        fg_oauth_assert(is_wp_error($checked), 'Invalid authorization input was accepted.');
    }
    $missing = fg_oauth_authorization_params($client); unset($missing['resource']);
    fg_oauth_assert(is_wp_error(FG_OAuth::authorization_request($missing)), 'Missing resource audience was accepted.');
});

fg_oauth_case('consent requires a real allowed WordPress login session and a valid request nonce', static function(): void {
    global $fg_oauth_admin, $fg_oauth_settings;
    $client = fg_oauth_client();
    $params = fg_oauth_authorization_params($client);
    wp_set_current_user(0); unset($_COOKIE[LOGGED_IN_COOKIE]);
    fg_oauth_assert(is_wp_error(FG_OAuth::begin_consent($params)), 'Anonymous consent was accepted.');
    wp_set_current_user($fg_oauth_admin->ID);
    fg_oauth_assert(is_wp_error(FG_OAuth::begin_consent($params)), 'Consent without a native login session was accepted.');
    fg_oauth_session($fg_oauth_admin->ID);
    update_option('fg_settings', array_replace($fg_oauth_settings, ['users' => []]));
    fg_oauth_assert(is_wp_error(FG_OAuth::begin_consent($params)), 'A user excluded from gateway access could consent.');
    update_option('fg_settings', $fg_oauth_settings);
    $consent = FG_OAuth::begin_consent($params);
    fg_oauth_assert(!is_wp_error($consent), 'Could not prepare the nonce rejection test.');
    try { fg_oauth_submit($consent['id'], 'allow', 'invalid-synthetic-nonce'); }
    catch (RuntimeException $error) {
        fg_oauth_assert(!($error instanceof FG_OAuth_Test_Redirect), 'Invalid nonce produced a callback code.');
        return;
    }
    throw new RuntimeException('Invalid nonce was accepted.');
});

fg_oauth_case('consent is bound to its initiating login session and cannot be replayed', static function(): void {
    global $fg_oauth_admin;
    $client = fg_oauth_client();
    fg_oauth_session($fg_oauth_admin->ID);
    $consent = FG_OAuth::begin_consent(fg_oauth_authorization_params($client));
    fg_oauth_assert(!is_wp_error($consent), 'Could not create session-bound consent.');
    $original_cookie = $_COOKIE[LOGGED_IN_COOKIE];
    fg_oauth_session($fg_oauth_admin->ID);
    fg_oauth_assert(is_wp_error(FG_OAuth::complete_consent($consent['id'], true)), 'A different login session completed another session\'s consent.');
    $_COOKIE[LOGGED_IN_COOKIE] = $original_cookie;
    fg_oauth_assert(!is_wp_error(FG_OAuth::complete_consent($consent['id'], true)), 'Original login session could not complete its consent.');
    fg_oauth_assert(is_wp_error(FG_OAuth::complete_consent($consent['id'], true)), 'Consent request was reusable.');
});

fg_oauth_case('consent denial returns access_denied with state and does not issue a code', static function(): void {
    global $fg_oauth_admin;
    $client = fg_oauth_client();
    fg_oauth_session($fg_oauth_admin->ID);
    $consent = FG_OAuth::begin_consent(fg_oauth_authorization_params($client));
    $location = fg_oauth_submit($consent['id'], 'deny');
    parse_str(wp_parse_url($location, PHP_URL_QUERY) ?? '', $query);
    fg_oauth_assert(($query['error'] ?? '') === 'access_denied' && !isset($query['code']) && ($query['state'] ?? '') === 'synthetic-csrf-state', 'Denied consent issued a code or lost the original state.');
});

fg_oauth_case('posted OAuth parameter changes cannot alter the server-side consent request', static function(): void {
    global $fg_oauth_admin;
    $client = fg_oauth_client();
    $other = fg_oauth_client();
    fg_oauth_session($fg_oauth_admin->ID);
    $consent = FG_OAuth::begin_consent(fg_oauth_authorization_params($client));
    // Stored request is the authority. Attacker-controlled POST fields must be ignored or rejected.
    $location = fg_oauth_submit($consent['id'], 'allow', null, ['client_id' => $other['client_id'], 'redirect_uri' => 'https://attacker.example/callback', 'resource' => 'https://attacker.example/mcp', 'scope' => 'mcp admin', 'code_challenge' => str_repeat('x', 43)]);
    fg_oauth_assert(!is_wp_error($location) && str_starts_with($location, $client['redirect_uris'][0] . '?'), 'Modified browser fields changed the registered callback.');
    parse_str(wp_parse_url($location, PHP_URL_QUERY) ?? '', $query);
    [$verifier] = fg_oauth_pkce();
    $tokens = fg_oauth_ok(fg_oauth_dispatch('/oauth/token', fg_oauth_token_params($client, $query['code'], $verifier)), 'Original consent binding');
    fg_oauth_assert(isset($tokens['access_token']), 'Modified browser fields changed the original PKCE, client or audience binding.');
});

fg_oauth_case('settings changed while consent is pending cannot widen the displayed permissions', static function(): void {
    global $fg_oauth_admin, $fg_oauth_settings;
    $client = fg_oauth_client();
    update_option('fg_settings', array_replace($fg_oauth_settings, ['writes' => false, 'sensitive' => false]));
    fg_oauth_session($fg_oauth_admin->ID);
    $consent = FG_OAuth::begin_consent(fg_oauth_authorization_params($client));
    update_option('fg_settings', $fg_oauth_settings);
    $location = FG_OAuth::complete_consent($consent['id'], true);
    // Revoking stale consent is also safe; otherwise the original flags must survive.
    if (is_wp_error($location)) { return; }
    parse_str(wp_parse_url($location, PHP_URL_QUERY) ?? '', $query);
    [$verifier] = fg_oauth_pkce();
    $tokens = fg_oauth_ok(fg_oauth_dispatch('/oauth/token', fg_oauth_token_params($client, $query['code'], $verifier)), 'Pending consent exchange');
    $identity = FG_OAuth::authenticate($tokens['access_token']);
    fg_oauth_assert(!is_wp_error($identity) && empty($identity['writes']) && empty($identity['sensitive']), 'Settings widened permissions after the consent screen was prepared.');
});

fg_oauth_case('authorization code cannot be exchanged with incorrect PKCE, client, redirect or resource', static function(): void {
    $client = fg_oauth_client();
    $other = fg_oauth_client();
    [$verifier] = fg_oauth_pkce();
    foreach ([['code_verifier' => str_repeat('x', 43)], ['client_id' => $other['client_id']], ['redirect_uri' => 'https://chatgpt.com/connector/oauth/different-callback'], ['resource' => 'https://attacker.example/mcp']] as $override) {
        $code = fg_oauth_code($client)['code'];
        $response = fg_oauth_dispatch('/oauth/token', array_replace(fg_oauth_token_params($client, $code, $verifier), $override));
        fg_oauth_assert($response->get_status() >= 400 && !isset($response->get_data()['access_token']), 'A wrongly bound authorization code was accepted.');
    }
});

fg_oauth_case('authorization code is single use and unknown codes never issue tokens', static function(): void {
    $client = fg_oauth_client();
    [$verifier] = fg_oauth_pkce();
    $params = fg_oauth_token_params($client, fg_oauth_code($client)['code'], $verifier);
    fg_oauth_ok(fg_oauth_dispatch('/oauth/token', $params), 'First code exchange');
    fg_oauth_error(fg_oauth_dispatch('/oauth/token', $params), 'invalid_grant');
    $params['code'] = str_repeat('unknown-code', 4);
    fg_oauth_error(fg_oauth_dispatch('/oauth/token', $params), 'invalid_grant');
});

fg_oauth_case('both confidential client methods work and incorrect secrets or method changes are rejected', static function(): void {
    [$verifier] = fg_oauth_pkce();
    foreach (['client_secret_basic', 'client_secret_post'] as $method) {
        $client = fg_oauth_client($method);
        $tokens = fg_oauth_tokens($client);
        fg_oauth_assert(fg_oauth_mcp($tokens['access_token'], 'ping')->get_status() === 200, 'Confidential client did not obtain a usable token.');
        $params = fg_oauth_token_params($client, fg_oauth_code($client)['code'], $verifier);
        $headers = [];
        if ($method === 'client_secret_basic') { $headers['Authorization'] = 'Basic ' . base64_encode($client['client_id'] . ':invalid-secret'); unset($params['client_id']); }
        else { $params['client_secret'] = 'invalid-secret'; }
        fg_oauth_error(fg_oauth_dispatch('/oauth/token', $params, 'POST', $headers), 'invalid_client');
        $params = fg_oauth_token_params($client, fg_oauth_code($client)['code'], $verifier);
        if ($method === 'client_secret_basic') { $params['client_secret'] = $client['client_secret']; $headers = []; }
        else { $headers = ['Authorization' => 'Basic ' . base64_encode($client['client_id'] . ':' . $client['client_secret'])]; unset($params['client_id']); }
        fg_oauth_error(fg_oauth_dispatch('/oauth/token', $params, 'POST', $headers), 'invalid_client');
    }
});

fg_oauth_case('real WordPress REST serving accepts OAuth client_secret_basic despite native Application Password authentication', static function(): void {
    global $fg_oauth_admin;
    $application_password = WP_Application_Passwords::create_new_application_password($fg_oauth_admin->ID, ['name' => 'Disposable OAuth collision fixture']);
    fg_oauth_assert(!is_wp_error($application_password) && WP_Application_Passwords::is_in_use(), 'Native Application Password authentication is not active in the collision fixture.');
    $client = fg_oauth_client('client_secret_basic');
    [$verifier] = fg_oauth_pkce();
    $params = fg_oauth_token_params($client, fg_oauth_code($client)['code'], $verifier);
    unset($params['client_id']);
    $wire = fg_oauth_wire('/jalin-mcp/v1/oauth/token', $params, [
        'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($client['client_id'] . ':' . $client['client_secret']),
        'PHP_AUTH_USER' => $client['client_id'], 'PHP_AUTH_PW' => $client['client_secret'],
    ]);
    fg_oauth_assert($wire['status'] === 200 && isset($wire['body']['access_token']), 'Native WordPress authentication prevented the OAuth Basic token exchange.');
    fg_oauth_assert(fg_oauth_mcp($wire['body']['access_token'], 'ping')->get_status() === 200, 'Wire-issued OAuth token was not usable.');
    $access = $wire['body']['access_token'];
    $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $access, 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
    $initialized = fg_oauth_wire('/jalin-mcp/v1/mcp', ['jsonrpc' => '2.0', 'id' => 'oauth-wire', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'clientInfo' => (object) ['name' => 'synthetic-wire', 'version' => '1'], 'capabilities' => (object) []]], $headers);
    fg_oauth_assert($initialized['status'] === 200 && ($initialized['body']['result']['protocolVersion'] ?? '') === '2025-11-25', 'Real REST serving did not initialize with its bearer token.');
    $notification = fg_oauth_wire('/jalin-mcp/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);
    fg_oauth_assert($notification['status'] === 202 && $notification['raw_empty'], 'OAuth notification did not return an empty 202 body.');
    $fallback_params = fg_oauth_token_params($client, fg_oauth_code($client)['code'], $verifier);
    unset($fallback_params['client_id']);
    $fallback = fg_oauth_wire('/jalin-mcp/v1/oauth/token', $fallback_params, ['PHP_AUTH_USER' => $client['client_id'], 'PHP_AUTH_PW' => $client['client_secret']]);
    fg_oauth_assert($fallback['status'] === 200 && isset($fallback['body']['access_token']), 'PHP_AUTH-only shared-hosting Basic credentials did not authenticate the OAuth client.');
});

fg_oauth_case('native Application Password bypass follows the resolved OAuth route and ignores a conflicting query parameter', static function(): void {
    global $wp;
    $original = $wp->query_vars;
    try {
        $_GET['rest_route'] = '/jalin-mcp/v1/oauth/token';
        $_SERVER['REQUEST_URI'] = wp_parse_url(rest_url('jalin-mcp/v1/oauth/token'), PHP_URL_PATH);
        $wp->query_vars['rest_route'] = '/wp/v2/users/me';
        fg_oauth_assert(apply_filters('application_password_is_api_request', true) === true, 'A spoofed OAuth query disabled native authentication for a different resolved route.');
        $wp->query_vars['rest_route'] = '/jalin-mcp/v1/oauth/token';
        fg_oauth_assert(apply_filters('application_password_is_api_request', true) === false, 'The resolved OAuth client endpoint did not isolate client Basic credentials.');
    } finally { $wp->query_vars = $original; }
});

fg_oauth_case('refresh rotates credentials while preserving grant ownership and replay revokes the token family', static function(): void {
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    $before = FG_OAuth::authenticate($tokens['access_token']);
    fg_oauth_assert(!is_wp_error($before), 'Initial access token was invalid.');
    $next = fg_oauth_ok(fg_oauth_refresh($client, $tokens['refresh_token']), 'Refresh exchange');
    fg_oauth_assert($next['access_token'] !== $tokens['access_token'] && $next['refresh_token'] !== $tokens['refresh_token'], 'Refresh did not rotate both token values.');
    $after = FG_OAuth::authenticate($next['access_token']);
    fg_oauth_assert(!is_wp_error($after) && $after['credential_id'] === $before['credential_id'], 'Refresh changed the approval-owning grant UUID.');
    fg_oauth_error(fg_oauth_refresh($client, $tokens['refresh_token']), 'invalid_grant');
    fg_oauth_assert(is_wp_error(FG_OAuth::authenticate($next['access_token'])), 'Refresh replay did not revoke the active token family.');
    fg_oauth_error(fg_oauth_refresh($client, $next['refresh_token']), 'invalid_grant');
});

fg_oauth_case('refresh rejects a different client, resource and wider scope', static function(): void {
    $client = fg_oauth_client();
    $other = fg_oauth_client();
    foreach ([['client_id' => $other['client_id']], ['resource' => 'https://attacker.example/mcp'], ['scope' => 'mcp admin']] as $override) {
        $tokens = fg_oauth_tokens($client);
        $reply = fg_oauth_refresh($client, $tokens['refresh_token'], $override);
        fg_oauth_assert($reply->get_status() >= 400 && !isset($reply->get_data()['access_token']), 'Refresh widened client, audience or scope.');
    }
});

fg_oauth_case('approval ownership survives refresh and excludes a separate authorization grant', static function(): void {
    global $fg_oauth_effects, $fg_oauth_admin;
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    $initial_effects = $fg_oauth_effects;
    $proposal = fg_oauth_ok(fg_oauth_mcp($tokens['access_token'], 'tools/call', ['name' => 'oauth_fixture_write', 'arguments' => (object) []]), 'OAuth proposal');
    $change_id = $proposal['result']['structuredContent']['data']['change_id'] ?? '';
    fg_oauth_assert($change_id !== '' && $fg_oauth_effects === $initial_effects, 'OAuth proposal caused a side effect or omitted its ID.');
    wp_set_current_user($fg_oauth_admin->ID);
    FG_Approvals::review($change_id, 'approved');
    $other_grant = fg_oauth_tokens($client);
    $denied = fg_oauth_ok(fg_oauth_mcp($other_grant['access_token'], 'tools/call', ['name' => 'gateway_apply_change', 'arguments' => ['change_id' => $change_id]]), 'Different-grant apply');
    fg_oauth_assert(($denied['result']['isError'] ?? false) === true && $fg_oauth_effects === $initial_effects, 'A fresh authorization grant applied another grant\'s approval.');
    $next = fg_oauth_ok(fg_oauth_refresh($client, $tokens['refresh_token']), 'Approval owner refresh');
    $applied = fg_oauth_ok(fg_oauth_mcp($next['access_token'], 'tools/call', ['name' => 'gateway_apply_change', 'arguments' => ['change_id' => $change_id]]), 'Refreshed owner apply');
    fg_oauth_assert(($applied['result']['structuredContent']['data']['status'] ?? '') === 'applied' && $fg_oauth_effects === $initial_effects + 1, 'Refreshed original grant could not execute its approved change exactly once.');
});

fg_oauth_case('later site settings cannot widen consented writes or sensitive reads', static function(): void {
    global $fg_oauth_settings;
    update_option('fg_settings', array_replace($fg_oauth_settings, ['writes' => false, 'sensitive' => false]));
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    update_option('fg_settings', $fg_oauth_settings);
    $catalog = fg_oauth_ok(fg_oauth_mcp($tokens['access_token'], 'tools/list'), 'Original consent catalog');
    $names = array_column($catalog['result']['tools'] ?? [], 'name');
    fg_oauth_assert(!in_array('oauth_fixture_write', $names, true) && !in_array('oauth_fixture_private', $names, true), 'Updated site settings widened the original consent catalog.');
    foreach (['oauth_fixture_write', 'oauth_fixture_private'] as $name) {
        $reply = fg_oauth_ok(fg_oauth_mcp($tokens['access_token'], 'tools/call', ['name' => $name, 'arguments' => (object) []]), 'Restricted consent call');
        fg_oauth_assert(($reply['result']['isError'] ?? false) === true, 'Updated site settings widened the original consent execution rights.');
    }
    $refreshed = fg_oauth_ok(fg_oauth_refresh($client, $tokens['refresh_token']), 'Restricted consent refresh');
    $identity = FG_OAuth::authenticate($refreshed['access_token']);
    fg_oauth_assert(!is_wp_error($identity) && empty($identity['writes']) && empty($identity['sensitive']), 'Refresh widened the original consent flags.');
});

fg_oauth_case('disabled gateway, disabled OAuth, excluded users and removed native roles deny existing access', static function(): void {
    global $fg_oauth_settings;
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    foreach ([['enabled' => false], ['oauth_enabled' => false], ['users' => []]] as $change) {
        update_option('fg_settings', array_replace($fg_oauth_settings, $change));
        fg_oauth_assert(fg_oauth_mcp($tokens['access_token'], 'ping')->get_status() >= 400, 'A disabled access control accepted an existing bearer token.');
        fg_oauth_assert(fg_oauth_refresh($client, $tokens['refresh_token'])->get_status() >= 400, 'A disabled access control allowed a refresh.');
        update_option('fg_settings', $fg_oauth_settings);
    }
    $id = wp_create_user('oauth-fixture-editor', wp_generate_password(), 'oauth-editor@example.test');
    $user = get_user_by('id', $id); $user->set_role('editor');
    update_option('fg_settings', array_replace($fg_oauth_settings, ['users' => [$id]]));
    $editor_tokens = fg_oauth_tokens($client, $id);
    $user->set_role('subscriber');
    $reply = fg_oauth_ok(fg_oauth_mcp($editor_tokens['access_token'], 'tools/call', ['name' => 'oauth_fixture_edit', 'arguments' => (object) []]), 'Downgraded role call');
    fg_oauth_assert(($reply['result']['isError'] ?? false) === true, 'Removed native WordPress capability remained available.');
});

fg_oauth_case('valid bearer tokens are rejected on unrelated native WordPress REST routes', static function(): void {
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    $wire = fg_oauth_wire('/wp/v2/users/me', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token']], 'GET');
    fg_oauth_assert($wire['status'] === 401 && ($wire['body']['code'] ?? '') === 'rest_not_logged_in', 'A gateway bearer token authenticated unrelated WordPress REST.');
    wp_set_current_user(0);
    $wrong = new WP_REST_Request('GET', '/wp/v2/users/me');
    $wrong->set_header('Authorization', 'Bearer ' . $tokens['access_token']);
    fg_oauth_assert(is_wp_error(FG_Auth::permission($wrong)) && get_current_user_id() === 0, 'Gateway permission injected a user for a non-MCP route.');
});

fg_oauth_case('token revocation is idempotent and invalidates access and refresh credentials', static function(): void {
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    foreach ([$tokens['refresh_token'], $tokens['refresh_token'], str_repeat('unknown-token', 4)] as $token) {
        $reply = fg_oauth_dispatch('/oauth/revoke', ['token' => $token, 'client_id' => $client['client_id']]);
        fg_oauth_assert($reply->get_status() === 200, 'Revocation leaked whether a token exists or could not revoke the token.');
    }
    fg_oauth_assert(is_wp_error(FG_OAuth::authenticate($tokens['access_token'])), 'Revoked grant still accepted its access token.');
    fg_oauth_error(fg_oauth_refresh($client, $tokens['refresh_token']), 'invalid_grant');
});

fg_oauth_case('expired consent, authorization codes, access tokens and grants fail closed', static function(): void {
    global $wpdb, $fg_oauth_admin;
    $client = fg_oauth_client();
    fg_oauth_session($fg_oauth_admin->ID);
    $consent = FG_OAuth::begin_consent(fg_oauth_authorization_params($client));
    $wpdb->update(FG_Core::table('oauth_requests'), ['expires' => time() - 1], ['id' => $consent['id']]);
    fg_oauth_assert(is_wp_error(FG_OAuth::complete_consent($consent['id'], true)), 'Expired consent issued a code.');
    [$verifier] = fg_oauth_pkce();
    $code = fg_oauth_code($client)['code'];
    $wpdb->update(FG_Core::table('oauth_codes'), ['expires' => time() - 1], ['hash' => hash('sha256', $code)]);
    fg_oauth_error(fg_oauth_dispatch('/oauth/token', fg_oauth_token_params($client, $code, $verifier)), 'invalid_grant');
    $tokens = fg_oauth_tokens($client);
    $identity = FG_OAuth::authenticate($tokens['access_token']);
    $wpdb->update(FG_Core::table('oauth_tokens'), ['expires' => time() - 1], ['hash' => hash('sha256', $tokens['access_token'])]);
    fg_oauth_assert(is_wp_error(FG_OAuth::authenticate($tokens['access_token'])), 'Expired access token authenticated.');
    $wpdb->update(FG_Core::table('oauth_grants'), ['expires' => time() - 1], ['id' => $identity['credential_id']]);
    fg_oauth_error(fg_oauth_refresh($client, $tokens['refresh_token']), 'invalid_grant');
});

fg_oauth_case('refresh does not extend the original fixed grant lifetime', static function(): void {
    global $wpdb;
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    $identity = FG_OAuth::authenticate($tokens['access_token']);
    $deadline = time() + 600;
    $wpdb->update(FG_Core::table('oauth_grants'), ['expires' => $deadline], ['id' => $identity['credential_id']]);
    $next = fg_oauth_ok(fg_oauth_refresh($client, $tokens['refresh_token']), 'Bounded grant refresh');
    $grant_expiry = (int) $wpdb->get_var($wpdb->prepare('SELECT expires FROM ' . FG_Core::table('oauth_grants') . ' WHERE id=%s', $identity['credential_id']));
    $refresh_expiry = (int) $wpdb->get_var($wpdb->prepare('SELECT expires FROM ' . FG_Core::table('oauth_tokens') . ' WHERE hash=%s', hash('sha256', $next['refresh_token'])));
    fg_oauth_assert($grant_expiry === $deadline && $refresh_expiry === $deadline && $next['expires_in'] <= 600, 'Refreshing extended the original grant deadline.');
});

fg_oauth_case('client secrets, consent sessions, authorization codes and tokens are stored only as hashes', static function(): void {
    global $wpdb;
    $client = fg_oauth_client('client_secret_post');
    [$verifier] = fg_oauth_pkce();
    $authorized = fg_oauth_code($client);
    $session = wp_get_session_token();
    $params = fg_oauth_token_params($client, $authorized['code'], $verifier);
    $params['client_secret'] = $client['client_secret'];
    $tokens = fg_oauth_ok(fg_oauth_dispatch('/oauth/token', $params), 'Hash-at-rest fixture');
    $rows = [];
    foreach (['oauth_clients', 'oauth_requests', 'oauth_codes', 'oauth_grants', 'oauth_tokens', 'audit'] as $table) {
        $rows[$table] = $wpdb->get_results('SELECT * FROM ' . FG_Core::table($table), ARRAY_A);
    }
    $stored = wp_json_encode($rows);
    foreach ([$client['client_secret'], $authorized['code'], $tokens['access_token'], $tokens['refresh_token'], $session] as $secret) {
        fg_oauth_assert($secret !== '' && !str_contains($stored, $secret), 'A raw OAuth secret was persisted in gateway tables.');
    }
    fg_oauth_assert(str_contains($stored, hash('sha256', $client['client_secret'])) && str_contains($stored, hash('sha256', $tokens['access_token'])), 'Expected stored credential hashes are missing.');
});

fg_oauth_case('disconnect all invalidates pending consent as well as existing token grants', static function(): void {
    global $fg_oauth_admin, $wpdb;
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    fg_oauth_session($fg_oauth_admin->ID);
    $consent = FG_OAuth::begin_consent(fg_oauth_authorization_params($client));
    FG_OAuth::revoke_all();
    fg_oauth_assert(is_wp_error(FG_OAuth::authenticate($tokens['access_token'])), 'Disconnect all left an active grant usable.');
    fg_oauth_assert(is_wp_error(FG_OAuth::complete_consent($consent['id'], true)), 'An open consent screen recreated a grant after disconnect all.');
    $in_flight = FG_OAuth::begin_consent(fg_oauth_authorization_params($client));
    $interleaved = false;
    $wpdb = new FG_OAuth_Test_DB_Proxy($wpdb, static function($method, $arguments) use (&$interleaved) {
        if (!$interleaved && $method === 'insert' && $arguments[0] === FG_Core::table('oauth_grants')) { $interleaved = true; FG_OAuth::revoke_all(); }
        return null;
    });
    $result = FG_OAuth::complete_consent($in_flight['id'], true);
    fg_oauth_assert($interleaved && is_wp_error($result), 'Consent recreated a usable grant during a concurrent disconnect-all operation.');
});

fg_oauth_case('revocation interleaved during refresh cannot issue a usable replacement token', static function(): void {
    global $wpdb;
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    $identity = FG_OAuth::authenticate($tokens['access_token']);
    $interleaved = false;
    $wpdb = new FG_OAuth_Test_DB_Proxy($wpdb, static function($method, $arguments) use (&$interleaved, $identity) {
        if (!$interleaved && $method === 'insert' && $arguments[0] === FG_Core::table('oauth_tokens') && ($arguments[1]['kind'] ?? '') === 'refresh') {
            $interleaved = true;
            FG_OAuth::revoke($identity['credential_id']);
        }
        return null;
    });
    $reply = fg_oauth_refresh($client, $tokens['refresh_token']);
    fg_oauth_assert($interleaved && $reply->get_status() >= 400 && !isset($reply->get_data()['access_token']), 'Revocation race issued replacement credentials.');
    fg_oauth_assert(is_wp_error(FG_OAuth::authenticate($tokens['access_token'])), 'Revocation race preserved original access.');
});

fg_oauth_case('failed refresh token storage revokes the grant and never returns partial credentials', static function(): void {
    global $wpdb;
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    $injected = false;
    $wpdb = new FG_OAuth_Test_DB_Proxy($wpdb, static function($method, $arguments) use (&$injected) {
        if ($method === 'insert' && $arguments[0] === FG_Core::table('oauth_tokens') && ($arguments[1]['kind'] ?? '') === 'refresh') { $injected = true; return [false]; }
        return null;
    });
    $reply = fg_oauth_refresh($client, $tokens['refresh_token']);
    fg_oauth_assert($injected && $reply->get_status() >= 400 && !isset($reply->get_data()['access_token']), 'Partial token storage returned credentials.');
    fg_oauth_assert(is_wp_error(FG_OAuth::authenticate($tokens['access_token'])), 'Storage failure left an ambiguous grant active.');
});

fg_oauth_case('concurrent refresh claimants cannot retain two usable token families', static function(): void {
    global $wpdb;
    $client = fg_oauth_client();
    $tokens = fg_oauth_tokens($client);
    $interleaved = false; $winner = null;
    $wpdb = new FG_OAuth_Test_DB_Proxy($wpdb, static function($method, $arguments) use (&$interleaved, &$winner, $client, $tokens) {
        if (!$interleaved && $method === 'query' && str_contains($arguments[0], "SET status='used'")) {
            $interleaved = true;
            $winner = fg_oauth_refresh($client, $tokens['refresh_token']);
        }
        return null;
    });
    $loser = fg_oauth_refresh($client, $tokens['refresh_token']);
    fg_oauth_assert($interleaved && $winner instanceof WP_REST_Response && $winner->get_status() === 200 && $loser->get_status() >= 400, 'Refresh claim did not select exactly one initial winner.');
    fg_oauth_assert(is_wp_error(FG_OAuth::authenticate($winner->get_data()['access_token'])), 'Detected concurrent refresh reuse did not revoke its family.');
});

fg_oauth_case('cleanup prunes abandoned client registrations while retaining established connections', static function(): void {
    global $wpdb, $fg_oauth_admin;
    $abandoned = fg_oauth_client();
    $established = fg_oauth_client();
    $tokens = fg_oauth_tokens($established);
    $identity = FG_OAuth::authenticate($tokens['access_token']);
    $wpdb->update(FG_Core::table('oauth_grants'), ['expires' => time() - 1], ['id' => $identity['credential_id']]);
    $pending = fg_oauth_client();
    fg_oauth_session($fg_oauth_admin->ID);
    $consent = FG_OAuth::begin_consent(fg_oauth_authorization_params($pending));
    fg_oauth_assert(!is_wp_error($consent), 'Could not prepare a live pending authorization.');
    foreach ([$abandoned, $established, $pending] as $client) {
        $wpdb->update(FG_Core::table('oauth_clients'), ['created' => time() - 2 * DAY_IN_SECONDS], ['id' => $client['client_id']]);
    }
    FG_OAuth::cleanup();
    $unused = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . FG_Core::table('oauth_clients') . ' WHERE id=%s', $abandoned['client_id']));
    $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . FG_Core::table('oauth_clients') . ' WHERE id=%s', $established['client_id']));
    $waiting = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . FG_Core::table('oauth_clients') . ' WHERE id=%s', $pending['client_id']));
    fg_oauth_assert($unused === 0 && $used === 1, 'Cleanup deleted an established client or retained an abandoned registration.');
    fg_oauth_assert($waiting === 1, 'Cleanup deleted a client while its valid consent screen was open.');
    $reconnected = fg_oauth_tokens($established);
    fg_oauth_assert(!is_wp_error(FG_OAuth::authenticate($reconnected['access_token'])), 'A cached client registration could not reconnect after its previous grant expired.');
    $table = FG_Core::table('oauth_clients');
    $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
    for ($i = $count; $i < 500; ++$i) {
        $saved = $wpdb->insert($table, ['id' => wp_generate_uuid4(), 'secret_hash' => '', 'name' => 'Abandoned cap fixture', 'auth_method' => 'none',
            'redirects' => wp_json_encode($pending['redirect_uris']), 'created' => time() - 2 * HOUR_IN_SECONDS, 'expires' => 0, 'authorized' => 0]);
        fg_oauth_assert($saved !== false, 'Could not prepare the bounded client-cap fixture.');
    }
    fg_oauth_assert((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) === 500, 'Client-cap fixture did not reach its documented bound.');
    $recovered = fg_oauth_client();
    fg_oauth_assert(isset($recovered['client_id']) && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table) <= 500, 'Abandoned registrations prevented bounded client-cap recovery.');
    fg_oauth_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE id=%s', $pending['client_id'])) === 1, 'Cap recovery removed an actively pending client.');
});

// Keep output free of authorization codes, client secrets and access/refresh tokens.
echo wp_json_encode(['cases' => $fg_oauth_results, 'passed' => count(array_filter($fg_oauth_results, static fn($case) => $case['pass'])), 'total' => count($fg_oauth_results)], JSON_PRETTY_PRINT);
foreach ($fg_oauth_results as $case) { if (!$case['pass']) { throw new RuntimeException('OAuth integration case failed: ' . $case['name']); } }
