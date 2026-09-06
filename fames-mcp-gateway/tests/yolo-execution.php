<?php
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { exit('Disposable test harness required.'); }
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin('woocommerce/woocommerce.php');
activate_plugin('fames-mcp-gateway/fames-mcp-gateway.php');
WC_Install::install(); WC()->init(); WC_Post_Types::register_taxonomies(); WC_Post_Types::register_post_types(); WC_Post_Types::register_post_status(); WC()->load_rest_api(); WC_Install::create_roles();
$GLOBALS['wp_roles'] = new WP_Roles();
if (!defined('REST_REQUEST')) { define('REST_REQUEST', true); }
$_SERVER['HTTPS'] = 'on';
$admin = get_users(['role'=>'administrator','number'=>1])[0];
wp_set_current_user($admin->ID);
$credential = WP_Application_Passwords::create_new_application_password($admin->ID, ['name'=>'YOLO Disposable Test']);
if (is_wp_error($credential)) { throw new RuntimeException('Fixture authentication setup failed.'); }
rest_get_server(); FG_Page_Layouts::register_canvas();
$cases=[]; $new_page=0;
function yolo_assert(bool $pass, string $message): void { if (!$pass) { throw new RuntimeException($message); } }
function yolo_mode(string $mode='yolo', bool $sensitive=true): void {
    global $admin;
    update_option('fg_settings', array_replace(FG_Core::settings(), ['enabled'=>true,'oauth_enabled'=>true,'writes'=>$mode !== 'read_only','write_mode'=>$mode === 'yolo' ? 'yolo' : 'reviewed','sensitive'=>$sensitive,'users'=>[$admin->ID],'_policy_revision'=>wp_generate_uuid4()]));
}
function yolo_auth(): void {
    global $admin,$credential;
    wp_set_current_user(0);
    $user=wp_authenticate_application_password(null,$admin->user_login,$credential[0]);
    yolo_assert($user instanceof WP_User,'Application Password authentication failed');
    wp_set_current_user($user->ID);
    yolo_assert(FG_Auth::permission(new WP_REST_Request('POST','/fames-mcp/v1/mcp'))===true,'Gateway authentication failed');
}
function yolo_reject(callable $call, string $reason=''): FG_Failure {
    try { $call(); } catch (FG_Failure $error) { yolo_assert($reason==='' || $error->reason===$reason,'Expected '.$reason.' got '.$error->reason); return $error; }
    throw new RuntimeException('Expected request rejection');
}
function yolo_case(string $name, callable $call): void {
    global $cases;
    try { yolo_mode(); yolo_auth(); $call(); $cases[]=['name'=>$name,'pass'=>true]; }
    catch (Throwable $error) { $cases[]=['name'=>$name,'pass'=>false,'error'=>$error->getMessage()]; }
}
function yolo_page_args(): array {
    return ['title'=>'YOLO Draft','layout'=>FG_Page_Layouts::CANVAS,'blocks'=>[['name'=>'core/heading','attributes'=>['content'=>'Editable Heading','level'=>1]],['name'=>'core/paragraph','attributes'=>['content'=>'Editable paragraph.']]]];
}
function yolo_error_id(FG_Failure $error): string {
    yolo_assert((bool)preg_match('/Change ID: ([a-f0-9-]{36})/', $error->getMessage(), $m),'Execution error does not expose its change ID'); return $m[1];
}

yolo_case('YOLO applies a draft immediately with client-attributed audit and no administrator review', static function(): void {
    global $wpdb;
    $result=FG_Tools::call('wp_content_create',['kind'=>'posts','title'=>'Immediate Draft','content'=>'Hello']);
    $row=FG_Approvals::get_owned($result['change_id']);
    yolo_assert($result['status']==='applied' && $result['execution_mode']==='yolo' && $row['status']==='applied','Direct write did not finish in one call');
    yolo_assert(empty($row['reviewer_id']) && !isset($result['review_url']),'YOLO fabricated dashboard approval');
    yolo_assert(get_post($result['result']['data']['id'])->post_status==='draft','Draft default changed');
    $events=$wpdb->get_results($wpdb->prepare('SELECT outcome,auth_source FROM '.FG_Core::table('audit').' WHERE change_id=%s ORDER BY id',$result['change_id']),ARRAY_A);
    yolo_assert(array_column($events,'outcome')===['yolo_requested','yolo_execution_started','yolo_applied'],'Direct audit sequence differs');
    yolo_assert(count(array_filter($events,static fn($e)=>$e['auth_source']==='application_password'))===3,'Request identity missing');
    yolo_reject(static fn()=>FG_Approvals::apply($result['change_id']),'not_approved');
});
yolo_case('Read Only refuses writes and removes mutating tools from discovery', static function(): void {
    yolo_mode('read_only'); yolo_auth();
    yolo_reject(static fn()=>FG_Tools::call('wp_content_create',['kind'=>'posts','title'=>'Blocked']),'read_only');
    yolo_assert(!in_array('wp_content_create',array_column(FG_Tools::catalog(),'name'),true),'Read-only catalog advertises writes');
});
yolo_case('Reviewed mode still proposes and requires dashboard approval', static function(): void {
    yolo_mode('reviewed'); yolo_auth();
    $result=FG_Tools::call('wp_content_create',['kind'=>'posts','title'=>'Pending Review']);
    yolo_assert($result['status']==='pending' && isset($result['review_url']),'Review workflow bypassed');
    yolo_reject(static fn()=>FG_Approvals::apply($result['change_id']),'not_approved');
    FG_Approvals::review($result['change_id'],'approved');
    yolo_assert(FG_Approvals::apply($result['change_id'])['status']==='applied','Reviewed apply stopped working');
});
yolo_case('Enabling YOLO revokes old pending changes and never executes them', static function(): void {
    yolo_mode('reviewed'); yolo_auth();
    $result=FG_Tools::call('wp_content_create',['kind'=>'posts','title'=>'Do Not Auto Execute']);
    $saved=FG_Settings::save_section('connection',['writes'=>true,'write_mode'=>'yolo'],FG_Settings::fingerprint('connection'));
    yolo_assert(!is_wp_error($saved),'Mode save failed');
    yolo_assert(FG_Approvals::status($result['change_id'])['status']==='revoked','Pending request survived mode change');
    yolo_reject(static fn()=>FG_Approvals::apply($result['change_id']),'not_approved');
});
yolo_case('Unknown fields cannot select or bypass execution mode', static function(): void {
    yolo_reject(static fn()=>FG_Tools::call('wp_content_create',['kind'=>'posts','title'=>'Blocked','skip_approval'=>true]),'invalid_arguments');
});
yolo_case('Canvas designs apply without browser approval while retaining editable content', static function() use (&$new_page): void {
    $result=FG_Tools::call('wp_page_design_create',yolo_page_args()); $new_page=(int)$result['result']['data']['id'];
    yolo_assert($result['status']==='applied' && get_page_template_slug($new_page)===FG_Page_Layouts::CANVAS,'Canvas create failed');
    yolo_assert(has_blocks(get_post($new_page)->post_content) && get_post($new_page)->post_status==='draft','Editable draft content missing');
});
yolo_case('YOLO layout updates preserve unsupported content and paired recovery revisions', static function() use (&$new_page): void {
    $source='<!-- wp:vendor/keep --><div>Preserve me</div><!-- /wp:vendor/keep -->';
    wp_update_post(['ID'=>$new_page,'post_content'=>$source]);
    $result=FG_Tools::call('wp_page_design_update',['id'=>$new_page,'expected_state'=>FG_Page_Design::state($new_page),'layout'=>'default']);
    yolo_assert(get_post($new_page)->post_content===$source && !empty($result['result']['data']['recovery_revision_id']),'Recovery or content preservation failed');
});
yolo_case('Stale page state and unsupported blocks remain rejected in YOLO', static function() use (&$new_page): void {
    $state=FG_Page_Design::state($new_page); wp_update_post(['ID'=>$new_page,'post_title'=>'Manual Edit']);
    yolo_reject(static fn()=>FG_Tools::call('wp_page_design_update',['id'=>$new_page,'expected_state'=>$state,'title'=>'Stale']),'page_changed');
    $args=yolo_page_args();$args['blocks']=[['name'=>'core/html','attributes'=>[]]];
    yolo_reject(static fn()=>FG_Tools::call('wp_page_design_create',$args));
    yolo_assert(get_post($new_page)->post_title==='Manual Edit','Stale write changed title');
});
yolo_case('Native page permissions still reject another author under a subscriber account', static function(): void {
    global $admin;
    $old=$admin; $id=wp_create_user('yolo-subscriber',wp_generate_password(),'subscriber@example.invalid');
    wp_set_current_user($id); yolo_reject(static fn()=>FG_Tools::call('wp_page_design_create',yolo_page_args()),'forbidden');
    wp_set_current_user($old->ID);
});
yolo_case('Sensitive-data controls still block order writes', static function(): void {
    yolo_mode('yolo',false); yolo_auth();
    yolo_reject(static fn()=>FG_Tools::call('wc_orders_create',['line_items'=>[]]),'forbidden');
});
yolo_case('YOLO creates only an unpaid order and can add an internal note', static function(): void {
    $product=new WC_Product_Simple();$product->set_name('Fixture Product');$product->set_regular_price('12.50');$id=$product->save();
    $result=FG_Tools::call('wc_orders_create',['line_items'=>[['product_id'=>$id,'quantity'=>1]]]);
    $order=wc_get_order($result['result']['data']['id']);
    yolo_assert($order && $order->get_status()==='pending' && !$order->is_paid(),'Order bypassed unpaid boundary');
    yolo_assert(FG_Tools::call('wc_orders_add_note',['id'=>$order->get_id(),'note'=>'Internal YOLO test'])['status']==='applied','Internal note failed');
});
yolo_case('Paid-order financial protections remain active and failure returns its ID', static function(): void {
    $order=wc_create_order(['status'=>'processing']);$order->set_date_paid(time());$order->save();
    $error=yolo_reject(static fn()=>FG_Tools::call('wc_orders_update',['id'=>$order->get_id(),'billing'=>['city'=>'Forbidden City']]),'execution_failed');
    $id=yolo_error_id($error);yolo_assert(FG_Approvals::status($id)['status']==='failed','Failure status missing');
    yolo_assert(wc_get_order($order->get_id())->get_billing_city()!=='Forbidden City','Paid-order guard bypassed');
});
yolo_case('A mode downgrade after execution claim prevents the handler running', static function(): void {
    global $wpdb;
    $called=0;FG_Tools::register('fixture_write','Fixture',FG_Tools::schema(),static function() use (&$called){$called++;return ['ok'=>true];},true,'read');
    $hook=static function($query) use (&$hook) { if(str_contains($query,'yolo_execution_started') && str_starts_with($query,'INSERT')) { remove_filter('query',$hook);yolo_mode('reviewed'); }return $query; };
    add_filter('query',$hook);try{$error=yolo_reject(static fn()=>FG_Tools::call('fixture_write',[]),'execution_failed');}finally{remove_filter('query',$hook);}
    yolo_assert($called===0,'Handler ran after mode downgrade'); yolo_assert(FG_Approvals::status(yolo_error_id($error))['status']==='failed','Downgraded change left executable');
});
yolo_case('Audit failure before execution prevents a side effect', static function(): void {
    global $wpdb;
    $called=0;FG_Tools::register('fixture_write','Fixture',FG_Tools::schema(),static function() use (&$called){$called++;return ['ok'=>true];},true,'read');
    $hook=static fn($query)=>str_starts_with($query,'INSERT') && str_contains($query,'yolo_execution_started') ? 'INSERT INTO fg_missing_audit_table (id) VALUES (1)' : $query;
    $previous=$wpdb->suppress_errors(true);add_filter('query',$hook);
    try{$error=yolo_reject(static fn()=>FG_Tools::call('fixture_write',[]),'audit_unavailable');}finally{remove_filter('query',$hook);$wpdb->suppress_errors($previous);}
    yolo_assert($called===0 && FG_Approvals::status(yolo_error_id($error))['status']==='failed','Audit failure allowed execution');
});
yolo_case('A handler failure is terminal and is logged once with the direct change ID', static function(): void {
    global $wpdb;
    FG_Tools::register('fixture_write','Fixture',FG_Tools::schema(),static function(){throw new RuntimeException('Fixture side effect uncertain');},true,'read');
    $error=yolo_reject(static fn()=>FG_Tools::call('fixture_write',[]),'execution_failed');$id=yolo_error_id($error);
    yolo_assert(FG_Approvals::status($id)['status']==='failed','Failure did not become terminal');
    yolo_assert((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.FG_Core::table('audit').' WHERE change_id=%s AND outcome=%s',$id,'yolo_failed_check_site'))===1,'Failure audit duplicated');
    yolo_reject(static fn()=>FG_Approvals::apply($id),'not_approved');
});
yolo_case('Public apply cannot execute a direct record even if its status is forged to approved', static function(): void {
    global $wpdb;
    FG_Tools::register('fixture_write','Fixture',FG_Tools::schema(),static fn()=>['ok'=>true],true,'read');
    $result=FG_Tools::call('fixture_write',[]);$wpdb->update(FG_Core::table('changes'),['status'=>'approved'],['id'=>$result['change_id']]);
    yolo_reject(static fn()=>FG_Approvals::apply($result['change_id']),'invalid_change');
});
echo wp_json_encode(['wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'database'=>get_class($wpdb),'passed'=>count(array_filter($cases,static fn($c)=>$c['pass'])),'total'=>count($cases),'cases'=>$cases],JSON_PRETTY_PRINT);
