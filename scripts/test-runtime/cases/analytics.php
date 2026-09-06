<?php
/** Standalone tests: php tests/analytics.php. No customer data or network access. */
define('ABSPATH', __DIR__ . '/');
class FG_Failure extends RuntimeException { public function __construct($reason, $message) { parent::__construct($message); } }
function wp_timezone() { return new DateTimeZone('Asia/Kuala_Lumpur'); }
function wp_strip_all_tags($value) { return strip_tags($value); }
require dirname(__DIR__) . '/includes/class-analytics.php';
$checks = 0;
function check($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException($message); } $checks++; }
function measurement($value, $currency = 'MYR', $assumed = false) { return ['amount' => $value, 'currency' => $currency, 'currency_assumed' => $assumed]; }
function row($id, $currency, $total, $fee) {
    return ['order_id' => $id, 'currency' => $currency, 'payment_method' => 'stripe', 'date_created_site' => '2026-08-01',
        'date_paid' => $id === 1 ? '2026-08-01' : null,
        'metrics' => ['order_total_original' => $total, 'recorded_refund_shipping_tax_lifetime' => $id === 1 ? 1.0 : null],
        'taxes' => [['rate_id' => 1, 'rate_percent' => 8.0, 'label' => 'SST', 'compound' => false, 'original_item_tax' => 8.0, 'original_shipping_tax' => 0.8, 'recorded_refunded_tax_lifetime' => 0.0]],
        'customer_fee_entries' => [['name' => 'Handling', 'total_tax_excl' => 2.0, 'tax' => 0.16]],
        'gateway_finance' => ['fee' => $fee, 'net' => measurement(null, $currency)],
        'affiliate' => ['affiliate_id' => 'affiliate-7', 'commission' => measurement($id === 1 ? 0.0 : null, $currency), 'status' => 'pending', 'source' => 'fixture']];
}

$report = FG_Analytics::summarize([
    row(1, 'MYR', 108.0, measurement(3.0, 'USD')),
    row(2, 'MYR', 54.0, measurement(null, 'USD')),
    row(3, 'USD', 90.0, measurement(-0.5, 'USD')),
]);
check(count($report['by_order_currency']) === 2, 'Currencies must stay separate.');
$myr = $report['by_order_currency'][0];
check($myr['totals']['metrics']['order_total_original']['sum_of_known'] === 162.0, 'MYR total excludes USD.');
check($myr['totals']['orders_with_date_paid'] === 1, 'Date-paid count must be distinct from order count.');
check($myr['totals']['metrics']['recorded_refund_shipping_tax_lifetime']['missing_orders'] === 1, 'Missing allocation is counted.');
check(!$myr['totals']['metrics']['recorded_refund_shipping_tax_lifetime']['is_complete'], 'Partial metric cannot be complete.');
check($myr['by_customer_fee_name'][0]['metrics']['original_total_tax_excl']['sum_of_known'] === 4.0, 'Customer fee total is separate.');
check($myr['by_tax_rate'][0]['metrics']['original_item_tax']['sum_of_known'] === 16.0, 'Tax breakdown sums within currency.');
$fee = $report['gateway_metadata_by_currency'][0];
check($fee['currency'] === 'USD' && $fee['sum_of_known'] === 2.5, 'Settlement currency and signed gateway fees are preserved.');
check($fee['known_orders'] === 2 && $fee['missing_orders'] === 1 && !$fee['is_complete'], 'Gateway coverage is explicit.');
check($report['affiliate_commissions_by_currency'][0]['sum_of_known'] === 0.0, 'An actual zero commission remains known zero.');
check($report['affiliate_commissions_by_currency'][1]['sum_of_known'] === null, 'Unknown commission must never become zero.');
$unknown = FG_Analytics::summarize([row(4, 'MYR', 5.0, measurement(1.0, null)), row(5, 'MYR', 5.0, measurement(2.0, null))]);
$unknown_fee = array_values(array_filter($unknown['gateway_metadata_by_currency'], function($r) { return $r['field'] === 'fee'; }));
check(count($unknown_fee) === 2, 'Unknown-currency amounts must remain per-order.');
check(!$unknown_fee[0]['is_complete'], 'Unknown currency is incomplete.');
check(FG_Analytics::number('0') === 0.0 && FG_Analytics::number('-2.5') === -2.5, 'Numeric zero and signed strings accepted.');
check(FG_Analytics::number(true) === null && FG_Analytics::number([]) === null && FG_Analytics::number('1e999') === null && FG_Analytics::number('RM5') === null, 'Nonfinancial scalars and infinity rejected.');

$range = FG_Analytics::date_range('2026-08-01', '2026-08-31');
check($range['from'] === '2026-08-01T00:00:00+08:00' && $range['to'] === '2026-08-31T23:59:59+08:00', 'Full days are inclusive in site timezone.');
check(FG_Analytics::date_range('2026-08-01T00:00:00Z', '2026-08-02T00:00:00Z')['from'] === '2026-08-01T00:00:00+00:00', 'Explicit UTC is preserved.');
foreach ([['2026-02-30','2026-03-01'], ['2026-08-01','2026-09-01'], ['2026-08-02','2026-08-01'], ['yesterday','today'], ['2026-08-01T00:00:00','2026-08-02']] as $invalid) {
    try { FG_Analytics::date_range($invalid[0], $invalid[1]); throw new RuntimeException('Invalid range accepted.'); }
    catch (FG_Failure $expected) { $checks++; }
}
$mapped = new ReflectionMethod(FG_Analytics::class, 'mapped');
if (PHP_VERSION_ID < 80100) { $mapped->setAccessible(true); }
$order = new class { public function get_meta($key, $single) { return ['_fee' => '-250', '_currency' => 'usd', '_bad' => ['secret'], '_zero' => '0'][$key] ?? ''; } public function get_currency() { return 'MYR'; } };
$value = $mapped->invoke(null, $order, ['fee_key' => '_fee', 'fee_divisor' => 100, 'currency_key' => '_currency'], 'fee_key', 'currency_key');
check($value['amount'] === -2.5 && $value['currency'] === 'USD' && !$value['currency_assumed'], 'Configured minor units convert to major units and preserve sign.');
$value = $mapped->invoke(null, $order, ['fee_key' => '_fee', 'fee_divisor' => 1000], 'fee_key', 'currency_key');
check($value['amount'] === -0.25 && $value['currency'] === 'MYR' && $value['currency_assumed'], 'Three-decimal divisor and assumed currency explicit.');
$value = $mapped->invoke(null, $order, ['fee_key' => '_zero'], 'fee_key', 'currency_key');
check($value['amount'] === 0.0 && $value['availability'] === 'available', 'Zero fee is available, not missing.');
$value = $mapped->invoke(null, $order, ['fee_key' => '_bad'], 'fee_key', 'currency_key');
check($value['amount'] === null && $value['availability'] === 'invalid_numeric_value', 'Structured metadata rejected.');
$value = $mapped->invoke(null, $order, ['fee_key' => '_fee', 'currency_key' => '_absent'], 'fee_key', 'currency_key');
check($value['currency'] === null && !$value['currency_assumed'], 'A configured but missing settlement currency is not silently replaced.');
$value = $mapped->invoke(null, $order, [], 'fee_key', 'currency_key');
check($value['amount'] === null && $value['availability'] === 'not_configured', 'No fee mappings means unavailable, never inferred.');
echo "Analytics fixtures passed: $checks checks.\n";
