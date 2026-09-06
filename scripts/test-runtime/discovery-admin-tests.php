<?php
/** Disposable admin-boundary regression coverage for OAuth discovery publication. */
define('FG_TEST_DISPOSABLE', true);
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$_SERVER['HTTPS'] = 'on'; $_SERVER['SERVER_PORT'] = '443';
update_option('home', 'https://gateway.example.test'); update_option('siteurl', 'https://gateway.example.test');
global $wp_rewrite; $wp_rewrite->set_permalink_structure('/%postname%/');
$result = activate_plugin('jalin-mcp-gateway/jalin-mcp-gateway.php');
if (is_wp_error($result)) { throw new RuntimeException('Plugin activation failed: ' . $result->get_error_message()); }
$admin = (int) get_users(['role' => 'administrator', 'number' => 1])[0]->ID;
$authorization_path = ABSPATH . '.well-known/oauth-authorization-server/wp-json/jalin-mcp/v1/oauth/issuer.json';
$resource_path = ABSPATH . '.well-known/oauth-protected-resource/wp-json/jalin-mcp/v1/mcp';
$cases = [];
function fg_discovery_admin_assert(string $name, bool $pass): void { global $cases; $cases[] = ['name' => $name, 'pass' => $pass]; if (!$pass) { throw new RuntimeException($name); } }
function fg_discovery_admin_clear(): void {
    global $authorization_path, $resource_path;
    foreach ([$authorization_path, $resource_path] as $path) { @unlink($path); }
    foreach ([dirname($authorization_path), dirname(dirname($authorization_path)), dirname($resource_path), dirname(dirname($resource_path)), ABSPATH . '.well-known'] as $directory) { @rmdir($directory); }
    delete_option('fg_oauth_discovery_files'); delete_option('fg_oauth_discovery_lock');
}
function fg_discovery_admin_absent(): bool { global $authorization_path, $resource_path; return !file_exists($authorization_path) && !file_exists($resource_path) && get_option('fg_oauth_discovery_files', null) === null && get_option('fg_oauth_discovery_lock', null) === null; }
class FG_Discovery_Admin_Redirect extends RuntimeException {}
class FG_Discovery_Admin_Die extends RuntimeException {}
add_filter('wp_redirect', static function ($location) { throw new FG_Discovery_Admin_Redirect($location); });
add_filter('wp_die_handler', static fn() => static function ($message, $title = '', $args = []) { throw new FG_Discovery_Admin_Die((string) $message, (int) ($args['response'] ?? 0)); });

fg_discovery_admin_clear();
wp_set_current_user($admin);
update_option('fg_settings', ['enabled'=>true, 'oauth_enabled'=>true, 'writes'=>false, 'sensitive'=>false, 'users'=>[$admin], 'origins'=>[], 'finance_mappings'=>[]], false);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['page' => 'jalin-mcp-gateway']; $_POST = []; $_REQUEST = $_GET;
do_action('admin_init');
do_action('load-settings_page_jalin-mcp-gateway');
ob_start(); FG_Admin::page(); ob_end_clean();
fg_discovery_admin_assert('Admin settings GET creates neither discovery files nor ownership options', fg_discovery_admin_absent());

fg_discovery_admin_clear();
update_option('fg_settings', ['enabled'=>false, 'oauth_enabled'=>false, 'writes'=>false, 'sensitive'=>false, 'users'=>[], 'origins'=>[], 'finance_mappings'=>[]], false);
foreach ([['GET', $admin, 'valid', 'wrong method'], ['POST', 0, 'valid', 'unauthorized'], ['POST', $admin, 'bad', 'bad nonce']] as [$method, $user, $nonce, $label]) {
    $_SERVER['REQUEST_METHOD'] = $method; wp_set_current_user($user);
    $_POST = ['_wpnonce' => $nonce === 'valid' ? wp_create_nonce('fg_quick_connect') : 'bad', 'settings_version' => FG_Settings::fingerprint('connection')]; $_REQUEST = $_POST;
    $blocked = false;
    try { do_action('admin_post_fg_quick_connect'); } catch (FG_Discovery_Admin_Die $error) { $blocked = true; }
    fg_discovery_admin_assert('Quick setup rejects ' . $label . ' without publication', $blocked && fg_discovery_admin_absent());
}
wp_set_current_user($admin); $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['_wpnonce'=>wp_create_nonce('fg_quick_connect'), 'settings_version'=>'stale']; $_REQUEST = $_POST;
$stale = false;
try { do_action('admin_post_fg_quick_connect'); } catch (FG_Discovery_Admin_Redirect $redirect) { $stale = true; }
fg_discovery_admin_assert('Stale quick setup fails without publication', $stale && !FG_Core::settings()['enabled'] && fg_discovery_admin_absent());

$_POST = ['_wpnonce'=>wp_create_nonce('fg_quick_connect'), 'settings_version'=>FG_Settings::fingerprint('connection')]; $_REQUEST = $_POST;
$enabled = false;
try { do_action('admin_post_fg_quick_connect'); } catch (FG_Discovery_Admin_Redirect $redirect) { $enabled = true; }
fg_discovery_admin_assert('Valid explicit setup enables and publishes canonical discovery documents', $enabled && FG_Core::settings()['enabled'] && file_exists($authorization_path) && file_exists($resource_path) && json_decode(file_get_contents($authorization_path), true) === FG_OAuth::authorization_metadata() && json_decode(file_get_contents($resource_path), true) === FG_OAuth::resource_metadata());

fg_discovery_admin_clear();
$requests = [];
function fg_discovery_admin_response(int $status, array $data, array $headers = []): array { return ['headers'=>array_merge(['content-type'=>'application/json'], $headers), 'body'=>wp_json_encode($data), 'response'=>['code'=>$status, 'message'=>'Fixture']]; }
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$requests) {
    $requests[] = ['url'=>$url, 'args'=>$args];
    if (in_array($url, FG_OAuth::authorization_metadata_urls(), true)) {
        return fg_discovery_admin_response(200, ['issuer'=>FG_OAuth::issuer(), 'authorization_endpoint'=>add_query_arg('action', 'fg_oauth_authorize', admin_url('admin-post.php')), 'token_endpoint'=>rest_url('jalin-mcp/v1/oauth/token'), 'registration_endpoint'=>rest_url('jalin-mcp/v1/oauth/register'), 'code_challenge_methods_supported'=>['S256'], 'response_types_supported'=>['code'], 'grant_types_supported'=>['authorization_code']]);
    }
    if ($url === FG_OAuth::resource_metadata_url() || $url === FG_Discovery::protected_resource_url()) { return fg_discovery_admin_response(200, ['resource'=>FG_OAuth::resource(), 'authorization_servers'=>[FG_OAuth::issuer()]]); }
    if ($url === FG_OAuth::resource()) { return ['headers'=>['www-authenticate'=>'Bearer resource_metadata="' . FG_OAuth::resource_metadata_url() . '"'], 'body'=>'', 'response'=>['code'=>401, 'message'=>'Fixture']]; }
    throw new RuntimeException('Unexpected diagnostic URL: ' . $url);
}, 10, 3);
$_POST = ['_wpnonce'=>wp_create_nonce('fg_connection_check')]; $_REQUEST = $_POST;
$checked = false;
try { do_action('admin_post_fg_connection_check'); } catch (FG_Discovery_Admin_Redirect $redirect) { $checked = true; }
fg_discovery_admin_assert('Check Connection POST republishes canonical documents with five bounded synthetic checks', $checked && file_exists($authorization_path) && file_exists($resource_path) && json_decode(file_get_contents($authorization_path), true) === FG_OAuth::authorization_metadata() && json_decode(file_get_contents($resource_path), true) === FG_OAuth::resource_metadata() && count($requests) === 5 && count(array_filter($requests, static fn($request) => (int) $request['args']['redirection'] === 0 && (float) $request['args']['timeout'] === 5.0 && (int) $request['args']['limit_response_size'] === 32768)) === 5);

// Exercise the AJAX publication path with OAuth enabled, so settings cannot mask a missing guard.
fg_discovery_admin_clear();
update_option('fg_settings', ['enabled'=>true, 'oauth_enabled'=>true, 'writes'=>false, 'sensitive'=>false, 'users'=>[$admin], 'origins'=>[], 'finance_mappings'=>[]], false);
define('DOING_AJAX', true);
add_filter('wp_die_ajax_handler', static fn() => static function ($message, $title = '', $args = []) { throw new FG_Discovery_Admin_Die((string) $message, (int) ($args['response'] ?? 0)); });
function fg_discovery_admin_ajax(string $method, int $user, string $nonce): array {
    wp_set_current_user($user); $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = ['_ajax_nonce'=>$nonce === 'valid' ? wp_create_nonce('fg_connection_check') : 'bad']; $_REQUEST = $_POST;
    $status = null; http_response_code(200); ob_start();
    try { do_action('wp_ajax_fg_connection_check'); }
    catch (FG_Discovery_Admin_Die $error) { $status = $error->getCode() ?: http_response_code(); }
    finally { $body = ob_get_clean(); }
    return [$status, json_decode($body, true)];
}
foreach ([['GET', $admin, 'valid', 405], ['POST', 0, 'valid', 403], ['POST', $admin, 'bad', 403]] as [$method, $user, $nonce, $expected]) {
    [$status] = fg_discovery_admin_ajax($method, $user, $nonce);
    fg_discovery_admin_assert('Enabled AJAX rejects ' . $method . '/' . $user . '/' . $nonce . ' without publication', $status === $expected && fg_discovery_admin_absent());
}
$requests = [];
[, $ajax] = fg_discovery_admin_ajax('POST', $admin, 'valid');
fg_discovery_admin_assert('Valid AJAX Check Connection publishes canonical documents', ($ajax['success'] ?? false) === true && count($requests) === 5 && file_exists($authorization_path) && file_exists($resource_path) && json_decode(file_get_contents($authorization_path), true) === FG_OAuth::authorization_metadata() && json_decode(file_get_contents($resource_path), true) === FG_OAuth::resource_metadata());
remove_all_filters('wp_die_ajax_handler');
remove_all_filters('pre_http_request');
fg_discovery_admin_clear();
remove_all_filters('wp_redirect'); remove_all_filters('wp_die_handler');
echo wp_json_encode(['passed'=>count($cases), 'total'=>count($cases), 'cases'=>$cases], JSON_PRETTY_PRINT);
