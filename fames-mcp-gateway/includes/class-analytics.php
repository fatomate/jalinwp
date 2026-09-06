<?php
defined('ABSPATH') || exit;

/** Order-cohort analytics. Uses WooCommerce CRUD for both HPOS and legacy stores. */
final class FG_Analytics {
    public static function register(): void {
        if (!function_exists('wc_get_orders')) { return; }
        FG_Tools::register('wc_sales_analysis',
            'Analyze ONE page of orders created in an explicit range of at most 31 days. Default statuses processing/completed. Fetch and combine ALL pages for period totals. Refunds are lifetime refunds on the selected parent orders, not refunds dated in this period. Amounts stay separated by currency; unavailable gateway fees and affiliate commission are never zero-filled.',
            FG_Tools::schema([
                'date_from' => ['type' => 'string', 'maxLength' => 35, 'description' => 'Inclusive YYYY-MM-DD in site timezone, or RFC3339 timestamp with seconds and offset.'],
                'date_to' => ['type' => 'string', 'maxLength' => 35, 'description' => 'Inclusive YYYY-MM-DD end of day in site timezone, or RFC3339 timestamp with seconds and offset.'],
                'statuses' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'uniqueItems' => true, 'items' => ['type' => 'string', 'maxLength' => 40]],
                'payment_method' => ['type' => 'string', 'maxLength' => 100],
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ], ['date_from', 'date_to']), [self::class, 'sales'], false, 'manage_woocommerce', true);
        FG_Tools::register('wc_order_financials',
            'Retrieve one order financial breakdown: tax rates, shipping, customer fee lines, coupons, recorded refunds, and only administrator-mapped gateway fee/net and affiliate metadata. No gateway API call or payment action.',
            FG_Tools::schema(['order_id' => FG_Tools::id()], ['order_id']), [self::class, 'financials'], false, 'manage_woocommerce', true);
    }

    public static function sales(array $args): array {
        self::require_order_read();
        $range = self::date_range($args['date_from'], $args['date_to']);
        $statuses = $args['statuses'] ?? ['processing', 'completed'];
        $known = array_map(static function ($status) { return substr($status, 0, 3) === 'wc-' ? substr($status, 3) : $status; }, array_keys(wc_get_order_statuses()));
        foreach ($statuses as &$status) {
            $status = preg_replace('/^wc-/', '', $status);
            if (!in_array($status, $known, true)) { throw new FG_Failure('invalid_status', 'An order status is not registered on this site.'); }
        }
        unset($status);
        $statuses = array_values(array_unique($statuses));
        $page = max(1, min(10000, (int) ($args['page'] ?? 1)));
        $limit = max(1, min(50, (int) ($args['per_page'] ?? 25)));
        $query = [
            'type' => 'shop_order', 'status' => $statuses, 'date_created' => $range['query'],
            'limit' => $limit, 'page' => $page, 'paginate' => true, 'return' => 'objects',
            // IDs give deterministic pagination when creation timestamps tie.
            'orderby' => 'ID', 'order' => 'ASC',
        ];
        if (!empty($args['payment_method'])) { $query['payment_method'] = $args['payment_method']; }
        $result = wc_get_orders($query);
        if (!is_object($result) || !isset($result->orders, $result->total, $result->max_num_pages)) {
            throw new FG_Failure('query_failed', 'WooCommerce did not return a paginated order result.');
        }
        $rows = [];
        foreach ($result->orders as $order) {
            self::require_order_read((int) $order->get_id());
            $rows[] = self::snapshot($order, false);
        }
        $total = (int) $result->total;
        $pages = (int) $result->max_num_pages;
        $response = [
            'scope' => [
                'date_basis' => 'order_created', 'date_from_inclusive' => $range['from'], 'date_to_inclusive' => $range['to'],
                'site_timezone' => wp_timezone_string(), 'statuses' => $statuses,
                'payment_method_filter' => $args['payment_method'] ?? null,
                'refund_basis' => 'All recorded refunds to date on selected parent orders, regardless of refund creation date.',
                'generated_at_utc' => gmdate('c'), 'snapshot_isolation' => false,
            ],
            'pagination' => [
                'page' => $page, 'per_page' => $limit, 'returned_orders' => count($rows),
                'total_orders' => $total, 'total_pages' => $pages,
                'next_page' => $page < $pages ? $page + 1 : null,
                'is_complete' => $page === 1 && $total === count($rows),
                'sum_scope' => 'this_page_only',
                'instructions' => 'Fetch pages 1 through total_pages with identical filters, deduplicate by order_id, and combine same-currency sums and counts before reporting period totals. The last page alone is not complete. Orders can change between requests; rerun if reconciling a changing store.',
            ],
            'page_analysis' => self::summarize($rows),
            'order_ids' => array_column($rows, 'order_id'),
            'orders' => $rows,
            'notes' => self::notes(),
        ];
        if (strlen(wp_json_encode($response)) > 400000) {
            throw new FG_Failure('analysis_page_too_large', 'This page has too much financial detail. Retry with a smaller per_page value (as low as 1); use wc_order_financials for an unusually large single order.');
        }
        return $response;
    }

    public static function financials(array $args): array {
        self::require_order_read((int) $args['order_id']);
        $order = wc_get_order((int) $args['order_id']);
        if (!$order || $order->get_type() !== 'shop_order') { throw new FG_Failure('order_not_found', 'A standard WooCommerce order was not found.'); }
        return ['order' => self::snapshot($order, true), 'notes' => self::notes()];
    }

    private static function require_order_read(int $order_id = 0): void {
        if (!function_exists('wc_rest_check_post_permissions') || !wc_rest_check_post_permissions('shop_order', 'read', $order_id)) {
            throw new FG_Failure('forbidden', 'WooCommerce does not allow your user to read this order data.');
        }
    }

    /** Public for focused fixture testing without loading a WordPress installation. */
    public static function date_range(string $from, string $to): array {
        $start = self::parse_date($from, false);
        $end = self::parse_date($to, true);
        // Calendar month windows stay valid across daylight-saving boundaries.
        if ($end < $start || $end >= $start->modify('+31 days')) {
            throw new FG_Failure('invalid_date_range', 'Use an inclusive range of at most 31 days, with date_to on or after date_from. Split longer periods into non-overlapping ranges.');
        }
        return ['query' => $start->getTimestamp() . '...' . $end->getTimestamp(), 'from' => $start->format(DATE_ATOM), 'to' => $end->format(DATE_ATOM)];
    }

    private static function parse_date(string $value, bool $end): DateTimeImmutable {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
            if ($date && $date->format('Y-m-d') === $value) { return $end ? $date->setTime(23, 59, 59) : $date; }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value)) {
            try {
                $date = new DateTimeImmutable($value);
                if ($date->format('Y-m-d\TH:i:s') === substr($value, 0, 19)) { return $date; }
            } catch (Exception $ignored) { /* Return one safe validation error below. */ }
        }
        throw new FG_Failure('invalid_date', 'Dates must be valid YYYY-MM-DD or RFC3339 timestamps with seconds and a timezone offset.');
    }

    private static function notes(): array {
        return [
            'Order original total includes discounts, taxes, shipping and customer fee lines. It is an order amount, not independently verified captured cash. Date-paid counts are reported separately.',
            'Net order total after recorded refunds is an estimate: original order total minus recorded lifetime refunds. It is not bank payout, accounting profit, or a cash-flow report.',
            'Default processing/completed status selection excludes fully refunded orders and unpaid statuses. Include refunded explicitly for a broader historical sale cohort. Current status changes can alter earlier period totals.',
            'Recorded refund tax and shipping reflect WooCommerce allocations. Amount-only refunds may have no allocated tax or shipping; recorded zero does not prove no tax was economically refunded. No automatic tax apportionment is performed.',
            'Gateway processing fees differ from customer fee lines. Mapped fee/net/commission values are converted to MAJOR currency units using the configured divisor (1, 100 or 1000), preserve their signs, may be stale, and are not fetched or reconciled with gateway payouts. Unknown amounts are null, never an assumed zero.',
            'Settlement and commission currencies are grouped separately from order currencies. currency_assumed means order currency was used because no currency mapping was configured; verify that assumption before reconciliation.',
            'WooCommerce currency numbers are rounded to eight decimal places for analysis. No exchange-rate conversion, accounting profit, cash capture verification, chargeback or settlement ledger is supplied.',
        ];
    }

    public static function number($value): ?float {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) { return null; }
        $number = (float) $value;
        return is_finite($number) ? round($number, 8) : null;
    }

    private static function text($value, int $length = 160): ?string {
        if (!is_string($value) && !is_int($value) && !is_float($value)) { return null; }
        $value = trim(wp_strip_all_tags((string) $value));
        return $value === '' ? null : substr($value, 0, $length);
    }

    private static function currency($value): ?string {
        return is_string($value) && preg_match('/^[A-Za-z]{3}$/D', $value) ? strtoupper($value) : null;
    }

    private static function mapped($order, array $mapping, string $field, string $currency_field): array {
        $key = isset($mapping[$field]) && is_string($mapping[$field]) ? $mapping[$field] : '';
        $divisor_key = str_replace('_key', '_divisor', $field);
        $divisor = (int) ($mapping[$divisor_key] ?? 1);
        if (!in_array($divisor, [1, 100, 1000], true)) { $divisor = 1; }
        $currency_key = isset($mapping[$currency_field]) && is_string($mapping[$currency_field]) ? $mapping[$currency_field] : '';
        $currency = $currency_key !== '' ? self::currency($order->get_meta($currency_key, true)) : self::currency($order->get_currency());
        if ($key === '') { $amount = null; $availability = 'not_configured'; }
        else {
            $raw = $order->get_meta($key, true);
            $amount = self::number($raw);
            if ($amount !== null) { $amount = round($amount / $divisor, 8); }
            $availability = $amount !== null ? 'available' : (($raw === '' || $raw === null) ? 'missing' : 'invalid_numeric_value');
        }
        return [
            'amount' => $amount, 'currency' => $currency, 'currency_assumed' => $currency_key === '',
            'currency_availability' => $currency !== null ? ($currency_key === '' ? 'assumed_order_currency' : 'available') : 'missing_or_invalid',
            'availability' => $availability, 'source_meta_key' => $key ?: null, 'stored_amount_divisor' => $divisor, 'units' => 'major_currency',
        ];
    }

    private static function affiliate($order, array $mapping): array {
        $key = isset($mapping['affiliate_id_key']) && is_string($mapping['affiliate_id_key']) ? $mapping['affiliate_id_key'] : '';
        $default = [
            'affiliate_id' => $key !== '' ? self::text($order->get_meta($key, true)) : null,
            'commission' => self::mapped($order, $mapping, 'commission_key', 'commission_currency_key'),
            'status' => null, 'source' => 'configured_order_meta',
        ];
        // Site-owned adapters may use their own plugin API. No affiliate tables are assumed.
        $adapter = apply_filters('fames_mcp_affiliate_details', null, $order);
        if (!is_array($adapter)) { return $default; }
        $amount = self::number($adapter['commission'] ?? null);
        $currency = self::currency($adapter['currency'] ?? null);
        return [
            'affiliate_id' => self::text($adapter['affiliate_id'] ?? null),
            'commission' => [
                'amount' => $amount, 'currency' => $currency, 'currency_assumed' => false,
                'currency_availability' => $currency ? 'available' : 'missing_or_invalid',
                'availability' => $amount !== null ? 'available' : 'missing_or_invalid',
                'source_meta_key' => null, 'units' => 'major_currency',
            ],
            'status' => self::text($adapter['status'] ?? null, 80),
            'source' => self::text($adapter['source'] ?? 'site_adapter', 80),
        ];
    }

    /** Shared read-only extraction; previews supply their validated map without changing settings. */
    public static function mapped_finance($order, array $mapping): array {
        return [
            'gateway_finance' => [
                'fee' => self::mapped($order, $mapping, 'fee_key', 'currency_key'),
                'net' => self::mapped($order, $mapping, 'net_key', 'currency_key'),
            ],
            'affiliate' => self::affiliate($order, $mapping),
        ];
    }

    public static function snapshot($order, bool $details = false): array {
        $gateway = (string) $order->get_payment_method();
        $settings = FG_Core::settings();
        $mapping = $settings['finance_mappings'][$gateway] ?? [];
        if (!is_array($mapping)) { $mapping = []; }
        $mapped = self::mapped_finance($order, $mapping);
        $created = $order->get_date_created();
        $paid = $order->get_date_paid();
        $currency = self::currency($order->get_currency()) ?? 'UNKNOWN';
        $fees = [];
        $fee_total = 0.0;
        $fee_tax = 0.0;
        foreach ($order->get_items('fee') as $item) {
            $total = self::number($item->get_total());
            $tax = self::number($item->get_total_tax());
            $fee_total += $total ?? 0;
            $fee_tax += $tax ?? 0;
            $fees[] = ['item_id' => $item->get_id(), 'name' => self::text($item->get_name()), 'total_tax_excl' => $total, 'tax' => $tax];
        }
        $taxes = [];
        foreach ($order->get_items('tax') as $item) {
            $rate_id = (int) $item->get_rate_id();
            $taxes[] = [
                'rate_id' => $rate_id, 'label' => self::text($item->get_label()),
                'rate_percent' => method_exists($item, 'get_rate_percent') ? self::number($item->get_rate_percent()) : null,
                'compound' => (bool) $item->get_compound(),
                'original_item_tax' => self::number($item->get_tax_total()),
                'original_shipping_tax' => self::number($item->get_shipping_tax_total()),
                'recorded_refunded_tax_lifetime' => method_exists($order, 'get_total_tax_refunded_by_rate_id') ? self::number($order->get_total_tax_refunded_by_rate_id($rate_id)) : null,
            ];
        }
        $total = self::number($order->get_total());
        $refunded = self::number($order->get_total_refunded());
        $subtotal = self::number($order->get_subtotal());
        $discount = self::number($order->get_discount_total());
        $row = [
            'order_id' => (int) $order->get_id(), 'status' => $order->get_status(), 'currency' => $currency,
            'payment_method' => $gateway, 'payment_method_title' => self::text($order->get_payment_method_title()),
            'date_created' => $created ? wp_date(DATE_ATOM, $created->getTimestamp(), wp_timezone()) : null,
            'date_created_site' => $created ? wp_date('Y-m-d', $created->getTimestamp(), wp_timezone()) : 'unknown',
            'date_paid' => $paid ? wp_date(DATE_ATOM, $paid->getTimestamp(), wp_timezone()) : null,
            'metrics' => [
                'order_total_original' => $total,
                'product_subtotal_before_coupon_tax_excl' => $subtotal,
                'product_sales_after_discount_tax_excl' => $subtotal !== null && $discount !== null ? round($subtotal - $discount, 8) : null,
                'discount_total_tax_excl' => $discount, 'discount_tax' => self::number($order->get_discount_tax()),
                'shipping_total_tax_excl' => self::number($order->get_shipping_total()), 'shipping_tax' => self::number($order->get_shipping_tax()),
                'order_tax' => self::number($order->get_total_tax()),
                'customer_fee_total_tax_excl' => round($fee_total, 8), 'customer_fee_tax' => round($fee_tax, 8),
                'recorded_refunds_total_lifetime' => $refunded,
                'net_order_total_after_recorded_refunds_estimate' => $total !== null && $refunded !== null ? round($total - $refunded, 8) : null,
                'recorded_refund_tax_lifetime' => method_exists($order, 'get_total_tax_refunded') ? self::number($order->get_total_tax_refunded()) : null,
                'recorded_refund_shipping_tax_excl_lifetime' => method_exists($order, 'get_total_shipping_refunded') ? self::number($order->get_total_shipping_refunded()) : null,
                'recorded_refund_shipping_tax_lifetime' => method_exists($order, 'get_total_shipping_tax_refunded') ? self::number($order->get_total_shipping_tax_refunded()) : null,
            ],
            'taxes' => $taxes,
            'customer_fee_entries' => $fees,
            'gateway_finance' => $mapped['gateway_finance'],
            'affiliate' => $mapped['affiliate'],
        ];
        if ($details) {
            unset($row['customer_fee_entries']);
            $coupons = [];
            foreach ($order->get_items('coupon') as $item) {
                $coupons[] = ['code' => self::text($item->get_code()), 'discount_tax_excl' => self::number($item->get_discount()), 'discount_tax' => self::number($item->get_discount_tax())];
            }
            $shipping = [];
            foreach ($order->get_items('shipping') as $item) {
                $shipping[] = ['item_id' => $item->get_id(), 'method_id' => self::text($item->get_method_id()), 'name' => self::text($item->get_name()), 'total_tax_excl' => self::number($item->get_total()), 'tax' => self::number($item->get_total_tax())];
            }
            $refunds = [];
            foreach ($order->get_refunds() as $refund) {
                $date = $refund->get_date_created();
                $refunds[] = [
                    'refund_id' => (int) $refund->get_id(), 'date_created' => $date ? wp_date(DATE_ATOM, $date->getTimestamp(), wp_timezone()) : null,
                    'amount' => self::number($refund->get_amount()), 'currency' => $currency,
                    'recorded_tax_signed' => self::number($refund->get_total_tax()),
                    'recorded_shipping_tax_excl_signed' => self::number($refund->get_shipping_total()),
                    'recorded_shipping_tax_signed' => self::number($refund->get_shipping_tax()),
                    'payment_refunded_flag' => method_exists($refund, 'get_refunded_payment') ? (bool) $refund->get_refunded_payment() : null,
                    'allocation_note' => 'Signed WooCommerce refund item totals; amount-only refunds may have zero allocations. Payment flag is recorded site state, not independently verified settlement.',
                ];
            }
            foreach (['customer_fee_lines' => $fees, 'coupon_lines' => $coupons, 'shipping_lines' => $shipping, 'refunds' => $refunds] as $key => $items) {
                $row[$key] = ['items' => array_slice($items, 0, 200), 'total_items' => count($items), 'is_complete' => count($items) <= 200];
            }
            $row['taxes'] = ['items' => array_slice($taxes, 0, 200), 'total_items' => count($taxes), 'is_complete' => count($taxes) <= 200];
        }
        return $row;
    }

    private static function add_metrics(array &$bucket, array $row): void {
        $bucket['order_count'] = ($bucket['order_count'] ?? 0) + 1;
        $bucket['orders_with_date_paid'] = ($bucket['orders_with_date_paid'] ?? 0) + (!empty($row['date_paid']) ? 1 : 0);
        foreach ($row['metrics'] as $name => $value) {
            if (!isset($bucket['metrics'][$name])) { $bucket['metrics'][$name] = ['sum_of_known' => null, 'known_orders' => 0, 'missing_orders' => 0]; }
            $metric =& $bucket['metrics'][$name];
            if ($value !== null) { $metric['sum_of_known'] = round(($metric['sum_of_known'] ?? 0) + $value, 8); $metric['known_orders']++; }
            else { $metric['missing_orders']++; }
            $metric['is_complete'] = $metric['missing_orders'] === 0;
            unset($metric);
        }
    }

    private static function add_measurement(array &$bucket, array $value): void {
        $bucket['orders'] = ($bucket['orders'] ?? 0) + 1;
        $bucket['known_orders'] = ($bucket['known_orders'] ?? 0) + ($value['amount'] !== null ? 1 : 0);
        $bucket['missing_orders'] = ($bucket['missing_orders'] ?? 0) + ($value['amount'] === null ? 1 : 0);
        $bucket['currency_assumed_orders'] = ($bucket['currency_assumed_orders'] ?? 0) + ($value['currency_assumed'] ? 1 : 0);
        if (!array_key_exists('sum_of_known', $bucket)) { $bucket['sum_of_known'] = null; }
        if ($value['amount'] !== null) { $bucket['sum_of_known'] = round(($bucket['sum_of_known'] ?? 0) + $value['amount'], 8); }
        $bucket['is_complete'] = $bucket['missing_orders'] === 0 && $value['currency'] !== null;
    }

    /** Pure aggregation of normalized snapshots. Never mixes order or settlement currencies. */
    public static function summarize(array $rows): array {
        $currencies = [];
        $gateway_finance = [];
        $affiliates = [];
        foreach ($rows as $row) {
            $currency = $row['currency'];
            if (!isset($currencies[$currency])) { $currencies[$currency] = ['currency' => $currency, 'totals' => [], 'by_payment_method' => [], 'by_date_created' => [], 'by_tax_rate' => [], 'by_customer_fee_name' => []]; }
            $bucket =& $currencies[$currency];
            self::add_metrics($bucket['totals'], $row);
            $gateway = $row['payment_method'];
            if (!isset($bucket['by_payment_method'][$gateway])) { $bucket['by_payment_method'][$gateway] = ['payment_method' => $gateway]; }
            self::add_metrics($bucket['by_payment_method'][$gateway], $row);
            $day = $row['date_created_site'];
            if (!isset($bucket['by_date_created'][$day])) { $bucket['by_date_created'][$day] = ['date_created' => $day]; }
            self::add_metrics($bucket['by_date_created'][$day], $row);
            $order_fee_groups = [];
            foreach ($row['customer_fee_entries'] ?? [] as $fee) {
                $name = $fee['name'] ?? '(unnamed)';
                if (!isset($order_fee_groups[$name])) { $order_fee_groups[$name] = ['total_tax_excl' => 0.0, 'tax' => 0.0, 'lines' => 0]; }
                $order_fee_groups[$name]['total_tax_excl'] += $fee['total_tax_excl'] ?? 0;
                $order_fee_groups[$name]['tax'] += $fee['tax'] ?? 0;
                $order_fee_groups[$name]['lines']++;
            }
            foreach ($order_fee_groups as $name => $fee) {
                if (!isset($bucket['by_customer_fee_name'][$name])) { $bucket['by_customer_fee_name'][$name] = ['name' => (string) $name, 'fee_line_count' => 0]; }
                $bucket['by_customer_fee_name'][$name]['fee_line_count'] += $fee['lines'];
                self::add_metrics($bucket['by_customer_fee_name'][$name], ['date_paid' => $row['date_paid'], 'metrics' => ['original_total_tax_excl' => $fee['total_tax_excl'], 'original_tax' => $fee['tax']]]);
            }
            foreach ($row['taxes'] as $tax) {
                // Include historical percent, label and compound flag if a rate was edited.
                $key = json_encode([$tax['rate_id'], $tax['rate_percent'], $tax['label'], $tax['compound']]);
                if (!isset($bucket['by_tax_rate'][$key])) {
                    $bucket['by_tax_rate'][$key] = ['rate_id' => $tax['rate_id'], 'rate_percent' => $tax['rate_percent'], 'label' => $tax['label'], 'compound' => $tax['compound']];
                }
                $tax_row = ['date_paid' => null, 'metrics' => array_intersect_key($tax, array_flip(['original_item_tax', 'original_shipping_tax', 'recorded_refunded_tax_lifetime']))];
                self::add_metrics($bucket['by_tax_rate'][$key], $tax_row);
            }
            unset($bucket);
            foreach ($row['gateway_finance'] as $field => $value) {
                // Unknown currency amounts cannot safely be summed, even with each other.
                $key = json_encode([$gateway, $field, $value['currency'], $value['currency'] === null ? $row['order_id'] : null]);
                if (!isset($gateway_finance[$key])) { $gateway_finance[$key] = ['payment_method' => $gateway, 'field' => $field, 'currency' => $value['currency']]; }
                self::add_measurement($gateway_finance[$key], $value);
                if ($value['currency'] === null) { $gateway_finance[$key]['order_id'] = $row['order_id']; }
            }
            $affiliate = $row['affiliate'];
            $value = $affiliate['commission'];
            $key = json_encode([$affiliate['affiliate_id'], $value['currency'], $affiliate['status'], $affiliate['source'], $value['currency'] === null ? $row['order_id'] : null]);
            if (!isset($affiliates[$key])) {
                $affiliates[$key] = ['affiliate_id' => $affiliate['affiliate_id'], 'currency' => $value['currency'], 'status' => $affiliate['status'], 'source' => $affiliate['source']];
            }
            self::add_measurement($affiliates[$key], $value);
            if ($value['currency'] === null) { $affiliates[$key]['order_id'] = $row['order_id']; }
        }
        foreach ($currencies as &$currency) {
            foreach (['by_payment_method', 'by_date_created', 'by_tax_rate', 'by_customer_fee_name'] as $key) { $currency[$key] = array_values($currency[$key]); }
        }
        unset($currency);
        return [
            'order_count' => count($rows), 'by_order_currency' => array_values($currencies),
            'gateway_metadata_by_currency' => array_values($gateway_finance),
            'affiliate_commissions_by_currency' => array_values($affiliates),
            'coverage_note' => 'sum_of_known covers only known values in this page. Missing values are counted and remain unknown. A null affiliate ID means no attribution was supplied by the configured source; it does not establish an organic sale.',
        ];
    }
}
