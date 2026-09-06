<?php
/** Run on an isolated disposable WordPress + WooCommerce install; creates fixture orders. */
if (!defined('FG_TEST_DISPOSABLE') || FG_TEST_DISPOSABLE !== true) { throw new RuntimeException('Analytics integration tests require an explicitly disposable test install.'); }
if (!defined('ABSPATH')) { require '/wordpress/wp-load.php'; }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin('woocommerce/woocommerce.php');
activate_plugin('fames-mcp-gateway/fames-mcp-gateway.php');
WC_Install::install();
WC()->init();
WC_Post_Types::register_taxonomies();
WC_Post_Types::register_post_types();
WC_Post_Types::register_post_status();
WC()->load_rest_api();
WC_Install::create_roles();
// Playground uses SQLite. Native Woo uses MySQL's unsigned-max LIMIT for -1;
// SQLite cannot represent that integer. Translate only that sentinel in this
// explicitly enabled fixture shim; production plugin code is never modified.
if (defined('FG_TEST_SQLITE_HPOS_SHIM') && FG_TEST_SQLITE_HPOS_SHIM) {
    add_filter('woocommerce_orders_table_query_clauses', static function($clauses) {
        if (isset($clauses['limits'])) {
            $clauses['limits'] = preg_replace('/^(LIMIT \d+, )18446744073709551615$/D', '${1}9223372036854775807', $clauses['limits']);
        }
        return $clauses;
    });
}
if (defined('FG_TEST_HPOS') && FG_TEST_HPOS) {
    $synchronizer = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class);
    if (!$synchronizer->create_database_tables()) { throw new RuntimeException('Fixture could not create HPOS tables'); }
    update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
    update_option('woocommerce_custom_orders_table_enabled', 'yes');
    if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) { throw new RuntimeException('Fixture could not enable HPOS'); }
}
$GLOBALS['wp_roles'] = new WP_Roles();
$admins = get_users(['role' => 'administrator', 'number' => 1]);
if (!$admins) { throw new RuntimeException('Fixture administrator missing'); }
wp_set_current_user(0);
wp_set_current_user($admins[0]->ID);
update_option('timezone_string', 'Asia/Kuala_Lumpur');
update_option('woocommerce_calc_taxes', 'yes');
update_option('fg_settings', ['enabled' => true, 'sensitive' => true, 'users' => [$admins[0]->ID], 'finance_mappings' => [
    'stripe' => ['fee_key' => '_fixture_fee', 'fee_divisor' => 100, 'net_key' => '_fixture_net', 'net_divisor' => 100, 'currency_key' => '_fixture_currency',
        'affiliate_id_key' => '_fixture_affiliate', 'commission_key' => '_fixture_commission', 'commission_divisor' => 1, 'commission_currency_key' => '_fixture_commission_currency'],
]]);
rest_get_server();
FG_Analytics::register();
$checks = [];
function fa_assert($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks[] = $message; }
function fa_equal($actual, $expected, $message) { fa_assert(abs($actual - $expected) < 0.000001, $message . ': got ' . $actual); }
function fa_read($name, $args) { $tool = FG_Tools::validate($name, $args); return ($tool['handler'])($args); }
function fa_fails($callback, $message) { try { $callback(); } catch (FG_Failure $error) { fa_assert(true, $message); return; } throw new RuntimeException($message); }

$rate_id = WC_Tax::_insert_tax_rate(['tax_rate_country' => 'MY', 'tax_rate_state' => '', 'tax_rate' => '8.0000', 'tax_rate_name' => 'SST', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1]);
$product = new WC_Product_Simple(); $product->set_name('Analysis fixture'); $product->set_regular_price('33.333333'); $product->set_status('publish'); $product->save();
$order = wc_create_order();
$order->set_currency('MYR'); $order->set_payment_method('stripe'); $order->set_payment_method_title('Fixture Stripe');
$order->set_date_created('2026-08-01T14:00:00+08:00'); $order->set_date_paid('2026-08-01T14:00:00+08:00');
$line = new WC_Order_Item_Product(); $line->set_product($product); $line->set_quantity(3); $line->set_subtotal('100.00'); $line->set_total('90.00'); $line->set_taxes(['subtotal' => [$rate_id => '8.00'], 'total' => [$rate_id => '7.20']]); $order->add_item($line);
$fee = new WC_Order_Item_Fee(); $fee->set_name('Handling'); $fee->set_total('2.00'); $fee->set_taxes(['total' => [$rate_id => '0.16']]); $order->add_item($fee);
$shipping = new WC_Order_Item_Shipping(); $shipping->set_method_title('Delivery'); $shipping->set_method_id('flat_rate'); $shipping->set_total('5.00'); $shipping->set_taxes(['total' => [$rate_id => '0.40']]); $order->add_item($shipping);
$order->update_taxes();
$order->calculate_totals(false);
$order->set_status('processing');
$order->update_meta_data('_fixture_fee', '-250'); $order->update_meta_data('_fixture_net', '9970'); $order->update_meta_data('_fixture_currency', 'MYR');
$order->update_meta_data('_fixture_affiliate', 'a-42'); $order->update_meta_data('_fixture_commission', '9.00'); $order->update_meta_data('_fixture_commission_currency', 'MYR'); $order->save();
$id = $order->get_id();
$line_id = array_key_first($order->get_items('line_item'));
$refund = wc_create_refund(['order_id' => $id, 'amount' => '32.40', 'reason' => 'Fixture partial refund', 'line_items' => [$line_id => ['qty' => 1, 'refund_total' => '30.00', 'refund_tax' => [$rate_id => '2.40']]], 'refund_payment' => false, 'restock_items' => false]);
fa_assert(!is_wp_error($refund), 'WooCommerce created fixture partial refund');
$refund->set_date_created('2026-09-04T10:00:00+08:00'); $refund->save();
$amount_only = wc_create_refund(['order_id' => $id, 'amount' => '5.00', 'reason' => 'Fixture amount-only refund', 'refund_payment' => false, 'restock_items' => false]);
fa_assert(!is_wp_error($amount_only), 'WooCommerce created fixture amount-only refund');

$other = wc_create_order(); $other->set_currency('MYR'); $other->set_payment_method('billplz'); $other->set_date_created('2026-08-02T10:00:00+08:00'); $other->set_status('completed'); $other->set_total('20.00'); $other->save();
$usd = wc_create_order(); $usd->set_currency('USD'); $usd->set_payment_method('stripe'); $usd->set_date_created('2026-08-03T10:00:00+08:00'); $usd->set_status('processing'); $usd->set_total('11.00'); $usd->update_meta_data('_fixture_fee', '0'); $usd->update_meta_data('_fixture_currency', 'USD'); $usd->save();
$fully_refunded = wc_create_order(); $fully_refunded->set_currency('MYR'); $fully_refunded->set_payment_method('billplz'); $fully_refunded->set_date_created('2026-08-04T10:00:00+08:00'); $fully_refunded->set_status('refunded'); $fully_refunded->set_total('7.00'); $fully_refunded->save();

$drill = fa_read('wc_order_financials', ['order_id' => $id])['order'];
fa_assert($drill['date_created'] === '2026-08-01T14:00:00+08:00', 'ISO order date preserves local timezone offset');
fa_equal($drill['metrics']['order_total_original'], 104.76, 'Original total includes discounted products, shipping, fee and all tax');
fa_equal($drill['metrics']['discount_total_tax_excl'], 10.0, 'Coupon/product discount preserved');
fa_equal($drill['metrics']['order_tax'], 7.76, 'Tax total includes product, fee and shipping');
fa_equal($drill['metrics']['recorded_refunds_total_lifetime'], 37.4, 'Both partial and amount-only refunds included');
fa_equal($drill['metrics']['recorded_refund_tax_lifetime'], 2.4, 'Only recorded allocated refund tax returned');
fa_equal($drill['metrics']['net_order_total_after_recorded_refunds_estimate'], 67.36, 'Original less lifetime recorded refunds');
fa_equal($drill['gateway_finance']['fee']['amount'], -2.5, 'Signed stored gateway fee converts from cents');
fa_equal($drill['affiliate']['commission']['amount'], 9.0, 'Mapped affiliate commission read');
fa_assert(count($drill['refunds']['items']) === 2, 'Refund details include both records');
fa_assert($drill['customer_fee_lines']['items'][0]['name'] === 'Handling', 'Customer fee line name exposed');
fa_assert($drill['taxes']['items'][0]['rate_id'] === $rate_id, 'Tax rate uses WooCommerce tax item');
fa_equal($drill['taxes']['items'][0]['recorded_refunded_tax_lifetime'], 2.4, 'Refund tax is attributed to its recorded rate');
fa_assert(!isset($drill['billing']) && !isset($drill['meta_data']), 'Drilldown omits contact data and arbitrary metadata');

$params = ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'per_page' => 2];
$page1 = fa_read('wc_sales_analysis', $params);
$page2 = fa_read('wc_sales_analysis', $params + ['page' => 2]);
fa_assert($page1['pagination']['total_orders'] === 3 && $page1['pagination']['total_pages'] === 2, 'Default cohort excludes refunded status');
fa_assert(!$page1['pagination']['is_complete'] && !$page2['pagination']['is_complete'], 'Neither a partial nor final page claims full-period completeness');
fa_assert(count($page1['orders']) === 2 && count($page2['orders']) === 1, 'Normalized rows permit client deduplication');
fa_assert(count(array_intersect($page1['order_ids'], $page2['order_ids'])) === 0, 'Stable ID ordering keeps pages disjoint on fixture');
$full = fa_read('wc_sales_analysis', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'per_page' => 50]);
fa_assert($full['pagination']['is_complete'], 'A single response covering cohort is complete');
fa_assert(count($full['page_analysis']['by_order_currency']) === 2, 'MYR and USD sales are separated');
$myr = array_values(array_filter($full['page_analysis']['by_order_currency'], static function($v) { return $v['currency'] === 'MYR'; }))[0];
fa_equal($myr['totals']['metrics']['order_total_original']['sum_of_known'], 124.76, 'MYR sales excludes USD and fully-refunded default status');
fa_equal($myr['totals']['metrics']['recorded_refunds_total_lifetime']['sum_of_known'], 37.4, 'September refund belongs to selected August order cohort');
$all_status = fa_read('wc_sales_analysis', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'statuses' => ['processing','completed','refunded']]);
fa_assert($all_status['pagination']['total_orders'] === 4, 'Explicit refunded status expands cohort');
$billplz = fa_read('wc_sales_analysis', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'payment_method' => 'billplz']);
fa_assert($billplz['pagination']['total_orders'] === 1, 'Payment-method filter applies');
fa_assert($billplz['orders'][0]['gateway_finance']['fee']['amount'] === null, 'Unconfigured Billplz fee remains unavailable');
$day = fa_read('wc_sales_analysis', ['date_from' => '2026-08-01', 'date_to' => '2026-08-01']);
fa_assert($day['order_ids'] === [$id], 'Inclusive day range matches site timezone');
fa_fails(static function(){ fa_read('wc_sales_analysis', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'statuses' => ['not-registered']]); }, 'Unknown status rejected');

$filter = static function($unused, $order){ return ['affiliate_id' => 'adapted-7', 'commission' => '-1.75', 'currency' => 'USD', 'status' => 'reversed', 'source' => 'fixture_adapter', 'secret' => 'must-not-return']; };
add_filter('fames_mcp_affiliate_details', $filter, 10, 2);
$adapted = fa_read('wc_order_financials', ['order_id' => $id])['order']['affiliate'];
fa_assert($adapted['affiliate_id'] === 'adapted-7' && $adapted['commission']['amount'] === -1.75 && $adapted['commission']['currency'] === 'USD', 'Affiliate adapter respects signed major units and own currency');
fa_assert(!isset($adapted['secret']), 'Affiliate adapter drops unallowlisted fields');
remove_filter('fames_mcp_affiliate_details', $filter, 10);

$deny = static function($allowed, $context, $object_id, $type) use ($id) { return $type === 'shop_order' && $object_id === $id ? false : $allowed; };
add_filter('woocommerce_rest_check_permissions', $deny, 10, 4);
fa_fails(static function() use ($id){ fa_read('wc_order_financials', ['order_id' => $id]); }, 'Native per-order permission filter prevents financial read');
remove_filter('woocommerce_rest_check_permissions', $deny, 10);

$edge1 = wc_create_order(); $edge1->set_currency('MYR'); $edge1->set_status('processing'); $edge1->set_date_created('2026-08-20T23:59:59+08:00'); $edge1->set_total('1.00'); $edge1->save();
$edge2 = wc_create_order(); $edge2->set_currency('MYR'); $edge2->set_status('processing'); $edge2->set_date_created('2026-08-21T00:00:00+08:00'); $edge2->set_total('1.00'); $edge2->save();
$edge_day1 = fa_read('wc_sales_analysis', ['date_from' => '2026-08-20', 'date_to' => '2026-08-20']);
$edge_day2 = fa_read('wc_sales_analysis', ['date_from' => '2026-08-21', 'date_to' => '2026-08-21']);
fa_assert($edge_day1['order_ids'] === [$edge1->get_id()], 'Inclusive local end-of-day excludes next midnight');
fa_assert($edge_day2['order_ids'] === [$edge2->get_id()], 'Inclusive next local day begins exactly at midnight');

echo json_encode(['checks' => count($checks), 'passed' => $checks, 'storage' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy', 'woocommerce' => WC_VERSION, 'sqlite_hpos_limit_compatibility_shim' => defined('FG_TEST_SQLITE_HPOS_SHIM') && FG_TEST_SQLITE_HPOS_SHIM], JSON_PRETTY_PRINT);
