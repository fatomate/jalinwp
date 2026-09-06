<?php
/** Actual WP settings, WC CRUD and finance extraction; disposable test installs only. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { throw new RuntimeException('Disposable install required.'); }
if (!defined('ABSPATH')) { require '/wordpress/wp-load.php'; }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin('woocommerce/woocommerce.php'); activate_plugin('jalin-mcp-gateway/jalin-mcp-gateway.php');
require_once FG_DIR . 'includes/class-settings.php'; require_once FG_DIR . 'includes/class-finance-admin.php';
WC_Install::install(); WC()->init(); WC_Post_Types::register_post_types(); WC()->load_rest_api(); WC_Install::create_roles();
if (defined('FG_TEST_HPOS') && FG_TEST_HPOS) {
    add_filter('woocommerce_orders_table_query_clauses', static function ($clauses) {
        if (isset($clauses['limits'])) { $clauses['limits'] = preg_replace('/^(LIMIT \d+, )18446744073709551615$/D', '${1}9223372036854775807', $clauses['limits']); }
        return $clauses;
    });
    $sync = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
    if (!$sync->create_database_tables()) { throw new RuntimeException('HPOS table setup failed.'); }
    update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no'); update_option('woocommerce_custom_orders_table_enabled', 'yes');
    if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) { throw new RuntimeException('HPOS activation failed.'); }
}
$GLOBALS['wp_roles'] = new WP_Roles(); $admin = get_users(['role' => 'administrator', 'number' => 1])[0];
wp_set_current_user(0); wp_set_current_user($admin->ID);
$checks = [];
function ff_check($result, string $label): void { global $checks; if (!$result) { throw new RuntimeException($label); } $checks[] = $label; }
function ff_row(array $result, string $field): array { return array_values(array_filter($result['rows'], static fn($row) => $row['field'] === $field))[0]; }
$input = ['fee_key' => '_fixture_fee', 'fee_divisor' => 100, 'net_key' => '_fixture_net', 'net_divisor' => 1000, 'currency_key' => '_fixture_currency', 'affiliate_id_key' => '_fixture_affiliate', 'commission_key' => '_fixture_commission', 'commission_divisor' => 1, 'commission_currency_key' => ''];
$map = FG_Finance_Admin::validate_mapping('gateway_Custom-01', $input);
ff_check(!is_wp_error($map) && $map['net_divisor'] === 1000, 'Exact gateway case and all supported divisors preserved.');
foreach (['_api_key', '_refresh_token', '_card_number', '_private_key', '_cvv', 'space key', 'a/b', str_repeat('a', 151)] as $key) { ff_check(is_wp_error(FG_Finance_Admin::validate_mapping('fixture', ['fee_key' => $key])), 'Unsafe or invalid metadata key rejected: ' . $key); }
foreach ([0, 10, 10000, '100.0', '100abc', true, []] as $divisor) { ff_check(is_wp_error(FG_Finance_Admin::validate_mapping('fixture', ['fee_key' => '_fee', 'fee_divisor' => $divisor])), 'Invalid divisor rejected without coercion: ' . wp_json_encode($divisor)); }
ff_check(is_wp_error(FG_Finance_Admin::validate_mapping('bad/gateway', $input)), 'Invalid gateway ID rejected.');
ff_check(is_wp_error(FG_Finance_Admin::validate_mapping('fixture', ['unsupported_key' => '_foo'])), 'Unexpected fields rejected.');
ff_check(is_wp_error(FG_Finance_Admin::validate_mapping('fixture', [])), 'Empty save cannot silently remove mapping.');
ff_check(!is_wp_error(FG_Finance_Admin::validate_mapping('fixture', [], true)), 'Empty preview explains Not Configured.');
update_option('fg_settings', ['enabled' => true, 'oauth_enabled' => true, 'writes' => false, 'sensitive' => true, 'users' => [$admin->ID], 'origins' => ['https://client.example'], 'finance_mappings' => ['gateway_Custom-01' => $map]]);
$order = wc_create_order(); $order->set_currency('MYR'); $order->set_payment_method('gateway_Custom-01'); $order->set_total('25.00');
foreach (['_fixture_fee' => '-250', '_fixture_net' => '12500', '_fixture_currency' => 'USD', '_fixture_affiliate' => 'affiliate-42', '_fixture_commission' => '0', '_secret_token' => 'NEVER_RETURN'] as $key => $value) { $order->update_meta_data($key, $value); } $order->save();
$order_id = $order->get_id(); $before = FG_Core::settings(); $original_data = wp_json_encode(wc_get_order($order_id)->get_data());
$mutations = [];
$detect_mutations = static function ($query) use (&$mutations) { if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query)) { $mutations[] = $query; } return $query; };
add_filter('query', $detect_mutations, 999); $preview = FG_Finance_Admin::preview($order_id, 'gateway_Custom-01', $input); remove_filter('query', $detect_mutations, 999);
ff_check(!is_wp_error($preview), 'Real order preview succeeds with native order permission.');
ff_check(!$mutations, 'Preview performs no INSERT, UPDATE, DELETE or REPLACE query.');
ff_check(FG_Core::settings() === $before && wp_json_encode(wc_get_order($order_id)->get_data()) === $original_data, 'Preview preserves all settings and order state.');
$financials = FG_Analytics::financials(['order_id' => $order_id])['order'];
ff_check(ff_row($preview, 'Processing Fee')['value'] === $financials['gateway_finance']['fee']['amount'] && ff_row($preview, 'Processing Fee')['value'] === -2.5, 'Preview and financials share signed hundredths normalization.');
ff_check(ff_row($preview, 'Gateway Net Amount')['value'] === $financials['gateway_finance']['net']['amount'] && ff_row($preview, 'Gateway Net Amount')['value'] === 12.5, 'Preview and financials share thousandths normalization.');
ff_check(ff_row($preview, 'Affiliate Commission')['value'] === 0.0 && ff_row($preview, 'Affiliate Commission')['status'] === 'available', 'Recorded zero is Available.');
ff_check(ff_row($preview, 'Processing Fee')['currency'] === 'USD' && ff_row($preview, 'Affiliate Commission')['currency'] === 'MYR', 'Settlement and commission currencies remain separate.');
ff_check(ff_row($preview, 'Commission Currency')['status'] === 'assumed_order_currency', 'Blank currency mapping reports Assumed Order Currency.');
ff_check(strpos(wp_json_encode($preview), 'NEVER_RETURN') === false && !isset($preview['billing']) && !isset($preview['meta_data']), 'Preview omits arbitrary metadata and customer data.');
$unsaved = array_replace($input, ['fee_divisor' => 1]);
$changed = FG_Finance_Admin::preview($order_id, 'different_gateway', $unsaved);
ff_check(ff_row($changed, 'Processing Fee')['value'] === -250.0 && !$changed['gateway_matches'], 'Unsaved map used directly and gateway mismatch flagged.');
ff_check(FG_Core::settings()['finance_mappings']['gateway_Custom-01']['fee_divisor'] === 100, 'Unsaved map never replaces stored mapping.');
ff_check(ff_row(FG_Finance_Admin::preview($order_id, 'gateway_Custom-01', []), 'Processing Fee')['status'] === 'not_configured', 'Unconfigured amount has typed Not Configured.');
$missing = FG_Finance_Admin::preview($order_id, 'gateway_Custom-01', ['fee_key' => '_missing']);
ff_check(ff_row($missing, 'Processing Fee')['status'] === 'missing' && ff_row($missing, 'Processing Fee')['value'] === null, 'Absent amount remains typed Missing and null.');
$order->update_meta_data('_fixture_fee', ['invalid' => 'RAW_INVALID_SECRET']); $order->update_meta_data('_fixture_currency', 'RAW_INVALID_CURRENCY'); $order->save();
$invalid = FG_Finance_Admin::preview($order_id, 'gateway_Custom-01', $input);
ff_check(ff_row($invalid, 'Processing Fee')['status'] === 'invalid_value' && ff_row($invalid, 'Settlement Currency')['status'] === 'invalid_value', 'Invalid amount and currency produce typed Invalid Value.');
ff_check(strpos(wp_json_encode($invalid), 'RAW_INVALID') === false, 'Raw invalid values never returned.');
$adapter = static fn() => ['affiliate_id' => 'adapter-7', 'commission' => '-1.75', 'currency' => 'SGD', 'source' => 'configured_order_meta', 'secret' => 'NEVER_ADAPTER'];
add_filter('jalin_mcp_affiliate_details', $adapter); $adapted = FG_Finance_Admin::preview($order_id, 'gateway_Custom-01', $input);
ff_check($adapted['affiliate_adapter'] && ff_row($adapted, 'Affiliate Commission')['value'] === -1.75 && ff_row($adapted, 'Affiliate Commission')['currency'] === 'SGD', 'Adapter precedence, signed units and currency preserved regardless of source label.');
ff_check(strpos(wp_json_encode($adapted), 'NEVER_ADAPTER') === false, 'Adapter result remains allowlisted.'); remove_filter('jalin_mcp_affiliate_details', $adapter);
$settings = FG_Core::settings(); $settings['sensitive'] = false; update_option('fg_settings', $settings);
ff_check(FG_Finance_Admin::preview($order_id, 'gateway_Custom-01', $input)->get_error_code() === 'sensitive_access_disabled', 'Sensitive policy blocks sample order reads.');
ff_check(FG_Finance_Admin::permission() === true, 'Configuration possible before sensitive data enabled.');
$settings['sensitive'] = true; update_option('fg_settings', $settings);
$deny_order = static function ($permission, $context, $id, $type) use ($order_id) { return $id === $order_id ? false : $permission; };
add_filter('woocommerce_rest_check_permissions', $deny_order, 10, 4);
ff_check(FG_Finance_Admin::preview($order_id, 'gateway_Custom-01', $input)->get_error_code() === 'order_forbidden', 'Native per-order permission enforced.'); remove_filter('woocommerce_rest_check_permissions', $deny_order, 10);
wp_set_current_user(0); ff_check(FG_Finance_Admin::preview($order_id, 'gateway_Custom-01', $input)->get_error_code() === 'finance_forbidden', 'Unauthenticated sample read denied.'); wp_set_current_user($admin->ID);
$rows = FG_Finance_Admin::gateways(['uninstalled_legacy' => $map]);
ff_check(isset($rows['uninstalled_legacy']) && !$rows['uninstalled_legacy']['registered'], 'Removed gateway saved mapping remains listed.');
ff_check(isset($rows['bacs']) && !isset($rows['stripe']) && !isset($rows['billplz']), 'Actual registered gateways shown without fake provider cards.');
$finance_version = FG_Settings::fingerprint('finance');
ff_check(!is_wp_error(FG_Settings::save_section('connection', ['sensitive' => false], FG_Settings::fingerprint('connection'))), 'Connection section changes independently.');
$saved = FG_Finance_Admin::persist('second_gateway', $map, $finance_version, 'create');
ff_check(!is_wp_error($saved) && !FG_Core::settings()['sensitive'] && isset(FG_Core::settings()['finance_mappings']['gateway_Custom-01']), 'Old finance form preserves newer access controls and custom mappings.');
$stale = FG_Finance_Admin::persist('third_gateway', $map, $finance_version, 'create');
ff_check(is_wp_error($stale) && !isset(FG_Core::settings()['finance_mappings']['third_gateway']), 'Stale finance edit cannot overwrite newer finance changes.');
ff_check(FG_Finance_Admin::persist('second_gateway', $map, FG_Settings::fingerprint('finance'), 'create')->get_error_code() === 'duplicate_gateway', 'Duplicate creation rejected explicitly.');
$no_op = FG_Finance_Admin::persist('second_gateway', $map, FG_Settings::fingerprint('finance'), 'edit'); ff_check(!is_wp_error($no_op) && !$no_op['changed'], 'Unchanged mapping save is a no-op.');
$removed = FG_Finance_Admin::persist('second_gateway', [], FG_Settings::fingerprint('finance'), 'remove');
ff_check(!is_wp_error($removed) && !isset(FG_Core::settings()['finance_mappings']['second_gateway']) && isset(FG_Core::settings()['finance_mappings']['gateway_Custom-01']), 'Explicit deletion removes only chosen mapping.');
$settings = FG_Core::settings(); for ($i = 0; $i < 19; $i++) { $settings['finance_mappings']['limit_' . $i] = $map; } update_option('fg_settings', $settings);
ff_check(FG_Finance_Admin::persist('over_limit', $map, FG_Settings::fingerprint('finance'), 'create')->get_error_code() === 'mapping_limit', 'Twenty-mapping limit enforced.');
ob_start(); FG_Finance_Admin::render(FG_Core::settings()); $html = ob_get_clean();
ff_check(strpos($html, 'Included With WooCommerce') !== false && strpos($html, 'Test With an Order') !== false && strpos($html, 'name="mapping[fee_key]"') !== false, 'UI provides automatic fields, grouped exact-key editor and order test.');
ff_check(strpos($html, 'name="enabled"') === false && strpos($html, 'name="users"') === false, 'Finance forms do not submit access settings.');
ff_check(substr_count($html, 'aria-describedby=') >= 6 && strpos($html, 'aria-live="polite"') !== false, 'Field descriptions and preview announcements accessible.');
// Exercise real AJAX/admin guards through WordPress's die-handler extension, without process exit.
if (!defined('DOING_AJAX')) { define('DOING_AJAX', true); }
class FF_Request_Finished extends RuntimeException {}
$die_handler = static fn() => static function ($message = '', $title = '', $args = []) { throw new FF_Request_Finished(is_scalar($message) ? (string) $message : 'Request Finished'); };
add_filter('wp_die_ajax_handler', $die_handler);
function ff_request(callable $request): array {
    ob_start();
    try { $request(); throw new RuntimeException('Expected a response terminator.'); }
    catch (FF_Request_Finished $done) { return ['body' => ob_get_clean(), 'reason' => $done->getMessage()]; }
    catch (Throwable $error) { ob_end_clean(); throw $error; }
}
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_REQUEST = [];
$reply = ff_request([FG_Finance_Admin::class, 'preview_ajax']);
ff_check(strpos($reply['body'], 'POST required.') !== false, 'Preview endpoint rejects GET.');
$reply = ff_request([FG_Finance_Admin::class, 'save']);
ff_check($reply['reason'] === 'POST required.', 'Save endpoint rejects GET.');
$reply = ff_request([FG_Finance_Admin::class, 'remove']);
ff_check($reply['reason'] === 'POST required.', 'Remove endpoint rejects GET.');
$_SERVER['REQUEST_METHOD'] = 'POST';
$reply = ff_request([FG_Finance_Admin::class, 'preview_ajax']);
ff_check(strpos($reply['body'], 'valid request') !== false, 'Preview endpoint rejects missing nonce.');
$reply = ff_request([FG_Finance_Admin::class, 'save']);
ff_check($reply['reason'] !== '', 'Save endpoint rejects missing nonce.');
$_POST = ['nonce' => wp_create_nonce('fg_finance_preview'), 'order_id' => (string) $order_id, 'gateway' => 'gateway_Custom-01', 'mapping' => $input]; $_REQUEST = $_POST;
$reply = ff_request([FG_Finance_Admin::class, 'preview_ajax']);
ff_check(strpos($reply['body'], 'Enable Order') !== false, 'AJAX path also enforces sensitive policy.');
$settings = FG_Core::settings(); $settings['sensitive'] = true; update_option('fg_settings', $settings);
$reply = ff_request([FG_Finance_Admin::class, 'preview_ajax']);
$decoded = json_decode($reply['body'], true);
ff_check($decoded['success'] === true && $decoded['data']['order_id'] === $order_id && count($decoded['data']['rows']) === 6, 'Valid nonce AJAX path returns only six normalized finance rows.');
$_POST['order_id'] = ['invalid']; $_REQUEST = $_POST;
$reply = ff_request([FG_Finance_Admin::class, 'preview_ajax']);
ff_check(strpos($reply['body'], 'valid Order ID') !== false, 'AJAX order input rejects arrays.');
wp_set_current_user(0);
$reply = ff_request([FG_Finance_Admin::class, 'preview_ajax']);
ff_check(strpos($reply['body'], 'Administrator access') !== false, 'AJAX path rejects unauthenticated caller.');
wp_set_current_user($admin->ID); remove_filter('wp_die_ajax_handler', $die_handler);
file_put_contents('/test-results/finance-admin-' . (defined('FG_TEST_HPOS') && FG_TEST_HPOS ? 'hpos' : 'legacy') . '-render.html', $html);
echo wp_json_encode(['passed' => count($checks), 'total' => count($checks), 'cases' => $checks, 'storage' => defined('FG_TEST_HPOS') && FG_TEST_HPOS ? 'HPOS (SQLite fixture LIMIT shim)' : 'Legacy WooCommerce CRUD', 'native_database' => false], JSON_PRETTY_PRINT);
