<?php
/** Integration tests. Creates and removes disposable fixture settings and grants. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { throw new RuntimeException('An explicitly disposable test install is required.'); }
require '/wordpress/wp-load.php';
$plugin = '/wordpress/wp-content/plugins/fames-mcp-gateway/';
foreach (['core','auth','oauth','settings','admin'] as $class) { require_once $plugin . 'includes/class-' . $class . '.php'; }
if (!defined('FG_FILE')) { define('FG_FILE', $plugin . 'fames-mcp-gateway.php'); }
if (!defined('FG_VERSION')) { define('FG_VERSION', '0.3.0-test'); }
FG_Core::activate();
wp_set_current_user(1);
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'POST';
global $wpdb;
$wpdb->suppress_errors(true);
$cases = [];
function check($name, $value) { global $cases; $cases[] = ['name'=>$name, 'pass'=>(bool)$value]; if (!$value) { throw new RuntimeException($name); } }
function fresh_settings() { wp_cache_delete('fg_settings','options'); wp_cache_delete('alloptions','options'); return FG_Core::settings(); }
function seed_settings($overrides = []) { $base=['enabled'=>false,'oauth_enabled'=>false,'writes'=>false,'sensitive'=>false,'users'=>[77],'origins'=>['https://app.example'],'finance_mappings'=>['custom'=>['fee_key'=>'_fee','fee_divisor'=>100]]]; update_option('fg_settings',array_replace($base,$overrides),false); return fresh_settings(); }
function proposal() { global $wpdb; $id=wp_generate_uuid4(); $wpdb->insert(FG_Core::table('changes'),['id'=>$id,'user_id'=>1,'credential_id'=>'fixture','tool'=>'fixture','arguments'=>'{}','digest'=>str_repeat('a',64),'status'=>'approved','created'=>time(),'expires'=>time()+900]); return $id; }
function call_private($method, ...$args) { $r=new ReflectionMethod(FG_Admin::class,$method);$r->setAccessible(true);return $r->invoke(null,...$args); }
seed_settings();
$before=fresh_settings();$v=FG_Settings::fingerprint('connection');
$r=FG_Settings::enable_for_user(1,$v);$s=fresh_settings();
check('Enable succeeds and adds only current administrator', !is_wp_error($r) && $s['enabled'] && $s['oauth_enabled'] && $s['users']===[77,1]);
check('Enable preserves finance preferences and origins', $s['finance_mappings']===$before['finance_mappings'] && $s['origins']===$before['origins'] && !$s['sensitive'] && !$s['writes']);
check('Policy revision established and fresh', FG_Settings::policy_revision()===($s['_policy_revision']??'') && strlen(FG_Settings::policy_revision())===36);
$id=proposal();$revision=FG_Settings::policy_revision();$v=FG_Settings::fingerprint('connection');
$r=FG_Settings::enable_for_user(1,$v);
check('Enable duplicate is a no-op', !is_wp_error($r) && !$r['changed'] && FG_Settings::policy_revision()===$revision);
check('No-op preserves pending approval', $wpdb->get_var($wpdb->prepare('SELECT status FROM '.FG_Core::table('changes').' WHERE id=%s',$id))==='approved');
$finance_version=FG_Settings::fingerprint('finance');$connect_version=FG_Settings::fingerprint('connection');
$r=FG_Settings::save_section('finance',['finance_mappings'=>['custom'=>['fee_key'=>'_new_fee','fee_divisor'=>1000]]],$finance_version);
check('Finance save succeeds',!is_wp_error($r));
$r=FG_Settings::save_section('connection',['writes'=>true],$connect_version);
check('Independent cross-tab save merges current finance',!is_wp_error($r) && fresh_settings()['finance_mappings']['custom']['fee_key']==='_new_fee' && fresh_settings()['writes']);
check('Real changes revoke approvals', $wpdb->get_var($wpdb->prepare('SELECT status FROM '.FG_Core::table('changes').' WHERE id=%s',$id))==='revoked');
$r=FG_Settings::save_section('connection',['sensitive'=>true],$connect_version);
check('Stale same-section save rejected', is_wp_error($r) && $r->get_error_code()==='fg_settings_stale' && !fresh_settings()['sensitive']);
$r=FG_Settings::save_section('finance',['enabled'=>false],FG_Settings::fingerprint('finance'));
check('Finance cannot alter access controls',is_wp_error($r)&&fresh_settings()['enabled']);
$r=FG_Settings::save_section('connection',['users'=>[]],FG_Settings::fingerprint('connection'));
check('Connection form cannot clear allowed users',is_wp_error($r)&&fresh_settings()['users']===[77,1]);
$r=FG_Settings::save_section('connection',['enabled'=>false],FG_Settings::fingerprint('connection'));
check('Connection form cannot disable or enable gateway',is_wp_error($r)&&fresh_settings()['enabled']);
$r=FG_Settings::disable('');check('Missing version rejects state mutation',is_wp_error($r)&&fresh_settings()['enabled']);

// Force an unrelated writer between the read and conditional update. The first CAS must fail,
// then the retry must merge the newer Finance data without overriding it.
$v=FG_Settings::fingerprint('connection');$once=false;
$race=static function($sql)use(&$once,$wpdb){if(!$once && str_starts_with($sql,"UPDATE $wpdb->options SET option_value=")&&str_contains($sql,"option_name='fg_settings'")){$once=true;$s=fresh_settings();$s['finance_mappings']['race']=['fee_key'=>'_race','fee_divisor'=>1];update_option('fg_settings',$s,false);}return $sql;};
add_filter('query',$race);$r=FG_Settings::save_section('connection',['sensitive'=>true],$v);remove_filter('query',$race);
check('CAS protects unrelated concurrent write',!is_wp_error($r)&&$once&&isset(fresh_settings()['finance_mappings']['race'])&&fresh_settings()['sensitive']);
$v=FG_Settings::fingerprint('connection');$once=false;
$race=static function($sql)use(&$once,$wpdb){if(!$once && str_starts_with($sql,"UPDATE $wpdb->options SET option_value=")&&str_contains($sql,"option_name='fg_settings'")){$once=true;$s=fresh_settings();$s['origins']=['https://concurrent.example'];update_option('fg_settings',$s,false);}return $sql;};
add_filter('query',$race);$r=FG_Settings::save_section('connection',['writes'=>false],$v);remove_filter('query',$race);
check('CAS rejects conflicting concurrent write',is_wp_error($r)&&$once&&fresh_settings()['writes']&&fresh_settings()['origins']===['https://concurrent.example']);
$before=fresh_settings();$epoch=get_option('fg_oauth_epoch');$r=FG_Settings::disable(FG_Settings::fingerprint('connection'));$s=fresh_settings();
check('Disable persists first and rotates OAuth epoch',!is_wp_error($r)&&!$s['enabled']&&get_option('fg_oauth_epoch')!==$epoch);
check('Disable preserves allowed users and all preferences',$s['users']===$before['users']&&$s['finance_mappings']===$before['finance_mappings']&&$s['sensitive']===$before['sensitive']&&$s['writes']===$before['writes']);
$epoch=get_option('fg_oauth_epoch');$r=FG_Settings::enable_for_user(1,FG_Settings::fingerprint('connection'));
check('Re-enable cannot restore previous OAuth epoch',!is_wp_error($r)&&fresh_settings()['enabled']&&get_option('fg_oauth_epoch')===$epoch);
// Force revoke_all storage cleanup to fail after disable has persisted.
$fail=static fn($sql)=>str_starts_with($sql,'UPDATE '.FG_Core::table('oauth_grants').' ') ? 'UPDATE fg_missing_storage SET status=1' : $sql;
add_filter('query',$fail);$r=FG_Settings::disable(FG_Settings::fingerprint('connection'));remove_filter('query',$fail);
check('Failed revocation leaves gateway disabled and pending',is_wp_error($r)&&!fresh_settings()['enabled']&&!empty(fresh_settings()['_oauth_revoke_pending']));
add_filter('query',$fail);$r=FG_Settings::enable_for_user(1,FG_Settings::fingerprint('connection'));remove_filter('query',$fail);
check('Cannot re-enable while revocation keeps failing',is_wp_error($r)&&!fresh_settings()['enabled']);
$r=FG_Settings::enable_for_user(1,FG_Settings::fingerprint('connection'));
check('Re-enable completes pending revocation before access resumes',!is_wp_error($r)&&fresh_settings()['enabled']&&empty(fresh_settings()['_oauth_revoke_pending']));
$r=FG_Settings::remove_account(77,FG_Settings::fingerprint('connection'));
check('Explicit account removal preserves current administrator',!is_wp_error($r)&&fresh_settings()['users']===[1]);
$r=FG_Settings::enable_for_user(77,FG_Settings::fingerprint('connection'));
check('Cannot enable missing or unauthorized account',is_wp_error($r)&&fresh_settings()['users']===[1]);
$before=fresh_settings();$deny=static fn($sql)=>str_starts_with($sql,"UPDATE $wpdb->options SET option_value=")&&str_contains($sql,"option_name='fg_settings'") ? 'UPDATE fg_missing_storage SET status=1' : $sql;
add_filter('query',$deny);$r=FG_Settings::disable(FG_Settings::fingerprint('connection'));remove_filter('query',$deny);
check('Failed settings write does not claim success',is_wp_error($r)&&fresh_settings()===$before);

// Paired snapshots and mapping deltas remain safe even if the WordPress option cache is stale.
$old=FG_Settings::snapshot('connection');$new=$old['settings'];$new['writes']=false;$new['finance_mappings']['external']=['fee_key'=>'_external','fee_divisor'=>1];
$wpdb->update($wpdb->options,['option_value'=>maybe_serialize($new)],['option_name'=>'fg_settings']);
$snapshot=FG_Settings::snapshot('connection');
check('Snapshot reads current database settings with matched version',$snapshot['settings']['writes']===false&&$snapshot['version']===FG_Settings::version_for('connection',$new));
ob_start();call_private('form',$old['settings']);$html=ob_get_clean();preg_match('/name="settings_version" value="([^"]+)"/',$html,$version_match);
check('Form uses version of displayed values despite a concurrent save',($version_match[1]??'')===$old['version']&&$old['version']!==$snapshot['version']);
$r=FG_Settings::mutate_finance('custom',['fee_key'=>'_delta','fee_divisor'=>1],'edit',FG_Settings::fingerprint('finance'));
check('Finance delta preserves fresh unrelated mappings',!is_wp_error($r)&&isset(fresh_settings()['finance_mappings']['external'])&&fresh_settings()['finance_mappings']['custom']['fee_key']==='_delta');
$r=FG_Settings::mutate_finance('custom',[],'create',FG_Settings::fingerprint('finance'));check('Finance duplicate check uses current mapping state',is_wp_error($r)&&$r->get_error_code()==='duplicate_gateway');
$r=FG_Settings::mutate_finance('missing',[],'edit',FG_Settings::fingerprint('finance'));check('Finance missing check uses current mapping state',is_wp_error($r)&&$r->get_error_code()==='mapping_missing');

// Admin output: no all-user enumeration, checked controls explained, clean account state.
$all_users=false;$watch=static function($query)use(&$all_users){$all_users=true;};add_action('pre_get_users',$watch);
ob_start();call_private('form',fresh_settings());$html=ob_get_clean();remove_action('pre_get_users',$watch);
check('Access UI never enumerates all WordPress users',!$all_users&&!str_contains($html,'users[]'));
check('Gateway checkbox removed from access form',!str_contains($html,'name="enabled"'));
check('Each remaining access checkbox has associated permanent help',substr_count($html,'type="checkbox"')===2&&substr_count($html,'aria-describedby="fg-help-')===2);
check('Connection form excludes finance data',!str_contains($html,'finance[')&&!str_contains($html,'finance_mappings'));
check('Compact account summary and Title Case render',str_contains($html,'Your Account')&&str_contains($html,'Advanced Access')&&str_contains($html,'Save Access Controls'));
ob_start();call_private('connect',fresh_settings());$html=ob_get_clean();
check('Enabled status shows one-click Disable MCP Connection',str_contains($html,'MCP Enabled')&&str_contains($html,'Disable MCP Connection')&&!str_contains($html,'Enable For My Account'));
$s=fresh_settings();$s['users']=[77];ob_start();call_private('connect',$s);$html=ob_get_clean();
check('Site enabled but current account not allowed has both actions',str_contains($html,'MCP Enabled')&&str_contains($html,'Disable MCP Connection')&&str_contains($html,'Enable For My Account'));
$s['enabled']=false;ob_start();call_private('connect',$s);$html=ob_get_clean();check('Disabled state has only Enable For My Account',str_contains($html,'MCP Disabled')&&str_contains($html,'Enable For My Account')&&!str_contains($html,'Disable MCP Connection'));
$fail=static fn($sql)=>str_starts_with($sql,'SELECT g.')&&str_contains($sql,FG_Core::table('oauth_grants')) ? 'SELECT * FROM fg_missing_storage' : $sql;
add_filter('query',$fail);ob_start();call_private('connections');$html=ob_get_clean();remove_filter('query',$fail);
check('Storage failure has error instead of empty OAuth list',str_contains($html,'Connections Could Not Be Loaded')&&!str_contains($html,'No OAuth connections are recorded'));
$wpdb->query('DELETE FROM '.FG_Core::table('oauth_grants'));ob_start();call_private('connections');$html=ob_get_clean();
check('Genuine empty OAuth list has distinct guidance',str_contains($html,'No OAuth connections are recorded.')&&str_contains($html,'Open Connection Setup')&&!str_contains($html,'Connections Could Not Be Loaded'));
FG_Core::audit('setup_fixture','passed','',['auth_source'=>'wordpress_admin']);ob_start();call_private('audit');$html=ob_get_clean();
check('Activity table renders Client and explicit WordPress Admin',str_contains($html,'<th>Client</th>')&&str_contains($html,'WordPress Admin'));
// Real grant storage -> real FG_OAuth::connections -> actual FG_Admin table. No listing stub.
$hash_method=new ReflectionMethod(FG_OAuth::class,'user_hash');$hash_method->setAccessible(true);
foreach (['Claude','ChatGPT & Co. <script>alert("x")</script>'] as $client_name) {
 $client_id=wp_generate_uuid4();$grant_id=wp_generate_uuid4();
 $wpdb->insert(FG_Core::table('oauth_clients'),['id'=>$client_id,'secret_hash'=>'','name'=>$client_name,'auth_method'=>'none','redirects'=>'[]','authorized'=>1,'created'=>time(),'expires'=>0]);
 $wpdb->insert(FG_Core::table('oauth_grants'),['id'=>$grant_id,'client_id'=>$client_id,'user_id'=>1,'user_hash'=>$hash_method->invoke(null,1),'resource'=>FG_OAuth::resource(),'allow_writes'=>1,'allow_sensitive'=>1,'status'=>'active','created'=>time()-500,'expires'=>time()+86400,'last_used'=>time()-30,'tokens_issued_at'=>time()-480,'first_authenticated_at'=>time()-470,'consent_recorded_at'=>time()-500]);
 FG_Core::audit('wp_content_list','read','',['auth_source'=>'oauth','oauth_client_id'=>$client_id,'oauth_grant_id'=>$grant_id,'client_name'=>$client_name]);
}
ob_start();call_private('connections');$html=ob_get_clean();
check('Actual listing renders two clients for the same WordPress account',str_contains($html,'Claude')&&str_contains($html,'ChatGPT &amp; Co.')&&substr_count($html,'name="grant_id"')===2);
check('Client display text is escaped and lifecycle evidence rendered',!str_contains($html,'<script>')&&str_contains($html,'Last Authenticated')&&str_contains($html,'Active'));

// Optional visual fixtures are only written by the disposable runtime wrapper.
if (defined('FG_SETUP_UI_PREVIEW_DIR')) {
 if (!is_dir(FG_SETUP_UI_PREVIEW_DIR)) { mkdir(FG_SETUP_UI_PREVIEW_DIR,0777,true); }
 foreach (['connection','connection-trace','discovery'] as $module) { require_once $plugin.'includes/class-'.$module.'.php'; }
 $styles='';foreach (['common','forms','buttons'] as $style) {$file=ABSPATH.'wp-admin/css/'.$style.'.min.css';if(is_readable($file)){$styles.=file_get_contents($file);}}
 file_put_contents(FG_SETUP_UI_PREVIEW_DIR.'/wordpress-admin.css',$styles);
 copy($plugin.'assets/admin.css',FG_SETUP_UI_PREVIEW_DIR.'/admin.css');copy($plugin.'assets/admin.js',FG_SETUP_UI_PREVIEW_DIR.'/admin.js');
 function write_ui_fixture($name,$content) {
  $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fames MCP — Visual Fixture</title><link rel="stylesheet" href="wordpress-admin.css"><link rel="stylesheet" href="admin.css"><style>body{font:13px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f0f1;color:#1d2327;padding:24px}.wrap{margin-left:auto;margin-right:auto}.fg-fixture-banner{max-width:1120px;margin:auto;padding:8px;color:#586271}button,input,textarea{font-family:inherit}</style></head><body><p class="fg-fixture-banner">Disposable Visual Fixture — Forms Do Not Submit</p>'.$content.'<script>document.addEventListener("submit",function(event){event.preventDefault();});</script><script src="admin.js"></script></body></html>';
  $html=preg_replace('/action="[^"]*"/','action="#"',$html);
  $html=preg_replace('/href="https?:[^"]*"/','href="#"',$html);
  file_put_contents(FG_SETUP_UI_PREVIEW_DIR.'/'.$name.'.html',$html);
 }
 $_GET=[];ob_start();FG_Admin::page();write_ui_fixture('connection',ob_get_clean());
 $_GET=['tab'=>'audit'];ob_start();FG_Admin::page();write_ui_fixture('activity',ob_get_clean());
 $state=fresh_settings();$disabled=$state;$disabled['enabled']=false;ob_start();echo '<div class="wrap fg-wrap">';call_private('connect',$disabled);call_private('form',$disabled);echo '</div>';write_ui_fixture('disabled',ob_get_clean());
 $unallowed=$state;$unallowed['users']=[77];ob_start();echo '<div class="wrap fg-wrap">';call_private('connect',$unallowed);call_private('form',$unallowed);echo '</div>';write_ui_fixture('account-disabled',ob_get_clean());
 $_GET=[];
}

// Actual POST handlers preserve values on validation errors and submit changes in one action.
class FG_Setup_Test_Redirect extends RuntimeException {}
$redirect = static function($location) { throw new FG_Setup_Test_Redirect($location); };
add_filter('wp_redirect', $redirect);
function submit_setup($method, $nonce, $values=[]) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=array_replace(['_wpnonce'=>wp_create_nonce($nonce),'settings_version'=>FG_Settings::fingerprint('connection')],$values); $_REQUEST=$_POST;
 try { FG_Admin::$method(); } catch (FG_Setup_Test_Redirect $e) { return $e->getMessage(); }
 throw new RuntimeException('Expected successful handler redirect');
}
$url=submit_setup('disable_connection','fg_disable_connection');check('One disable POST persists and returns to Connection',str_contains($url,'saved=changed')&&!fresh_settings()['enabled']);
$url=submit_setup('quick_connect','fg_quick_connect');check('One enable POST persists and returns to Connection',str_contains($url,'saved=changed')&&fresh_settings()['enabled']);
$before=fresh_settings();$url=submit_setup('save','fg_save',['change_mode'=>'reviewed','oauth_enabled'=>'1','origins'=>"https://bad.example/path\nhttps://keep.example"]);
$error=get_transient('fg_connection_form_1');check('Invalid origin preserves submitted input without settings mutation',str_contains($url,'settings_error=1')&&($error['input']['origins']??'')==="https://bad.example/path\nhttps://keep.example"&&fresh_settings()===$before);
$_GET=['settings_error'=>'1'];ob_start();call_private('form',fresh_settings());$html=ob_get_clean();$_GET=[];
check('Validation error renders preserved input and current-settings comparison',str_contains($html,'https://bad.example/path')&&str_contains($html,'Compare With Current Saved Settings')&&str_contains($html,'notice-error'));
remove_filter('wp_redirect',$redirect);
// Guard verification uses normal WordPress nonce check paths, never successful mutating endpoints.
$die=static function(){return static function($message){throw new RuntimeException(is_scalar($message)?(string)$message:'WP die');};};add_filter('wp_die_handler',$die);
foreach ([['GET',1,'valid','GET'],['POST',1,'bad','invalid nonce'],['POST',0,'bad','unauthorized']] as [$method,$uid,$nonce,$label]) {
 $_SERVER['REQUEST_METHOD']=$method;wp_set_current_user($uid);$_REQUEST['_wpnonce']=$nonce==='valid'?wp_create_nonce('fg_disable_connection'):'bad';$blocked=false;try{FG_Admin::disable_connection();}catch(RuntimeException $e){$blocked=true;}check('Disable action rejects '.$label,$blocked);
}
remove_filter('wp_die_handler',$die);
echo json_encode(['passed'=>count($cases),'total'=>count($cases),'cases'=>$cases],JSON_PRETTY_PRINT);
