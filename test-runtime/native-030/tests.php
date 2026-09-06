<?php
/** Loaded exclusively by run.php after installing a fresh native WordPress database. */
declare(strict_types=1);
if (!defined('FG_TEST_DISPOSABLE') || !FG_TEST_DISPOSABLE || !preg_match('/\Afg_native_030_[a-f0-9]{16}\z/', DB_NAME) || get_class($wpdb) !== 'wpdb') {
    throw new RuntimeException('Native test fixture guard failed.');
}
foreach (['core', 'tools', 'auth', 'oauth'] as $module) { require_once ABSPATH . 'wp-content/plugins/fames-mcp-gateway/includes/class-' . $module . '.php'; }

function fg_native_assert(bool $pass, string $message): void {
    if (!$pass) { throw new RuntimeException($message); }
}
function fg_native_case(string $name, callable $test): void {
    global $summary;
    try { $test(); $summary['cases'][] = ['name'=>$name,'pass'=>true]; }
    catch (Throwable $error) { $summary['cases'][] = ['name'=>$name,'pass'=>false,'error'=>$error->getMessage()]; throw $error; }
}
function fg_native_sql(string $sql): void {
    global $wpdb;
    fg_native_assert($wpdb->query($sql) !== false, 'Native fixture SQL operation failed.');
}
function fg_native_row(string $table, string $id): array {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%s", $id), ARRAY_A) ?: [];
}

$admin = get_user_by('login', 'native_admin');
wp_set_current_user($admin->ID);
FG_Core::activate();
$settings = ['enabled'=>true,'oauth_enabled'=>true,'writes'=>true,'sensitive'=>true,'users'=>[$admin->ID], 'origins'=>[],
    'finance_mappings'=>[['gateway'=>'fixture','fee_key'=>'_fixture_fee','unit'=>'major']]];
update_option('fg_settings', $settings, false);
$epoch = get_option('fg_oauth_epoch');
$audit = FG_Core::table('audit');
$grants = FG_Core::table('oauth_grants');
$changes = FG_Core::table('changes');
$clients = FG_Core::table('oauth_clients');
$tokens = FG_Core::table('oauth_tokens');
$now = time();
$client_id = wp_generate_uuid4();
$grant_id = wp_generate_uuid4();
$change_id = wp_generate_uuid4();
$raw_token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$token_hash = hash('sha256', $raw_token);

fg_native_case('real MySQL/MariaDB supports fresh dbDelta schema and markers', static function (): void {
    fg_native_assert(get_option('fg_db_version') === '0.3.0' && get_option('fg_oauth_schema') === '3', 'Fresh install did not advance both schema markers.');
    fg_native_assert(FG_Core::schema_ready(FG_Core::table('audit'), ['auth_source','oauth_client_id','oauth_grant_id','client_name'], ['client_created'=>['oauth_client_id','created']]), 'Fresh schema omits required audit columns or index.');
});

fg_native_case('construct an exact legacy shape with retained grants tokens and audit rows', static function () use ($audit, $grants, $changes, $clients, $tokens, $client_id, $grant_id, $change_id, $token_hash, $now, $admin, $epoch): void {
    global $wpdb;
    fg_native_sql("ALTER TABLE $audit DROP INDEX client_created, DROP COLUMN auth_source, DROP COLUMN oauth_client_id, DROP COLUMN oauth_grant_id, DROP COLUMN client_name");
    fg_native_sql("ALTER TABLE $grants DROP COLUMN consent_recorded_at, DROP COLUMN tokens_issued_at, DROP COLUMN first_authenticated_at, DROP COLUMN allow_yolo");
    fg_native_sql("ALTER TABLE $changes DROP COLUMN server_context");
    $client = ['id'=>$client_id,'secret_hash'=>str_repeat('a',64),'name'=>'Native Fixture Client','auth_method'=>'none','redirects'=>'["https://chatgpt.com/connector_platform_oauth_redirect"]','authorized'=>1,'created'=>$now,'expires'=>0];
    $grant = ['id'=>$grant_id,'client_id'=>$client_id,'user_id'=>$admin->ID,'user_hash'=>hash_hmac('sha256', $admin->ID . '|' . $admin->user_pass . '|' . $epoch, wp_salt('auth')),
        'resource'=>FG_OAuth::resource(),'allow_writes'=>1,'allow_sensitive'=>1,'status'=>'active','created'=>$now,'expires'=>$now+3600,'last_used'=>0];
    fg_native_assert($wpdb->insert($clients, $client) === 1 && $wpdb->insert($grants, $grant) === 1, 'Could not create retained legacy client and grant.');
    fg_native_assert($wpdb->insert($tokens, ['hash'=>$token_hash,'grant_id'=>$grant_id,'kind'=>'access','status'=>'active','expires'=>$now+1800,'created'=>$now]) === 1, 'Could not create retained legacy token.');
    fg_native_assert($wpdb->insert($audit, ['user_id'=>$admin->ID,'tool'=>'legacy_read','outcome'=>'success','change_id'=>'','created'=>$now]) === 1, 'Could not create retained legacy audit row.');
    fg_native_assert($wpdb->insert($changes, ['id'=>$change_id,'user_id'=>$admin->ID,'credential_id'=>$grant_id,'tool'=>'fixture_write','arguments'=>'{}','digest'=>str_repeat('b',64),'status'=>'pending','created'=>$now,'expires'=>$now+900,'reviewer_id'=>0]) === 1, 'Could not create retained legacy proposal.');
    update_option('fg_db_version', '0.2.0', false);
    update_option('fg_oauth_schema', '1', false);
});
$before_client = fg_native_row($clients, $client_id);
$before_grant = fg_native_row($grants, $grant_id);
$before_tokens = $wpdb->get_results("SELECT * FROM $tokens", ARRAY_A);
$before_audit = $wpdb->get_results("SELECT * FROM $audit", ARRAY_A);

fg_native_case('legacy upgrade adds columns and indexes using native dbDelta', static function () use ($audit, $grants, $changes): void {
    FG_Core::maybe_upgrade();
    fg_native_assert(get_option('fg_db_version') === '0.3.0' && get_option('fg_oauth_schema') === '3', 'Upgrade did not advance schema markers.');
    fg_native_assert(FG_Core::schema_ready($audit, ['auth_source','oauth_client_id','oauth_grant_id','client_name'], ['client_created'=>['oauth_client_id','created']]), 'Audit schema was not migrated.');
    fg_native_assert(FG_Core::schema_ready($grants, ['consent_recorded_at','tokens_issued_at','first_authenticated_at','allow_yolo'], ['PRIMARY'=>['id']]), 'Lifecycle schema was not migrated.');
    fg_native_assert(FG_Core::schema_ready($changes, ['server_context'], ['PRIMARY'=>['id']]), 'Proposal context schema was not migrated.');
});
fg_native_case('upgrade preserves settings OAuth epoch credentials and token hashes', static function () use ($settings, $epoch, $clients, $client_id, $before_client, $tokens, $before_tokens): void {
    global $wpdb;
    fg_native_assert(get_option('fg_settings') === $settings && get_option('fg_oauth_epoch') === $epoch, 'Upgrade changed settings or revoked all connections.');
    fg_native_assert(fg_native_row($clients, $client_id) === $before_client && $wpdb->get_results("SELECT * FROM $tokens", ARRAY_A) === $before_tokens, 'Upgrade changed retained credentials or tokens.');
});
fg_native_case('legacy grant timing remains unknown and existing grant fields remain intact', static function () use ($grants, $grant_id, $before_grant): void {
    $row = fg_native_row($grants, $grant_id);
    foreach ($before_grant as $key=>$value) { fg_native_assert($row[$key] === $value, 'Upgrade altered legacy grant field: ' . $key); }
    fg_native_assert((int) $row['allow_yolo'] === 0, 'Upgrade granted YOLO to a legacy connection.');
    foreach (['consent_recorded_at','tokens_issued_at','first_authenticated_at'] as $key) { fg_native_assert($row[$key] === null, 'Upgrade invented legacy lifecycle timestamps.'); }
});
fg_native_case('legacy activity remains readable without invented client attribution', static function () use ($audit, $before_audit): void {
    global $wpdb;
    $after = $wpdb->get_results("SELECT * FROM $audit ORDER BY id", ARRAY_A);
    fg_native_assert(count($after) === count($before_audit), 'Upgrade removed audit history.');
    foreach ($before_audit[0] as $key=>$value) { fg_native_assert($after[0][$key] === $value, 'Upgrade changed a historical audit field.'); }
    foreach (['auth_source','oauth_client_id','oauth_grant_id','client_name'] as $key) { fg_native_assert($after[0][$key] === '', 'Upgrade invented historical client attribution.'); }
});
fg_native_case('legacy pending changes without trustworthy server context are revoked', static function () use ($changes, $change_id): void {
    $row = fg_native_row($changes, $change_id);
    fg_native_assert($row['status'] === 'revoked' && $row['server_context'] === null && $row['arguments'] === '{}', 'Unsafe legacy pending proposal was not revoked or its payload changed.');
});
fg_native_case('connections query returns legacy authorization on native SQL without reserved alias', static function () use ($grant_id): void {
    global $wpdb;
    $rows = FG_OAuth::connections();
    fg_native_assert(!is_wp_error($rows) && count($rows) === 1 && $rows[0]['id'] === $grant_id, 'Native SQL did not return retained authorization.');
    fg_native_assert($rows[0]['lifecycle_status'] === 'authorized' && $rows[0]['sensitive'] === true, 'Legacy token evidence or sensitive grant projection failed.');
});
fg_native_case('retained bearer token authenticates after upgrade', static function () use ($raw_token, $grant_id): void {
    $context = FG_OAuth::authenticate($raw_token);
    fg_native_assert(is_array($context) && ($context['credential_id'] ?? '') === $grant_id && ($context['oauth_grant_id'] ?? '') === $grant_id, 'Retained token stopped authenticating after upgrade.');
    $rows = FG_OAuth::connections();
    fg_native_assert(!is_wp_error($rows) && $rows[0]['lifecycle_status'] === 'active', 'First authenticated request did not update lifecycle state.');
});
fg_native_case('query failure returns an explicit connection storage error', static function () use ($clients): void {
    fg_native_sql("RENAME TABLE $clients TO {$clients}_held");
    try {
        $result = FG_OAuth::connections();
        fg_native_assert(is_wp_error($result) && $result->get_error_code() === 'fg_oauth_connections_storage', 'Native query failure masqueraded as no connections.');
    } finally { fg_native_sql("RENAME TABLE {$clients}_held TO $clients"); }
});
fg_native_case('authenticated audit records store native client context without credentials', static function () use ($client_id, $grant_id, $audit): void {
    global $wpdb;
    fg_native_assert(FG_Core::audit('native_read', 'success', '', ['auth_source'=>'oauth','oauth_client_id'=>$client_id,'oauth_grant_id'=>$grant_id,'client_name'=>'Native Fixture Client']), 'Native audit insert failed.');
    $row = $wpdb->get_row("SELECT * FROM $audit WHERE tool='native_read' ORDER BY id DESC LIMIT 1", ARRAY_A);
    fg_native_assert($row['auth_source'] === 'oauth' && $row['oauth_client_id'] === $client_id && $row['oauth_grant_id'] === $grant_id && $row['client_name'] === 'Native Fixture Client', 'Client attribution was not persisted.');
});
fg_native_case('repeated upgrade is idempotent for rows markers and retained credentials', static function () use ($clients, $grants, $tokens, $audit, $changes, $epoch, $settings): void {
    global $wpdb;
    $tables = [$clients,$grants,$tokens,$audit,$changes]; $before = [];
    foreach ($tables as $table) { $before[$table] = $wpdb->get_results("SELECT * FROM $table", ARRAY_A); }
    FG_Core::activate(); FG_Core::maybe_upgrade();
    foreach ($tables as $table) { fg_native_assert($before[$table] === $wpdb->get_results("SELECT * FROM $table", ARRAY_A), 'Repeated upgrade changed retained rows.'); }
    fg_native_assert(get_option('fg_settings') === $settings && get_option('fg_oauth_epoch') === $epoch, 'Repeated upgrade changed settings or epoch.');
});
fg_native_case('failed additive migration cannot advance core version marker', static function () use ($audit): void {
    global $wpdb;
    fg_native_sql("ALTER TABLE $audit DROP COLUMN client_name");
    update_option('fg_db_version', '0.2.0', false);
    $blocked = static function (string $query) use ($audit): string {
        return str_contains($query, 'ALTER TABLE ' . $audit) && str_contains($query, 'ADD COLUMN client_name') ? 'SELECT * FROM fg_native_expected_missing_table' : $query;
    };
    $was_suppressed = $wpdb->suppress_errors(true);
    add_filter('query', $blocked);
    try { FG_Core::activate(); }
    finally { remove_filter('query', $blocked); $wpdb->suppress_errors($was_suppressed); }
    fg_native_assert(get_option('fg_db_version') === '0.2.0', 'Core marker advanced despite a missing migrated column.');
    FG_Core::activate();
    fg_native_assert(get_option('fg_db_version') === '0.3.0', 'Retry did not recover core migration.');
});
fg_native_case('failed lifecycle migration cannot advance OAuth schema marker', static function () use ($grants): void {
    global $wpdb;
    fg_native_sql("ALTER TABLE $grants DROP COLUMN tokens_issued_at");
    update_option('fg_oauth_schema', '1', false);
    $blocked = static function (string $query) use ($grants): string {
        return str_contains($query, 'ALTER TABLE ' . $grants) && str_contains($query, 'ADD COLUMN tokens_issued_at') ? 'SELECT * FROM fg_native_expected_missing_table' : $query;
    };
    $was_suppressed = $wpdb->suppress_errors(true);
    add_filter('query', $blocked);
    try { FG_OAuth::install(); }
    finally { remove_filter('query', $blocked); $wpdb->suppress_errors($was_suppressed); }
    fg_native_assert(get_option('fg_oauth_schema') === '1', 'OAuth marker advanced despite a missing lifecycle column.');
    FG_OAuth::install();
    fg_native_assert(get_option('fg_oauth_schema') === '3', 'Retry did not recover OAuth migration.');
});
