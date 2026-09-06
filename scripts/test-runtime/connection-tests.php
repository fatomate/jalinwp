<?php
// Disposable WordPress diagnostics: real OAuth URL helpers, controlled HTTP/publication responses.
require '/wordpress/wp-load.php';
require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-core.php';
require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-server.php';
require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-oauth.php';
require_once '/wordpress/wp-content/plugins/jalin-mcp-gateway/includes/class-connection.php';
define('FG_VERSION', '0.2.2');
final class FG_Discovery {
    public static function publish(): array {
        global $publication_calls, $publication_status;
        $publication_calls++;
        return ['status'=>$publication_status, 'message'=>$publication_status==='ready' ? 'The public discovery files are ready.' : 'Automatic discovery publication could not write to the confirmed web root.',
            'documents'=>['protected_resource'=>['status'=>$publication_status, 'message'=>$publication_status==='ready' ? 'The public resource discovery file is ready.' : 'The public resource discovery file could not be written.']]];
    }
    public static function protected_resource_url(): string {
        global $mode;
        if ($mode==='unsafe_proactive_url') { return ''; }
        $parts=wp_parse_url(FG_OAuth::resource());
        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'').'/.well-known/oauth-protected-resource'.$parts['path'];
    }
}
$_SERVER['HTTPS']='on'; $_SERVER['SERVER_PORT']='443';
update_option('home', 'https://gateway.example'); update_option('siteurl', 'https://gateway.example');
update_option('permalink_structure', '/%postname%/');
FG_Core::activate();
$admin = get_users(['role'=>'administrator','number'=>1])[0];
wp_set_current_user($admin->ID);
update_option('fg_settings',['enabled'=>true,'oauth_enabled'=>true,'users'=>[$admin->ID]]);
$mode='good'; $requests=[]; $cases=[]; $publication_status='ready'; $publication_calls=0;
function fg_diag_response(int $status,array|string $data=[],string $type='application/json; charset=UTF-8'): array {
    return ['headers'=>['content-type'=>$type],'body'=>is_array($data)?wp_json_encode($data):$data,'response'=>['code'=>$status,'message'=>'Fixture']];
}
function fg_diag_metadata(): array {
    return [
        'issuer'=>FG_OAuth::issuer(),
        'authorization_endpoint'=>add_query_arg('action','fg_oauth_authorize',admin_url('admin-post.php')),
        'token_endpoint'=>rest_url('jalin-mcp/v1/oauth/token'),
        'registration_endpoint'=>rest_url('jalin-mcp/v1/oauth/register'),
        'code_challenge_methods_supported'=>['S256'],
        'response_types_supported'=>['code'],
        'grant_types_supported'=>['authorization_code','refresh_token'],
    ];
}
add_filter('pre_http_request', static function($pre,$args,$url) use (&$mode,&$requests) {
    $requests[]=['url'=>$url,'args'=>$args];
    if ($mode==='transport') { return new WP_Error('http_request_failed','untrusted upstream diagnostic text'); }
    if (in_array($url,FG_OAuth::authorization_metadata_urls(),true)) {
        if ($mode==='blocked') { return fg_diag_response(403); }
        if (in_array($mode,['missing','missing_raw_rest_works'],true)) { return fg_diag_response(404); }
        if ($mode==='badjson') { return fg_diag_response(200,'<html>cache page</html>'); }
        if ($mode==='redirect') { return ['headers'=>['location'=>rest_url('jalin-mcp/v1/oauth/authorization-server')],'body'=>'','response'=>['code'=>302,'message'=>'Fixture']]; }
        $data=fg_diag_metadata();
        if ($mode==='malformed') { $data['code_challenge_methods_supported']='S256'; }
        if ($mode==='pkce_missing') { unset($data['code_challenge_methods_supported']); }
        if ($mode==='pkce_plain') { $data['code_challenge_methods_supported']=['plain']; }
        if ($mode==='issuer') { $data['issuer']='https://different.example/issuer'; }
        if ($mode==='old_issuer') { $data['issuer']=untrailingslashit(home_url('/')); }
        foreach (['authorization_endpoint','token_endpoint','registration_endpoint'] as $field) {
            if ($mode===$field) { $data[$field]='https://different.example/oauth'; }
        }
        if ($mode==='response_type') { $data['response_types_supported']=['token']; }
        if ($mode==='grant_type') { $data['grant_types_supported']='authorization_code'; }
        return fg_diag_response(200,$data,$mode==='wrong_mime'?'application/octet-stream':($mode==='missing_mime'?'':'application/json; charset=UTF-8'));
    }
    // Working debug/raw metadata must never substitute for the actual discovery URL.
    if ($url===rest_url('jalin-mcp/v1/oauth/authorization-server')) { return fg_diag_response(200,fg_diag_metadata()); }
    if ($url===FG_OAuth::resource_metadata_url()) {
        return fg_diag_response(200,['resource'=>FG_OAuth::resource(),'authorization_servers'=>$mode==='malformed'?FG_OAuth::issuer():[FG_OAuth::issuer()]],$mode==='resource_mime'?'text/plain':'application/json');
    }
    if ($url===FG_Discovery::protected_resource_url()) {
        if ($mode==='proactive_missing') { return fg_diag_response(404); }
        if ($mode==='proactive_blocked') { return fg_diag_response(403); }
        if ($mode==='proactive_redirect') { return ['headers'=>['location'=>FG_OAuth::resource_metadata_url()],'body'=>'','response'=>['code'=>302,'message'=>'Fixture']]; }
        if ($mode==='proactive_html') { return fg_diag_response(200,'<html>cached error page</html>','application/octet-stream'); }
        $data=['resource'=>FG_OAuth::resource(),'authorization_servers'=>[FG_OAuth::issuer()]];
        if ($mode==='proactive_resource') { $data['resource']='https://different.example/mcp'; }
        if ($mode==='proactive_issuer') { $data['authorization_servers']=['https://different.example/issuer']; }
        if ($mode==='proactive_malformed') { $data['authorization_servers']=FG_OAuth::issuer(); }
        if ($mode==='proactive_invalid_json') { return fg_diag_response(200,'{"resource":'); }
        return fg_diag_response(200,$data,$mode==='proactive_mime'?'application/octet-stream':($mode==='proactive_missing_mime'?'':'application/json'));
    }
    if ($url!==FG_OAuth::resource()) { throw new RuntimeException('Unexpected diagnostic request URL.'); }
    $method=strtoupper($args['method']??'GET');
    $header='Bearer resource_metadata="'.FG_OAuth::resource_metadata_url().'", scope="mcp"';
    if (($mode==='get_challenge_missing' && $method==='GET') || ($mode==='post_challenge_missing' && $method==='POST')) { $header=''; }
    return ['headers'=>['www-authenticate'=>$header],'body'=>'','response'=>['code'=>$mode==='challenge'||($mode==='get_challenge' && $method==='GET')?200:401,'message'=>'Fixture']];
},10,3);
function fg_diag_assert(string $name,bool $pass): void { global $cases; if(!$pass){throw new RuntimeException($name);} $cases[]=['name'=>$name,'pass'=>true]; }
function fg_diag_check(array $report,string $label): array { foreach($report['checks'] as $check){if($check['label']===$label){return $check;}} return ['status'=>'absent','detail'=>'']; }
function fg_diag_status(array $report,string $label): string { return fg_diag_check($report,$label)['status']; }
function fg_diag_run(string $next_mode): array { global $mode,$requests,$publication_calls; $mode=$next_mode; $requests=[]; $publication_calls=0; return FG_Connection::diagnostics(); }

$report=fg_diag_run('good');
fg_diag_assert('Published canonical discovery fixture passes all discovery and challenge checks',fg_diag_status($report,'OAuth discovery')==='pass' && fg_diag_status($report,'MCP resource discovery')==='pass' && fg_diag_status($report,'Proactive MCP resource discovery')==='pass' && fg_diag_status($report,'Sign-in challenge')==='pass' && fg_diag_status($report,'GET sign-in challenge')==='pass');
fg_diag_assert('Enabled administrator check attempts automatic publication once', $publication_calls===1);
fg_diag_assert('Checks retain explicit final external-client validation warning',fg_diag_status($report,'Final client test')==='warning');
fg_diag_assert('Canonical RFC8414 check uses only one authorization metadata URL',count($requests)===5 && $requests[0]['url']===FG_OAuth::authorization_metadata_urls()[0] && count(FG_OAuth::authorization_metadata_urls())===1);
$configured=array_merge(FG_OAuth::authorization_metadata_urls(),[FG_OAuth::resource_metadata_url(),FG_Discovery::protected_resource_url(),FG_OAuth::resource()]);
fg_diag_assert('All five probes use configured URLs with bounded redirects time response size and TLS verification',count(array_filter($requests,static fn($r)=>in_array($r['url'],$configured,true) && (int)$r['args']['redirection']===0 && (float)$r['args']['timeout']===5.0 && (int)$r['args']['limit_response_size']===32768 && ($r['args']['sslverify']??true)!==false))===5);
fg_diag_assert('Probes send neither account cookies nor authorization credentials',count(array_filter($requests,static fn($r)=>!isset($r['args']['headers']['Authorization']) && !isset($r['args']['headers']['Cookie']) && empty($r['args']['cookies'])))===5);
fg_diag_assert('POST initialization and independent GET both probe the MCP endpoint', $requests[3]['url']===FG_OAuth::resource() && $requests[3]['args']['method']==='POST' && json_decode($requests[3]['args']['body'],true)['method']==='initialize' && $requests[4]['url']===FG_OAuth::resource() && $requests[4]['args']['method']==='GET' && empty($requests[4]['args']['body']));
$publication_status='error'; $report=fg_diag_run('good');
fg_diag_assert('Valid canonical WordPress response passes when static publication is unavailable',fg_diag_status($report,'OAuth discovery')==='pass');
$report=fg_diag_run('missing');
fg_diag_assert('Missing canonical URL with publication failure reports the actionable setup reason',fg_diag_status($report,'OAuth discovery')==='fail' && str_contains(fg_diag_check($report,'OAuth discovery')['detail'],'could not write to the confirmed web root'));
$publication_status='ready'; $report=fg_diag_run('missing');
fg_diag_assert('Local file publication alone cannot pass an externally missing canonical URL',fg_diag_status($report,'OAuth discovery')==='fail' && count($requests)===5 && str_contains(fg_diag_check($report,'OAuth discovery')['detail'],'HTTP status: 404'));
$report=fg_diag_run('missing_raw_rest_works');
fg_diag_assert('Working raw REST metadata cannot hide a missing canonical discovery URL',fg_diag_status($report,'OAuth discovery')==='fail' && count($requests)===5 && !in_array(rest_url('jalin-mcp/v1/oauth/authorization-server'),array_column($requests,'url'),true));
$raw=wp_safe_remote_get(rest_url('jalin-mcp/v1/oauth/authorization-server'));
fg_diag_assert('Missing-discovery fixture independently confirms raw REST metadata is valid',wp_remote_retrieve_response_code($raw)===200 && json_decode(wp_remote_retrieve_body($raw),true)['issuer']===FG_OAuth::issuer());
$report=fg_diag_run('blocked');
fg_diag_assert('HTTP403 canonical discovery fails without claiming a working fallback',fg_diag_status($report,'OAuth discovery')==='fail' && count($requests)===5 && str_contains(fg_diag_check($report,'OAuth discovery')['detail'],'HTTP status: 403'));
$report=fg_diag_run('issuer');
fg_diag_assert('Metadata issuer mismatch fails closed',fg_diag_status($report,'OAuth discovery')==='fail' && count($requests)===5 && str_contains(fg_diag_check($report,'OAuth discovery')['detail'],'issuer'));
$report=fg_diag_run('old_issuer');
fg_diag_assert('Cached v0.2.0 home URL issuer is rejected with reconnect guidance',fg_diag_status($report,'OAuth discovery')==='fail' && str_contains(fg_diag_check($report,'OAuth discovery')['detail'],'Recreate the ChatGPT or Claude connector'));
foreach (['authorization_endpoint','token_endpoint','registration_endpoint'] as $field) {
    $report=fg_diag_run($field);
    fg_diag_assert('Mismatched '.$field.' fails closed',fg_diag_status($report,'OAuth discovery')==='fail' && count($requests)===5);
}
$report=fg_diag_run('pkce_missing');
fg_diag_assert('Missing PKCE capability fails closed',fg_diag_status($report,'OAuth discovery')==='fail');
$report=fg_diag_run('pkce_plain');
fg_diag_assert('PKCE plain cannot replace required S256 capability',fg_diag_status($report,'OAuth discovery')==='fail');
$report=fg_diag_run('response_type');
fg_diag_assert('Metadata must advertise authorization code responses',fg_diag_status($report,'OAuth discovery')==='fail');
$report=fg_diag_run('grant_type');
fg_diag_assert('Malformed grant type metadata fails without a PHP TypeError',fg_diag_status($report,'OAuth discovery')==='fail');
$report=fg_diag_run('malformed');
fg_diag_assert('Malformed PKCE and resource arrays fail without a PHP TypeError',fg_diag_status($report,'OAuth discovery')==='fail' && fg_diag_status($report,'MCP resource discovery')==='fail');
$report=fg_diag_run('wrong_mime');
fg_diag_assert('Valid JSON with octet-stream MIME fails client-compatible discovery validation',fg_diag_status($report,'OAuth discovery')==='fail');
$report=fg_diag_run('missing_mime');
fg_diag_assert('Missing JSON Content-Type fails discovery validation',fg_diag_status($report,'OAuth discovery')==='fail');
$report=fg_diag_run('resource_mime');
fg_diag_assert('Resource discovery requires JSON Content-Type',fg_diag_status($report,'MCP resource discovery')==='fail');
$report=fg_diag_run('badjson');
fg_diag_assert('HTML returned with HTTP200 cannot pass discovery',fg_diag_status($report,'OAuth discovery')==='fail');
$report=fg_diag_run('redirect');
fg_diag_assert('Discovery redirects are neither followed nor reported as verified success',fg_diag_status($report,'OAuth discovery')==='fail' && count($requests)===5);
$report=fg_diag_run('transport');
fg_diag_assert('Transport errors fail without echoing untrusted upstream details',fg_diag_status($report,'OAuth discovery')==='fail' && count($requests)===5 && !str_contains(wp_json_encode($report),'untrusted upstream'));
$report=fg_diag_run('challenge');
fg_diag_assert('Unexpected successful anonymous endpoint cannot pass either sign-in check',fg_diag_status($report,'Sign-in challenge')==='fail' && fg_diag_status($report,'GET sign-in challenge')==='fail');
$report=fg_diag_run('get_challenge');
fg_diag_assert('Successful POST challenge cannot hide cached HTTP200 on anonymous GET',fg_diag_status($report,'Sign-in challenge')==='pass' && fg_diag_status($report,'GET sign-in challenge')==='fail');
$report=fg_diag_run('get_challenge_missing');
fg_diag_assert('Missing GET WWW-Authenticate header fails the distinct GET check',fg_diag_status($report,'Sign-in challenge')==='pass' && fg_diag_status($report,'GET sign-in challenge')==='fail');
$report=fg_diag_run('post_challenge_missing');
fg_diag_assert('Working GET challenge cannot hide missing POST WWW-Authenticate header',fg_diag_status($report,'Sign-in challenge')==='fail' && fg_diag_status($report,'GET sign-in challenge')==='pass');

$report=fg_diag_run('proactive_missing');
fg_diag_assert('Old all-green REST and AS checks cannot hide a missing proactive resource URL',fg_diag_status($report,'OAuth discovery')==='pass' && fg_diag_status($report,'MCP resource discovery')==='pass' && fg_diag_status($report,'Sign-in challenge')==='pass' && fg_diag_status($report,'Proactive MCP resource discovery')==='fail');
fg_diag_assert('Proactive resource failure identifies exact standard URL and HTTP404',str_contains(fg_diag_check($report,'Proactive MCP resource discovery')['detail'],FG_Discovery::protected_resource_url()) && str_contains(fg_diag_check($report,'Proactive MCP resource discovery')['detail'],'HTTP status: 404'));
$publication_status='error'; $report=fg_diag_run('proactive_missing');
fg_diag_assert('Partial publication reports the resource file failure while AS remains valid',fg_diag_status($report,'OAuth discovery')==='pass' && fg_diag_status($report,'Proactive MCP resource discovery')==='fail' && str_contains(fg_diag_check($report,'Proactive MCP resource discovery')['detail'],'public resource discovery file could not be written'));
$publication_status='ready'; $report=fg_diag_run('proactive_blocked');
fg_diag_assert('Published bytes cannot hide blocked proactive discovery HTTP403',fg_diag_status($report,'Proactive MCP resource discovery')==='fail' && str_contains(fg_diag_check($report,'Proactive MCP resource discovery')['detail'],'HTTP status: 403'));
$report=fg_diag_run('proactive_redirect');
fg_diag_assert('Proactive discovery redirect cannot silently substitute the REST URL',fg_diag_status($report,'Proactive MCP resource discovery')==='fail' && count($requests)===5 && str_contains(fg_diag_check($report,'Proactive MCP resource discovery')['detail'],'HTTP status: 302'));
foreach (['proactive_resource','proactive_issuer','proactive_malformed','proactive_html','proactive_invalid_json'] as $failure) {
    $report=fg_diag_run($failure);
    fg_diag_assert('Proactive resource metadata fails safely for '.$failure,fg_diag_status($report,'Proactive MCP resource discovery')==='fail' && fg_diag_status($report,'OAuth discovery')==='pass');
}
$report=fg_diag_run('proactive_mime');
fg_diag_assert('Valid extensionless static JSON with octet-stream emits compatibility warning rather than pass',fg_diag_status($report,'Proactive MCP resource discovery')==='warning' && str_contains(fg_diag_check($report,'Proactive MCP resource discovery')['detail'],'SDK parses this response') && str_contains(fg_diag_check($report,'Proactive MCP resource discovery')['detail'],'stricter clients may reject'));
$report=fg_diag_run('proactive_missing_mime');
fg_diag_assert('Valid standard resource JSON without Content-Type remains a visible warning',fg_diag_status($report,'Proactive MCP resource discovery')==='warning');
$report=fg_diag_run('unsafe_proactive_url');
fg_diag_assert('Unsafe standard resource URL is never requested and fails without arbitrary fallback',fg_diag_status($report,'Proactive MCP resource discovery')==='fail' && count($requests)===4);
$_GET['url']='https://unexpected.example/diagnostic-target'; $_POST['resource_url']='http://127.0.0.1/private';
$report=fg_diag_run('good');
fg_diag_assert('User-provided URLs cannot redirect any diagnostic probe',count($requests)===5 && count(array_filter($requests,static fn($r)=>in_array($r['url'],$configured,true)))===5);
unset($_GET['url'],$_POST['resource_url']);

$original_issuer=FG_OAuth::issuer();
$parts=wp_parse_url($original_issuer);
$origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
$original_path=wp_parse_url($original_issuer,PHP_URL_PATH);
// Playground supplies WP_HOME/WP_SITEURL constants; use WordPress URL filters for fixtures.
$subdirectory=static fn($url)=>preg_replace('~^(https?://[^/]+)~','$1/shop',$url);
add_filter('home_url',$subdirectory);
$report=fg_diag_run('good');
fg_diag_assert('Subdirectory issuer uses host-root RFC8414 insertion path',fg_diag_status($report,'OAuth discovery')==='pass' && $requests[0]['url']===$origin.'/.well-known/oauth-authorization-server/shop'.$original_path);
fg_diag_assert('Subdirectory proactive resource uses host-root RFC9728 insertion path',fg_diag_status($report,'Proactive MCP resource discovery')==='pass' && $requests[2]['url']===$origin.'/.well-known/oauth-protected-resource'.wp_parse_url(FG_OAuth::resource(),PHP_URL_PATH));
$custom_prefix=static fn()=> 'gateway-api';
add_filter('rest_url_prefix',$custom_prefix);
$report=fg_diag_run('good');
fg_diag_assert('Custom REST prefix and subdirectory remain valid for discovery and endpoints',fg_diag_status($report,'OAuth discovery')==='pass' && fg_diag_status($report,'MCP resource discovery')==='pass' && fg_diag_status($report,'Proactive MCP resource discovery')==='pass' && $requests[0]['url']===$origin.'/.well-known/oauth-authorization-server/shop/gateway-api/jalin-mcp/v1/oauth/issuer.json' && $requests[2]['url']===$origin.'/.well-known/oauth-protected-resource/shop/gateway-api/jalin-mcp/v1/mcp');
remove_filter('rest_url_prefix',$custom_prefix);
remove_filter('home_url',$subdirectory);

update_option('fg_settings',['enabled'=>true,'oauth_enabled'=>false,'users'=>[$admin->ID]]);
$report=fg_diag_run('good');
fg_diag_assert('Disabled OAuth never invokes automatic publication', $publication_calls===0 && fg_diag_status($report,'OAuth enabled')==='fail');
update_option('fg_settings',['enabled'=>true,'oauth_enabled'=>true,'users'=>[$admin->ID]]);
wp_set_current_user(0); $report=fg_diag_run('good');
fg_diag_assert('Anonymous diagnostics perform no network publication or privileged inspection',count($requests)===0 && $publication_calls===0 && fg_diag_status($report,'Administrator access')==='fail');
wp_set_current_user($admin->ID); $_SERVER['HTTPS']='off'; $_SERVER['SERVER_PORT']='80'; $report=fg_diag_run('good');
fg_diag_assert('Incorrect HTTPS detection blocks publication and remote probes',count($requests)===0 && $publication_calls===0 && fg_diag_status($report,'HTTPS')==='fail');
echo wp_json_encode(['passed'=>count($cases),'total'=>count($cases),'cases'=>$cases],JSON_PRETTY_PRINT);
