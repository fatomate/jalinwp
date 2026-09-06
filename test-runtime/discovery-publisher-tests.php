<?php
// Public discovery publication tests in an isolated WordPress install and temporary directories.
require '/wordpress/wp-load.php';
require_once '/wordpress/wp-content/plugins/fames-mcp-gateway/includes/class-core.php';
require_once '/wordpress/wp-content/plugins/fames-mcp-gateway/includes/class-discovery.php';
final class FG_OAuth {
    public static string $issuer = 'https://gateway.example/wp-json/fames-mcp/v1/oauth/issuer.json';
    public static string $resource = 'https://gateway.example/wp-json/fames-mcp/v1/mcp';
    public static array $extra = [];
    public static array $resource_extra = [];
    public static function issuer(): string { return self::$issuer; }
    public static function resource(): string { return self::$resource; }
    public static function authorization_metadata(): array {
        return array_merge(['issuer' => self::$issuer, 'authorization_endpoint' => 'https://gateway.example/wp-admin/admin-post.php?action=fg_oauth_authorize',
            'token_endpoint' => 'https://gateway.example/wp-json/fames-mcp/v1/oauth/token', 'registration_endpoint' => 'https://gateway.example/wp-json/fames-mcp/v1/oauth/register',
            'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => ['mcp']], self::$extra);
    }
    public static function resource_metadata(): array {
        return array_merge(['resource' => self::$resource, 'authorization_servers' => [self::$issuer],
            'scopes_supported' => ['mcp'], 'bearer_methods_supported' => ['header'], 'resource_name' => 'Fames MCP Gateway'], self::$resource_extra);
    }
}
$cases = [];
function fg_publish_assert(string $name, bool $pass): void {
    global $cases;
    $cases[] = ['name' => $name, 'pass' => $pass];
    if (!$pass) { throw new RuntimeException($name); }
}
function fg_discovery_internal(string $method, ...$args) {
    $reflection = new ReflectionMethod(FG_Discovery::class, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke(null, ...$args);
}
function fg_discovery_fixture(): string {
    $root = '/tmp/fg-discovery-tests-' . wp_generate_uuid4();
    mkdir($root, 0755);
    return $root;
}
function fg_discovery_directory(string $root, string $relative): string {
    $path = $root . '/' . $relative;
    wp_mkdir_p(dirname($path));
    return $path;
}
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$site_url = 'https://gateway.example';
add_filter('site_url', static function ($url, $path) use (&$site_url) { return $site_url . '/' . ltrim($path, '/'); }, 10, 2);
$settings = ['enabled' => true, 'oauth_enabled' => true, 'writes' => false, 'sensitive' => true, 'users' => [$admin->ID], 'finance_mappings' => ['stripe' => ['fee_key' => '_fee']]];
update_option('fg_settings', $settings, false);
$relative = '.well-known/oauth-authorization-server/wp-json/fames-mcp/v1/oauth/issuer.json';
$public_path = '/wordpress/' . $relative;
$resource_relative = '.well-known/oauth-protected-resource/wp-json/fames-mcp/v1/mcp';
$resource_public_path = '/wordpress/' . $resource_relative;
wp_set_current_user(0);
$result = FG_Discovery::publish();
fg_publish_assert('Anonymous callers cannot publish or create discovery files', $result['status'] === 'skipped' && !file_exists($public_path) && !file_exists($resource_public_path) && get_option('fg_oauth_discovery_files', null) === null);
wp_set_current_user($admin->ID);
update_option('fg_settings', array_merge($settings, ['oauth_enabled' => false]));
$result = FG_Discovery::publish();
fg_publish_assert('Disabled OAuth creates no discovery file', $result['status'] === 'skipped' && !file_exists($public_path) && !file_exists($resource_public_path));
update_option('fg_settings', $settings);
$result = FG_Discovery::publish();
$data = json_decode(file_get_contents($public_path), true);
fg_publish_assert('Authorized enabled setup publishes canonical public metadata', $result['status'] === 'ready' && $data === FG_OAuth::authorization_metadata());
fg_publish_assert('Authorized setup also publishes standard path-aware protected resource metadata', json_decode(file_get_contents($resource_public_path), true) === FG_OAuth::resource_metadata() && FG_Discovery::protected_resource_url() === 'https://gateway.example/' . $resource_relative);
fg_publish_assert('Publication reports both documents independently with public URLs', $result['documents']['authorization_server']['status'] === 'ready' && $result['documents']['authorization_server']['url'] === 'https://gateway.example/' . $relative && $result['documents']['protected_resource']['status'] === 'ready' && $result['documents']['protected_resource']['url'] === FG_Discovery::protected_resource_url());
fg_publish_assert('Protected resource publication keeps the original audience and path without inventing a JSON transport URL', !str_ends_with($resource_public_path, '.json') && json_decode(file_get_contents($resource_public_path), true)['resource'] === 'https://gateway.example/wp-json/fames-mcp/v1/mcp');
fg_publish_assert('Published discovery is a .json file with no credentials', str_ends_with($public_path, '.json') && !isset($data['access_token'], $data['refresh_token'], $data['client_secret']));
fg_publish_assert('Publishing leaves gateway permissions and finance settings unchanged', get_option('fg_settings') === $settings);
$records = get_option('fg_oauth_discovery_files'); $before = file_get_contents($public_path); $resource_before = file_get_contents($resource_public_path);
$result = FG_Discovery::publish();
fg_publish_assert('Repeated publication is idempotent for both independently owned files', $result['status'] === 'ready' && count($records) === 2 && get_option('fg_oauth_discovery_files') === $records && file_get_contents($public_path) === $before && file_get_contents($resource_public_path) === $resource_before);
FG_OAuth::$extra = ['service_documentation' => 'https://gateway.example/help'];
$result = FG_Discovery::publish();
fg_publish_assert('Owned metadata updates atomically when public configuration changes', $result['status'] === 'ready' && json_decode(file_get_contents($public_path), true) === FG_OAuth::authorization_metadata());
fg_publish_assert('Ownership record tracks current file bytes', count(array_filter(get_option('fg_oauth_discovery_files'), static fn($record) => $record['sha256'] === hash_file('sha256', $public_path))) === 1);

$before = file_get_contents($public_path); $records = get_option('fg_oauth_discovery_files');
$deny_update = static fn($new, $old) => $old;
add_filter('pre_update_option_fg_oauth_discovery_files', $deny_update, 10, 2);
FG_OAuth::$extra['service_documentation'] = 'https://gateway.example/new-help';
$result = FG_Discovery::publish();
remove_filter('pre_update_option_fg_oauth_discovery_files', $deny_update, 10);
fg_publish_assert('Failed ownership persistence restores previous document and reports failure', $result['status'] === 'error' && str_contains($result['message'], 'restored') && file_get_contents($public_path) === $before && get_option('fg_oauth_discovery_files') === $records);
fg_publish_assert('A retry after storage recovery can update the still-owned file', FG_Discovery::publish()['status'] === 'ready');
$owned_as = file_get_contents($public_path);
file_put_contents($public_path, '{"external_service":true}');
FG_OAuth::$resource_extra['resource_documentation'] = 'https://gateway.example/mcp-help';
$result = FG_Discovery::publish();
fg_publish_assert('Outside edits to an owned file are preserved and reported as conflict', $result['status'] === 'conflict' && file_get_contents($public_path) === '{"external_service":true}');
fg_publish_assert('Authorization-server conflict does not prevent a protected-resource update', $result['documents']['authorization_server']['status'] === 'conflict' && $result['documents']['protected_resource']['status'] === 'ready' && json_decode(file_get_contents($resource_public_path), true) === FG_OAuth::resource_metadata());
$owned_resource = file_get_contents($resource_public_path);
file_put_contents($public_path, $owned_as);
file_put_contents($resource_public_path, '{"foreign_resource":true}');
FG_OAuth::$extra['service_documentation'] = 'https://gateway.example/latest-help';
$result = FG_Discovery::publish();
fg_publish_assert('Protected-resource conflict does not prevent an authorization-server update', $result['status'] === 'conflict' && $result['documents']['protected_resource']['status'] === 'conflict' && $result['documents']['authorization_server']['status'] === 'ready' && json_decode(file_get_contents($public_path), true) === FG_OAuth::authorization_metadata() && file_get_contents($resource_public_path) === '{"foreign_resource":true}');
file_put_contents($resource_public_path, $owned_resource);
file_put_contents($public_path, '{"external_service":true}');

$root = fg_discovery_fixture(); $other = fg_discovery_directory($root, $relative);
file_put_contents($other, wp_json_encode(FG_OAuth::authorization_metadata()) . "\n");
$before = file_get_contents($other);
$result = fg_discovery_internal('write', $root, $relative, $before);
fg_publish_assert('An existing unowned file is never adopted even when bytes match', $result['status'] === 'conflict' && file_get_contents($other) === $before);
$foreign_resource = fg_discovery_directory($root, $resource_relative);
file_put_contents($foreign_resource, wp_json_encode(FG_OAuth::resource_metadata()) . "\n");
$before = file_get_contents($foreign_resource);
$result = fg_discovery_internal('write', $root, $resource_relative, $before);
fg_publish_assert('An unowned protected-resource file is never adopted even when metadata matches', $result['status'] === 'conflict' && file_get_contents($foreign_resource) === $before);
$foreign_root = fg_discovery_fixture();
wp_mkdir_p($foreign_root . '/.well-known');
file_put_contents($foreign_root . '/.well-known/oauth-protected-resource', '{"other_resource":true}');
$result = fg_discovery_internal('write', $foreign_root, $resource_relative, "{}\n");
fg_publish_assert('A foreign host-root protected-resource file is preserved as a reported parent conflict', $result['status'] === 'error' && file_get_contents($foreign_root . '/.well-known/oauth-protected-resource') === '{"other_resource":true}');
$foreign_acme = $root . '/.well-known/acme-challenge/test-token';
wp_mkdir_p(dirname($foreign_acme)); file_put_contents($foreign_acme, 'existing-certificate-token');
$fresh = fg_discovery_fixture(); $result = fg_discovery_internal('write', $fresh, $relative, "{\"issuer\":\"https://example.com\"}\n");
fg_publish_assert('Unrelated certificate challenge files remain untouched', file_get_contents($foreign_acme) === 'existing-certificate-token' && $result['status'] === 'ready');

$linked = fg_discovery_fixture(); $outside = fg_discovery_fixture();
symlink($outside, $linked . '/.well-known');
$result = fg_discovery_internal('write', $linked, $relative, "{}\n");
fg_publish_assert('A symlink directory is rejected without writing through it', $result['status'] === 'error' && count(scandir($outside)) === 2);
$result = fg_discovery_internal('write', $linked, $resource_relative, "{}\n");
fg_publish_assert('Protected-resource publication also refuses symlink parent directories', $result['status'] === 'error' && count(scandir($outside)) === 2);
$linked_file_root = fg_discovery_fixture(); $linked_file = fg_discovery_directory($linked_file_root, $relative);
file_put_contents($outside . '/foreign.json', '{"foreign":true}'); symlink($outside . '/foreign.json', $linked_file);
$result = fg_discovery_internal('write', $linked_file_root, $relative, "{}\n");
fg_publish_assert('A final-file symlink is rejected without changing its target', $result['status'] === 'conflict' && file_get_contents($outside . '/foreign.json') === '{"foreign":true}');
$readonly = fg_discovery_fixture(); chmod($readonly, 0555);
$result = fg_discovery_internal('write', $readonly, $relative, "{}\n");
fg_publish_assert('Read-only directories produce bounded errors without changing permissions', $result['status'] === 'error' && (fileperms($readonly) & 0777) === 0555 && !file_exists($readonly . '/.well-known'));
chmod($readonly, 0755);
$blocked = fg_discovery_fixture(); file_put_contents($blocked . '/.well-known', 'foreign-file');
$result = fg_discovery_internal('write', $blocked, $relative, "{}\n");
fg_publish_assert('An existing file in a parent-directory position is preserved', $result['status'] === 'error' && file_get_contents($blocked . '/.well-known') === 'foreign-file');
$new_root = fg_discovery_fixture();
add_filter('pre_update_option_fg_oauth_discovery_files', $deny_update, 10, 2);
$result = fg_discovery_internal('write', $new_root, $relative, "{}\n");
remove_filter('pre_update_option_fg_oauth_discovery_files', $deny_update, 10);
fg_publish_assert('Failed ownership persistence removes the newly created document', $result['status'] === 'error' && !file_exists($new_root . '/' . $relative));

$install_root = fg_discovery_fixture(); mkdir($install_root . '/shop');
fg_publish_assert('Canonical root WordPress needs no document-root guess', fg_discovery_internal('webroot', $install_root, 'https://example.com/', '') === realpath($install_root));
fg_publish_assert('A subdirectory install accepts a confirmed matching document root', fg_discovery_internal('webroot', $install_root . '/shop', 'https://example.com/shop/', $install_root) === realpath($install_root));
fg_publish_assert('A subdirectory install rejects missing or unrelated document roots', fg_discovery_internal('webroot', $install_root . '/shop', 'https://example.com/shop/', '') === null && fg_discovery_internal('webroot', $install_root . '/shop', 'https://example.com/shop/', $outside) === null);
fg_publish_assert('Unusual encoded or traversal installation paths fail closed', fg_discovery_internal('webroot', $install_root . '/shop', 'https://example.com/%73hop/', $install_root) === null && fg_discovery_internal('webroot', $install_root . '/shop', 'https://example.com/../shop/', $install_root) === null);
fg_publish_assert('Static metadata path follows RFC8414 for a .json issuer', fg_discovery_internal('target', FG_OAuth::issuer(), 'https://gateway.example/') === $relative);
fg_publish_assert('Protected-resource publication follows RFC9728 for the canonical resource', fg_discovery_internal('resource_target', FG_OAuth::resource(), 'https://gateway.example/') === $resource_relative);
foreach (['http://gateway.example/wp-json/fames-mcp/v1/oauth/issuer.json', 'https://attacker.example/wp-json/fames-mcp/v1/oauth/issuer.json',
    'https://gateway.example:8443/wp-json/fames-mcp/v1/oauth/issuer.json', 'https://gateway.example/wp-json/../oauth/issuer.json',
    'https://gateway.example/wp-json/%2e%2e/oauth/issuer.json', 'https://gateway.example/wp-json/fames-mcp/v1/oauth/issuer.json?x=1',
    'https://gateway.example/wp-json/fames-mcp/v1/oauth/issuer.json#fragment', 'https://user:secret@gateway.example/wp-json/fames-mcp/v1/oauth/issuer.json'] as $url) {
    if (fg_discovery_internal('target', $url, 'https://gateway.example/') !== null) { throw new RuntimeException('Unsafe issuer accepted'); }
}
fg_publish_assert('Foreign origins, credentials, ports, query, fragment and traversal issuer paths are rejected', true);
foreach (['http://gateway.example/wp-json/fames-mcp/v1/mcp', 'https://attacker.example/wp-json/fames-mcp/v1/mcp',
    'https://gateway.example:8443/wp-json/fames-mcp/v1/mcp', 'https://gateway.example/wp-json/../mcp',
    'https://gateway.example/wp-json/%2e%2e/mcp', 'https://gateway.example/wp-json/fames-mcp/v1/mcp?x=1',
    'https://gateway.example/wp-json/fames-mcp/v1/mcp#fragment', 'https://user:secret@gateway.example/wp-json/fames-mcp/v1/mcp'] as $url) {
    if (fg_discovery_internal('resource_target', $url, 'https://gateway.example/') !== null) { throw new RuntimeException('Unsafe resource accepted'); }
}
fg_publish_assert('Protected-resource paths reject foreign origins, credentials, ports, query, fragment and traversal', true);
$saved_resource = FG_OAuth::$resource;
FG_OAuth::$resource = 'https://other.example/wp-json/fames-mcp/v1/mcp';
fg_publish_assert('Public protected-resource URL helper fails closed for an external resource', FG_Discovery::protected_resource_url() === '');
FG_OAuth::$resource = $saved_resource;

$saved_issuer = FG_OAuth::$issuer; FG_OAuth::$issuer = 'https://other.example/wp-json/fames-mcp/v1/oauth/issuer.json';
fg_publish_assert('Public entry point also rejects an external issuer', FG_Discovery::publish()['status'] === 'skipped'); FG_OAuth::$issuer = $saved_issuer;
FG_OAuth::$extra['oversized'] = str_repeat('x', 33000);
fg_publish_assert('Oversized metadata is rejected before filesystem publication', FG_Discovery::publish()['status'] === 'error'); unset(FG_OAuth::$extra['oversized']);
$lock = time() . ':' . wp_generate_uuid4(); add_option('fg_oauth_discovery_lock', $lock, '', false);
$result = FG_Discovery::publish();
fg_publish_assert('Concurrent publication is skipped without taking another request lock', $result['status'] === 'skipped' && get_option('fg_oauth_discovery_lock') === $lock);
fg_discovery_internal('unlock', 'different-lock');
fg_publish_assert('Lock release cannot delete a successor request lock', get_option('fg_oauth_discovery_lock') === $lock);
delete_option('fg_oauth_discovery_lock'); add_option('fg_oauth_discovery_lock', (time() - 120) . ':' . wp_generate_uuid4(), '', false);
$result = FG_Discovery::publish();
fg_publish_assert('Expired publication locks recover and report the existing file conflict', $result['status'] === 'conflict' && get_option('fg_oauth_discovery_lock', null) === null);
fg_publish_assert('Diagnostic errors do not expose filesystem paths', !str_contains(wp_json_encode($result), '/wordpress') && !str_contains(wp_json_encode($result), '/tmp/'));
echo wp_json_encode(['passed' => count($cases), 'total' => count($cases), 'cases' => $cases], JSON_PRETTY_PRINT);
