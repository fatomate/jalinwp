<?php
/** OAuth record cleanup regression cases. Never run against a real site. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { exit('Disposable test harness required.'); }
$fg_cleanup_root = defined('FG_TEST_WP_ROOT') ? FG_TEST_WP_ROOT : '/wordpress';
require_once $fg_cleanup_root . '/wp-load.php';
foreach (['core','auth','oauth'] as $module) {
    require_once $fg_cleanup_root . '/wp-content/plugins/jalin-mcp-gateway/includes/class-' . $module . '.php';
}
$_SERVER['HTTPS'] = 'on'; $_SERVER['SERVER_PORT'] = '443'; $_SERVER['REMOTE_ADDR'] = '192.0.2.46';
update_option('home', 'https://gateway.example.test'); update_option('siteurl', 'https://gateway.example.test');
global $wp_rewrite, $wpdb;
$wp_rewrite->set_permalink_structure('/%postname%/');
FG_Core::activate(); FG_OAuth::install();
$fg_cleanup_admin = get_users(['role'=>'administrator','number'=>1])[0];
$fg_cleanup_settings = ['enabled'=>true,'oauth_enabled'=>true,'writes'=>false,'write_mode'=>'reviewed','sensitive'=>false,'users'=>[$fg_cleanup_admin->ID],'origins'=>[],'finance_mappings'=>[]];
$fg_cleanup_results = [];
function fc_assert($condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function fc_t(string $name): string { return FG_Core::table('oauth_' . $name); }
function fc_case(string $name, callable $callback): void {
    global $wpdb, $fg_cleanup_results, $fg_cleanup_settings, $fg_cleanup_admin;
    foreach (['tokens','codes','grants','requests','clients'] as $table) { $wpdb->query('DELETE FROM ' . fc_t($table)); }
    update_option('fg_settings', $fg_cleanup_settings); update_option('fg_oauth_epoch', wp_generate_uuid4(), false);
    wp_set_current_user($fg_cleanup_admin->ID);
    try { $callback(); $fg_cleanup_results[] = ['name'=>$name,'pass'=>true]; }
    catch (Throwable $error) { $fg_cleanup_results[] = ['name'=>$name,'pass'=>false,'error'=>$error->getMessage()]; }
}
function fc_client(): string {
    global $wpdb;
    $id = wp_generate_uuid4();
    fc_assert($wpdb->insert(fc_t('clients'), ['id'=>$id,'name'=>'Cleanup Fixture','auth_method'=>'none','redirects'=>'["https://client.example.test/callback"]','authorized'=>1,'created'=>time(),'expires'=>0]) === 1, 'Client fixture failed.');
    return $id;
}
function fc_grant(?string $client = null, array $overrides = []): array {
    global $wpdb, $fg_cleanup_admin;
    $client = $client ?? fc_client();
    $row = array_replace(['id'=>wp_generate_uuid4(),'client_id'=>$client,'user_id'=>$fg_cleanup_admin->ID,
        'user_hash'=>hash_hmac('sha256', $fg_cleanup_admin->ID . '|' . $fg_cleanup_admin->user_pass . '|' . get_option('fg_oauth_epoch'), wp_salt('auth')),
        'resource'=>FG_OAuth::resource(),'allow_writes'=>0,'allow_yolo'=>0,'allow_sensitive'=>0,
        'status'=>'active','created'=>time(),'expires'=>time()+DAY_IN_SECONDS,'last_used'=>0], $overrides);
    fc_assert($wpdb->insert(fc_t('grants'), $row) === 1, 'Grant fixture failed.');
    return $row;
}
function fc_credentials(array $grant): array {
    global $wpdb;
    $values = [];
    foreach (['access','refresh'] as $kind) {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); $values[$kind] = $raw;
        fc_assert($wpdb->insert(fc_t('tokens'), ['hash'=>hash('sha256',$raw),'grant_id'=>$grant['id'],'kind'=>$kind,'status'=>'active','expires'=>time()+3600,'created'=>time()]) === 1, 'Token fixture failed.');
    }
    fc_assert($wpdb->insert(fc_t('codes'), ['hash'=>hash('sha256',wp_generate_uuid4()),'grant_id'=>$grant['id'],'client_id'=>$grant['client_id'],
        'redirect_uri'=>'https://client.example.test/callback','challenge'=>str_repeat('a',43),'resource'=>FG_OAuth::resource(),'consumed'=>0,'expires'=>time()+300]) === 1, 'Code fixture failed.');
    return $values;
}
function fc_count(string $table, ?string $grant_id = null): int {
    global $wpdb;
    $query = 'SELECT COUNT(*) FROM ' . fc_t($table);
    if ($grant_id !== null) { $query = $wpdb->prepare($query . ($table === 'grants' ? ' WHERE id=%s' : ' WHERE grant_id=%s'), $grant_id); }
    return (int) $wpdb->get_var($query);
}
function fc_fault(callable $filter, callable $callback) {
    add_filter('query', $filter);
    try { return $callback(); } finally { remove_filter('query', $filter); }
}
function fc_storage_error($result): void {
    fc_assert(is_wp_error($result) && $result->get_error_data()['status'] === 503, 'Storage failure reported as success.');
    fc_assert(!str_contains($result->get_error_message(), 'SELECT') && !str_contains($result->get_error_message(), 'fc_missing'), 'Database details leaked.');
}
fc_case('active connection deletion is refused and credentials remain usable', function () {
    $g=fc_grant(); $tokens=fc_credentials($g); $result=FG_OAuth::delete_connection($g['id']);
    fc_assert(is_wp_error($result) && $result->get_error_code()==='fg_oauth_connection_active', 'Active grant deleted.');
    fc_assert(fc_count('tokens',$g['id'])===2 && fc_count('codes',$g['id'])===1 && !is_wp_error(FG_OAuth::authenticate($tokens['access'])), 'Active credentials altered.');
    fc_assert(FG_OAuth::connections()[0]['can_delete']===false, 'Active delete action exposed.');
});
fc_case('temporary policy restriction cannot make a connection deletable', function () {
    global $fg_cleanup_settings;
    $g=fc_grant(); $tokens=fc_credentials($g);
    update_option('fg_settings', array_replace($fg_cleanup_settings,['enabled'=>false]));
    $row=FG_OAuth::connections()[0]; $result=FG_OAuth::delete_connection($g['id']);
    fc_assert($row['lifecycle_status']==='reconnect_required' && !$row['can_delete'] && is_wp_error($result), 'Temporary restriction treated as terminal.');
    update_option('fg_settings',$fg_cleanup_settings);
    fc_assert(!is_wp_error(FG_OAuth::authenticate($tokens['access'])), 'Restricted grant was silently disconnected.');
});
fc_case('revoked deletion removes tokens and codes but retains registration audit settings and changes', function () {
    global $wpdb, $fg_cleanup_admin, $fg_cleanup_settings;
    $g=fc_grant(null,['status'=>'revoked']); $tokens=fc_credentials($g);
    $change_id=wp_generate_uuid4(); $change_table=FG_Core::table('changes'); $audit_table=FG_Core::table('audit');
    fc_assert($wpdb->insert($change_table,['id'=>$change_id,'user_id'=>$fg_cleanup_admin->ID,'credential_id'=>$g['id'],'tool'=>'cleanup_fixture','arguments'=>'{}','digest'=>hash('sha256','{}'),'status'=>'applied','created'=>time(),'expires'=>time()+900])===1,'Change fixture failed.');
    fc_assert(FG_Core::audit('cleanup_fixture','read','',['auth_source'=>'oauth','oauth_grant_id'=>$g['id'],'oauth_client_id'=>$g['client_id'],'client_name'=>'Cleanup Fixture']), 'Audit fixture failed.');
    $before_audit=$wpdb->get_results("SELECT * FROM $audit_table",ARRAY_A);
    $before_change=$wpdb->get_row($wpdb->prepare("SELECT * FROM $change_table WHERE id=%s",$change_id),ARRAY_A);
    $before_settings=FG_Core::settings();
    fc_assert(FG_OAuth::connections()[0]['can_delete']===true,'Revoked record not deletable.');
    fc_assert(FG_OAuth::delete_connection($g['id'])===['deleted'=>1], 'Revoked record deletion failed.');
    fc_assert(fc_count('grants')===0 && fc_count('tokens')===0 && fc_count('codes')===0 && fc_count('clients')===1,'Cleanup scope incorrect.');
    fc_assert($before_audit===$wpdb->get_results("SELECT * FROM $audit_table",ARRAY_A),'Audit history changed.');
    fc_assert($before_change===$wpdb->get_row($wpdb->prepare("SELECT * FROM $change_table WHERE id=%s",$change_id),ARRAY_A),'Change history changed.');
    fc_assert(FG_Core::settings()===$before_settings,'Settings changed.');
    fc_assert(is_wp_error(FG_OAuth::authenticate($tokens['access'])),'Deleted credential authorized.');
    // Simulate a token insert that was already in flight when the grant was removed.
    $late=fc_credentials($g);
    fc_assert(is_wp_error(FG_OAuth::authenticate($late['access'])),'Late orphan credential revived a deleted grant.');
    fc_assert(FG_OAuth::delete_connection($g['id'])===['deleted'=>0], 'Repeat deletion not idempotent.');
});
fc_case('expired active record has truthful lifecycle and can be removed', function () {
    $g=fc_grant(null,['expires'=>time()-1]); $tokens=fc_credentials($g); $row=FG_OAuth::connections()[0];
    fc_assert($row['lifecycle_status']==='expired' && $row['lifecycle_label']==='Expired' && $row['can_delete'], 'Expiry not projected.');
    fc_assert(FG_OAuth::delete_connection($g['id'])===['deleted'=>1] && fc_count('tokens')===0 && fc_count('codes')===0 && is_wp_error(FG_OAuth::authenticate($tokens['access'])), 'Expired cleanup failed.');
});
fc_case('clear inactive covers more than displayed 100 while preserving active connections', function () {
    $client=fc_client(); $active=fc_grant($client); $tokens=fc_credentials($active);
    for($i=0;$i<105;$i++) { fc_grant($client,['status'=>'revoked']); }
    fc_grant($client,['expires'=>time()-1]);
    fc_assert(count(FG_OAuth::connections())===100,'Display fixture does not exceed listing limit.');
    fc_assert(FG_OAuth::clear_connections()===['deleted'=>106] && fc_count('grants')===1 && fc_count('clients')===1, 'Bulk cleanup truncated to displayed rows.');
    fc_assert(!is_wp_error(FG_OAuth::authenticate($tokens['access'])), 'Clear inactive disconnected active client.');
});
fc_case('clear all rotates epoch cancels pending consent and revokes previous credentials', function () {
    global $wpdb, $fg_cleanup_admin;
    $g=fc_grant(); $tokens=fc_credentials($g); $old_epoch=get_option('fg_oauth_epoch'); $pending=wp_generate_uuid4();
    $wpdb->insert(fc_t('requests'),['id'=>$pending,'user_id'=>$fg_cleanup_admin->ID,'session_hash'=>hash('sha256','fixture'),'parameters'=>'{}','status'=>'pending','created'=>time(),'expires'=>time()+300]);
    fc_assert(FG_OAuth::clear_connections(true)===['deleted'=>1] && fc_count('grants')===0 && fc_count('tokens')===0 && fc_count('codes')===0,'Clear all did not remove revoked data.');
    fc_assert($old_epoch!==get_option('fg_oauth_epoch') && $wpdb->get_var($wpdb->prepare('SELECT status FROM '.fc_t('requests').' WHERE id=%s',$pending))==='revoked','Epoch/pending request not revoked.');
    fc_assert(is_wp_error(FG_OAuth::authenticate($tokens['access'])) && fc_count('clients')===1, 'Credentials revived or registration deleted.');
});
fc_case('clear all preserves usable authorization created after its revocation phase', function () {
    $old=fc_grant(); fc_credentials($old); $new=null; $injected=false;
    $filter=static function($sql) use (&$new,&$injected) {
        if(!$injected && str_starts_with($sql,'SELECT id FROM '.fc_t('grants').' WHERE')) { $injected=true; $new=fc_grant(); }
        return $sql;
    };
    $result=fc_fault($filter,static fn()=>FG_OAuth::clear_connections(true));
    fc_assert($result===['deleted'=>1] && $new && fc_count('grants',$new['id'])===1,'New concurrent authorization was cleared.');
    $tokens=fc_credentials($new);
    fc_assert(!is_wp_error(FG_OAuth::authenticate($tokens['access'])), 'New authorization was unusable.');
});
fc_case('invalid identifier is rejected without deleting records', function () {
    fc_grant(null,['status'=>'revoked']);
    $result=FG_OAuth::delete_connection("' OR 1=1 --");
    fc_assert(is_wp_error($result) && $result->get_error_data()['status']===400 && fc_count('grants')===1,'Malformed ID accepted.');
});
fc_case('snapshot storage failure is not an empty successful cleanup', function () {
    fc_grant(null,['status'=>'revoked']);
    $result=fc_fault(static fn($sql)=>str_starts_with($sql,'SELECT id FROM '.fc_t('grants').' WHERE') ? 'SELECT * FROM fc_missing_snapshot_table' : $sql,static fn()=>FG_OAuth::clear_connections());
    fc_storage_error($result); fc_assert(fc_count('grants')===1,'Snapshot failure deleted data.');
});
fc_case('terminal claim failure preserves grant and credentials', function () {
    $g=fc_grant(null,['expires'=>time()-1]); fc_credentials($g);
    $result=fc_fault(static fn($sql)=>str_starts_with($sql,'UPDATE '.fc_t('grants')." SET status='revoked' WHERE id=") ? 'SELECT * FROM fc_missing_claim_table' : $sql,static fn()=>FG_OAuth::delete_connection($g['id']));
    fc_storage_error($result); fc_assert(fc_count('grants')===1 && fc_count('tokens')===2 && fc_count('codes')===1,'Claim failure changed credentials.');
});
fc_case('grant read failure is reported rather than mistaken for already removed', function () {
    $g=fc_grant(null,['status'=>'revoked']); fc_credentials($g);
    $result=fc_fault(static fn($sql)=>str_starts_with($sql,'SELECT status FROM '.fc_t('grants').' WHERE id=') ? 'SELECT * FROM fc_missing_read_table' : $sql,static fn()=>FG_OAuth::delete_connection($g['id']));
    fc_storage_error($result); fc_assert(fc_count('grants')===1 && fc_count('tokens')===2,'Read failure removed credentials.');
});
fc_case('dependent deletion failure remains revoked and retry cleans up', function () {
    $g=fc_grant(null,['expires'=>time()-1]); $tokens=fc_credentials($g);
    $result=fc_fault(static fn($sql)=>str_starts_with($sql,'DELETE FROM '.fc_t('codes').' WHERE') ? 'SELECT * FROM fc_missing_codes_table' : $sql,static fn()=>FG_OAuth::delete_connection($g['id']));
    fc_storage_error($result); fc_assert(fc_count('grants')===1 && fc_count('tokens')===0 && fc_count('codes')===1 && FG_OAuth::connections()[0]['lifecycle_status']==='revoked','Partial cleanup not retryable.');
    fc_assert(is_wp_error(FG_OAuth::authenticate($tokens['access'])) && FG_OAuth::delete_connection($g['id'])===['deleted'=>1], 'Partial credential revived or retry failed.');
});
fc_case('grant deletion failure preserves revoked row for retry', function () {
    $g=fc_grant(null,['status'=>'revoked']); fc_credentials($g);
    $result=fc_fault(static fn($sql)=>str_starts_with($sql,'DELETE FROM '.fc_t('grants').' WHERE id=') ? 'SELECT * FROM fc_missing_grant_delete_table' : $sql,static fn()=>FG_OAuth::delete_connection($g['id']));
    fc_storage_error($result); fc_assert(fc_count('grants')===1 && fc_count('tokens')===0 && fc_count('codes')===0,'Failed final delete falsely complete.');
});
fc_case('partial bulk failure returns actual removed count and retains failed record', function () {
    $bad=fc_grant(null,['status'=>'revoked']); fc_credentials($bad); fc_grant(null,['status'=>'revoked']);
    $result=fc_fault(static fn($sql)=>str_starts_with($sql,'DELETE FROM '.fc_t('codes').' WHERE') && str_contains($sql,$bad['id']) ? 'SELECT * FROM fc_missing_partial_table' : $sql,static fn()=>FG_OAuth::clear_connections());
    fc_storage_error($result); fc_assert($result->get_error_data()['deleted']===1 && $result->get_error_data()['failed']===1 && fc_count('grants',$bad['id'])===1,'Partial failure counts misleading.');
});
fc_case('revocation storage failure prevents clear all deleting history', function () {
    $g=fc_grant(); $tokens=fc_credentials($g);
    $result=fc_fault(static fn($sql)=>$sql==='UPDATE '.fc_t('grants')." SET status='revoked' WHERE status='active'" ? 'SELECT * FROM fc_missing_revoke_table' : $sql,static fn()=>FG_OAuth::clear_connections(true));
    fc_storage_error($result); fc_assert($result->get_error_code()==='fg_oauth_connections_revoke' && fc_count('grants')===1 && fc_count('tokens')===2 && fc_count('codes')===1,'Failed revoke still deleted records.');
    fc_assert(is_wp_error(FG_OAuth::authenticate($tokens['access'])), 'Epoch rotation did not invalidate old token.');
});
fc_case('empty history cleanup succeeds with zero count', function () { fc_assert(FG_OAuth::clear_connections()===['deleted'=>0],'Empty cleanup failed.'); });
$fg_cleanup_passed=count(array_filter($fg_cleanup_results,static fn($r)=>$r['pass']));
echo wp_json_encode(['passed'=>$fg_cleanup_passed,'total'=>count($fg_cleanup_results),'database_class'=>get_class($wpdb),'cases'=>$fg_cleanup_results],JSON_PRETTY_PRINT);
if ($fg_cleanup_passed !== count($fg_cleanup_results)) { throw new RuntimeException('OAuth cleanup regression cases failed. See the case results.'); }
