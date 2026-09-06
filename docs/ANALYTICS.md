# WooCommerce financial analysis

The gateway provides `wc_sales_analysis` and `wc_order_financials`. Both require the WordPress `manage_woocommerce` capability and the gateway's sensitive-data setting. Order queries and reads use WooCommerce CRUD, compatible with HPOS and legacy order storage. The gateway makes no payment-gateway requests.

## Analyze a period

```json
{
  "date_from": "2026-08-01",
  "date_to": "2026-08-31",
  "statuses": ["processing", "completed", "refunded"],
  "page": 1,
  "per_page": 50
}
```

This selects orders by **creation date**, inclusive. Date-only inputs use the site's timezone; explicit RFC3339 timestamps retain their offset. Windows cannot exceed 31 days. Split longer periods into non-overlapping windows. An optional `payment_method` filters by the WooCommerce gateway ID.

Each response covers **one page only**, capped at 50 orders (default 25). Read `pagination.total_pages`, fetch every page with identical filters, and add same-currency sums and coverage counts. Normalized per-order financial rows are also included in `orders`: use these to deduplicate by `order_id` and recompute totals if pages overlap or if exporting the data. Never treat the last page as a full-period total. `is_complete` is true only if this single response contains the entire requested cohort. Responses above 400 KB ask the client to reduce `per_page`. Pagination does not freeze the store: newly created, edited, or reclassified orders can affect successive requests. Reconcile against a stable export or rerun on a quiet store for accounting work.

Default statuses are `processing` and `completed`. Those defaults exclude fully refunded orders, pending payments, and on-hold orders. For a historical sale cohort, explicitly include `refunded`; include other statuses only if they belong in your business definition. Selection uses each order's **current** status, not its status at the reporting date.

### Returned breakdowns

| Group | Contents |
| --- | --- |
| Order currency | Original order total; product subtotal before coupons; product sales after discounts, excluding tax; discounts and discount tax; shipping and shipping tax; order tax; customer fees and fee tax; recorded lifetime refunds; original total minus recorded refunds |
| Payment method | The same metrics, per gateway and order currency |
| Creation day | The same metrics in the site's timezone |
| Tax rate | Original item/fee tax, shipping tax, and recorded lifetime tax refunds by rate; rate ID, stored percent, label, compound flag |
| Customer fee name | Original customer fee totals and tax, distinct from processing fees charged to the merchant |
| Gateway metadata currency | Known stored processing fee and gateway net sums, sign preserved, with known/missing counts and assumed-currency counts |
| Affiliate and commission currency | Affiliate ID, commission amount, status/source when supplied by an adapter, and coverage counts |

Every numeric aggregate uses `sum_of_known`, `known_orders`, `missing_orders`, and `is_complete`. A missing amount remains unknown. `sum_of_known: null` means no known amounts; `0` is an actual known sum. Coverage is per metric and separate from pagination completeness. A metric being complete on one page does not make the period complete.

Order currencies never share a sum. Gateway settlement currency and affiliate commission currency may differ from order currency and are grouped independently. Gateway metadata with an unknown currency stays per-order, because unknown currencies cannot safely be combined.

`order_total_original` is the persisted order grand total, including tax, shipping, fees and discounts. It is not proof of cash capture. `orders_with_date_paid` reports recorded payment dates separately. `net_order_total_after_recorded_refunds_estimate` subtracts recorded refunds; it is not bank payout, profit or a cash-flow statement.

### Refund interpretation

Refunds are all recorded refunds **to date on the selected parent orders**. They are not filtered by the refund date. A September refund against an August order appears when analyzing the August cohort, while an August refund against a July order does not. Use a dedicated settlement/refund ledger for cash flow by transaction date.

Refund tax and shipping figures are recorded WooCommerce allocations. An amount-only refund can have zero allocated tax or shipping even when it economically includes those components. The plugin does not invent an allocation. A refund allocation method unavailable in the installed WooCommerce version returns null. Rates are historical stored order tax fields; they do not establish any legal tax treatment.

## Configure processing fees and affiliates

Settings are stored in `FG_Core::settings()['finance_mappings']`, indexed by the **actual WooCommerce payment-method ID**. Defaults are empty: no Stripe, Billplz or affiliate key is guessed. Different gateway plugins and versions can store different metadata, or store none at all.

```json
{
  "your_gateway_id": {
    "fee_key": "_your_verified_processing_fee",
    "fee_divisor": 100,
    "net_key": "_your_verified_settlement_net",
    "net_divisor": 100,
    "currency_key": "_your_verified_settlement_currency",
    "affiliate_id_key": "_your_verified_affiliate_id",
    "commission_key": "_your_verified_commission",
    "commission_divisor": 1,
    "commission_currency_key": "_your_verified_commission_currency"
  }
}
```

These keys are placeholders. Before configuring, verify the exact keys, signs, currencies, and units against the installed gateway/affiliate plugin and a known order. Amount divisors are `1`, `100` or `1000`, with `1` the default. For example a stored `250` with divisor `100` becomes `2.5` major currency units. Do not set divisor 100 merely because a currency normally has two decimal places; the plugin may already store major units.

Only the explicitly configured keys are read for financial metadata. Fee, net and commission must be finite numeric scalars; arrays, objects, booleans, currency-prefixed strings and infinity are rejected. Signed values are preserved. A mapped zero is valid and different from a missing value.

If no currency key is configured, the order currency is used with `currency_assumed: true`. If a currency key **is** configured but its value is missing or invalid, the currency stays null. Verify assumed currencies before reconciliation, especially for Stripe settlement currencies. Billplz fees may require an adapter/import if the installed integration does not persist them. Stored gateway net is exposed as recorded; the plugin does not subtract processing fees again or infer a payout.

## Single-order drilldown

Call `wc_order_financials` with `{"order_id": 123}`. It returns order totals, tax lines, customer fees, shipping lines, coupon discounts, recorded refund records, and normalized mapped gateway/affiliate values. No customer address, payment token, arbitrary metadata dump, or gateway credentials are returned by this tool. Detail lists are capped at 200 entries each and disclose truncation with `total_items` and `is_complete`; order totals still cover the whole order.

## Affiliate plugin adapters

Affiliate systems frequently use their own tables and can track multiple commissions, reversals and payment states. Exact order metadata mappings work when those values exist on the order. Otherwise a site-owned plugin can provide one normalized attribution record through:

```php
add_filter('jalin_mcp_affiliate_details', function ($unused, $order) {
    // Query your installed affiliate plugin's documented API for this order.
    // Return null when no adapter data is available; metadata mappings then apply.
    return [
        'affiliate_id' => 'affiliate-42',
        'commission' => '12.50', // MAJOR currency units, signed as your source stores it.
        'currency' => 'MYR',
        'status' => 'unpaid',
        'source' => 'my_affiliate_adapter',
    ];
}, 10, 2);
```

The adapter's allowed scalar fields are `affiliate_id`, `commission`, `currency`, `status`, and `source`. All other fields are discarded; nested structures are rejected. Adapter commission amounts already use major units, so configured metadata divisors do not apply to adapters. A null affiliate ID means attribution was unavailable from the configured source, not proof of an organic sale. This first version exposes one attribution record per order; multi-tier commission ledgers need a dedicated adapter/tool rather than flattening several affiliates into a misleading ID.

The source data may not include chargebacks, gateway fee refunds, currency conversion, reserves, payout timing, or externally paid commissions. Amounts are rounded to eight decimals for analysis; no exchange conversion or profit calculation is performed.

## Verification and sources

`tests/analytics.php` checks currency separation, partial coverage, known zero versus missing values, signed amounts, unknown currencies, date validation and inclusive site-timezone ranges, and mapped major/minor units. Run it with `php tests/analytics.php`.

`tests/analytics-integration.php` creates disposable WooCommerce fixtures and requires `FG_TEST_DISPOSABLE === true` before execution. The fixtures cover actual tax/fee/refund CRUD, default and explicit statuses, pagination, two currencies, local midnight boundaries, metadata units, affiliate adapter filtering, and native order permissions. The development environment runs WordPress 6.8.8, PHP 8.3 and WooCommerce 10.2.2 with SQLite. Its HPOS run requires an explicit **test-only** SQL compatibility shim: WooCommerce's unlimited refund queries use MySQL's unsigned maximum `LIMIT 18446744073709551615`, which SQLite cannot represent; the shim changes only that sentinel to SQLite's signed maximum. The installed plugin contains no such shim. These checks do not replace staging validation on your production MySQL/MariaDB version and installed gateway/affiliate plugins.

The implementation follows the primary [WooCommerce order query documentation](https://developer.woocommerce.com/docs/features/orders/wc-get-orders/), [WC_Order API](https://woocommerce.github.io/code-reference/classes/WC-Order.html), [WC_Order_Item_Tax API](https://woocommerce.github.io/code-reference/classes/WC-Order-Item-Tax.html), and [WC_Order_Refund API](https://woocommerce.github.io/code-reference/classes/WC-Order-Refund.html).


## Finance Setup in 0.3.0

The Finance Setup tab distinguishes automatic WooCommerce totals/taxes/payment methods/customer fees from optional merchant processing fees and affiliate costs. It lists registered gateways and saved historical IDs. Each mapping has grouped fields, amount-unit examples, Save Mapping and explicit Remove Mapping actions. No live provider API or guessed metadata defaults are used.

Test With This Order evaluates unsaved fields through `FG_Analytics::mapped_finance()`, the same extractor used by the report. It requires administrator access, WooCommerce management, enabled sensitive data and native permission to read that specific order. The preview returns only selected normalized fields and availability states, never a general metadata dump, contact details or raw malformed values. Zero differs from missing, invalid or unconfigured values. Currency differences remain explicit. A successful preview does not save; Save Mapping writes only that mapping through the fresh settings mutation boundary. Separate affiliate storage still requires the documented adapter.
