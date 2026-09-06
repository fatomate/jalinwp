<?php
/** MCP metadata integration on a disposable WordPress fixture. No content writes are made. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { exit('Disposable test harness required.'); }
require_once '/wordpress/wp-load.php';
foreach (['core', 'settings', 'auth', 'tools', 'approvals', 'server', 'wp-tools', 'wc-tools', 'design-blocks', 'page-layouts', 'page-design'] as $module) {
    require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-' . $module . '.php';
}
if (!defined('FG_DIR')) { define('FG_DIR', '/wordpress/wp-content/plugins/jalin-mcp-gateway/'); }
if (!defined('FG_FILE')) { define('FG_FILE', FG_DIR . 'jalin-mcp-gateway.php'); }
if (!defined('FG_VERSION')) { define('FG_VERSION', '0.3.1-test'); }
if (!defined('REST_REQUEST')) { define('REST_REQUEST', true); }
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
FG_Core::activate();
FG_Auth::boot();
FG_Page_Layouts::boot();
FG_Page_Layouts::register_canvas();
FG_WP_Tools::register();
FG_WC_Tools::register();
FG_Page_Design::register();
rest_get_server();
$metadata_admin = get_users(['role'=>'administrator', 'number'=>1])[0];
$created = WP_Application_Passwords::create_new_application_password($metadata_admin->ID, ['name'=>'Disposable YOLO Metadata Fixture']);
if (is_wp_error($created)) { throw new RuntimeException('Could not create fixture credentials.'); }
[$metadata_password, $metadata_credential] = $created;
$metadata_cases = [];
function fg_metadata_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function fg_metadata_case(string $name, callable $callback): void {
    global $metadata_cases;
    try { $callback(); $metadata_cases[] = ['name'=>$name, 'pass'=>true]; }
    catch (Throwable $error) { $metadata_cases[] = ['name'=>$name, 'pass'=>false, 'error'=>$error->getMessage()]; }
}
function fg_metadata_request(string $method, array $params = []): array {
    global $metadata_admin, $metadata_password;
    // Each request authenticates real fixture credentials and enters the production permission boundary.
    FG_Auth::reset_context();
    wp_set_current_user(0);
    $user = wp_authenticate_application_password(null, $metadata_admin->user_login, $metadata_password);
    fg_metadata_assert($user instanceof WP_User, 'Fixture authentication failed.');
    wp_set_current_user($user->ID);
    $request = new WP_REST_Request('POST', '/jalin-mcp/v1/mcp');
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('Accept', 'application/json, text/event-stream');
    $request->set_body(wp_json_encode(['jsonrpc'=>'2.0', 'id'=>1, 'method'=>$method, 'params'=>(object)$params]));
    fg_metadata_assert(FG_Auth::permission($request) === true, 'MCP permission failed.');
    return FG_Server::handle($request)->get_data()['result'];
}

foreach (['read_only', 'reviewed', 'yolo'] as $mode) {
    fg_metadata_case($mode . ' initialization, catalog, and validation describe the effective mode without changing content', static function() use ($mode): void {
        global $metadata_admin, $wpdb;
        update_option('fg_settings', ['enabled'=>true, 'oauth_enabled'=>false, 'writes'=>$mode !== 'read_only', 'write_mode'=>$mode === 'yolo' ? 'yolo' : 'reviewed', 'sensitive'=>true, 'users'=>[$metadata_admin->ID], 'origins'=>[], '_policy_revision'=>wp_generate_uuid4()]);
        $before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        $initial = fg_metadata_request('initialize', ['protocolVersion'=>FG_Server::VERSIONS[0], 'capabilities'=>(object)[], 'clientInfo'=>(object)['name'=>'metadata-fixture', 'version'=>'1']]);
        $expected = ['read_only'=>'This connection has read-only access.', 'reviewed'=>'Reviewed Changes is active for this connection.', 'yolo'=>'YOLO Mode is active for this connection.'];
        fg_metadata_assert(str_contains($initial['instructions'], $expected[$mode]), 'Initialization advertised the wrong mode.');
        if ($mode === 'yolo') { fg_metadata_assert(str_contains($initial['instructions'], 'without WordPress dashboard approval'), 'Initialization did not explain direct execution.'); }
        $catalog = fg_metadata_request('tools/list')['tools'];
        $writes = array_filter($catalog, static fn($tool)=>!$tool['annotations']['readOnlyHint'] && $tool['name'] !== 'gateway_apply_change');
        fg_metadata_assert(($mode === 'read_only') === empty($writes), 'Read-only catalog or writable catalog is incorrect.');
        foreach ($writes as $tool) {
            fg_metadata_assert(str_starts_with($tool['description'], $mode === 'yolo' ? 'YOLO Mode:' : 'Reviewed Changes:'), 'Missing effective mode for ' . $tool['name']);
            if ($mode === 'yolo') { fg_metadata_assert(!str_contains($tool['description'], 'Requests wp-admin approval') && !str_contains($tool['description'], 'Propose '), 'Contradictory YOLO tool instructions: ' . $tool['name']); }
            fg_metadata_assert($tool['annotations']['idempotentHint'] === false, 'A write was advertised as safe to repeat.');
        }
        $by_name = array_column($catalog, null, 'name');
        fg_metadata_assert(isset($by_name['gateway_change_status'], $by_name['gateway_apply_change']), 'Existing reviewed-change tools disappeared.');
        fg_metadata_assert(str_contains($by_name['gateway_apply_change']['description'], 'cannot be applied or retried here'), 'Apply does not distinguish YOLO records.');
        $validation = fg_metadata_request('tools/call', ['name'=>'wp_page_design_validate', 'arguments'=>['title'=>'Metadata Validation Fixture', 'blocks'=>[['name'=>'core/paragraph', 'attributes'=>['content'=>'No content should be saved.']]]]]);
        fg_metadata_assert(empty($validation['isError']), 'Design validation failed.');
        $data = $validation['structuredContent']['data']['data'];
        fg_metadata_assert($data['write_mode'] === $mode && $data['validation_method'] === 'Strict PHP Block Adapters' && $data['writes_performed'] === false, 'Validation mode or method is inaccurate.');
        $editor = ['read_only'=>'Not Run; This Connection Is Read Only', 'reviewed'=>'Required During Administrator Review', 'yolo'=>'Not Run in YOLO Mode; PHP Validation Only'];
        fg_metadata_assert($data['installed_editor_validation'] === $editor[$mode], 'Validation misrepresented the browser check.');
        fg_metadata_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}") === $before, 'Read-only validation created content.');
    });
}
$passed = count(array_filter($metadata_cases, static fn($case)=>$case['pass']));
echo wp_json_encode(['passed'=>$passed, 'total'=>count($metadata_cases), 'environment'=>['wordpress'=>get_bloginfo('version'), 'php'=>PHP_VERSION, 'database'=>'SQLite WordPress Integration'], 'cases'=>$metadata_cases], JSON_PRETTY_PRINT);
if ($passed !== count($metadata_cases)) { throw new RuntimeException('YOLO metadata checks failed.'); }
