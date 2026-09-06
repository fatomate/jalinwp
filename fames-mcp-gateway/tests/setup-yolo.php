<?php
/** YOLO settings integration. Run only in an explicitly disposable WordPress install. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { throw new RuntimeException('An explicitly disposable test install is required.'); }
require '/wordpress/wp-load.php';
$plugin = '/wordpress/wp-content/plugins/fames-mcp-gateway/';
foreach (['core','auth','oauth','settings','admin'] as $class) { require_once $plugin . 'includes/class-' . $class . '.php'; }
if (!defined('FG_FILE')) { define('FG_FILE', $plugin . 'fames-mcp-gateway.php'); }
if (!defined('FG_VERSION')) { define('FG_VERSION', '0.3.1-test'); }
FG_Core::activate();
wp_set_current_user(1);
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'POST';
global $wpdb;
$wpdb->suppress_errors(true);
$cases = [];
function check($name, $value) { global $cases; $cases[] = ['name'=>$name, 'pass'=>(bool)$value]; if (!$value) { throw new RuntimeException($name); } }
function state() { return FG_Settings::snapshot('connection')['settings']; }
function invoke_admin($method, ...$args) { $method=new ReflectionMethod(FG_Admin::class,$method); $method->setAccessible(true); return $method->invoke(null,...$args); }
function seed($values=[]) { update_option('fg_settings',array_replace(['enabled'=>true,'oauth_enabled'=>true,'writes'=>true,'sensitive'=>false,'users'=>[1],'origins'=>[],'finance_mappings'=>['custom'=>['fee_key'=>'_fee','fee_divisor'=>100]]],$values),false); }
function request_status($id) { global $wpdb; return $wpdb->get_var($wpdb->prepare('SELECT status FROM '.FG_Core::table('changes').' WHERE id=%s',$id)); }
function pending_request() { global $wpdb; $id=wp_generate_uuid4(); $wpdb->insert(FG_Core::table('changes'),['id'=>$id,'user_id'=>1,'credential_id'=>'fixture','tool'=>'fixture','arguments'=>'{}','digest'=>str_repeat('a',64),'status'=>'approved','created'=>time(),'expires'=>time()+900]); return $id; }

seed();
check('Legacy write-enabled settings default to Reviewed Changes',state()['writes']===true && state()['write_mode']==='reviewed');
$before=state();$version=FG_Settings::fingerprint('connection');$revision=FG_Settings::policy_revision();$id=pending_request();
$result=FG_Settings::save_section('connection',['writes'=>true,'write_mode'=>'reviewed'],$version);
check('Saving the legacy reviewed default is a no-op',!is_wp_error($result)&&!$result['changed']&&state()===$before&&FG_Settings::policy_revision()===$revision&&request_status($id)==='approved');
foreach (['invalid','YOLO','readonly',true,[],null] as $invalid) {
    $before=state();$result=FG_Settings::save_section('connection',['write_mode'=>$invalid],FG_Settings::fingerprint('connection'));
    check('Invalid persisted mode rejected: '.get_debug_type($invalid).':'.wp_json_encode($invalid),is_wp_error($result)&&$result->get_error_code()==='fg_settings_mode'&&state()===$before);
}
$financeVersion=FG_Settings::fingerprint('finance');
$result=FG_Settings::save_section('connection',['writes'=>true,'write_mode'=>'yolo'],$version);
check('Explicit YOLO save preserves other access controls and finance',!is_wp_error($result)&&state()['writes']&&state()['write_mode']==='yolo'&&!state()['sensitive']&&state()['users']===[1]&&state()['finance_mappings']===$before['finance_mappings']);
check('YOLO mode changes fingerprint and invalidates unfinished requests',FG_Settings::fingerprint('connection')!==$version&&FG_Settings::policy_revision()!==$revision&&request_status($id)==='revoked');
$result=FG_Settings::save_section('connection',['writes'=>true,'write_mode'=>'reviewed'],$version);
check('Stale same-section form cannot overwrite YOLO mode',is_wp_error($result)&&$result->get_error_code()==='fg_settings_stale'&&state()['write_mode']==='yolo');
$result=FG_Settings::save_section('finance',['finance_mappings'=>['new'=>['fee_key'=>'_new','fee_divisor'=>1]]],$financeVersion);
check('Previously opened Finance form preserves newly saved YOLO mode',!is_wp_error($result)&&state()['write_mode']==='yolo'&&state()['finance_mappings']['new']['fee_key']==='_new');
$result=FG_Settings::save_section('finance',['write_mode'=>'reviewed'],FG_Settings::fingerprint('finance'));
check('Finance section cannot change mode',is_wp_error($result)&&state()['write_mode']==='yolo');
$revision=FG_Settings::policy_revision();$id=pending_request();
$result=FG_Settings::save_section('connection',['writes'=>true,'write_mode'=>'yolo'],FG_Settings::fingerprint('connection'));
check('Saving unchanged YOLO mode preserves requests and policy revision',!is_wp_error($result)&&!$result['changed']&&FG_Settings::policy_revision()===$revision&&request_status($id)==='approved');

class FG_Yolo_Test_Redirect extends RuntimeException {}
$redirect=static function($location) { throw new FG_Yolo_Test_Redirect($location); };add_filter('wp_redirect',$redirect);
function submit_mode($mode, $overrides=[]) {
    $_SERVER['REQUEST_METHOD']='POST';
    $_POST=array_replace(['_wpnonce'=>wp_create_nonce('fg_save'),'settings_version'=>FG_Settings::fingerprint('connection'),'change_mode'=>$mode,'oauth_enabled'=>'1','origins'=>''],$overrides);$_REQUEST=$_POST;
    try { FG_Admin::save(); } catch(FG_Yolo_Test_Redirect $e) { return $e->getMessage(); }
    throw new RuntimeException('Expected handler redirect.');
}
$url=submit_mode('readonly');
check('Read Only POST disables writes and clears latent YOLO mode',str_contains($url,'saved=changed')&&!state()['writes']&&state()['write_mode']==='reviewed');
$url=submit_mode('reviewed');
check('Reviewed Changes POST enables reviewed writes',str_contains($url,'saved=changed')&&state()['writes']&&state()['write_mode']==='reviewed');
$url=submit_mode('yolo');
check('YOLO POST enables direct mode without another dashboard confirmation',str_contains($url,'saved=changed')&&state()['writes']&&state()['write_mode']==='yolo');
$url=submit_mode('yolo');check('Repeated YOLO POST reports unchanged',str_contains($url,'saved=unchanged'));
foreach (['', 'admin', ['yolo']] as $invalid) {
    $before=state();$url=submit_mode($invalid);$error=get_transient('fg_connection_form_1');
    check('Invalid submitted mode does not alter settings: '.wp_json_encode($invalid),str_contains($url,'settings_error=1')&&$error['code']==='fg_settings_mode'&&state()===$before);
}
$before=state();$url=submit_mode('reviewed',['origins'=>'https://bad.example/path']);
$error=get_transient('fg_connection_form_1');
check('Origin validation preserves unsaved reviewed selection',str_contains($url,'settings_error=1')&&$error['input']['write_mode']==='reviewed'&&$error['input']['writes']===true&&state()===$before);
$_GET=['settings_error'=>'1'];ob_start();invoke_admin('form',state());$html=ob_get_clean();$_GET=[];
check('Error form shows unsaved mode separately from current YOLO setting',str_contains($html,'Compare With Current Saved Settings')&&preg_match('/id="fg-mode-reviewed"[^>]*checked=/', $html)&&str_contains($html,'<strong>Change Mode:</strong> YOLO Mode (No Dashboard Approval)'));
delete_transient('fg_connection_form_1');
ob_start();invoke_admin('form',state());$html=ob_get_clean();
check('Three explicit change modes replace the writes checkbox',substr_count($html,'type="radio"')===3&&!str_contains($html,'name="writes"')&&str_contains($html,'Read Only')&&str_contains($html,'Reviewed Changes')&&str_contains($html,'YOLO Mode (No Dashboard Approval)'));
check('Current YOLO selection and all mode descriptions are accessible',preg_match('/id="fg-mode-yolo"[^>]*checked=/', $html)&&substr_count($html,'aria-describedby="fg-mode-help-')===3);
check('Remaining access checkboxes keep permanent help',substr_count($html,'type="checkbox"')===2&&substr_count($html,'aria-describedby="fg-help-')===2);
check('YOLO UI explains retained controls and one-time OAuth reconnect',str_contains($html,'WordPress permissions, data access controls, validation, and activity logging still apply.')&&str_contains($html,'reconnect each client once')&&str_contains($html,'Previously approved reviewed connections continue to require dashboard approval'));
ob_start();invoke_admin('connect',state());$html=ob_get_clean();
check('Saved change mode is visible beside connection setup',str_contains($html,'<strong>Change Mode:</strong> YOLO Mode (No Dashboard Approval)'));
$before=state();$epoch=get_option('fg_oauth_epoch');$result=FG_Settings::disable(FG_Settings::fingerprint('connection'));
check('Disable preserves YOLO preference while invalidating OAuth credentials',!is_wp_error($result)&&!state()['enabled']&&state()['write_mode']==='yolo'&&get_option('fg_oauth_epoch')!==$epoch&&state()['finance_mappings']===$before['finance_mappings']);
$result=FG_Settings::enable_for_user(1,FG_Settings::fingerprint('connection'));
check('Re-enable keeps deliberate mode choice without opting in new permissions',!is_wp_error($result)&&state()['enabled']&&state()['write_mode']==='yolo'&&!state()['sensitive']);
$ready=pending_request();$wpdb->update(FG_Core::table('changes'),['status'=>'ready','server_context'=>wp_json_encode(['execution_mode'=>'yolo'])],['id'=>$ready]);
$result=FG_Settings::save_section('connection',['writes'=>true,'write_mode'=>'reviewed'],FG_Settings::fingerprint('connection'));
check('Changing mode revokes queued direct execution records',!is_wp_error($result)&&request_status($ready)==='revoked');
$wpdb->update(FG_Core::table('changes'),['status'=>'applied'],['id'=>$ready]);
ob_start();invoke_admin('changes');$html=ob_get_clean();
check('Direct execution history identifies YOLO without an approval button',str_contains($html,'YOLO — No Dashboard Approval')&&!str_contains($html,'name="change_id" value="'.$ready.'"'));
FG_Core::audit('fixture','yolo_applied',$ready,['auth_source'=>'wordpress_admin']);ob_start();invoke_admin('audit');$html=ob_get_clean();
check('Activity renders readable YOLO outcomes',str_contains($html,'YOLO Applied'));
FG_Settings::save_section('connection',['writes'=>true,'write_mode'=>'yolo'],FG_Settings::fingerprint('connection'));
$hash=new ReflectionMethod(FG_OAuth::class,'user_hash');$hash->setAccessible(true);
foreach ([['Legacy Reviewed Client',0],['New YOLO Client',1]] as [$name,$allowYolo]) {
    $client=wp_generate_uuid4();$grant=wp_generate_uuid4();
    $wpdb->insert(FG_Core::table('oauth_clients'),['id'=>$client,'secret_hash'=>'','name'=>$name,'auth_method'=>'none','redirects'=>'[]','authorized'=>1,'created'=>time(),'expires'=>0]);
    $wpdb->insert(FG_Core::table('oauth_grants'),['id'=>$grant,'client_id'=>$client,'user_id'=>1,'user_hash'=>$hash->invoke(null,1),'resource'=>FG_OAuth::resource(),'allow_writes'=>1,'allow_yolo'=>$allowYolo,'allow_sensitive'=>0,'status'=>'active','created'=>time()-100,'expires'=>time()+3600,'last_used'=>time()-10,'tokens_issued_at'=>time()-80,'consent_recorded_at'=>time()-100]);
}
ob_start();invoke_admin('connections');$html=ob_get_clean();
check('Connection table distinguishes reviewed consent from active YOLO',str_contains($html,'Legacy Reviewed Client')&&str_contains($html,'New YOLO Client')&&str_contains($html,'YOLO Changes (No Dashboard Approval)')&&substr_count($html,'YOLO Active:')===1&&str_contains($html,'Dashboard approval is required for this connection.'));
remove_filter('wp_redirect',$redirect);

$die=static function(){return static function($message){throw new RuntimeException(is_scalar($message)?(string)$message:'WP die');};};add_filter('wp_die_handler',$die);
foreach ([['GET',1,'valid','GET'],['POST',1,'bad','invalid nonce'],['POST',0,'bad','unauthorized account']] as [$method,$uid,$nonce,$label]) {
    $before=state();$_SERVER['REQUEST_METHOD']=$method;wp_set_current_user($uid);$_POST=['change_mode'=>'yolo','oauth_enabled'=>'1','settings_version'=>FG_Settings::fingerprint('connection'),'_wpnonce'=>$nonce==='valid'?wp_create_nonce('fg_save'):'bad'];$_REQUEST=$_POST;
    $blocked=false;try{FG_Admin::save();}catch(RuntimeException $e){$blocked=true;}check('YOLO save rejects '.$label.' before changing settings',$blocked&&state()===$before);
}
remove_filter('wp_die_handler',$die);
echo json_encode(['passed'=>count($cases),'total'=>count($cases),'cases'=>$cases],JSON_PRETTY_PRINT);
