<?php
/** Security/integration cases for a disposable WordPress Playground only. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { exit('Disposable test harness required.'); }
require_once '/wordpress/wp-load.php';
foreach (['core', 'tools', 'auth', 'approvals', 'server'] as $module) {
    require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-' . $module . '.php';
}
if (!defined('FG_VERSION')) { define('FG_VERSION', '0.1.0-test'); }
if (!defined('REST_REQUEST')) { define('REST_REQUEST', true); }
$_SERVER['HTTPS'] = 'on';
FG_Core::activate();
FG_Auth::boot();
$fg_sec_results = [];
$fg_sec_effects = 0;
$fg_sec_admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$fg_sec_settings = ['enabled' => true, 'writes' => true, 'sensitive' => false, 'users' => [$fg_sec_admin->ID], 'origins' => ['https://allowed.example']];
update_option('fg_settings', $fg_sec_settings);
wp_set_current_user($fg_sec_admin->ID);

function fg_sec_assert(bool $test, string $message): void { if (!$test) { throw new RuntimeException($message); } }
function fg_sec_reject(callable $call, string $reason): void {
    try { $call(); }
    catch (FG_Failure $error) { fg_sec_assert($error->reason === $reason, 'Unexpected failure: ' . $error->reason); return; }
    throw new RuntimeException('Expected failure: ' . $reason);
}
function fg_sec_case(string $name, callable $case): void {
    global $fg_sec_results, $wpdb, $fg_sec_settings, $fg_sec_admin, $fg_sec_item;
    $database = $wpdb;
    try { $case(); $fg_sec_results[] = ['name' => $name, 'pass' => true]; }
    catch (Throwable $error) { $fg_sec_results[] = ['name' => $name, 'pass' => false, 'error' => $error->getMessage()]; }
    finally {
        $wpdb = $database;
        $_SERVER['HTTPS'] = 'on';
        update_option('fg_settings', $fg_sec_settings);
        wp_set_current_user($fg_sec_admin->ID);
        if (isset($fg_sec_item)) { fg_sec_authenticate($fg_sec_admin, $fg_sec_item); }
    }
}
function fg_sec_request($body, string $method = 'POST'): WP_REST_Request {
    $request = new WP_REST_Request($method, '/jalin-mcp/v1/mcp');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('Accept', 'application/json, text/event-stream');
    $request->set_body(is_string($body) ? $body : wp_json_encode($body));
    return $request;
}
/** Each synthetic request must complete the real gateway permission boundary. */
function fg_sec_authenticate(WP_User $user, array $item): void {
    wp_set_current_user($user->ID);
    do_action('application_password_did_authenticate', $user, $item);
    fg_sec_assert(FG_Auth::permission(fg_sec_request(['jsonrpc'=>'2.0','id'=>1,'method'=>'ping'])) === true, 'Fixture MCP permission authentication failed.');
}
function fg_sec_change(): string { return FG_Tools::call('security_write', ['value' => 'bounded-change'])['change_id']; }

/** Interleave a second operation at a real SQL boundary; all SQL runs on WordPress's database. */
final class FG_Security_DB_Proxy {
    private $database;
    private $before;
    public function __construct($database, callable $before) { $this->database = $database; $this->before = $before; }
    public function __get($name) { return $this->database->$name; }
    public function __set($name, $value) { $this->database->$name = $value; }
    public function __call($name, $args) {
        $override = ($this->before)($name, $args);
        return is_array($override) ? $override[0] : $this->database->$name(...$args);
    }
}

FG_Tools::register('security_write', 'Synthetic test side effect.', FG_Tools::schema(['value' => ['type' => 'string', 'maxLength' => 50]], ['value']), static function ($args) {
    global $fg_sec_effects; ++$fg_sec_effects; return ['value' => $args['value']];
}, true, 'read');
FG_Tools::register('security_privileged', 'Synthetic capability check.', FG_Tools::schema(), static fn() => [], true, 'edit_posts');
FG_Tools::register('security_wp_error', 'Synthetic native failure.', FG_Tools::schema(), static fn() => new WP_Error('synthetic_failure', 'A native handler rejected the request.'), true, 'read');
FG_Tools::register('security_read_error', 'Synthetic native read failure.', FG_Tools::schema(), static fn() => new WP_Error('synthetic_failure', 'A native handler rejected the request.'), false, 'read');

fg_sec_case('cookie-only access is rejected even for an enabled administrator', static function(): void {
    $result = FG_Auth::permission(fg_sec_request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
    fg_sec_assert(is_wp_error($result) && $result->get_error_code() === 'fg_auth', 'Cookie-only user was accepted.');
});

fg_sec_case('real WordPress Application Password authentication captures user and credential UUID', static function(): void {
    global $fg_sec_admin, $fg_sec_item, $fg_sec_password;
    $created = WP_Application_Passwords::create_new_application_password($fg_sec_admin->ID, ['name' => 'Disposable gateway security test']);
    fg_sec_assert(!is_wp_error($created), 'Could not create test Application Password.');
    [$password, $fg_sec_item] = $created;
    $fg_sec_password = $password;
    wp_set_current_user(0);
    $authenticated = wp_authenticate_application_password(null, $fg_sec_admin->user_login, $password);
    fg_sec_assert($authenticated instanceof WP_User, 'Real Application Password authentication failed.');
    wp_set_current_user($authenticated->ID);
    fg_sec_assert(FG_Auth::credential_id() === $fg_sec_item['uuid'], 'Credential UUID was not captured.');
    fg_sec_assert(true === FG_Auth::permission(fg_sec_request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])), 'Valid Application Password was rejected.');
});

fg_sec_case('HTTPS, gateway enablement, allowed-user and Origin controls fail closed', static function(): void {
    global $fg_sec_settings, $fg_sec_admin, $fg_sec_item;
    // Each permission probe models a separate request with freshly authenticated Basic credentials.
    $permission = static function ($request) use ($fg_sec_admin, $fg_sec_item) {
        do_action('application_password_did_authenticate', $fg_sec_admin, $fg_sec_item);
        return FG_Auth::permission($request);
    };
    $request = fg_sec_request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['SERVER_PORT'] = '80';
    fg_sec_assert($permission($request)->get_error_code() === 'fg_https', 'HTTP was accepted.');
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    update_option('fg_settings', array_replace($fg_sec_settings, ['enabled' => false]));
    fg_sec_assert($permission($request)->get_error_code() === 'fg_disabled', 'Disabled gateway accepted request.');
    update_option('fg_settings', array_replace($fg_sec_settings, ['users' => []]));
    fg_sec_assert($permission($request)->get_error_code() === 'fg_forbidden', 'Unlisted user was accepted.');
    update_option('fg_settings', $fg_sec_settings);
    foreach (['https://attacker.example', 'null', 'https://allowed.example/path', 'https://user@allowed.example', 'http://allowed.example'] as $origin) {
        $request->set_header('Origin', $origin);
        fg_sec_assert($permission($request)->get_error_code() === 'fg_origin', 'Unsafe Origin accepted: ' . $origin);
    }
    $request->set_header('Origin', 'https://allowed.example');
    fg_sec_assert(true === $permission($request), 'Configured Origin was rejected.');
});

fg_sec_case('MCP initialize negotiates supported versions and notifications never execute tools', static function(): void {
    global $fg_sec_effects;
    $before = $fg_sec_effects;
    foreach (FG_Server::VERSIONS as $version) {
        $reply = FG_Server::handle(fg_sec_request(['jsonrpc' => '2.0', 'id' => 123, 'method' => 'initialize', 'params' => ['protocolVersion' => $version, 'capabilities' => (object) [], 'clientInfo' => (object) ['name' => 'test', 'version' => '1']]]))->get_data();
        fg_sec_assert($reply['id'] === 123 && $reply['result']['protocolVersion'] === $version, 'Negotiation changed the ID or protocol.');
    }
    $ack = FG_Server::handle(fg_sec_request(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    fg_sec_assert($ack->get_status() === 202, 'Notification acknowledgement is not 202.');
    $invalid = FG_Server::handle(fg_sec_request(['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => 'security_write', 'arguments' => ['value' => 'blocked']]]));
    fg_sec_assert($invalid->get_status() === 400 && $fg_sec_effects === $before, 'A no-ID tool message executed.');
});

fg_sec_case('rate limiting rejects unavailable storage and request 61 within the same minute', static function(): void {
    global $wpdb;
    $database = $wpdb;
    $wpdb = new FG_Security_DB_Proxy($database, static function($method, $args) {
        return $method === 'get_var' && str_contains($args[0], 'SELECT hits FROM') ? [null] : null;
    });
    fg_sec_assert(false === FG_Core::rate_limit(), 'Missing rate-limit lookup was accepted.');
    $wpdb = new FG_Security_DB_Proxy($database, static function($method, $args) {
        return $method === 'query' && str_contains($args[0], 'INSERT INTO') ? [false] : null;
    });
    fg_sec_assert(false === FG_Core::rate_limit(), 'Failed rate-limit increment was accepted.');
    $wpdb = $database;
    $wpdb->query('DELETE FROM ' . FG_Core::table('limits'));
    // Execute away from the minute boundary to avoid asserting across two buckets.
    if ((int) gmdate('s') < 58) {
        for ($request = 1; $request <= 60; ++$request) { fg_sec_assert(true === FG_Core::rate_limit(), 'Request below the limit was rejected.'); }
        fg_sec_assert(false === FG_Core::rate_limit(), 'Request 61 was accepted.');
    }
    $wpdb->query('DELETE FROM ' . FG_Core::table('limits'));
});

fg_sec_case('malformed MCP envelopes, null arguments and unsupported HTTP protocol are rejected', static function(): void {
    $cases = [
        ['not-json', -32700],
        [[], -32600],
        [['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'], -32600],
        [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'result' => (object) []], -32600],
        [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'security_write', 'arguments' => null]], -32602],
        [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []], -32602],
    ];
    foreach ($cases as [$body, $expected]) {
        $reply = FG_Server::handle(fg_sec_request($body))->get_data();
        fg_sec_assert(($reply['error']['code'] ?? null) === $expected, 'Wrong malformed-message error for ' . wp_json_encode($body));
    }
    $request = fg_sec_request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    $request->set_header('MCP-Protocol-Version', '1900-01-01');
    fg_sec_assert(FG_Server::handle($request)->get_status() === 400, 'Unsupported protocol header accepted.');
    fg_sec_assert(FG_Server::handle(fg_sec_request([], 'GET'))->get_status() === 405, 'Unsupported GET was accepted.');
});

fg_sec_case('proposals do not execute; approved changes execute only once', static function(): void {
    global $fg_sec_effects;
    $before = $fg_sec_effects;
    $id = fg_sec_change();
    fg_sec_assert(FG_Approvals::status($id)['status'] === 'pending' && $fg_sec_effects === $before, 'Proposal caused a side effect.');
    fg_sec_reject(static fn() => FG_Approvals::apply($id), 'not_approved');
    FG_Approvals::review($id, 'approved');
    fg_sec_assert(FG_Approvals::apply($id)['status'] === 'applied', 'Approved change did not apply.');
    fg_sec_reject(static fn() => FG_Approvals::apply($id), 'not_approved');
    fg_sec_reject(static fn() => FG_Approvals::review($id, 'rejected'), 'expired');
    fg_sec_assert($fg_sec_effects === $before + 1 && FG_Approvals::status($id)['status'] === 'applied', 'Change executed twice or a late review altered its status.');
});

fg_sec_case('approval is bound to both WordPress user and Application Password UUID', static function(): void {
    global $fg_sec_admin, $fg_sec_item, $fg_sec_settings;
    $id = fg_sec_change();
    fg_sec_authenticate($fg_sec_admin, ['uuid' => wp_generate_uuid4()]);
    fg_sec_reject(static fn() => FG_Approvals::status($id), 'not_found');
    fg_sec_reject(static fn() => FG_Approvals::apply($id), 'not_found');
    $other_id = wp_create_user('security-other-owner', wp_generate_password(), 'security-other@example.test');
    update_option('fg_settings', array_replace($fg_sec_settings, ['users'=>[$fg_sec_admin->ID, $other_id]]));
    fg_sec_authenticate(get_user_by('id', $other_id), $fg_sec_item);
    fg_sec_reject(static fn() => FG_Approvals::status($id), 'not_found');
});

fg_sec_case('expired and tampered approvals cannot execute', static function(): void {
    global $wpdb, $fg_sec_effects;
    $before = $fg_sec_effects;
    $expired = fg_sec_change(); FG_Approvals::review($expired, 'approved');
    $wpdb->update(FG_Core::table('changes'), ['expires' => time() - 1], ['id' => $expired]);
    fg_sec_assert(FG_Approvals::status($expired)['status'] === 'expired', 'Expired status was not surfaced.');
    fg_sec_reject(static fn() => FG_Approvals::apply($expired), 'expired');
    $tampered = fg_sec_change(); FG_Approvals::review($tampered, 'approved');
    $wpdb->update(FG_Core::table('changes'), ['arguments' => '{"value":"tampered"}'], ['id' => $tampered]);
    fg_sec_reject(static fn() => FG_Approvals::apply($tampered), 'invalid_change');
    fg_sec_assert($fg_sec_effects === $before, 'Expired/tampered change executed.');
});

fg_sec_case('disabling writes blocks both proposals and previously approved execution', static function(): void {
    global $fg_sec_settings;
    $id = fg_sec_change(); FG_Approvals::review($id, 'approved');
    update_option('fg_settings', array_replace($fg_sec_settings, ['writes' => false]));
    fg_sec_reject(static fn() => fg_sec_change(), 'read_only');
    fg_sec_reject(static fn() => FG_Approvals::apply($id), 'read_only');
});

fg_sec_case('permissions are rechecked after approval and nonadministrators cannot review', static function(): void {
    global $fg_sec_admin, $fg_sec_item, $fg_sec_settings;
    $editor_id = wp_create_user('security-editor', wp_generate_password(), 'security-editor@example.test');
    $editor = get_user_by('id', $editor_id); $editor->set_role('editor');
    update_option('fg_settings', array_replace($fg_sec_settings, ['users'=>[$fg_sec_admin->ID, $editor_id]]));
    fg_sec_authenticate($editor, $fg_sec_item);
    $id = FG_Tools::call('security_privileged', [])['change_id'];
    fg_sec_reject(static fn() => FG_Approvals::review($id, 'approved'), 'forbidden');
    wp_set_current_user($fg_sec_admin->ID); FG_Approvals::review($id, 'approved');
    $editor->set_role('subscriber');
    fg_sec_authenticate($editor, $fg_sec_item);
    fg_sec_reject(static fn() => FG_Approvals::apply($id), 'forbidden');
});

fg_sec_case('native WP_Error handlers become failures and cannot be marked applied', static function(): void {
    global $wpdb;
    $reply = FG_Server::handle(fg_sec_request(['jsonrpc' => '2.0', 'id' => 30, 'method' => 'tools/call', 'params' => ['name' => 'security_read_error', 'arguments' => (object) []]]))->get_data();
    fg_sec_assert($reply['result']['isError'] === true, 'WP_Error read was reported as success.');
    $id = FG_Tools::call('security_wp_error', [])['change_id']; FG_Approvals::review($id, 'approved');
    fg_sec_reject(static fn() => FG_Approvals::apply($id), 'execution_failed');
    fg_sec_assert(FG_Approvals::status($id)['status'] === 'failed', 'WP_Error write was marked applied.');
});

fg_sec_case('failed review audit cannot expose an executable approval during interleaving', static function(): void {
    global $wpdb, $fg_sec_effects;
    $before = $fg_sec_effects;
    $id = fg_sec_change();
    $attempted = false;
    $wpdb = new FG_Security_DB_Proxy($wpdb, static function($method, $args) use ($id, &$attempted) {
        if ($method === 'insert' && ($args[1]['tool'] ?? '') === 'gateway_review') {
            $attempted = true;
            fg_sec_reject(static fn() => FG_Approvals::apply($id), 'not_approved');
            return [false];
        }
        return null;
    });
    fg_sec_reject(static fn() => FG_Approvals::review($id, 'approved'), 'audit_unavailable');
    fg_sec_assert($attempted && $fg_sec_effects === $before && FG_Approvals::status($id)['status'] === 'rejected', 'Failed audit allowed an execution.');
});

fg_sec_case('successful review remains unexecutable until audit has completed', static function(): void {
    global $wpdb, $fg_sec_effects;
    $before = $fg_sec_effects;
    $id = fg_sec_change();
    $attempted = false;
    $wpdb = new FG_Security_DB_Proxy($wpdb, static function($method, $args) use ($id, &$attempted) {
        if ($method === 'insert' && ($args[1]['tool'] ?? '') === 'gateway_review') {
            $attempted = true;
            fg_sec_reject(static fn() => FG_Approvals::apply($id), 'not_approved');
        }
        return null;
    });
    FG_Approvals::review($id, 'approved');
    fg_sec_assert($attempted && $fg_sec_effects === $before, 'Review executed before its audit completed.');
    FG_Approvals::apply($id);
    fg_sec_assert($fg_sec_effects === $before + 1, 'Approval did not become executable after successful audit.');
});

fg_sec_case('a concurrent apply winner causes the stale claimant to fail without duplicate execution', static function(): void {
    global $wpdb, $fg_sec_effects;
    $before = $fg_sec_effects;
    $id = fg_sec_change(); FG_Approvals::review($id, 'approved');
    $interleaved = false;
    $wpdb = new FG_Security_DB_Proxy($wpdb, static function($method, $args) use ($id, &$interleaved) {
        if (!$interleaved && $method === 'query' && str_contains($args[0], "SET status='executing'")) {
            $interleaved = true;
            FG_Approvals::apply($id);
        }
        return null;
    });
    fg_sec_reject(static fn() => FG_Approvals::apply($id), 'already_claimed');
    fg_sec_assert($interleaved && $fg_sec_effects === $before + 1 && FG_Approvals::status($id)['status'] === 'applied', 'Concurrent callers executed twice or corrupted final status.');
});

fg_sec_case('revocation during review cannot be overwritten by review finalization', static function(): void {
    global $wpdb, $fg_sec_effects;
    $before = $fg_sec_effects;
    $id = fg_sec_change();
    $wpdb = new FG_Security_DB_Proxy($wpdb, static function($method, $args) use ($id) {
        global $wpdb;
        if ($method === 'insert' && ($args[1]['tool'] ?? '') === 'gateway_review') {
            $wpdb->update(FG_Core::table('changes'), ['status' => 'revoked'], ['id' => $id]);
        }
        return null;
    });
    fg_sec_reject(static fn() => FG_Approvals::review($id, 'approved'), 'storage_error');
    fg_sec_assert(FG_Approvals::status($id)['status'] === 'revoked' && $fg_sec_effects === $before, 'Review resurrected revoked approval.');
});

fg_sec_case('native WordPress REST serving authenticates Basic headers and emits an empty notification body', static function(): void {
    global $fg_sec_admin, $fg_sec_password, $HTTP_RAW_POST_DATA;
    $server = rest_get_server();
    FG_Server::register();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/json';
    $_SERVER['HTTP_ACCEPT'] = 'application/json, text/event-stream';
    $_SERVER['PHP_AUTH_USER'] = $fg_sec_admin->user_login;
    $_SERVER['PHP_AUTH_PW'] = $fg_sec_password;
    unset($_SERVER['HTTP_ORIGIN']);
    $_GET = []; $_POST = [];
    unset($GLOBALS['current_user']);
    // Exercise WordPress's determine_current_user callbacks using actual Basic credentials.
    fg_sec_assert(wp_get_current_user()->ID === $fg_sec_admin->ID, 'Basic headers did not authenticate through WordPress.');
    $HTTP_RAW_POST_DATA = wp_json_encode(['jsonrpc' => '2.0', 'id' => 'wire-init', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => (object) ['name' => 'security-wire', 'version' => '1']]]);
    ob_start();
    $server->serve_request('/jalin-mcp/v1/mcp');
    $body = ob_get_clean();
    $reply = json_decode($body, true);
    fg_sec_assert(($reply['id'] ?? null) === 'wire-init' && ($reply['result']['protocolVersion'] ?? null) === '2025-11-25', 'Wire initialize did not return its JSON-RPC result: ' . substr($body, 0, 300));
    // A second HTTP request runs WordPress authentication again; it cannot inherit request-local identity.
    unset($GLOBALS['current_user'], $GLOBALS['wp_rest_application_password_status']);
    fg_sec_assert(wp_get_current_user()->ID === $fg_sec_admin->ID, 'Second Basic request did not authenticate.');
    $HTTP_RAW_POST_DATA = wp_json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    ob_start();
    $server->serve_request('/jalin-mcp/v1/mcp');
    $body = ob_get_clean();
    fg_sec_assert($body === '', 'Accepted notification emitted a response body: ' . substr($body, 0, 300));
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $HTTP_RAW_POST_DATA);
});

echo wp_json_encode(['cases' => $fg_sec_results, 'passed' => count(array_filter($fg_sec_results, static fn($case) => $case['pass'])), 'total' => count($fg_sec_results)], JSON_PRETTY_PRINT);
foreach ($fg_sec_results as $case) { if (!$case['pass']) { throw new RuntimeException('Security integration case failed: ' . $case['name']); } }
